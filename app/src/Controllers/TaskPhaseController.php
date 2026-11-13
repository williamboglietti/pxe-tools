<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Machine;
use Admin\Models\TaskPhase;
use Admin\View;

/**
 * Endpoints API pour le suivi temps réel des phases de tâches.
 *
 *   GET /api/task-phase            ← pingué par preseed (early/late_command) + worker
 *   GET /api/machines/{id}/task-progress ← fragment HTMX polling 2s
 *
 * Le ping reste public pour le client Debian Installer sans session ; le
 * fragment de progression est réservé à l'interface d'administration.
 */
final class TaskPhaseController
{
    /**
     * Ping d'une phase. Params GET :
     *   task   = install | wipe | ansible       (obligatoire)
     *   id     = task_id (installations.id ou wipes.id)
     *   phase  = clé de phase (preseed-loaded, partman-done, ...)
     *   status = pending | running | success | failed | skipped (défaut: running)
     *   message = ligne descriptive optionnelle
     *
     * Retourne toujours 204 (no content) pour minimiser le poids réseau côté
     * client preseed (wget -q). Le status code suffit pour confirmer la prise
     * en compte ; les erreurs de validation sont silencieuses (best-effort).
     */
    public function ping(array $params): void
    {
        $taskType = $_GET['task']  ?? '';
        $taskId   = (int)($_GET['id'] ?? 0);
        $mac      = $_GET['mac']   ?? '';
        $phase    = $_GET['phase'] ?? '';
        $status   = $_GET['status'] ?? 'running';
        $message  = $_GET['message'] ?? null;

        if ($taskType === '' || $phase === '') {
            http_response_code(400);
            return;
        }

        // Si task_id absent mais mac fourni, on résout : latest install/wipe
        // in_progress pour cette MAC. Permet aux pings depuis le preseed
        // (qui ne connaît que la MAC) de fonctionner sans paramètre id.
        if ($taskId <= 0 && $mac !== '') {
            $pdo = Db::get();
            if ($taskType === 'install' || $taskType === 'ansible') {
                $stmt = $pdo->prepare(
                    "SELECT i.id FROM installations i
                     JOIN machines m ON i.machine_id = m.id
                     WHERE LOWER(m.mac) = LOWER(?) AND i.install_status = 'in_progress'
                     ORDER BY i.id DESC LIMIT 1"
                );
                $stmt->execute([$mac]);
                $taskId = (int)($stmt->fetchColumn() ?: 0);
            } elseif ($taskType === 'wipe') {
                $stmt = $pdo->prepare(
                    "SELECT w.id FROM wipes w
                     JOIN machines m ON w.machine_id = m.id
                     WHERE LOWER(m.mac) = LOWER(?) AND w.wipe_status IN ('pending','running')
                     ORDER BY w.id DESC LIMIT 1"
                );
                $stmt->execute([$mac]);
                $taskId = (int)($stmt->fetchColumn() ?: 0);
            }
        }

        if ($taskId <= 0) {
            http_response_code(404);
            return;
        }

        TaskPhase::ping($taskType, $taskId, $phase, $status, $message);
        http_response_code(204);
    }

    /**
     * Fragment HTMX : timeline des phases de la tâche active sur la machine.
     * - Si aucune tâche active : répond avec HX-Redirect vers /machines/{id}
     *   pour que la page recharge et affiche le wizard.
     * - Sinon : rend le partial _task_progress.
     */
    public function progress(array $params): void
    {
        $rawId = $params['id'] ?? '';
        if ((!is_string($rawId) && !is_int($rawId)) || !ctype_digit((string)$rawId) || (int)$rawId <= 0) {
            http_response_code(404);
            return;
        }
        $machineId = (int)$rawId;
        $machine = Machine::find($machineId);
        if (!$machine) {
            http_response_code(404);
            return;
        }

        $active = TaskPhase::activeForMachine($machineId);
        if ($active === null) {
            // Plus de tâche active → la page parente doit recharger pour
            // réafficher le wizard. HX-Redirect évite le polling infini.
            header('HX-Redirect: /machines/' . $machineId);
            return;
        }

        $phases = TaskPhase::listForTask($active['type'], $active['id']);
        $progress = TaskPhase::progress($phases);

        View::partial('machines/_task_progress', [
            'machine'  => $machine,
            'task'     => $active,
            'phases'   => $phases,
            'progress' => $progress,
        ]);
    }
}
