<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Setting;
use Admin\View;

/**
 * Toggle "Forcer la redirection HTTPS" du portail admin.
 *
 * Routes :
 *   GET  /settings/https       → form (checkbox)
 *   POST /settings/https       → sauve la setting + enqueue hot-reload nginx
 *
 * Le listener HTTP reste actif. Le toggle contrôle aussi le listener HTTPS :
 *   - 'on'  → redirect 301 vers HTTPS (force le navigateur à utiliser TLS)
 *   - 'off' → l'app est servie en clair sur HTTP, sans listener HTTPS local
 *
 * Hot-reload via init_job `system:nginx_reload` consommé par worker.py :
 *   1. Re-rend admin-https.conf + admin-http.conf
 *   2. `nginx -t` valide la conf
 *   3. `nginx -s reload` applique sans downtime
 * Plus de restart container nécessaire.
 *
 * Choix initial fait dans le wizard étape Réseau (KEY_HTTPS_ENABLED).
 */
final class HttpsController
{
    public function form(array $params): void
    {
        View::render('settings/https/form', [
            'jobs' => \Admin\Util\ApplianceJobs::tracked('https_job_ids'),
            'https_port' => (int)(getenv('ADMIN_PORT') ?: 8443),
            'http_port' => (int)(getenv('ADMIN_HTTP_PORT') ?: 8080),
            'enabled' => Setting::get(Setting::KEY_HTTPS_ENABLED, 'off') === 'on',
        ], 'Redirection HTTPS');
    }

    public function update(array $params): void
    {
        $enabled = isset($_POST['https_enabled']);
        try {
            $ids = \Admin\Util\ApplianceJobs::enqueue([
                'system:nginx_reload' => $enabled ? 'Application demandée : HTTP vers HTTPS' : 'Application demandée : HTTP local seul',
            ], [Setting::KEY_HTTPS_ENABLED => $enabled ? 'on' : 'off'], 'https_job_ids');
        } catch (\Throwable $error) {
            View::flash('error', 'Enregistrement refusé : ' . $error->getMessage());
            View::redirect('/settings/https');
            return;
        }
        View::flash('info', 'Choix HTTPS enregistré. Application demandée ; attendez le résultat du traitement avant de changer d’adresse.', '/jobs/' . $ids[0], 'Suivre l’application');
        View::redirect('/settings/https');
    }
}
