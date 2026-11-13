<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use Admin\Models\Action;

/**
 * Phases fines des tâches longues (install Debian, wipe, ansible playbook).
 *
 * Le couple (task_type, task_id) référence logiquement :
 *   - installations.id      pour task_type='install'
 *   - installations.id      pour task_type='ansible' (rattaché à la même install)
 *   - wipes.id              pour task_type='wipe'
 *
 * Les phases sont déclarées en constante PHP (cf. PHASES) pour avoir un ordre
 * stable et des labels FR humains côté UI, même si la row DB n'existe pas
 * encore (status='pending' implicite).
 *
 * Cycle de vie typique :
 *   1. start($task, $id) → crée toutes les phases en 'pending'
 *   2. Worker / preseed ping ping($task, $id, $key, $status, $message)
 *      → upsert : 'pending'→'running' (set started_at), 'running'→'success'|'failed' (set ended_at)
 *   3. listForTask($task, $id) → retourne les phases enrichies (status réel
 *      ou 'pending' pour celles jamais pingées) dans l'ordre déclaré
 */
final class TaskPhase
{
    /** Phases déclarées par type de tâche, dans l'ordre d'exécution attendu. */
    public const PHASES = [
        'wipe' => [
            ['key' => 'preseed-loaded', 'label' => 'Preseed de wipe chargé'],
            ['key' => 'disks-detected', 'label' => 'Disques détectés'],
            ['key' => 'wiping',         'label' => 'Effacement en cours'],
            ['key' => 'post-action',    'label' => 'Action post-wipe (poweroff/reboot)'],
        ],
        'install' => [
            ['key' => 'preseed-loaded',     'label' => 'Preseed Debian chargé'],
            ['key' => 'network-configured', 'label' => 'Configuration réseau'],
            ['key' => 'disk-guard',         'label' => 'Vérification des numéros de série disque'],
            ['key' => 'mirror-fetched',     'label' => 'Miroir Debian contacté'],
            ['key' => 'partman-start',      'label' => 'Partitionnement (partman)'],
            ['key' => 'partman-done',       'label' => 'Disques partitionnés'],
            ['key' => 'base-install-start', 'label' => 'Installation du système de base'],
            ['key' => 'base-install-done',  'label' => 'Système de base installé'],
            ['key' => 'apt-setup',          'label' => 'Configuration APT'],
            ['key' => 'tasksel',            'label' => 'Sélection des paquets (tasksel)'],
            ['key' => 'bootloader',         'label' => 'Installation du bootloader (GRUB)'],
            ['key' => 'late-command',       'label' => 'Late command (post-install)'],
            ['key' => 'finished',           'label' => 'Installation terminée'],
        ],
        'ansible' => [
            ['key' => 'playbook-start', 'label' => 'Démarrage du playbook Ansible'],
            ['key' => 'playbook-done',  'label' => 'Playbook terminé'],
        ],
    ];

    public const VALID_TYPES    = ['install', 'wipe', 'ansible'];
    public const VALID_STATUSES = ['pending', 'running', 'success', 'failed', 'skipped'];

    /**
     * Initialise les phases d'une tâche en DB (status='pending' pour toutes).
     * Idempotent : INSERT OR IGNORE — les rows déjà créées ne sont pas écrasées.
     */
    public static function start(string $taskType, int $taskId): void
    {
        if (!in_array($taskType, self::VALID_TYPES, true)) return;
        $phases = self::PHASES[$taskType] ?? [];
        if (empty($phases)) return;

        $stmt = Db::get()->prepare(
            'INSERT OR IGNORE INTO task_phases (task_type, task_id, phase_key, status) VALUES (?, ?, ?, ?)'
        );
        foreach ($phases as $p) {
            $stmt->execute([$taskType, $taskId, $p['key'], 'pending']);
        }
    }

    /**
     * Upsert d'une phase. Set started_at sur transition vers 'running',
     * ended_at sur transition vers 'success'|'failed'|'skipped'.
     *
     * @param string|null $status si null, on ne touche pas au status (juste update message)
     */
    public static function ping(string $taskType, int $taskId, string $phaseKey, ?string $status = null, ?string $message = null): void
    {
        if (!in_array($taskType, self::VALID_TYPES, true)) return;
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) return;

        $pdo = Db::get();
        // Existe déjà ?
        $stmt = $pdo->prepare('SELECT id, status, started_at, ended_at FROM task_phases WHERE task_type=? AND task_id=? AND phase_key=?');
        $stmt->execute([$taskType, $taskId, $phaseKey]);
        $row = $stmt->fetch();

        $now = date('Y-m-d H:i:s');

