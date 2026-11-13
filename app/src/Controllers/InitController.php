<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\InitGuard;
use Admin\View;

/**
 * Page d'init du serveur PXE : montre la progression des jobs bootstrap.
 *   - GET  /init             → page HTML
 *   - GET  /api/init-status  → fragment HTMX (poll toutes les 2s)
 *   - POST /api/init-retry   → repasse les jobs failed en pending
 */
final class InitController
{
    public function index(): void
    {
        // Spec B Lot 2 : wizard prioritaire. Si pas complété, rediriger vers
        // l'étape 1 du wizard d'init (création réseau + admin + defaults).
        if (InitGuard::wizardPending()) {
            View::redirect('/init/welcome');
            return;
        }
        $jobs = $this->jobs();
        $downloads = InitGuard::listBaseDownloads();
        View::render('init/index', [
            'jobs'       => $jobs,
            'progress'   => InitGuard::overallProgress($jobs),
            'hasFailure' => InitGuard::hasFailures($jobs),
            'downloads'  => $downloads,
            'jobIds' => $this->jobIds(),
        ], 'Activité de l’appliance');
    }

    /** Fragment HTMX : la zone de progress (poll toutes les 2s). */
    public function status(): void
    {
        $jobs = $this->jobs();
        $progress = InitGuard::overallProgress($jobs);
        $downloads = InitGuard::listBaseDownloads();
        View::partial('init/_progress', [
            'jobs'       => $jobs,
            'progress'   => $progress,
            'hasFailure' => InitGuard::hasFailures($jobs),
            'downloads'  => $downloads,
            'jobIds' => $this->jobIds(),
        ]);
    }

    private function jobIds(): array
    {
        $raw = $_GET['jobs'] ?? $_POST['jobs'] ?? '';
        if (!is_string($raw) || !preg_match('/^[0-9]+(?:,[0-9]+){0,49}$/D', $raw)) return [];
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn(int $id): bool => $id > 0)));
    }

    private function jobs(): array
    {
        $ids = $this->jobIds();
        if ($ids === []) return InitGuard::listJobs();
        $query = Db::get()->prepare('SELECT * FROM init_jobs WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $query->execute($ids);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['title'] = $row['job_type'];
            $row['sub_message'] = $row['message'];
            $stamp = $row['updated_at'] ?: $row['started_at'];
            $age = $stamp ? max(0, time() - (int)strtotime($stamp . ' UTC')) : 0;
            $row['stale_age_s'] = $age;
            $row['stale'] = $row['status'] === 'running' && $age > InitGuard::STALE_THRESHOLD_S;
        }
        return $rows;
    }

    /** Retry : remet les jobs failed en pending → worker les repickup. */
    public function retry(): void
    {
        try {
            $pdo = Db::get();
            $ids = array_column($this->jobs(), 'id');
            if ($ids !== []) {
                $query = $pdo->prepare("UPDATE init_jobs SET status = 'pending', started_at = NULL, ended_at = NULL, updated_at = NULL WHERE status = 'failed' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
                $query->execute($ids);
            }
        } catch (\Throwable $e) {
            View::json(['error' => $e->getMessage()], 500);
            return;
        }
        InitGuard::resetCache();
        View::redirect('/init' . ($this->jobIds() !== [] ? '?jobs=' . implode(',', $this->jobIds()) : ''));
    }

    /** Retry ciblé d'un job (utilisé pour les jobs stale = running silencieux).
     * Le log des suppressions d'image porte les métadonnées nécessaires à la
     * reprise ; les journaux des autres jobs sont remis à zéro. */
    public function retryJob(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            View::json(['error' => 'id invalide'], 400);
            return;
        }
        try {
            $pdo = Db::get();
            $stmt = $pdo->prepare(
                "UPDATE init_jobs SET status='pending', started_at=NULL, ended_at=NULL, "
                . "updated_at=NULL, progress_percent=0, "
                . "log=CASE WHEN job_type LIKE 'image:delete:%' THEN log ELSE '' END, message=NULL "
                . "WHERE id = ? AND (status = 'failed' OR (status = 'running' AND "
                . Db::nowEpoch() . ' - ' . Db::unixEpoch('COALESCE(updated_at, started_at)') . ' > ?))'
            );
            // PDO execute(array) binds values as strings by default. Bind the
            // threshold as an integer so SQLite compares elapsed seconds
            // numerically when retrying a stale running job.
            $stmt->bindValue(1, $id, \PDO::PARAM_INT);
            $stmt->bindValue(2, InitGuard::STALE_THRESHOLD_S, \PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                View::flash('warning', 'Ce traitement ne peut pas être relancé : il est terminé ou son activité est encore récente.', '/jobs/' . $id, 'Consulter le traitement');
                View::redirect('/jobs/' . $id);
                return;
            }
        } catch (\Throwable $e) {
            View::json(['error' => $e->getMessage()], 500);
            return;
        }
        InitGuard::resetCache();
        // HTMX-aware : si POST AJAX, redemander juste le fragment progress
        if (isset($_SERVER['HTTP_HX_REQUEST'])) {
            $this->status();
            return;
        }
        View::redirect('/init' . ($this->jobIds() !== [] ? '?jobs=' . implode(',', $this->jobIds()) : ''));
    }
}
