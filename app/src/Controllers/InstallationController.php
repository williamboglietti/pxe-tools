<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Installation;
use Admin\View;

final class InstallationController
{
    public function index(array $params): void
    {
        $rows = Db::get()->query(
            'SELECT i.*, m.hostname, m.mac '
          . 'FROM installations i JOIN machines m ON i.machine_id = m.id '
          . 'ORDER BY i.created_at DESC LIMIT 100'
        )->fetchAll();
        View::render('installations/index', ['installations' => $rows], 'Installations');
    }

    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $install = Installation::find($id);
        if (!$install) { http_response_code(404); return; }
        $row = Db::get()->prepare(
            'SELECT m.hostname, m.mac FROM machines m WHERE id = ?'
        );
        $row->execute([$install['machine_id']]);
        $machine = $row->fetch();
        View::render('installations/show', [
            'install' => $install,
            'machine' => $machine,
        ], 'Install #' . $id);
    }

    /** HTMX-polled fragment that renders just the log block + status. */
    public function logFragment(array $params): void
    {
        $id = (int) $params['id'];
        $install = Installation::find($id);
        if (!$install) { http_response_code(404); return; }
        View::partial('installations/_log', ['install' => $install]);
    }

    /**
     * POST /installations/{id}/cancel — annule une install en cours.
     *
     * Délègue à Installation::abort() qui marque l'installation et son action
     * parente (si présente) comme annulées. Best-effort : si l'install n'est
     * pas in_progress (déjà success/failed/aborted), no-op + flash info.
     */
    public function cancel(array $params): void
    {
        $id = (int) $params['id'];
        $install = Installation::find($id);
        if (!$install) { http_response_code(404); return; }

        if ($install['install_status'] !== 'in_progress') {
            View::flash('info', 'Installation déjà terminée — annulation ignorée');
            View::redirect('/installations/' . $id);
            return;
        }

        try {
            Installation::abort($id);
            View::flash('success', 'Suivi de l’installation clôturé. Cela ne garantit pas l’arrêt du processus distant.');
        } catch (\Throwable $e) {
            View::flash('error', 'Annulation impossible : ' . $e->getMessage());
        }
        View::redirect('/installations/' . $id);
    }
}
