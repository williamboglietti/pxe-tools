<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Db;
use Admin\Models\ActionCheckpoints;
use Admin\Models\Machine;

/**
 * Lecture seule : dernière action suivie par machine, pour la supervision.
 * Jamais de token, de MAC, de journal ni de config d'installation.
 * Tous les champs sont toujours présents (0 / "" plutôt que null) : Telegraf n'émet pas un
 * champ null et les requêtes Flux pivotent sur un même _time.
 */
final class InstallMonitor
{
    public const WINDOW_SECONDS = 86400;
    private const TYPES = ['reinstall', 'install_only', 'wipe_only', 'disktest'];

    public static function snapshot(int $now, int $windowSeconds = self::WINDOW_SECONDS): array
    {
        $in = implode(',', array_fill(0, count(self::TYPES), '?'));
        $stmt = Db::get()->prepare(
            "SELECT a.id, a.machine_id, a.type, a.status, a.created_at, a.ended_at,
                    (SELECT MAX(e.created_at) FROM machine_events e WHERE e.action_id = a.id) AS last_event_at
             FROM actions a
             WHERE a.type IN ($in)
               AND a.id = (SELECT MAX(b.id) FROM actions b WHERE b.machine_id = a.machine_id AND b.type IN ($in))
             ORDER BY a.machine_id"
        );
        $stmt->execute([...self::TYPES, ...self::TYPES]);

        $installs = [];
        foreach ($stmt->fetchAll() as $action) {
            $started = self::epoch($action['created_at']);
            $disks = $action['type'] === 'disktest' ? self::diskProgress((int)$action['id']) : [];
            // Un test disque ne crée aucun machine_event pendant sa progression : sans ça, l'alerte « sans progrès » se déclenche à tort.
            $lastEvent = max(self::epoch($action['last_event_at']), self::lastProgressAt($disks));
            $ended = self::epoch($action['ended_at']);
            $reference = $ended ?: ($lastEvent ?: $started);
            if ($now - $reference > $windowSeconds) continue;
            $machine = Machine::find((int)$action['machine_id']);
            if ($machine === null) continue;

            $status = (string)$action['status'];
            [$done, $total, $phase] = self::progress((int)$action['id'], (string)$action['type']);
            $pct = $status === 'success' ? 100 : ($total > 0 ? intdiv($done * 100, $total) : 0);
            if ($action['type'] === 'disktest' && in_array($status, ['pending', 'running'], true)) {
                $pct = self::disktestPct($disks) ?? $pct;
            }
            $installs[] = [
                'machine_id' => (int)$action['machine_id'],
                'label' => self::label($machine),
                'action_id' => (int)$action['id'],
                'type' => (string)$action['type'],
                'status' => $status,
                'phase' => $phase,
                'steps_done' => $done,
                'steps_total' => $total,
                'pct' => $pct,
                'started_at' => $started,
                'last_event_at' => $lastEvent,
                'ended_at' => $ended,
            ];
        }
        return ['generated_at' => $now, 'installs' => $installs];
    }

    /** Machine::displayLabel retombe sur la MAC : on s'arrête à un identifiant anonyme. */
    private static function label(array $machine): string
    {
        $hostname = trim((string)($machine['hostname'] ?? ''));
        if ($hostname !== '') return $hostname;
        $vendorProduct = Machine::vendorProduct($machine);
        return $vendorProduct !== '' ? $vendorProduct : 'machine-' . (int)$machine['id'];
    }

    /** @return array{0:int,1:int,2:string} étapes reçues, taille du catalogue, libellé du dernier checkpoint atteint */
    private static function progress(int $actionId, string $type): array
    {
        $catalog = ActionCheckpoints::forType($type);
        $stmt = Db::get()->prepare('SELECT DISTINCT checkpoint FROM machine_events WHERE action_id = ? AND checkpoint IS NOT NULL');
        $stmt->execute([$actionId]);
        $received = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));
        $done = 0;
        $phase = '';
        foreach ($catalog as $entry) {
            if (isset($received[$entry['code']])) {
                $done++;
                $phase = (string)$entry['label'];
            }
        }
        return [$done, count($catalog), $phase];
    }

    /** @return list<array> progression live par disque (vide hors test disque ou sans progression) */
    private static function diskProgress(int $actionId): array
    {
        $stmt = Db::get()->prepare('SELECT progress_json FROM disk_tests WHERE action_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$actionId]);
        $progress = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($progress) ? array_values(array_filter($progress, 'is_array')) : [];
    }

    private static function disktestPct(array $disks): ?int
    {
        $values = [];
        foreach ($disks as $disk) {
            if (isset($disk['pct']) && is_numeric($disk['pct'])) $values[] = (float)$disk['pct'];
        }
        return $values === [] ? null : max(0, min(100, (int)round(array_sum($values) / count($values))));
    }

    /** `updated_at` est en ISO 8601 avec son propre décalage (date('c')), contrairement aux horodatages de la base. */
    private static function lastProgressAt(array $disks): int
    {
        $latest = 0;
        foreach ($disks as $disk) {
            $time = is_string($disk['updated_at'] ?? null) ? strtotime($disk['updated_at']) : false;
            if ($time !== false) $latest = max($latest, $time);
        }
        return $latest;
    }

    /** Les horodatages de la base sont en UTC, sans fuseau. */
    private static function epoch(mixed $value): int
    {
        if ($value === null || $value === '') return 0;
        $time = strtotime((string)$value . ' UTC');
        return $time === false ? 0 : $time;
    }
}
