<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

/**
 * Spec C — Vue agrégée des 4 types de tâches d'une machine.
 *
 * Sources :
 *   - discoveries  (Spec C nouvelle table)
 *   - installations
 *   - wipes
 *   - disk_tests
 *
 * Normalisation pour l'UI timeline :
 *   - type      : 'discovery' | 'install' | 'wipe' | 'disktest'
 *   - id        : id dans la table source
 *   - status    : pending | running | success | failed (mappé depuis le status spécifique)
 *   - started_at, ended_at, created_at
 *   - summary   : description courte (human-friendly)
 *   - duration_s: secondes entre started/ended (null si en cours)
 *
 * Ne fait pas de lookup par task_id direct — utiliser les models Discovery,
 * Installation, Wipe, DiskTest pour les détails.
 */
final class MachineTask
{
    /**
     * Retourne les N dernières actions d'une machine, enrichies avec le compte
     * de sous-jobs par type. SELECT depuis `actions` (plus d'UNION ALL) — les
     * rows legacy sans action_id ne remontent plus dans cette liste.
     *
     * @return array<int,array{type:string,id:int,action_id:int,status:string,started_at:?string,ended_at:?string,created_at:?string,duration_s:?int,summary:string,subjobs_summary:string}>
     */
    public static function recentByMachine(int $machineId, int $limit = 20): array
    {
        $pdo = Db::get();

        // Sub-counts par type de sous-job pour construire le résumé texte.
        // POURQUOI SELECT depuis actions et non UNION ALL : la spec (§ UI ligne 252)
        // impose une vue centrée sur l'action parente. Les rows legacy sans action_id
        // seront migrées en Worker_MIGR (étape 7).
        $sql = <<<SQL
        SELECT a.*,
               (SELECT COUNT(*) FROM wipes WHERE action_id = a.id)         AS wipe_count,
               (SELECT COUNT(*) FROM installations WHERE action_id = a.id) AS install_count,
               (SELECT COUNT(*) FROM discoveries WHERE action_id = a.id)   AS discovery_count,
               (SELECT COUNT(*) FROM disk_tests WHERE action_id = a.id)    AS disktest_count
          FROM actions a
         WHERE a.machine_id = :mid
         ORDER BY a.created_at DESC
         LIMIT :lim
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':mid', $machineId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([self::class, 'normalizeAction'], $rows);
    }

    /**
     * Normalise une row `actions` enrichie (avec sub-counts) en structure homogène
     * compatible avec le partial _tasks_list.php.
     */
    private static function normalizeAction(array $row): array
    {
        $started  = $row['created_at'] ?? null;  // actions.created_at = déclenchement admin
        $ended    = $row['ended_at'] ?? null;
        $duration = null;
        if ($started && $ended) {
            $duration = max(0, strtotime($ended) - strtotime($started));
        }

        $wipeCount      = (int)($row['wipe_count'] ?? 0);
        $installCount   = (int)($row['install_count'] ?? 0);
        $discoveryCount = (int)($row['discovery_count'] ?? 0);
        $disktestCount  = (int)($row['disktest_count'] ?? 0);

        // Résumé textuel des sous-jobs présents dans cette action
        $parts = [];
        if ($wipeCount > 0)      $parts[] = 'wipe';
        if ($installCount > 0)   $parts[] = 'install';
        if ($discoveryCount > 0) $parts[] = 'discovery';
        if ($disktestCount > 0)  $parts[] = 'disktest';
        $subjobsSummary = $parts ? implode(' + ', $parts) : 'aucun sous-job';

        $type = (string)($row['type'] ?? 'unknown');
        $summary = match ($type) {
            'reinstall'    => 'Reinstall (' . $subjobsSummary . ')',
            'install_only' => 'Installation',
            'wipe_only'    => 'Effacement disques',
            'discovery'    => 'Discovery',
            'disktest'     => 'Tests de disques',
            default        => ucfirst($type),
        };

        return [
            'type'            => $type,
            'id'              => (int)$row['id'],
            'action_id'       => (int)$row['id'],
            'status'          => (string)($row['status'] ?? 'pending'),
            'started_at'      => $started,
            'ended_at'        => $ended,
            'created_at'      => $started,
            'duration_s'      => $duration,
            'summary'         => $summary,
            'subjobs_summary' => $subjobsSummary,
        ];
    }

    /**
     * Compte les tâches d'une machine par type (pour badge UI éventuel).
     *
     * @return array{discovery:int,install:int,wipe:int,disktest:int}
     */
    public static function countsByMachine(int $machineId): array
    {
        $pdo = Db::get();
        $count = function (string $table) use ($pdo, $machineId): int {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE machine_id = ?");
            $stmt->execute([$machineId]);
            return (int)$stmt->fetchColumn();
        };
        return [
            'discovery' => $count('discoveries'),
            'install'   => $count('installations'),
            'wipe'      => $count('wipes'),
            'disktest'  => $count('disk_tests'),
        ];
    }
}
