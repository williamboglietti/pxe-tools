<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

/**
 * Journal d'événements par machine. Alimenté par :
 *  - les pings de discover.sh (phases d'inventaire, actions consommées)
 *  - les actions admin via UI (power, status manuel, install lancée…)
 *  - les changements de status (Machine::setStatus loggue automatiquement)
 *
 * Affiché dans l'onglet "Événements" de la fiche machine sous forme de terminal
 * (polling HTMX 2s pour les machines en flow live).
 */
final class MachineEvent
{
    public const LEVEL_INFO    = 'info';
    public const LEVEL_ACTION  = 'action';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    public const ALLOWED_LEVELS = [
        self::LEVEL_INFO,
        self::LEVEL_ACTION,
        self::LEVEL_WARNING,
        self::LEVEL_ERROR,
    ];

    /**
     * Insère un événement. Échec silencieux (logger ne doit jamais casser le
     * flow appelant — ex: si la table n'existe pas en pre-migration).
     *
     * $checkpoint et $actionId sont optionnels (refactor actions/checkpoints
     * 2026-05-16) : si $checkpoint est non-null, c'est un jalon structuré du
     * catalogue ActionCheckpoints, sinon c'est un log libre.
     */
    public static function log(
        int $machineId,
        string $level,
        string $message,
        ?string $checkpoint = null,
        ?int $actionId = null
    ): void {
        if ($machineId <= 0 || $message === '') return;
        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            $level = self::LEVEL_INFO;
        }
        try {
            Db::get()->prepare(
                'INSERT INTO machine_events (machine_id, level, message, checkpoint, action_id) '
              . 'VALUES (?, ?, ?, ?, ?)'
            )->execute([$machineId, $level, $message, $checkpoint, $actionId]);
        } catch (\Throwable $e) {
            error_log('MachineEvent::log failed: ' . $e->getMessage());
        }
    }

    /**
     * Variante : log via MAC (utile pour les handlers PXE qui ne connaissent
     * que la MAC, pas l'id). Lookup par MAC primaire OU MAC secondaire.
     *
     * $checkpoint + $actionId optionnels : cohérence signature avec log() depuis
     * Worker_INFRA01. Utile pour PxeController qui logge des checkpoints sans
     * connaître l'id machine direct mais la MAC (preseed/grub serve handlers).
     */
    public static function logByMac(
        string $mac,
        string $level,
        string $message,
        ?string $checkpoint = null,
        ?int $actionId = null
    ): void {
        $machine = Machine::findByAnyMac($mac);
        if ($machine) {
            self::log((int)$machine['id'], $level, $message, $checkpoint, $actionId);
        }
    }

    // Convention : les checkpoints checkpoint dans grub.php.j2 et preseed.php.j2
    // utilisent des INSERT PDO directs (pas d'appel MachineEvent::log) car ces
    // templates sont des scripts standalone sans autoloader Admin\ disponible
    // dans /srv/http. MachineEvent::insertCheckpoint() a donc été supprimé
    // (dead code — 0 call site dans Admin\) pour éviter qu'un lecteur ne cherche
    // des appelants inexistants. PxeController utilise MachineEvent::logByMac()
    // directement pour les checkpoints preseed_loaded_* qu'il émet côté PHP.

    /**
     * Récupère tous les événements liés à une action_id donnée,
     * ordre chronologique croissant (pour la checklist progressive).
     *
     * Colonnes retournées : id, level, message, checkpoint, action_id, created_at.
     * IMPORTANT : ne retourne PAS le token (n'est pas dans machine_events).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forAction(int $actionId, int $limit = 200): array
    {
        if ($actionId <= 0) return [];
        $stmt = Db::get()->prepare(
            'SELECT id, level, message, checkpoint, action_id, created_at '
          . 'FROM machine_events '
          . 'WHERE action_id = ? '
          . 'ORDER BY created_at ASC, id ASC '
          . 'LIMIT ?'
        );
        $stmt->execute([$actionId, $limit]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Récupère les N derniers événements d'une machine, ordre chronologique
     * croissant (le plus récent en bas, comme un terminal qui scroll).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $machineId, int $limit = 100): array
    {
        if ($machineId <= 0 || $limit <= 0) return [];
        $stmt = Db::get()->prepare(
            'SELECT id, level, message, checkpoint, action_id, created_at '
          . 'FROM machine_events '
          . 'WHERE machine_id = ? '
          . 'ORDER BY created_at DESC, id DESC '
          . 'LIMIT ?'
        );
        $stmt->execute([$machineId, $limit]);
        $rows = $stmt->fetchAll();
        // Inverse pour rendre dans l'ordre chronologique (ancien → récent)
        return array_reverse($rows);
    }
}
