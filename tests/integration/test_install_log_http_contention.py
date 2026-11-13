#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the real install-log HTTP route with a concurrent SQLite writer."""
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
if os.environ.get('PXE_INSTALL_LOG_HTTP_ISOLATED') != '1' or Path('/etc/pxe-tools/db.conf').exists():
    raise SystemExit('Run only in an isolated fixture without appliance data')

with tempfile.TemporaryDirectory(prefix='pxe-log-http-contention-') as temporary:
    work = Path(temporary)
    db = work / 'fixture.sqlite'
    token = 'a' * 64
    with closing(sqlite3.connect(db)) as connection, connection:
        connection.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        assert connection.execute('PRAGMA journal_mode=WAL').fetchone()[0] == 'wal'
        connection.execute("INSERT INTO settings(key,value) VALUES('pxe_subnet','127.0.0.0/8')")
        connection.execute("INSERT INTO machines(id,mac,status) VALUES(1,'02:00:00:00:00:01','installing')")
        connection.execute("INSERT INTO actions(id,machine_id,type,status,token) VALUES(1,1,'install_only','running',?)", (token,))
        connection.execute("INSERT INTO installations(id,machine_id,install_status,action_id) VALUES(1,1,'in_progress',1)")
        connection.execute('CREATE TABLE lock_probe (value INTEGER)')
        connection.execute('INSERT INTO lock_probe VALUES(0)')
    env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(db))
    router = work / 'router.php'
    router.write_text('<?php require ' + repr(str(ROOT / 'app/public/index.php')) + ';')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = work / 'server.log'

    def post():
        request = urllib.request.Request(f'http://127.0.0.1:{port}/api/internal/install-log?install_id=1&seq=1',
                                         data=b'fixture-log', headers={'Authorization': 'Bearer ' + token}, method='POST')
        try:
            response = urllib.request.urlopen(request, timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, json.loads(response.read())

    with log.open('w') as output:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(router)], env=env,
                                  stdout=output, stderr=output)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Fixture HTTP server did not start')
            with closing(sqlite3.connect(db, timeout=5)) as writer, writer:
                writer.execute('BEGIN IMMEDIATE')
                writer.execute('UPDATE lock_probe SET value=value+1')
                with ThreadPoolExecutor(max_workers=1) as pool:
                    output.flush()
                    accepted_before = log.read_text().count('Accepted')
                    request = pool.submit(post)
                    deadline = time.monotonic() + 3
                    while log.read_text().count('Accepted') <= accepted_before:
                        if time.monotonic() >= deadline:
                            raise AssertionError('HTTP server did not accept the fixture request')
                        time.sleep(.01)
                    time.sleep(.25)
                    if request.done():
                        raise AssertionError(f'HTTP request completed while writer held the lock: {request.result()}')
                    writer.commit()
                    status, result = request.result(timeout=10)
            assert status == 200, (status, result)
            assert result == {'status': 'ok', 'bytes': 11, 'appended': 11, 'seq': 1}, result
            with closing(sqlite3.connect(db)) as connection:
                assert connection.execute('SELECT install_log FROM installations WHERE id=1').fetchone()[0] == 'fixture-log'
            print('OK real HTTP install-log waited for competing SQLite writer and appended once')
        finally:
            server.terminate()
            try:
                server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
