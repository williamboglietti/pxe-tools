<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

final class Wipe
{
    public const MODES = ['partial', 'full'];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM wipes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Latest wipe for a machine (any status). */
    public static function latestForMachine(int $machineId): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT * FROM wipes WHERE machine_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Active (pending|running) wipe job for a given MAC.
     * Utilisé par wipe.sh pour récupérer son job. Passe par
     * Machine::findByAnyMac pour gérer le cas multi-NIC : wipe.sh peut tourner
     * sur une interface ≠ MAC primaire DB.
     */
    public static function activeByMac(string $mac): ?array
    {
        $machine = Machine::findByAnyMac($mac);
        if (!$machine) return null;
        $stmt = Db::get()->prepare(
            'SELECT w.*, m.mac, m.hostname '
          . 'FROM wipes w JOIN machines m ON w.machine_id = m.id '
          . "WHERE w.machine_id = ? AND w.wipe_status IN ('pending','running') "
          . 'ORDER BY w.id DESC LIMIT 1'
        );
        $stmt->execute([(int)$machine['id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public const POST_ACTIONS = ['reboot', 'poweroff'];

    /**
     * Démarre un wipe job avec mode par disque.
     *
     * @param array<int,array{name:string,mode:string,identity?:array<string,string>}> $disksWithModes
     *        Liste d'objets {name, mode, identity?} : ex
     *        [{"name":"/dev/sda","mode":"partial","identity":{"kind":"serial","value":"…"}}]
     * @param string      $postAction              'reboot' ou 'poweroff' — action après fin du wipe
     * @param string|null $chainInstallConfigJson  Si non null, snapshot JSON {install_config, hostname, domain}
     *                                              consommé par PxeController::wipeEnd à la fin du wipe pour
     *                                              enchaîner directement vers une install (status 'configured'
     *                                              au lieu de 'wiped'). Permet la séquence wipe→install en 1
     *                                              seul reboot admin.
     *
     * Le champ legacy wipes.wipe_mode est conservé pour rétrocompat / display :
     *   - 'mixed' si au moins 2 modes différents dans la liste
     *   - sinon le mode commun (partial|full)
     * Au runtime, wipe.sh consulte le mode AU NIVEAU DU DISQUE dans disks_json,
     * pas wipe_mode global.
     */
    public static function start(int $machineId, array $disksWithModes, string $postAction = 'poweroff', ?string $chainInstallConfigJson = null, ?int $actionId = null): int
    {
        if (!in_array($postAction, self::POST_ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid post_action: $postAction");
        }
        if (empty($disksWithModes)) {
            throw new \InvalidArgumentException("disksWithModes vide");
        }
        // Validation : chaque entrée doit avoir name (string non vide) + mode (in MODES).
        // Les callers destructifs valident l'identité courante avant d'arriver ici;
        // les anciens jobs sans identité restent lisibles mais le runner les bloquera.
        $clean = [];
        $modesSet = [];
        foreach ($disksWithModes as $d) {
            $name = (string)($d['name'] ?? '');
            $mode = (string)($d['mode'] ?? '');
            if ($name === '' || !in_array($mode, self::MODES, true)) {
                throw new \InvalidArgumentException("Invalid disk entry: " . json_encode($d));
            }
            $entry = ['name' => $name, 'mode' => $mode];
            if (is_array($d['identity'] ?? null)) {
                $identity = $d['identity'];
                $serial = strtoupper(trim((string)($identity['value'] ?? '')));
                if (($identity['kind'] ?? '') === 'serial' && $serial !== '') {
                    $entry['identity'] = [
                        'kind' => 'serial', 'value' => $serial,
                        'source' => (string)($identity['source'] ?? 'unknown'),
                        'status' => (string)($identity['status'] ?? 'valid'),
                    ];
                }
            }
            $clean[] = $entry;
            $modesSet[$mode] = true;
        }
        $aggregateMode = count($modesSet) === 1 ? array_key_first($modesSet) : 'mixed';
        $disksJson = json_encode($clean, JSON_UNESCAPED_SLASHES);
        Db::get()->prepare(
            "INSERT INTO wipes (machine_id, disks_json, wipe_mode, wipe_status, post_action, chain_install_config_json, action_id, started_at) "
          . "VALUES (?, ?, ?, 'pending', ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$machineId, $disksJson, $aggregateMode, $postAction, $chainInstallConfigJson, $actionId]);
        return (int) Db::get()->lastInsertId();
    }

    public static function setStatus(int $id, string $status): void
    {
        Db::get()->prepare('UPDATE wipes SET wipe_status = ? WHERE id = ?')
                  ->execute([$status, $id]);
    }

    public static function appendLog(int $id, string $line): void
    {
        $concat = Db::concat('log');
        Db::get()->prepare(
            "UPDATE wipes SET log = $concat WHERE id = ?"
        )->execute([$line, $id]);
    }

    public static function end(int $id, string $status): void
    {
        Db::get()->prepare(
            'UPDATE wipes SET wipe_status = ?, ended_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$status, $id]);

        // Hook : recompute l'état agrégé de l'action parente si ce wipe lui est rattaché.
        // Guard null : les sous-jobs legacy (action_id IS NULL) sont silencieusement ignorés.
        $row = self::find($id);
        if ($row && !empty($row['action_id'])) {
            Action::recomputeEndState((int)$row['action_id']);
        }
    }
}
