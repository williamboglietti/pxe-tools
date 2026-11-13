#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise real PHP install callbacks against a disposable SQLite database."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
TOKEN = 'a' * 64
OTHER_TOKEN = 'b' * 64
MAC = '02:00:00:00:00:01'


def request(database, directory, operation, address, token=''):
    status = Path(directory) / 'status'
    bootstrap = r'''spl_autoload_register(function($c) {
        if (str_starts_with($c, 'Admin\\')) {
            require getenv('PXE_APP_DIR') . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        }
    });
    $_SERVER['REMOTE_ADDR'] = $argv[1];
    if ($argv[2] !== '') $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[2];
    $_GET['mac'] = $argv[3];
    $_GET['status'] = 'success';
    register_shutdown_function(function() { file_put_contents($argv[4], (string)http_response_code()); });
    (new Admin\Controllers\PxeController())->{$OPERATION}([]);'''.replace('{$OPERATION}', operation)
    env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(database))
    result = subprocess.run(['php', '-r', bootstrap, address, token, MAC, str(status)],
                            env=env, capture_output=True, text=True, check=True)
    return int(status.read_text()), json.loads(result.stdout)


with tempfile.TemporaryDirectory(prefix='pxe-callback-auth-') as tmp:
    database = Path(tmp) / 'test.sqlite'
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        db.execute("INSERT INTO settings(key,value) VALUES ('pxe_subnet','192.0.2.0/24')")
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'configured')", (MAC,))
        db.execute("INSERT INTO actions(machine_id,type,status,token,created_by) VALUES (1,'install_only','running',?,'fixture')", (TOKEN,))
        db.execute("INSERT INTO actions(machine_id,type,status,token,created_by) VALUES (1,'reboot','running',?,'fixture')", (OTHER_TOKEN,))
        db.commit()

    assert request(database, tmp, 'installStart', '192.0.2.2')[0] == 404
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT count(*) FROM installations').fetchone()[0] == 0
        assert db.execute('SELECT status FROM machines WHERE id=1').fetchone()[0] == 'configured'

    start_status, start = request(database, tmp, 'installStart', '127.0.0.1')
    assert start_status == 200 and start['installation_id'] > 0
    with sqlite3.connect(database) as db:
        db.execute('UPDATE installations SET action_id=1 WHERE id=?', (start['installation_id'],))
        db.commit()

    for address, token in [('192.0.2.2', ''), ('192.0.2.2', OTHER_TOKEN), ('198.51.100.2', TOKEN)]:
        assert request(database, tmp, 'installEnd', address, token)[0] == 404
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT install_status FROM installations WHERE id=?', (start['installation_id'],)).fetchone()[0] == 'in_progress'
        assert db.execute('SELECT local_boot_source FROM machines WHERE id=1').fetchone()[0] == 'none'

    finish_status, finish = request(database, tmp, 'installEnd', '192.0.2.2', TOKEN)
    assert finish_status == 200 and finish['machine_status'] == 'installed'
    assert request(database, tmp, 'installEnd', '192.0.2.2', TOKEN)[0] == 200
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT install_status FROM installations WHERE id=?', (start['installation_id'],)).fetchone()[0] == 'success'
        assert db.execute('SELECT local_boot_source FROM machines WHERE id=1').fetchone()[0] == 'pxe'
print('install callbacks require server-local start and action-bound completion')
