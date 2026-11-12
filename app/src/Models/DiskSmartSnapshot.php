<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use Admin\Util\DiskIdentity;
use PDO;

final class DiskSmartSnapshot
{
    /**
     * Valide la requête en gardant l’ordre de refus identique à l’API PXE.
     *
     * @return array{http:int,response:array<string,string>,serial:?string,payload:?array,job:?array}
     */
    public static function validate(int $testId, ?array $test, string $disk, string $phase, string $body): array
    {
        if ($testId <= 0) {
            return self::invalid(400, 'test_id required');
        }
        if ($test === null) {
            return self::invalid(404, 'test not found');
        }
        if (!in_array((string)($test['job_status'] ?? ''), ['pending', 'running'], true)) {
            return ['http' => 200, 'response' => ['status' => 'ignored'], 'serial' => null, 'payload' => null, 'job' => null];
        }
        if (!in_array($phase, ['before', 'after'], true)) {
            return self::invalid(400, 'invalid phase');
        }
        if (strlen($body) > 1_048_576) {
            return self::invalid(413, 'body too large');
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return self::invalid(400, 'invalid JSON');
        }

        $jobs = json_decode((string)($test['jobs_json'] ?? ''), true);
        $job = null;
        if ($disk !== '' && is_array($jobs)) {
            foreach ($jobs as $candidate) {
                if (is_array($candidate) && ($candidate['disk'] ?? null) === $disk) {
                    $job = $candidate;
                    break;
                }
            }
        }
        if ($job === null) {
            return self::invalid(400, 'unknown disk');
        }

        $serial = DiskTest::jobSerial($job);
        if ($serial === null) {
            return self::invalid(409, 'job serial required');
        }
        // Sans numéro de série dans le relevé, rien ne prouve qu'il vient du bon disque
        // (smartctl en échec sort un JSON d'erreur sans identité) : on ne le range pas.
        if (!array_key_exists('serial_number', $payload)) {
            return self::invalid(409, 'serial missing in snapshot');
        }
        if (DiskIdentity::normalize($payload['serial_number']) !== $serial) {
            return self::invalid(409, 'serial mismatch');
        }

        return ['http' => 200, 'response' => ['status' => 'ok'], 'serial' => $serial, 'payload' => $payload, 'job' => $job];
    }

    public static function store(
        int $machineId,
        int $testId,
        string $serial,
        ?string $device,
        string $phase,
        string $json
    ): void {
        if (!in_array($phase, ['before', 'after'], true)) {
            throw new \InvalidArgumentException('Invalid SMART snapshot phase');
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('SMART snapshot must contain a JSON object or array');
        }

        $pdo = Db::get();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM disk_smart_snapshots WHERE test_id = ? AND serial = ? AND phase = ?'
            )->execute([$testId, $serial, $phase]);
            $pdo->prepare(
                'INSERT INTO disk_smart_snapshots (machine_id, test_id, serial, device, phase, smart_json) '
              . 'VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$machineId, $testId, $serial, $device, $phase, $json]);

            $stmt = $pdo->prepare(
                'SELECT id FROM disk_smart_snapshots WHERE serial = ? AND phase = ? '
              . 'ORDER BY created_at DESC, id DESC'
            );
            $stmt->execute([$serial, $phase]);
            $oldIds = array_slice($stmt->fetchAll(PDO::FETCH_COLUMN), 20);
            if ($oldIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($oldIds), '?'));
                $pdo->prepare("DELETE FROM disk_smart_snapshots WHERE id IN ($placeholders)")
                    ->execute($oldIds);
            }

            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function forSerial(string $serial, int $limit = 20): array
    {
        if ($limit <= 0) return [];
        $stmt = Db::get()->prepare(
            'SELECT * FROM disk_smart_snapshots WHERE serial = ? '
          . 'ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $serial, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{before:?array,after:?array} */
    public static function pairForTest(int $testId, string $serial): array
    {
        return [
            'before' => self::findForTestPhase($testId, $serial, 'before'),
            'after' => self::findForTestPhase($testId, $serial, 'after'),
        ];
    }

    /** @return array{http:int,response:array<string,string>,serial:null,payload:null,job:null} */
    private static function invalid(int $http, string $message): array
    {
        return [
            'http' => $http,
            'response' => ['error' => $message],
            'serial' => null,
            'payload' => null,
            'job' => null,
        ];
    }

    private static function findForTestPhase(int $testId, string $serial, string $phase): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT * FROM disk_smart_snapshots WHERE test_id = ? AND serial = ? AND phase = ? '
          . 'ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$testId, $serial, $phase]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::decodeRow($row);
    }

    /** @param array<string,mixed> $row */
    private static function decodeRow(array $row): array
    {
        $decoded = json_decode((string)($row['smart_json'] ?? ''), true);
        $row['smart_json'] = is_array($decoded) && json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        return $row;
    }
}
