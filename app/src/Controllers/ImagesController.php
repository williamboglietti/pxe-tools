<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\OsImage;
use Admin\Models\Setting;
use Admin\View;
use PDO;

/**
 * Gestion CRUD du catalogue d'images OS + live progress.
 *
 * Routes :
 *   GET    /settings/images                → page complète (cards groupées par version)
 *   GET    /api/images-status              → fragment HTMX (poll 2s, live progress)
 *   POST   /settings/images/refresh        → enqueue init_job bootstrap:refresh_catalog
 *   POST   /settings/images/{id}/toggle    → flip enabled (active → set pending si pas downloaded)
 *   POST   /settings/images/{id}/download  → force re-télécharge (status=pending) + active
 *   DELETE /settings/images/{id}           → DB row + rm fichiers locaux
 *
 * Le worker (run_image_download) poll directement os_images WHERE enabled=1
 * AND status IN ('pending','failed'). Pas d'init_job intermédiaire.
 */
final class ImagesController
{
    /** Job type pour le refresh catalogue (v2 multi-distros). */
    private const REFRESH_JOB_TYPE = 'bootstrap:refresh_catalog_v2';
    private const AUTO_REFRESH_INTERVALS = [24, 168, 720];

    public function index(array $params): void
    {
        View::render('settings/images/index', [
            'images' => OsImage::allFlat(),
            'catalog_jobs' => \Admin\Util\ApplianceJobs::tracked('catalog_job_ids'),
            'refresh_running' => self::refreshRunning(),
            'auto_refresh' => self::autoRefreshSettings(),
        ], 'Images OS');
    }

    /** @return array{enabled:bool,interval_hours:int,last_at:int,next_at:int} */
    public static function autoRefreshSettings(): array
    {
        $interval = (int)Setting::get('catalog_auto_refresh_interval_hours', '168');
        if (!in_array($interval, self::AUTO_REFRESH_INTERVALS, true)) {
            $interval = 168;
        }

        $lastAt = max(0, (int)Setting::get('catalog_auto_refresh_last_at', '0'));
        return [
            'enabled' => Setting::get('catalog_auto_refresh_enabled', 'off') === 'on',
            'interval_hours' => $interval,
            'last_at' => $lastAt,
            'next_at' => $lastAt > 0 ? $lastAt + ($interval * 3600) : 0,
        ];
    }

    /** Fragment HTMX retourné toutes les 2s : la table flat avec live progress. */
    public function statusFragment(array $params): void
    {
        View::partial('settings/images/_list', [
            'images' => OsImage::allFlat(),
            'refresh_running' => self::refreshRunning(),
        ]);
    }

    /**
     * GET /settings/images/add — picker du catalogue. Affiche les onglets distros
     * avec tableaux par version majeure + checkboxes par arch.
     */
    public function add(array $params): void
    {
        View::render('settings/images/add', [
            'distros' => \Admin\Catalog\DistroCatalog::all(),
            'grouped' => \Admin\Models\CatalogEntry::groupedForPicker(),
            'counts'  => \Admin\Models\CatalogEntry::countsByDistro(),
        ], 'Ajouter une image');
    }

    /**
     * POST /settings/images/add — enqueue N téléchargements depuis le picker.
     *
     * Body : rows[] = "distro:version_full:arch" (1+ tuples).
     * Pour chaque tuple : lookup `catalog_entries`, INSERT/UPSERT `os_images`
     * avec status='pending'. Le worker poll et déclenche le DL.
     */
    public function addSubmit(array $params): void
    {
        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            View::flash('error', 'Aucune sélection.');
            View::redirect('/settings/images/add');
            return;
        }

        $pdo = Db::get();

        // Pré-check existence pour un message précis (en file vs déjà présent) ;
        // la mutation elle-même est déléguée à OsImage::ensureForDistroVersion()
        // (même logique SELECT-puis-INSERT, partagée avec le wizard d'onboarding
        // et PresetController/MachineController — pas d'ON CONFLICT possible, le
        // UNIQUE INDEX sur version_full est partial).
        $existsStmt = $pdo->prepare(
            "SELECT id FROM os_images WHERE distro = ? AND version_full = ? AND arch = ? LIMIT 1"
        );
        $ok = 0;
        $skipped = 0;
        foreach ($rows as $tuple) {
            if (!is_string($tuple)) { $skipped++; continue; }
            $parts = explode(':', $tuple);
            if (count($parts) !== 3) { $skipped++; continue; }
            [$distro, $vf, $arch] = $parts;

            $existsStmt->execute([$distro, $vf, $arch]);
            if ($existsStmt->fetch(PDO::FETCH_ASSOC) !== false) {
                $skipped++;
                continue;
            }
            if (!OsImage::ensureForDistroVersion($distro, $vf, $arch)) {
                $skipped++;
                continue;
            }
            $ok++;
        }

