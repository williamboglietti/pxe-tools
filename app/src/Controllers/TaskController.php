<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Action;
use Admin\Models\ActionCheckpoints;
use Admin\Models\Discovery;
use Admin\Models\DiskTest;
use Admin\Models\Installation;
use Admin\Models\Machine;
use Admin\Models\MachineEvent;
use Admin\Models\MachineTask;
use Admin\Models\Wipe;
use Admin\View;

/**
 * Spec C — Endpoints unifiés des tâches d'une machine.
 *
 * Routes (nouvelles) :
 *   GET /tasks/{id}              → drill-down action parente (id numérique pur)
 *   GET /tasks/wipe-{id}         → 301 vers /tasks/{action_id} (legacy compat)
 *   GET /tasks/install-{id}      → 301 vers /tasks/{action_id} (legacy compat)
 *   GET /tasks/disktest-{id}     → 301 vers /tasks/{action_id} (legacy compat)
 *   GET /tasks/discovery-{id}    → 301 vers /tasks/{action_id} (legacy compat)
 *
 * Routes conservées :
 *   GET /machines/{id}/tasks/{type}-{taskId}  → drill-down générique machine-scoped
 *   GET /machines/{id}/tasks                  → fragment HTMX (timeline list)
 */
final class TaskController
{
    private const VALID_TYPES = ['discovery', 'install', 'wipe', 'disktest'];

    /**
     * Route canonique : GET /tasks/{id}.
     * - Si {id} est numérique pur → action parente (showAction réel).
     * - Sinon → legacy redirect 301 (délègue à showLegacy).
     *
     * POURQUOI discrimination dans le handler et non dans deux routes :
     * Router::compile transforme {id} en [^/]+, les deux patterns
     * /tasks/{id} et /tasks/{task} seraient identiques. Premier match
     * gagnerait toujours → délégation interne pour lever l'ambiguïté.
     */
    public function showAction(array $params): void
    {
        $raw = (string)($params['id'] ?? '');

        // Si ce n'est pas numérique pur, traiter comme une route legacy
        if (!ctype_digit($raw)) {
            $params['task'] = $raw;
            $this->showLegacy($params);
            return;
        }

        $actionId = (int)$raw;
        $action = Action::findById($actionId);
        if (!$action) {
            http_response_code(404);
            echo "Action introuvable\n";
            return;
        }

        $machine = Machine::find((int)$action['machine_id']);
        if (!$machine) {
            http_response_code(404);
            echo "Machine introuvable\n";
            return;
        }

        // SEC : token = secret auth machine, ne doit jamais atteindre le rendu HTML
        unset($action['token']);

        // Chargement des sous-jobs liés à cette action
        $subjobs = Action::findSubjobsByActionId($actionId);

        View::render('tasks/show', [
            'action'  => $action,
            'machine' => $machine,
            'subjobs' => $subjobs,
            // Compat legacy : type + task pour les partials existants si un seul sous-job
            'type'    => $action['type'],
            'task'    => null,
            'events'  => [],
        ], 'Action #' . $actionId . ' — ' . $action['type']);
    }

    /**
     * Routes legacy → 301 redirect vers /tasks/{action_id}.
     * Format : GET /tasks/{prefix}-{id} où prefix ∈ {wipe, install, disktest, discovery}.
     *
     * Si la row est absente OU action_id IS NULL (job orphelin legacy) → 404.
     */
    public function showLegacy(array $params): void
    {
        $combo = (string)($params['task'] ?? '');
        if (!preg_match('/^(wipe|install|disktest|discovery)-(\d+)$/', $combo, $m)) {
            http_response_code(404);
            echo "Identifiant de tâche invalide\n";
            return;
        }

        $prefix = $m[1];
        $subjobId = (int)$m[2];
        $actionId = self::lookupActionId($prefix, $subjobId);

        if ($actionId === null) {
            http_response_code(404);
            echo "Tâche introuvable ou orpheline (non rattachée à une action)\n";
            return;
        }

        header('Location: /tasks/' . $actionId, true, 301);
    }

