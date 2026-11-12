<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use Admin\Util\DiskIdentity;
use Admin\Util\DiskReliability;

/** Read-only history follows a physical serial, including moves between machines. */
final class DiskReliabilityHistory
{
    public static function forInventory(array $inventory): array
    {
        $counts = [];
        foreach (($inventory['disks'] ?? []) as $disk) {
            if (!is_array($disk)) continue;
            $identity = is_array($disk['identity'] ?? null) ? $disk['identity'] : [];
            if (isset($identity['status']) && $identity['status'] !== 'valid') continue;
            $serial = DiskIdentity::normalize($identity['value'] ?? $disk['serial'] ?? null);
            if ($serial !== null) $counts[$serial] = ($counts[$serial] ?? 0) + 1;
        }
        $out = [];
        $pairsBySerial = [];
        foreach ($counts as $serial => $count) {
            if ($count !== 1) continue;
            $pairs = [];
            foreach (DiskSmartSnapshot::forSerial($serial, 40) as $snapshot) {
                $id = (int)$snapshot['test_id'];
                $phase = (string)$snapshot['phase'];
                if (!isset($pairs[$id][$phase])) $pairs[$id][$phase] = $snapshot;
            }
            $pairsBySerial[$serial] = $pairs;
            $out[$serial] = ['serial' => $serial, 'latest' => null, 'history' => []];
        }
        if ($out === []) return [];
        // Newer incomplete jobs may have no snapshot. They must remain visible
        // instead of silently preserving an old healthy grade as the latest one.
        $stmt = Db::get()->query('SELECT id,machine_id,jobs_json,results_json,job_status,ended_at FROM disk_tests ORDER BY id DESC');
        while ($test = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $jobs = json_decode((string)$test['jobs_json'], true);
            $seen = [];
            foreach (is_array($jobs) ? $jobs : [] as $job) {
                $serial = is_array($job) ? DiskTest::jobSerial($job) : null;
                if ($serial === null || !isset($out[$serial]) || isset($seen[$serial]) || count($out[$serial]['history']) >= 20) continue;
                $seen[$serial] = true;
                $row = self::fromPair($serial, $test, $pairsBySerial[$serial][(int)$test['id']] ?? []);
                $out[$serial]['history'][] = $row;
                if ($out[$serial]['latest'] === null) $out[$serial]['latest'] = $row;
            }
            if (count(array_filter($out, static fn(array $disk): bool => count($disk['history']) >= 20)) === count($out)) break;
        }
        return $out;
    }

    /** Pure adapter: never reinterpret an old passed verdict as completion evidence. */
    public static function fromPair(string $serial, array $test, array $pair): array
    {
        $serial = DiskIdentity::normalize($serial) ?? '';
        $jobs = json_decode((string)($test['jobs_json'] ?? ''), true);
        $results = json_decode((string)($test['results_json'] ?? ''), true);
        $job = null;
        foreach (is_array($jobs) ? $jobs : [] as $candidate) {
            if (is_array($candidate) && DiskTest::jobSerial($candidate) === $serial) {
                $job = $candidate;
                break;
            }
        }
        $result = null;
        foreach (is_array($results) ? $results : [] as $candidate) {
            if (is_array($candidate) && $job !== null
                && ($candidate['disk'] ?? null) === ($job['disk'] ?? null)
                && ($candidate['mode'] ?? null) === ($job['mode'] ?? null)
                && DiskIdentity::normalize($candidate['serial'] ?? null) === $serial) {
                $result = $candidate;
                break;
            }
        }
        $before = $pair['before']['smart_json'] ?? null;
        $after = $pair['after']['smart_json'] ?? null;
        $context = null;
        $reason = 'Preuve de fin du test absente : relancer un test avec la version actuelle.';
        if (!in_array($test['job_status'] ?? '', ['success', 'failed'], true)) {
            $reason = 'Test en cours, annulé ou incomplet.';
        } elseif (!is_array($before) || !is_array($after)) {
            $reason = 'Relevé SMART avant ou après manquant.';
        } elseif (DiskIdentity::normalize($before['serial_number'] ?? null) !== $serial
            || DiskIdentity::normalize($after['serial_number'] ?? null) !== $serial) {
            $reason = 'Identité du disque différente dans les relevés SMART.';
        } elseif ($job !== null && $result !== null && in_array($result['verdict'] ?? '', ['passed', 'failed'], true)) {
            $context = self::testContext((string)$job['mode'], $result, $after);
        }
        $verdict = $context !== null
            ? DiskReliability::evaluate($before, $after, $context)
            : self::unknown($reason);
        return [
            'serial' => $serial,
            'test_id' => (int)($test['id'] ?? 0),
            'machine_id' => (int)($test['machine_id'] ?? 0),
            'date' => $result['completed_at'] ?? $test['ended_at'] ?? $pair['after']['created_at'] ?? $pair['before']['created_at'] ?? null,
            'mode' => (string)($job['mode'] ?? ''),
            'result' => $result,
            'verdict' => $verdict,
            'performance_measured' => $context !== null && $context['surface'] !== null && is_array($context['surface']['stalls']),
        ];
    }

