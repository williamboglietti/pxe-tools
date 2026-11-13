#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""PXE agents need a job token; observed DHCP peers also bind that token to IP."""

import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[2]
MAC_WIPE = '02:00:00:00:00:11'
MAC_TEST = '02:00:00:00:00:22'
MAC_CHAIN = '02:00:00:00:00:33'
MAC_FALLBACK = '02:00:00:00:00:44'
IP_WIPE = '192.0.2.11'
IP_TEST = '192.0.2.22'
IP_CHAIN = '192.0.2.33'
IP_FALLBACK = '192.0.2.44'


def request(database: Path, temporary: Path, controller: str, operation: str,
            address: str, params: dict[str, object], token: str = ''):
    status = temporary / 'status'
    bootstrap = r'''spl_autoload_register(function($c) {
        if (str_starts_with($c, 'Admin\\')) {
            require getenv('PXE_APP_DIR') . '/src/' . str_replace('\\', '/', substr($c, 6)) . '.php';
        }
    });
    $_SERVER['REMOTE_ADDR'] = $argv[1];
    $_GET = json_decode($argv[2], true);
    if ($argv[3] !== '') $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $argv[3];
    $status_path = $argv[4];
    register_shutdown_function(static function() use ($status_path) {
        file_put_contents($status_path, (string)(http_response_code() ?: 200));
    });
    (new {$CONTROLLER}())->{$OPERATION}([]);'''
    bootstrap = bootstrap.replace('{$CONTROLLER}', 'Admin\\Controllers\\' + controller)
    bootstrap = bootstrap.replace('{$OPERATION}', operation)
    env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite',
               PXE_DB_PATH=str(database))
    result = subprocess.run(['php', '-r', bootstrap, address, json.dumps(params), token, str(status)],
                            env=env, capture_output=True, text=True)
    if result.returncode != 0:
        raise AssertionError(result.stderr[:500])
    return int(status.read_text()), result.stdout