    /**
     * Route machine-scoped conservée : GET /machines/{id}/tasks/{task}.
     * Toujours fonctionnelle pour le drill-down depuis la fiche machine.
     */
    public function show(array $params): void
    {
        $machineId = (int)$params['id'];
        $machine = Machine::find($machineId);
        if (!$machine) {
            http_response_code(404);
            echo "Machine introuvable\n";
            return;
        }

        $combo = (string)($params['task'] ?? '');
        if (!preg_match('/^(discovery|install|wipe|disktest)-(\d+)$/', $combo, $m)) {
            http_response_code(404);
            echo "Identifiant de tâche invalide (attendu: type-id)\n";
            return;
        }
        $type = $m[1];
        $taskId = (int)$m[2];

        $task = self::loadTask($type, $taskId);
        if ($task === null) {
            http_response_code(404);
            echo "Tâche introuvable\n";
            return;
        }
        // Sécurité : la tâche doit appartenir à la machine de l'URL
        if ((int)($task['machine_id'] ?? 0) !== $machineId) {
            http_response_code(404);
            echo "Tâche non trouvée pour cette machine\n";
            return;
        }

        // Pour le drill-down discovery, on charge les machine_events compris
        // entre started_at et ended_at (= les phases reçues via discoveryAction).
        $events = [];
        if ($type === 'discovery' && !empty($task['started_at'])) {
            $events = MachineEvent::recent($machineId, 100);
            $endTs = $task['ended_at'] ? strtotime($task['ended_at']) : time();
            $startTs = strtotime($task['started_at']);
            $events = array_filter($events, function ($e) use ($startTs, $endTs) {
                $ts = strtotime($e['created_at'] ?? '');
                return $ts >= $startTs && $ts <= $endTs;
            });
        }

        View::render('tasks/show', [
            'machine' => $machine,
            'type'    => $type,
            'task'    => $task,
            'action'  => null,
            'subjobs' => [],
            'events'  => array_values($events),
        ], 'Tâche · ' . ucfirst($type));
    }

    /**
     * Partial HTMX : GET /tasks/{action_id}/checklist
     * Sert uniquement le partial _checklist.php (hx-swap="outerHTML" sur #checklist).
     * Polling adaptatif : 2s running / 10s idle / null terminal.
     */
    public function checklist(array $params): void
    {
        $raw = (string)($params['action_id'] ?? '');
        if (!ctype_digit($raw)) {
            http_response_code(400);
            echo "Identifiant action invalide\n";
            return;
        }

        $actionId = (int)$raw;
        $action   = Action::findById($actionId);
        if (!$action) {
            http_response_code(404);
            echo "Action introuvable\n";
            return;
        }

        // SEC : token = secret auth machine, ne doit jamais atteindre le rendu HTML
        unset($action['token']);

        $actionStatus = (string)($action['status'] ?? 'pending');

        // Calcul poll_interval côté PHP (spec §Polling adaptatif)
        $events = MachineEvent::forAction($actionId);
        if (in_array($actionStatus, Action::STATUSES_TERMINAL, true)) {
            $pollInterval = null;
        } elseif ($actionStatus === 'running') {
            $latestEventTs = null;
            foreach ($events as $ev) {
                $ts = strtotime($ev['created_at'] ?? '');
                if ($ts && ($latestEventTs === null || $ts > $latestEventTs)) {
                    $latestEventTs = $ts;
                }
            }
            $idleSince    = $latestEventTs ? (time() - $latestEventTs) : PHP_INT_MAX;
            $pollInterval = $idleSince > 30 ? 10 : 2;
        } else {
            $pollInterval = 10;
        }

        $machine = Machine::find((int)($action['machine_id'] ?? 0));
        if (!$machine) {
            http_response_code(404);
            echo "Machine introuvable\n";
            return;
        }

        $catalog = ActionCheckpoints::forType((string)($action['type'] ?? ''));
        $subjobs = Action::findSubjobsByActionId($actionId);

        View::partial('tasks/_checklist', [
            'action'       => $action,
            'machine'      => $machine,
            'catalog'      => $catalog,
            'events'       => $events,
            'subjobs'      => $subjobs,
            'pollInterval' => $pollInterval,
        ]);
    }

