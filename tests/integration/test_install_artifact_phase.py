#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the real postboot file route across the Debian install phase."""
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
PHP = r'''spl_autoload_register(function($c) {
    if (str_starts_with($c, 'Admin\\')) {
        require getenv('PXE_APP_DIR') . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
    }
});
$_SERVER['REMOTE_ADDR'] = $argv[1];
$status_path = $argv[3];
register_shutdown_function(static function() use ($status_path) {
    file_put_contents($status_path, (string)http_response_code());
});
(new Admin\Controllers\PxeController())->serveActionFile(['action_id' => $argv[2], 'file' => 'postboot.sh']);'''


with tempfile.TemporaryDirectory(prefix='pxe-artifact-phase-') as tmp:
    root = Path(tmp)
    database = root / 'test.sqlite'
    output = root / 'postboot'
    script = output / '7' / 'postboot.sh'
    script.parent.mkdir(parents=True)
    script.write_text('#!/bin/sh\necho fixture\n')
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        db.execute("INSERT INTO settings(key,value) VALUES ('pxe_subnet','192.0.2.0/24')")
        db.execute("INSERT INTO machines(id,mac,status) VALUES (1,'02:00:00:00:00:01','installing')")
        db.execute("INSERT INTO actions(id,machine_id,type,status,token,created_by) VALUES (7,1,'install_only','running',?,'fixture')", ('a' * 64,))
        db.commit()

    def request(address='192.0.2.2'):
        status = root / 'status'
        env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite',
                   PXE_DB_PATH=str(database), POSTBOOT_OUTPUT_DIR=str(output))
        result = subprocess.run(['php', '-r', PHP, address, '7', str(status)],
                                env=env, capture_output=True, text=True)
        assert result.returncode == 0, result.stderr[:500]
        return int(status.read_text()), result.stdout

    assert request()[0] == 404  # Rendering ahead of an install does not expose the token.
    with sqlite3.connect(database) as db:
        db.execute("INSERT INTO installations(machine_id,install_status,action_id) VALUES (1,'in_progress',7)")
        db.commit()
    assert request()[0] == 200 and 'fixture' in request()[1]
    assert request('198.51.100.2')[0] == 404
    with sqlite3.connect(database) as db:
        db.execute("UPDATE installations SET install_status='success' WHERE action_id=7")
        db.commit()
    assert request()[0] == 404  # Action remains running while Ansible finishes.
print('postboot artifacts close when the Debian install phase finishes')