with tempfile.TemporaryDirectory(prefix='pxe-agent-callback-') as tmp:
    temporary = Path(tmp)
    database = temporary / 'test.sqlite'
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        db.executemany('INSERT INTO settings(key,value) VALUES (?,?)',
                       [('pxe_subnet', '192.0.2.0/24'), ('pxe_ip', '192.0.2.1')])
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'wiping')", (MAC_WIPE,))
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'testing')", (MAC_TEST,))
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'wiping')", (MAC_CHAIN,))
        db.execute("INSERT INTO machines(mac,status) VALUES (?, 'wiping')", (MAC_FALLBACK,))
        db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES (1,'wipe_only','pending',?)",
                   ('a' * 64,))
        db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES (2,'disktest','pending',?)",
                   ('b' * 64,))
        db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES (3,'reinstall','pending',?)",
                   ('c' * 64,))
        db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES (4,'wipe_only','pending',?)",
                   ('d' * 64,))
        db.execute("INSERT INTO wipes(machine_id,disks_json,wipe_mode,wipe_status,action_id) "
                   "VALUES (1,?,'partial','pending',1)",
                   (json.dumps([{'name': '/dev/sda', 'mode': 'partial'}]),))
        db.execute("INSERT INTO disk_tests(machine_id,jobs_json,parallel,job_status,action_id) "
                   "VALUES (2,?,0,'pending',2)",
                   (json.dumps([{'disk': '/dev/sda', 'mode': 'smart_short',
                                 'identity': {'kind': 'serial', 'value': 'TEST-DISK', 'status': 'valid'}}]),))
        db.execute("INSERT INTO wipes(machine_id,disks_json,wipe_mode,wipe_status,chain_install_config_json,action_id) "
                   "VALUES (3,?,'partial','pending',?,3)",
                   (json.dumps([{'name': '/dev/sdb', 'mode': 'partial'}]),
                    json.dumps({'install_config': {'system_disk_path': '/dev/sdb'}, 'hostname': 'chain-test'})))
        db.execute("INSERT INTO wipes(machine_id,disks_json,wipe_mode,wipe_status,action_id) "
                   "VALUES (4,?,'partial','pending',4)",
                   (json.dumps([{'name': '/dev/sdc', 'mode': 'partial'}]),))
        db.commit()

    # The trusted PXE subnet is the fallback boot boundary when proxy DHCP or
    # TFTP-only mode cannot report an IP. External peers still get no token.
    assert request(database, temporary, 'PxeController', 'wipeScript', '198.51.100.11',
                   {'mac': MAC_WIPE})[0] == 404
    assert request(database, temporary, 'PxeController', 'diskTestJob', '198.51.100.22',
                   {'mac': MAC_TEST})[0] == 404
    request(database, temporary, 'InternalApiController', 'dhcpEvent', '192.0.2.99',
            {'mac': MAC_WIPE, 'ip': IP_WIPE})
    request(database, temporary, 'InternalApiController', 'dnsmasqEvent', '127.0.0.1',
            {'type': 'dhcp_proxy', 'mac': MAC_TEST, 'ip': ''})
    with sqlite3.connect(database) as db:
        assert db.execute("SELECT COUNT(*) FROM machine_events WHERE checkpoint='pxe_boot_observed'").fetchone()[0] == 0
    fallback_status, fallback_script = request(database, temporary, 'PxeController', 'wipeScript',
                                               IP_FALLBACK, {'mac': MAC_FALLBACK})
    assert fallback_status == 200
    fallback_token = re.search(r'^BOOT_TOKEN="([a-f0-9]{64})"$', fallback_script, re.MULTILINE).group(1)
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT client_ip FROM boot_sessions WHERE token=?',
                          (fallback_token,)).fetchone()[0] is None
    request(database, temporary, 'InternalApiController', 'dhcpEvent', '127.0.0.1',
            {'mac': MAC_WIPE, 'ip': IP_WIPE})
    request(database, temporary, 'InternalApiController', 'dhcpEvent', '127.0.0.1',
            {'mac': MAC_TEST, 'ip': IP_TEST})
    request(database, temporary, 'InternalApiController', 'dnsmasqEvent', '127.0.0.1',
            {'type': 'dhcp_proxy', 'mac': MAC_CHAIN, 'ip': IP_CHAIN})
    wipe_status, wipe_script = request(database, temporary, 'PxeController', 'wipeScript',
                                       IP_WIPE, {'mac': MAC_WIPE})
    assert wipe_status == 200
    assert subprocess.run(['sh', '-n'], input=wipe_script, text=True,
                          capture_output=True).returncode == 0
    wipe_token = re.search(r'^BOOT_TOKEN="([a-f0-9]{64})"$', wipe_script, re.MULTILINE).group(1)
    test_status, test_json = request(database, temporary, 'PxeController', 'diskTestJob',
                                     IP_TEST, {'mac': MAC_TEST})
    assert test_status == 200
    test_token = json.loads(test_json)['boot_token']
    assert re.fullmatch('[a-f0-9]{64}', test_token) and test_token != 'b' * 64
    renewed_status, renewed_script = request(database, temporary, 'PxeController', 'wipeScript',
                                             IP_WIPE, {'mac': MAC_WIPE})
    assert renewed_status == 200
    old_wipe_token = wipe_token
    wipe_token = re.search(r'^BOOT_TOKEN="([a-f0-9]{64})"$', renewed_script, re.MULTILINE).group(1)
    assert wipe_token != old_wipe_token
    assert request(database, temporary, 'PxeController', 'wipeProgress', IP_WIPE,
                   {'wipe_id': '1', 'mac': MAC_WIPE, 'line': 'stale'}, old_wipe_token)[0] == 404
    # A silent agent session expires; a fresh job fetch replaces the old token.
    with sqlite3.connect(database) as db:
        db.execute("UPDATE boot_sessions SET served_at='2000-01-01 00:00:00' WHERE token=?",
                   (test_token,))
        db.commit()
    assert request(database, temporary, 'PxeController', 'diskTestProgress', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'line': 'expired'}, test_token)[0] == 404
    fresh_status, fresh_json = request(database, temporary, 'PxeController', 'diskTestJob',
                                       IP_TEST, {'mac': MAC_TEST})
    assert fresh_status == 200
    old_test_token = test_token
    test_token = json.loads(fresh_json)['boot_token']
    assert test_token != old_test_token
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT client_ip FROM boot_sessions WHERE token=?',
                          (wipe_token,)).fetchone()[0] == IP_WIPE
        assert db.execute('SELECT client_ip FROM boot_sessions WHERE token=?',
                          (test_token,)).fetchone()[0] == IP_TEST

    # The end callback is not the only write path: progress, SMART and result
    # callbacks must reject the same unauthenticated peer before any storage.
    write_calls = [
        ('wipeProgress', IP_WIPE, {'wipe_id': '1', 'mac': MAC_WIPE, 'line': 'forged'}),
        ('diskTestProgress', IP_TEST, {'test_id': '1', 'mac': MAC_TEST, 'line': 'forged'}),
        ('diskTestDiskProgress', IP_TEST,
         {'test_id': '1', 'mac': MAC_TEST, 'disk': '/dev/sda', 'phase': 'test', 'pct': '80'}),
        ('diskTestResult', IP_TEST,
         {'test_id': '1', 'mac': MAC_TEST, 'disk': '/dev/sda', 'mode': 'smart_short',
          'verdict': 'passed'}),
        ('diskTestSmart', IP_TEST,
         {'test_id': '1', 'mac': MAC_TEST, 'disk': '/dev/sda', 'phase': 'before'}),
    ]
    for operation, address, params in write_calls:
        assert request(database, temporary, 'PxeController', operation, address, params)[0] == 404
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT log FROM wipes WHERE id=1').fetchone()[0] is None
        test_row = db.execute('SELECT log,progress_json,results_json FROM disk_tests WHERE id=1').fetchone()
        assert test_row == (None, None, None)
        assert db.execute('SELECT COUNT(*) FROM disk_smart_snapshots').fetchone()[0] == 0

    assert request(database, temporary, 'PxeController', 'wipeProgress', IP_WIPE,
                   {'wipe_id': '1', 'mac': MAC_WIPE, 'line': 'agent-log'}, wipe_token)[0] == 200
    assert request(database, temporary, 'PxeController', 'diskTestProgress', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'line': 'agent-log'}, test_token)[0] == 200
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT selected_at FROM boot_sessions WHERE token=?',
                          (test_token,)).fetchone()[0] is not None
        db.execute("UPDATE boot_sessions SET served_at='2000-01-01 00:00:00' WHERE token=?",
                   (test_token,))
        db.commit()
    assert request(database, temporary, 'PxeController', 'diskTestDiskProgress', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'disk': '/dev/sda', 'phase': 'test', 'pct': '80'},
                   test_token)[0] == 200

    for address, mac, token in [(IP_WIPE, MAC_WIPE, ''), (IP_WIPE, MAC_WIPE, test_token),
                                (IP_TEST, MAC_WIPE, wipe_token), (IP_WIPE, MAC_TEST, wipe_token)]:
        assert request(database, temporary, 'PxeController', 'wipeEnd', address,
                       {'wipe_id': '1', 'mac': mac, 'status': 'failed'}, token)[0] == 404
    for address, mac, token in [(IP_TEST, MAC_TEST, ''), (IP_TEST, MAC_TEST, wipe_token),
                                (IP_WIPE, MAC_TEST, test_token), (IP_TEST, MAC_WIPE, test_token)]:
        assert request(database, temporary, 'PxeController', 'diskTestEnd', address,
                       {'test_id': '1', 'mac': mac, 'status': 'failed'}, token)[0] == 404
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT wipe_status FROM wipes WHERE id=1').fetchone()[0] == 'running'
        assert db.execute('SELECT job_status FROM disk_tests WHERE id=1').fetchone()[0] == 'running'

    assert request(database, temporary, 'PxeController', 'wipeEnd', IP_WIPE,
                   {'wipe_id': '1', 'mac': MAC_WIPE, 'status': 'failed'}, wipe_token)[0] == 200
    assert request(database, temporary, 'PxeController', 'wipeEnd', IP_FALLBACK,
                   {'wipe_id': '3', 'mac': MAC_FALLBACK, 'status': 'failed'}, fallback_token)[0] == 200
    # A coherent result and matching boot token still permit normal success.
    evidence = {'schema': 1, 'tool': 'smartctl', 'status': 'completed', 'type': 'short',
                'start_exit_code': 0, 'new_entry': True, 'passed': True}
    assert request(database, temporary, 'PxeController', 'diskTestResult', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'disk': '/dev/sda', 'mode': 'smart_short',
                    'verdict': 'passed', 'evidence': evidence}, test_token)[0] == 200
    assert request(database, temporary, 'PxeController', 'diskTestEnd', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'status': 'success'}, test_token)[0] == 200
    assert request(database, temporary, 'PxeController', 'wipeEnd', IP_WIPE,
                   {'wipe_id': '1', 'mac': MAC_WIPE, 'status': 'success'}, wipe_token)[0] == 404
    assert request(database, temporary, 'PxeController', 'diskTestEnd', IP_TEST,
                   {'test_id': '1', 'mac': MAC_TEST, 'status': 'failed'}, test_token)[0] == 404
    chain_status, chain_script = request(database, temporary, 'PxeController', 'wipeScript',
                                         IP_CHAIN, {'mac': MAC_CHAIN})
    assert chain_status == 200
    assert subprocess.run(['sh', '-n'], input=chain_script, text=True,
                          capture_output=True).returncode == 0
    chain_token = re.search(r'^BOOT_TOKEN="([a-f0-9]{64})"$', chain_script, re.MULTILINE).group(1)
    assert request(database, temporary, 'PxeController', 'wipeEnd', IP_CHAIN,
                   {'wipe_id': '2', 'mac': MAC_CHAIN, 'status': 'success'}, chain_token)[0] == 200
    with sqlite3.connect(database) as db:
        assert db.execute('SELECT wipe_status FROM wipes WHERE id=1').fetchone()[0] == 'failed'
        assert db.execute('SELECT job_status FROM disk_tests WHERE id=1').fetchone()[0] == 'success'
        assert db.execute('SELECT status FROM machines WHERE id=1').fetchone()[0] == 'failed'
        assert db.execute('SELECT status FROM machines WHERE id=2').fetchone()[0] == 'tested'
        assert db.execute('SELECT wipe_status FROM wipes WHERE id=3').fetchone()[0] == 'failed'
        chain = db.execute('SELECT status,next_action,install_config_json FROM machines WHERE id=3').fetchone()
        assert chain[0:2] == ('configured', 'reboot')
        assert json.loads(chain[2])['system_disk_path'] == '/dev/sdb'

print('PXE callbacks require an action-bound token and bind observed DHCP peers to IP')