        if (!$row) {
            // Création initiale via ping (cas où start() n'a pas pré-créé la phase).
            $startedAt = ($status === 'running' || $status === 'success' || $status === 'failed') ? $now : null;
            $endedAt   = ($status === 'success' || $status === 'failed' || $status === 'skipped') ? $now : null;
            $pdo->prepare(
                'INSERT INTO task_phases (task_type, task_id, phase_key, status, started_at, ended_at, message) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $taskType, $taskId, $phaseKey, $status ?? 'running', $startedAt, $endedAt, $message,
            ]);
            return;
        }

        // Update : préserve started_at/ended_at déjà set
        $newStartedAt = $row['started_at'] ?: (($status === 'running' || $status === 'success' || $status === 'failed') ? $now : null);
        $newEndedAt   = $row['ended_at'] ?: (($status === 'success' || $status === 'failed' || $status === 'skipped') ? $now : null);
        $newStatus    = $status ?? $row['status'];
        $newMessage   = $message ?? null;

        // Si message null mais status update, on garde le message existant via COALESCE
        if ($newMessage === null) {
            $pdo->prepare(
                'UPDATE task_phases SET status=?, started_at=?, ended_at=? WHERE id=?'
            )->execute([$newStatus, $newStartedAt, $newEndedAt, $row['id']]);
        } else {
            $pdo->prepare(
                'UPDATE task_phases SET status=?, started_at=?, ended_at=?, message=? WHERE id=?'
            )->execute([$newStatus, $newStartedAt, $newEndedAt, $newMessage, $row['id']]);
        }
    }

    /**
     * Liste les phases d'une tâche dans l'ordre PHASES, enrichies des données DB.
     * Les phases non encore créées en DB sont retournées en 'pending'.
     *
     * @return array<int,array{key:string,label:string,status:string,started_at:?string,ended_at:?string,message:?string}>
     */
    public static function listForTask(string $taskType, int $taskId): array
    {
        $declared = self::PHASES[$taskType] ?? [];
        if (empty($declared)) return [];

        $stmt = Db::get()->prepare('SELECT phase_key, status, started_at, ended_at, message FROM task_phases WHERE task_type=? AND task_id=?');
        $stmt->execute([$taskType, $taskId]);
        $rows = $stmt->fetchAll();
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['phase_key']] = $r;
        }

        $out = [];
        foreach ($declared as $p) {
            $key = $p['key'];
            $r = $byKey[$key] ?? null;
            $out[] = [
                'key'        => $key,
                'label'      => $p['label'],
                'status'     => $r['status'] ?? 'pending',
                'started_at' => $r['started_at'] ?? null,
                'ended_at'   => $r['ended_at'] ?? null,
                'message'    => $r['message'] ?? null,
            ];
        }
        return $out;
    }

    /** Compte les phases par status pour calculer un pourcentage de progression. */
    public static function progress(array $phases): array
    {
        $total = count($phases);
        if ($total === 0) return ['done' => 0, 'total' => 0, 'percent' => 0, 'has_failure' => false, 'is_running' => false];

        $done = 0;
        $hasFailure = false;
        $isRunning = false;
        foreach ($phases as $p) {
            $s = $p['status'];
            if ($s === 'success' || $s === 'skipped') $done++;
            if ($s === 'failed') $hasFailure = true;
            if ($s === 'running') $isRunning = true;
        }
        return [
            'done'        => $done,
            'total'       => $total,
            'percent'     => (int)floor(($done / $total) * 100),
            'has_failure' => $hasFailure,
            'is_running'  => $isRunning,
        ];
    }

    /**
     * Détecte la tâche active pour une machine donnée.
     * Renvoie ['type' => 'install'|'wipe', 'id' => int, ...] ou null.
     *
     * REINST03-LOGIC : exclut les sous-jobs dont l'action parente est déjà
     * terminale (success/failed/cancelled). Sans ce filtre, une wipe ou une
     * installation orpheline restait listée comme active alors que l'action
     * parente était clôturée → spinners ⟳ fantômes côté UI.
     *
     * Le JOIN est LEFT JOIN + IS NULL (ou action terminal) plutôt qu'INNER JOIN
     * pour ne pas exclure les sous-jobs sans action_id (legacy orphelins).
     */
    public static function activeForMachine(int $machineId): ?array
    {
        $pdo = Db::get();

        // Statuts terminaux sourcés depuis Action::STATUSES_TERMINAL.
        $terminal = Action::STATUSES_TERMINAL;
        $placeholders = implode(',', array_fill(0, count($terminal), '?'));

        // Wipe en cours dont l'action parente n'est PAS terminale
        $stmt = $pdo->prepare(
            "SELECT w.id, w.action_id, w.started_at
             FROM wipes w
             LEFT JOIN actions a ON a.id = w.action_id
             WHERE w.machine_id = ?
               AND w.wipe_status IN ('pending','running')
               AND (w.action_id IS NULL OR a.status NOT IN ($placeholders))
             ORDER BY w.id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$machineId], $terminal));
        $wipe = $stmt->fetch();

        // Installation en cours dont l'action parente n'est PAS terminale
        $stmt = $pdo->prepare(
            "SELECT i.id, i.action_id, i.started_at
             FROM installations i
             LEFT JOIN actions a ON a.id = i.action_id
             WHERE i.machine_id = ?
               AND i.install_status = 'in_progress'
               AND (i.action_id IS NULL OR a.status NOT IN ($placeholders))
             ORDER BY i.id DESC LIMIT 1"
        );
        $stmt->execute(array_merge([$machineId], $terminal));
        $install = $stmt->fetch();

        // Priorité : wipe avant install (si on a un wipe en cours, l'install n'est pas démarrée)
        if ($wipe) {
            return ['type' => 'wipe', 'id' => (int)$wipe['id'], 'action_id' => $wipe['action_id'] ? (int)$wipe['action_id'] : null, 'started_at' => $wipe['started_at']];
        }
        if ($install) {
            return ['type' => 'install', 'id' => (int)$install['id'], 'action_id' => $install['action_id'] ? (int)$install['action_id'] : null, 'started_at' => $install['started_at']];
        }
        return null;
    }
}
