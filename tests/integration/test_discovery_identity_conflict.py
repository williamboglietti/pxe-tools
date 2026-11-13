#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""A reported secondary MAC cannot make discovery delete another machine."""

import base64
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
ESTABLISHED_MAC = '02:00:00:00:00:01'
STUB_MAC = '02:00:00:00:00:02'


def discovery(database: Path, temporary: Path, mac: str, interfaces: list[str]):
    payload = {
        'mac': mac,
        'inventory': {'cpu_arch': 'x86_64'},
        'disks': [],
        'interfaces': [{'mac': value} for value in interfaces],
    }
    encoded = base64.urlsafe_b64encode(json.dumps(payload).encode()).decode()
    status = temporary / 'status'
    bootstrap = r'''spl_autoload_register(function($c) {
        if (str_starts_with($c, 'Admin\\')) {
            require getenv('PXE_APP_DIR') . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        }
    });
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_GET['b64'] = $argv[1];
    $status_path = $argv[2];
    register_shutdown_function(static function() use ($status_path) {
        file_put_contents($status_path, (string)http_response_code());
    });
    (new Admin\Controllers\PxeController())->discovery([]);'''
    env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite',
               PXE_DB_PATH=str(database))
    result = subprocess.run(['php', '-r', bootstrap, encoded, str(status)], env=env,
                            capture_output=True, text=True)
    if result.returncode != 0:
        raise AssertionError(result.stderr[:500])
    return int(status.read_text()), json.loads(result.stdout)


with tempfile.TemporaryDirectory(prefix='pxe-discovery-identity-') as tmp:
    temporary = Path(tmp)
    database = temporary / 'test.sqlite'
    existing_inventory = json.dumps({'interfaces': [{'mac': STUB_MAC}], 'marker': 'keep'})
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        db.execute("INSERT INTO machines(mac,status,inventory_json) VALUES (?, 'provisioned', ?)",
                   (ESTABLISHED_MAC, existing_inventory))
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'inventorying')", (STUB_MAC,))
        db.commit()

    # The stub's primary MAC masks the established machine's secondary MAC.
    # Discovery must preserve both rows and the established inventory for review.
    status, body = discovery(database, temporary, STUB_MAC, [ESTABLISHED_MAC])
    assert status == 409 and body['error'] == 'conflicting machine identities'
    with sqlite3.connect(database) as db:
        rows = db.execute('SELECT mac,status,inventory_json FROM machines ORDER BY id').fetchall()
        assert rows == [(ESTABLISHED_MAC, 'provisioned', existing_inventory),
                        (STUB_MAC, 'inventorying', None)]
        warnings = db.execute("SELECT COUNT(*) FROM machine_events WHERE level='warning'").fetchone()[0]
        assert warnings == 2

        # After an administrator verifies and removes the orphan stub, the
        # ordinary multi-NIC report still updates the established machine.
        db.execute('DELETE FROM machines WHERE mac = ?', (STUB_MAC,))
        db.commit()
    status, body = discovery(database, temporary, STUB_MAC, [ESTABLISHED_MAC])
    assert status == 200 and body['status'] == 'updated'
    with sqlite3.connect(database) as db:
        rows = db.execute('SELECT mac,status,inventory_json FROM machines').fetchall()
        assert len(rows) == 1 and rows[0][0] == ESTABLISHED_MAC
        assert rows[0][1] == 'provisioned'
        assert json.loads(rows[0][2])['boot_mac'] == STUB_MAC

print('conflicting discovery preserves both machines until administrator reconciliation')
