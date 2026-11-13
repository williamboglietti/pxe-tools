<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\DiskTest;
use Admin\View;

final class DiskTestController
{
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $test = DiskTest::find($id);
        if (!$test) { http_response_code(404); echo 'Test not found'; return; }

        // La page canonique des tests est /tasks/{action_id} (fusion 2026-06-10 :
        // suivi + résultats + journal + contrôles y sont réunis). Cette route ne
        // sert plus qu'aux vieux liens → redirect. Rendu legacy conservé pour
        // les tests orphelins d'avant le système d'actions (action_id NULL).
        if (!empty($test['action_id'])) {
            View::redirect('/tasks/' . (int)$test['action_id']);
            return;
        }

        $row = Db::get()->prepare('SELECT m.hostname, m.mac FROM machines m WHERE id = ?');
        $row->execute([$test['machine_id']]);
        $machine = $row->fetch();
        View::render('disktests/show', [
            'test'    => $test,
            'machine' => $machine,
        ], 'Test de disque #' . $id);
    }

    /** HTMX-polled fragment : statut + log + tableau résultats. */
    public function logFragment(array $params): void
    {
        $id = (int) $params['id'];
        $test = DiskTest::find($id);
        if (!$test) { http_response_code(404); return; }
        View::partial('disktests/_log', ['test' => $test]);
    }

    /** Fragment suivi par disque seul (fiche machine, onglet Stockage). */
    public function progressFragment(array $params): void
    {
        $id = (int) $params['id'];
        $test = DiskTest::find($id);
        if (!$test) { http_response_code(404); return; }
        View::partial('disktests/_progress', ['test' => $test]);
    }
}
