<?php
declare(strict_types=1);

namespace Admin\Controllers;

use Admin\Db;
use Admin\Models\Wipe;
use Admin\View;

final class WipeController
{
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $wipe = Wipe::find($id);
        if (!$wipe) { http_response_code(404); echo 'Wipe not found'; return; }
        $row = Db::get()->prepare('SELECT m.hostname, m.mac FROM machines m WHERE id = ?');
        $row->execute([$wipe['machine_id']]);
        $machine = $row->fetch();
        View::render('wipes/show', [
            'wipe'    => $wipe,
            'machine' => $machine,
        ], 'Wipe #' . $id);
    }

    /** HTMX-polled fragment : statut + log courant. */
    public function logFragment(array $params): void
    {
        $id = (int) $params['id'];
        $wipe = Wipe::find($id);
        if (!$wipe) { http_response_code(404); return; }
        View::partial('wipes/_log', ['wipe' => $wipe]);
    }
}