    /**
     * POST /actions/{id}/force-complete — clôt manuellement une action stuck.
     *
     * Cas d'usage : install/wipe physiquement terminé(e) mais l'action reste
     * 'running' à l'écran (checkpoint manquant, worker zombie). L'admin force
     * la clôture pour relancer une opération sur la machine.
     *
     * Garde-fou : refuse si l'action est déjà terminale (success/failed/
     * cancelled) — la requête est alors no-op silencieuse côté UI.
     *
     * À utiliser uniquement quand l'admin a confirmé que l'opération est
     * physiquement terminée (sinon, un worker peut continuer en background et
     * écrire des données dans un état réputé clôturé).
     */
    public function forceComplete(array $params): void
    {
        $raw = (string)($params['id'] ?? '');
        if (!ctype_digit($raw)) {
            http_response_code(400);
            echo "Identifiant action invalide\n";
            return;
        }
        $actionId = (int)$raw;
        $action = Action::findById($actionId);
        if (!$action) {
            http_response_code(404);
            echo "Action introuvable\n";
            return;
        }

        $machineId = (int)($action['machine_id'] ?? 0);
        $currentStatus = (string)($action['status'] ?? '');

        if (in_array($currentStatus, Action::STATUSES_TERMINAL, true)) {
            // Déjà terminale → on évite de logger un event parasite. Retour
            // direct vers la fiche machine (idempotence côté admin qui
            // double-clique).
            View::flash('info', "L'action #$actionId est déjà terminée (statut : $currentStatus).");
            View::redirect('/machines/' . $machineId);
            return;
        }

        \Admin\Util\MachineOperation::run($machineId, null, static function () use ($actionId, $machineId): void {
        Action::forceComplete($actionId);
        MachineEvent::log(
            $machineId,
            MachineEvent::LEVEL_ACTION,
            "Admin a clôturé le suivi de l’action #$actionId. Aucun arrêt distant garanti.",
            null,
            $actionId
        );
        });
        View::flash('success', "Suivi de l’action #$actionId clôturé. Aucun arrêt distant garanti ; si nécessaire, corrigez le statut machine dans Paramètres → Expert.");
        View::redirect('/machines/' . $machineId);
    }

    /**
     * GET /actions/{id}/log/{kind} — télécharge le journal d'installation (install) ou de
     * post-installation (ansible) d'une action, tel qu'affiché dans l'activité.
     */
    public function downloadLog(array $params): void
    {
        $raw  = (string)($params['id'] ?? '');
        $kind = (string)($params['kind'] ?? '');
        if (!ctype_digit($raw) || !in_array($kind, ['install', 'ansible'], true)) {
            http_response_code(400);
            echo "Requête invalide\n";
            return;
        }
        $actionId = (int)$raw;
        $action = Action::findById($actionId);
        if (!$action) {
            http_response_code(404);
            echo "Action introuvable\n";
            return;
        }
        $log = Action::logFromSubjobs(Action::findSubjobsByActionId($actionId), $kind);
        if ($log === '') {
            http_response_code(404);
            echo "Aucun journal reçu pour cette action\n";
            return;
        }
        $machine = Machine::find((int)($action['machine_id'] ?? 0));
        $host = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($machine['hostname'] ?? '')) ?: 'machine';
        $label = $kind === 'install' ? 'installation' : 'post-installation';

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $host . '-action' . $actionId . '-' . $label . '.log"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($log));
        echo $log;
    }

    public function listFragment(array $params): void
    {
        $machineId = (int)$params['id'];
        $machine = Machine::find($machineId);
        if (!$machine) {
            http_response_code(404);
            echo "Machine introuvable\n";
            return;
        }

        $tasks = MachineTask::recentByMachine($machineId, 30);

        // SEC : token = secret auth machine, ne doit jamais atteindre le rendu HTML
        foreach ($tasks as &$row) {
            unset($row['token']);
        }
        unset($row);

        View::partial('machines/_tasks_list', [
            'machine' => $machine,
            'tasks'   => $tasks,
        ]);
    }

    /**
     * Résout un sous-job legacy vers l'action_id correspondant.
     * Retourne null si la row est absente ou orpheline (action_id IS NULL).
     */
    private static function lookupActionId(string $prefix, int $subjobId): ?int
    {
        [$table, $col] = match ($prefix) {
            'wipe'      => ['wipes', 'id'],
            'install'   => ['installations', 'id'],
            'disktest'  => ['disk_tests', 'id'],
            'discovery' => ['discoveries', 'id'],
            default     => [null, null],
        };
        if ($table === null) return null;

        $stmt = Db::get()->prepare("SELECT action_id FROM $table WHERE id = ?");
        $stmt->execute([$subjobId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['action_id'])) return null;
        return (int)$row['action_id'];
    }

    /**
     * Charge une row depuis la table source selon le type de tâche.
     *
     * @return array<string,mixed>|null
     */
    private static function loadTask(string $type, int $id): ?array
    {
        return match ($type) {
            'discovery' => Discovery::findById($id),
            'install'   => Installation::find($id),
            'wipe'      => Wipe::find($id),
            'disktest'  => DiskTest::find($id),
            default     => null,
        };
    }
}