        if ($ok === 0) {
            View::flash('error', 'Aucun téléchargement n\'a pu être démarré.');
        } else {
            $msg = "$ok téléchargement(s) en file";
            if ($skipped > 0) $msg .= " ($skipped entrée(s) déjà présente(s) ou invalide(s))";
            View::flash('info', $msg);
        }
        View::redirect('/settings/images');
    }

    /**
     * Fragment HTMX du bouton "Refresh catalogue". Polling toutes les 3s
     * tant que $refresh_running est true → swap auto vers l'état idle
     * quand le worker a fini, sans rechargement manuel de la page.
     */
    public function refreshButtonFragment(array $params): void
    {
        View::partial('settings/images/_refresh_button', [
            'refresh_running' => self::refreshRunning(),
        ]);
    }

    public function refresh(array $params): void
    {
        if (self::refreshRunning()) {
            $query = Db::get()->prepare("SELECT id FROM init_jobs WHERE job_type IN ('bootstrap:refresh_catalog', ?) AND status IN ('pending','running') ORDER BY id LIMIT 1");
            $query->execute([self::REFRESH_JOB_TYPE]);
            $id = (int)$query->fetchColumn();
            View::flash('info', 'Une actualisation du catalogue est déjà en attente ou en cours.', '/jobs/' . $id, 'Suivre le traitement');
            View::redirect('/settings/images');
            return;
        }
        $ids = \Admin\Util\ApplianceJobs::enqueue([self::REFRESH_JOB_TYPE => 'Actualisation du catalogue demandée'], [], 'catalog_job_ids');
        View::flash('info', 'Actualisation du catalogue demandée. Les images choisies seront téléchargées séparément.', '/jobs/' . $ids[0], 'Suivre le traitement');
        View::redirect('/settings/images');
    }

    public function updateAutoRefresh(array $params): void
    {
        $interval = (int)($_POST['catalog_auto_refresh_interval_hours'] ?? 168);
        if (!in_array($interval, self::AUTO_REFRESH_INTERVALS, true)) {
            View::flash('error', 'Fréquence invalide.');
            View::redirect('/settings/images');
            return;
        }

        Setting::set(
            'catalog_auto_refresh_enabled',
            isset($_POST['catalog_auto_refresh_enabled']) ? 'on' : 'off',
        );
        Setting::set('catalog_auto_refresh_interval_hours', (string)$interval);
        View::flash('success', 'Actualisation automatique enregistrée.');
        View::redirect('/settings/images');
    }

    /** True si un job de refresh catalogue (v1 ou v2) est pending ou running. */
    private static function refreshRunning(): bool
    {
        try {
            $stmt = Db::get()->prepare(
                "SELECT COUNT(*) FROM init_jobs "
              . "WHERE job_type IN ('bootstrap:refresh_catalog', ?) "
              . "AND status IN ('pending', 'running')"
            );
            $stmt->execute([self::REFRESH_JOB_TYPE]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function toggle(array $params): void
    {
        $id = (int)$params['id'];
        $img = OsImage::findById($id);
        if ($img === null) {
            View::flash('error', 'Image introuvable');
            View::redirect('/settings/images');
            return;
        }
        if (isset($_POST['enabled']) && !in_array($_POST['enabled'], ['0', '1'], true)) {
            View::flash('error', 'Choix d’activation invalide.');
            View::redirect('/settings/images');
            return;
        }
        $newOn = isset($_POST['enabled']) ? $_POST['enabled'] === '1' : (int)$img['enabled'] === 0;
        try {
            OsImage::setEnabled($id, $newOn);
            $img = OsImage::findById($id) ?? $img;
        } catch (\Throwable $error) {
            View::flash('error', $error->getMessage());
            View::redirect('/settings/images');
            return;
        }

        // Si activation d'une image non téléchargée (ou échouée) → set pending
        // pour que le worker la pickle. Pas d'init_job intermédiaire.
        if ($newOn && $img['status'] === OsImage::STATUS_PENDING) {
            View::flash('info', "Image {$img['distro']} {$img['version']} activée — téléchargement à venir.");
        } else {
            View::flash('success', "Image {$img['distro']} {$img['version']} " . ($newOn ? 'activée' : 'désactivée') . '.');
        }
        View::redirect('/settings/images');
    }

    public function download(array $params): void
    {
        $id = (int)$params['id'];
        $img = OsImage::findById($id);
        if ($img === null) {
            View::flash('error', 'Image introuvable');
            View::redirect('/settings/images');
            return;
        }
        try {
            OsImage::resetToPending($id);
        } catch (\Throwable $error) {
            View::flash('error', $error->getMessage());
            View::redirect('/settings/images');
            return;
        }

        // Lot 2 : le même bouton sert aussi la page /init (section
        // « Téléchargement des images »). Là, hx-target=#init-progress → on
        // renvoie le fragment de progression /init, pas un HX-Redirect vers
        // /settings/images (qui ferait quitter la page d'init). Discriminé via
        // l'en-tête HX-Target posé par HTMX.
        if (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'init-progress') {
            (new InitController())->status();
            return;
        }

        View::flash('info', "Téléchargement de {$img['distro']} {$img['version']} relancé.");
        View::redirect('/settings/images');
    }

    public function delete(array $params): void
    {
        $id = (int)$params['id'];
        $img = OsImage::findById($id);
        if ($img === null) {
            View::flash('error', 'Image introuvable');
            View::redirect('/settings/images');
            return;
        }
        try {
            OsImage::deleteWithFiles($id);
        } catch (\Throwable $error) {
            View::flash('error', $error->getMessage());
            View::redirect('/settings/images');
            return;
        }
        View::flash('success', "Image {$img['distro']} {$img['version']} supprimée (DB + fichiers locaux).");
        View::redirect('/settings/images');
    }

}