    private static function testContext(string $mode, array $result, array $smart): ?array
    {
        $plans = [
            'smart_short' => 'smart', 'smart_long' => 'smart',
            'badblocks_quick_ro' => 'read', 'badblocks_long_ro' => 'read',
            'badblocks_quick_rw' => 'write1', 'badblocks_fixed_rw' => 'write1fixed',
            'badblocks_long_rw' => 'write2',
        ];
        $evidence = DiskTest::resultEvidence($result['evidence'] ?? null);
        if (!isset($plans[$mode]) || $evidence === null || !DiskTest::hasCompletedResult($mode, $result)) return null;
        $kind = is_array($smart['nvme_smart_health_information_log'] ?? null) ? 'nvme'
            : (is_int($smart['rotation_rate'] ?? null) && $smart['rotation_rate'] >= 0
                ? ($smart['rotation_rate'] === 0 ? 'ssd' : 'hdd') : null);
        if ($kind === null) return null;
        $context = ['status' => 'completed', 'plan' => $plans[$mode], 'kind' => $kind, 'selftests' => [], 'surface' => null];
        if (str_starts_with($mode, 'smart_')) {
            $type = $mode === 'smart_long' ? 'long' : 'short';
            if (($evidence['tool'] ?? '') !== 'smartctl' || ($evidence['type'] ?? '') !== $type) return null;
            $status = $evidence['status'] ?? '';
            if ($status === 'completed') {
                $rc = $evidence['start_exit_code'] ?? null;
                if (!is_int($rc) || $rc < 0 || ($rc & 7) !== 0
                    || (($evidence['new_entry'] ?? false) !== true && ($evidence['seen_running'] ?? false) !== true)
                    || !is_bool($evidence['passed'] ?? null)) return null;
            } elseif (!in_array($status, ['unsupported', 'interrupted'], true)) {
                return null;
            }
            $context['selftests'] = [['status' => $status, 'passed' => $evidence['passed'] ?? null, 'type' => $type]];
            return $context;
        }
        if (($evidence['tool'] ?? '') !== 'badblocks') return null;
        $expected = match ($mode) {
            'badblocks_fixed_rw' => ['0xaa'],
            'badblocks_quick_rw' => ['random'],
            'badblocks_long_rw' => ['0xaa', '0x55'],
            default => [],
        };
        $passes = $mode === 'badblocks_long_ro' ? 4 : 1;
        if (($evidence['status'] ?? '') !== 'completed' || ($evidence['exit_code'] ?? null) !== 0
            || ($evidence['pass_completed'] ?? null) !== true
            || !is_int($evidence['passes_completed'] ?? null) || $evidence['passes_completed'] < $passes
            || ($evidence['patterns'] ?? null) !== $expected
            || ($evidence['write_phases'] ?? null) !== count($expected)
            || ($evidence['verify_phases'] ?? null) !== count($expected)) return null;
        foreach (['errors', 'bad_blocks'] as $key) {
            if (!is_int($evidence[$key] ?? null) || $evidence[$key] < 0) return null;
        }
        if (!array_key_exists('stalls', $evidence) || ($evidence['stalls'] !== null && !is_array($evidence['stalls']))) return null;
        $context['surface'] = ['status' => 'completed', 'errors' => $evidence['errors'], 'bad_blocks' => $evidence['bad_blocks'], 'stalls' => $evidence['stalls']];
        return $context;
    }

    private static function unknown(string $reason): array
    {
        $unknown = DiskReliability::evaluate(null, null, []);
        $unknown['reasons'] = [$reason];
        return $unknown;
    }
}
