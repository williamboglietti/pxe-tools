<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

/**
 * Spec C — Une row = un cycle de discovery (machine PXE boot → POST inventaire
 * → poll d'action → poweroff/reboot). Sert à exposer une timeline par-tâche
 * dans l'UI (au lieu de la rivière machine_events).
 *
 * Cycle :
 *   1. Discovery::startForMachine($machineId, $ip) au POST /api/discovery
 *      → INSERT row (status=running, started_at=now)
 *   2. Discovery::recordPhase($id, $phase) à chaque /api/discovery-action
 *      → UPDATE last_phase (optionnel — le phase tracking est best-effort)
 *   3. Discovery::complete($id, $status, $inventory) à la fin
 *      → UPDATE ended_at, discovery_status, snapshot
 */
final class Discovery
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    public static function startForMachine(int $machineId, ?string $ipAddr = null): int
    {
        $pdo = Db::get();
        $pdo->prepare(
            "INSERT INTO discoveries (machine_id, started_at, ip_addr, discovery_status, last_phase)
             VALUES (?, CURRENT_TIMESTAMP, ?, 'running', 'started')"
        )->execute([$machineId, $ipAddr]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Trouve le cycle running courant pour une machine (ou null si aucun).
     * Évite de créer un doublon si le POST /api/discovery est rejoué sur
     * un cycle déjà en cours.
     */
    public static function currentForMachine(int $machineId): ?array
    {
        $stmt = Db::get()->prepare(
            "SELECT * FROM discoveries
             WHERE machine_id = ? AND discovery_status = 'running'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function recordPhase(int $discoveryId, string $phase): void
    {
        Db::get()
            ->prepare("UPDATE discoveries SET last_phase = ? WHERE id = ?")
            ->execute([substr($phase, 0, 64), $discoveryId]);
    }

    public static function complete(int $discoveryId, string $status, ?string $inventoryJson = null): void
    {
        if (!in_array($status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true)) {
            $status = self::STATUS_SUCCESS;
        }
        Db::get()
            ->prepare(
                "UPDATE discoveries
                 SET ended_at = CURRENT_TIMESTAMP, discovery_status = ?, inventory_snapshot_json = ?
                 WHERE id = ?"
            )
            ->execute([$status, $inventoryJson, $discoveryId]);

        // Hook : recompute l'état agrégé de l'action parente si cette discovery lui est rattachée.
        // Guard null : les sous-jobs legacy (action_id IS NULL) sont silencieusement ignorés.
        $row = self::findById($discoveryId);
        if ($row && !empty($row['action_id'])) {
            Action::recomputeEndState((int)$row['action_id']);
        }
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM discoveries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Retourne les N derniers cycles d'une machine (pour drill-down si besoin).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentByMachine(int $machineId, int $limit = 10): array
    {
        $stmt = Db::get()->prepare(
            "SELECT * FROM discoveries WHERE machine_id = ? ORDER BY id DESC LIMIT ?"
        );
        $stmt->execute([$machineId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
