<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Machine;
use Admin\View;

/**
 * Affichage du log live d'un init_job (ansible pull, redéploiement, etc.).
 * Polling adaptatif HTMX pour suivre l'avancement en temps réel.
 */
final class JobController
{
    private const STATUSES_TERMINAL = ['success', 'failed', 'skipped', 'cancelled', 'aborted'];

    /**
     * GET /jobs/{id} — layout principal de la page de suivi d'un job.
     */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $job = self::findJob($id);
        if (!$job) { http_response_code(404); echo 'Job introuvable'; return; }

        $machine = self::resolveMachine($job);
        $title = self::title($job, $machine);

        View::render('jobs/show', [
            'job'     => $job,
            'machine' => $machine,
            'title'   => $title,
        ], $title);
    }

    /**
     * GET /jobs/{id}/poll — partial HTMX (status + log + polling trigger).
     * Le partial décide lui-même de stopper le polling quand le job est terminal.
     */
    public function poll(array $params): void
    {
        $id = (int) $params['id'];
        $job = self::findJob($id);
        if (!$job) { http_response_code(404); echo 'Job introuvable'; return; }

        $machine = self::resolveMachine($job);
        View::partial('jobs/_log', [
            'job'     => $job,
            'machine' => $machine,
        ]);
    }

    /**
     * GET /api/init-job-status?job_type=... — retourne le statut JSON du job
     * le plus récent matching ce job_type. Utilisé par toast.js (mode "live")
     * pour suivre un push GitHub / pull / etc. directement dans le toast,
     * sans rediriger vers /init.
     *
     * Réponse 200 même si non trouvé (le poll peut survenir avant l'insert) :
     *   {found: false}
     *   {found: true, id, status, progress_percent, message, started_at, ended_at, updated_at}
     */
    public function statusByJobType(array $params): void
    {
        $jobType = trim((string)($_GET['job_type'] ?? ''));
        if ($jobType === '' || strlen($jobType) > 128) {
            View::json(['error' => 'job_type requis (1-128 chars)'], 400);
            return;
        }
        $stmt = Db::get()->prepare(
            'SELECT id, job_type, status, progress_percent, message, started_at, ended_at, updated_at '
            . 'FROM init_jobs WHERE job_type = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$jobType]);
        $row = $stmt->fetch();
        if (!$row) {
            View::json(['found' => false]);
            return;
        }
        View::json([
            'found'            => true,
            'id'               => (int)$row['id'],
            'job_type'         => (string)$row['job_type'],
            'status'           => (string)$row['status'],
            'progress_percent' => (int)($row['progress_percent'] ?? 0),
            'message'          => $row['message'] !== null ? (string)$row['message'] : null,
            'started_at'       => $row['started_at'],
            'ended_at'         => $row['ended_at'],
            'updated_at'       => $row['updated_at'],
        ]);
    }

    /** Exact-ID endpoint protected by the normal UI authentication middleware. */
    public function status(array $params): void
    {
        $job = \Admin\Models\ActivityQuery::jobStatus((int)$params['id']);
        View::json($job ? ['found' => true] + $job : ['found' => false], $job ? 200 : 404);
    }

    /** @return array<string,mixed>|null */
    private static function findJob(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM init_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Si le job_type matche `machine:ansible_redeploy:N`, retourne la ligne machine.
     * Sinon (ex: ansible_repo:pull), null.
     *
     * @return array<string,mixed>|null
     */
    private static function resolveMachine(array $job): ?array
    {
        $jobType = (string)($job['job_type'] ?? '');
        if (!str_starts_with($jobType, 'machine:ansible_redeploy:')) {
            return null;
        }
        $parts = explode(':', $jobType);
        $machineId = isset($parts[2]) ? (int)$parts[2] : 0;
        if ($machineId <= 0) return null;
        return Machine::find($machineId);
    }

    private static function title(array $job, ?array $machine): string
    {
        $jobType = (string)($job['job_type'] ?? '');
        if ($jobType === 'ansible_repo:pull') {
            return 'Mise à jour dépôt Ansible';
        }
        if (str_starts_with($jobType, 'machine:ansible_redeploy:') && $machine) {
            $label = Machine::displayLabel($machine);
            return "Redéploiement Ansible · $label";
        }
        return "Job #" . (int)$job['id'];
    }

    /**
     * Calcule le polling interval en secondes selon le status.
     * Aligné sur TaskController::checklist : 2s actif / 5s pending / arrêt si terminal.
     */
    public static function pollIntervalForStatus(string $status): ?int
    {
        if (in_array($status, self::STATUSES_TERMINAL, true)) {
            return null;
        }
        if ($status === 'running') {
            return 2;
        }
        return 5; // pending
    }
}
