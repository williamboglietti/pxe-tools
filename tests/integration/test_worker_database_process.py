#!/usr/bin/env python3
"""Run the actual worker with fake databases in a disposable container."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
CONFIG = Path('/etc/pxe-tools/db.conf')
if (os.environ.get('PXE_WORKER_DB_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').exists() or CONFIG.exists()):
    raise SystemExit('Only run in a disposable, isolated container without db.conf')

with tempfile.TemporaryDirectory(prefix='pxe-worker-db-process-') as temporary:
    root = Path(temporary)
    stale, chosen = root / 'stale.sqlite', root / 'chosen.sqlite'
    for path in (stale, chosen):
        with sqlite3.connect(path) as db:
            db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            db.execute("INSERT INTO init_jobs(id,job_type,status) VALUES(1,'fixture:unknown','pending')")
            db.execute("INSERT INTO settings(key,value) VALUES('catalog_auto_refresh_enabled','off')")
    tftp = root / 'tftp'
    tftp.mkdir()
    for name in ('grubx64.efi', 'lpxelinux.0'):
        (tftp / name).write_bytes(b'fixture')
    CONFIG.parent.mkdir(parents=True, exist_ok=True)
    CONFIG.write_text(json.dumps({'driver':'mysql', 'password':'fixture-secret'}))
    env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(stale), WORKER_POLL_S='1',
               TFTP_ROOT=str(tftp), ANSIBLE_WORKDIR=str(root / 'workspace'), PYTHONDONTWRITEBYTECODE='1')
    log_path = root / 'worker.log'
    with log_path.open('w') as log:
        worker = subprocess.Popen(['python3', str(ROOT / 'app/worker.py')], env=env, stdout=log, stderr=log)
        try:
            def wait_for(test, description):
                deadline = time.monotonic() + 25
                while time.monotonic() < deadline:
                    if worker.poll() is not None:
                        raise RuntimeError('worker stopped unexpectedly')
                    if test():
                        return
                    time.sleep(.2)
                raise RuntimeError('timed out: ' + description)
            def status(path, job_id):
                with sqlite3.connect(path, timeout=5) as db:
                    return db.execute('SELECT status FROM init_jobs WHERE id=?', (job_id,)).fetchone()[0]
            wait_for(lambda: 'ne prend actuellement en charge que SQLite' in log_path.read_text(), 'external database warning')
            assert status(stale, 1) == 'pending' and status(chosen, 1) == 'pending'
            CONFIG.write_text(json.dumps({'driver':'sqlite', 'path':str(chosen)}))
            wait_for(lambda: status(chosen, 1) == 'failed', 'configured SQLite job')
            assert status(stale, 1) == 'pending'
            CONFIG.write_text(json.dumps({'driver':'mysql', 'password':'fixture-secret'}))
            wait_for(lambda: log_path.read_text().count('ne prend actuellement en charge que SQLite') >= 2,
                     'changed configuration warning')
            with sqlite3.connect(chosen, timeout=5) as db:
                db.execute("INSERT INTO init_jobs(id,job_type,status) VALUES(2,'fixture:unknown','pending')")
            time.sleep(2.5)
            assert status(chosen, 2) == 'pending' and status(stale, 1) == 'pending'
            assert 'fixture-secret' not in log_path.read_text()
            print('Real worker waits on external DB, consumes only selected SQLite, then pauses after configuration changes')
        finally:
            worker.terminate()
            worker.wait(timeout=10)
            CONFIG.unlink(missing_ok=True)
