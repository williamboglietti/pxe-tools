#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise bounded deletion, interrupted jobs and protected-path refusals."""
import importlib.util
from http.cookiejar import CookieJar
import json
import os
import pwd
from pathlib import Path
import sqlite3
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
if (os.environ.get('PXE_IMAGE_DELETE_TEST') != '1' or os.geteuid() != 0
        or Path('/etc/pxe-tools/db.conf').exists()
        or {p.name for p in Path('/sys/class/net').iterdir()} != {'lo'}):
    raise SystemExit('A dedicated root networkless fixture without appliance configuration is required')
sys.dont_write_bytecode = True
with tempfile.TemporaryDirectory(prefix='pxe-delete-fixture-', dir='/var/lib') as temporary, \
        tempfile.TemporaryDirectory(prefix='pxe-delete-db-', dir='/tmp') as db_temporary:
    root = Path(temporary); root.chmod(0o755)
    http = root/'http'; http.mkdir(mode=0o755)
    database_dir = Path(db_temporary); database_dir.chmod(0o777)
    database = database_dir/'database.sqlite'
    with sqlite3.connect(database) as db:
        db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
    account=pwd.getpwnam('www-data')
    database.chmod(0o660); os.chown(database,account.pw_uid,account.pw_gid)
    os.environ.update(PXE_APP_DIR=str(ROOT/'app'), PXE_DB_PATH=str(database),
                      PXE_DB_DRIVER='sqlite', HTTP_ROOT=str(http), PXE_HTTP_ROOT=str(http))
    sys.path.insert(0, str(ROOT/'app'))
    spec = importlib.util.spec_from_file_location('worker_delete_test', ROOT/'app/worker.py')
    worker = importlib.util.module_from_spec(spec); spec.loader.exec_module(worker)
    worker.HTTP_ROOT = str(http); worker.DB_PATH = str(database)
    marker = root/'runtime'; marker.mkdir(mode=0o755)
    (marker/'runtime.env').write_text(f'HTTP_ROOT={http}\n')
    (marker/'runtime.env').chmod(0o644)
    from image_delete import require_trusted_http_root
    require_trusted_http_root(str(http), str(marker))
    try:
        require_trusted_http_root('/etc', str(marker))
        raise AssertionError('an untrusted HTTP root was accepted')
    except ValueError:
        pass
    (marker/'runtime.env').chmod(0o666)
    try:
        require_trusted_http_root(str(http), str(marker))
        raise AssertionError('a writable marker was accepted')
    except ValueError:
        pass
    (marker/'runtime.env').chmod(0o644)
    (marker/'runtime.env').write_text(f'HTTP_ROOT={http}\nHTTP_ROOT={http}\n')
    try:
        require_trusted_http_root(str(http), str(marker))
        raise AssertionError('an ambiguous marker was accepted')
    except ValueError:
        pass
    (marker/'runtime.env').write_text(f'HTTP_ROOT={http}\n')
    (marker/'runtime.env').unlink(); (marker/'runtime.env').symlink_to('/etc/passwd')
    try:
        require_trusted_http_root(str(http), str(marker))
        raise AssertionError('a linked marker was accepted')
    except OSError:
        pass
    (marker/'runtime.env').unlink(); (marker/'runtime.env').write_text(f'HTTP_ROOT={http}\n')
    (marker/'runtime.env').chmod(0o644)
    worker.require_trusted_http_root = lambda value: require_trusted_http_root(value, str(marker))
    def sql(query, params=()):
        with sqlite3.connect(database) as db: return db.execute(query, params).fetchall()
    def php(code, *args, expect=0):
        bootstrap = "spl_autoload_register(function($c){if(str_starts_with($c,'Admin\\\\')) require getenv('PXE_APP_DIR').'/src/'.str_replace('\\\\','/',substr($c,6)).'.php';});"
        result = subprocess.run(['php','-r',bootstrap+code,*map(str,args)], env=os.environ.copy(), capture_output=True, text=True)
        if result.returncode != expect: raise AssertionError((result.returncode, result.stderr))
        return result.stdout.strip()
    def image(ident, version=None):
        version = version or str(ident)
        sql("INSERT INTO os_images(id,distro,version,version_full,arch,enabled,status) VALUES(?,'fixture',?,?,'amd64',1,'downloaded')",(ident,version,version))
        path = http/'netboot'/'fixture'/version/'amd64'; path.mkdir(parents=True,mode=0o755)
        for name in ('linux','initrd.gz','firmware.cpio.gz'): (path/name).write_bytes(('fixture-'+name).encode())
        return path
    def queue(ident):
        return int(php("echo \\Admin\\Models\\OsImage::queueDelete((int)$argv[1]);",ident))
    def job(ident):
        with sqlite3.connect(database) as db:
            db.row_factory=sqlite3.Row
            return dict(db.execute('SELECT * FROM init_jobs WHERE id=?',(ident,)).fetchone())
    def run(ident): worker.run_bootstrap_job(job(ident))
    def state(ident): return sql('SELECT status FROM init_jobs WHERE id=?',(ident,))[0][0]

    path=image(1); number=queue(1)
    assert php("try { \\Admin\\Models\\OsImage::queueDelete((int)$argv[1]); exit(2); } catch (Throwable $e) { exit(0); }",1)==''
    run(number)
    assert state(number)=='success' and not sql('SELECT id FROM os_images WHERE id=1')
    assert not any((path/name).exists() for name in ('linux','initrd.gz','firmware.cpio.gz'))

    # The first rename happened but SQL did not commit: recover original before revalidating.
    path=image(2); number=queue(2); (path/'linux').rename(path/f'linux.pxe-delete-{number}')
    sql("UPDATE init_jobs SET status='running' WHERE id=?",(number,))
    worker.reap_running_zombies(); assert state(number)=='pending'
    run(number); assert state(number)=='success' and not list(path.glob('*.pxe-delete-*'))

    # SQL committed but cleanup did not: finish only the deterministic staged files.
    path=image(3); number=queue(3); (path/'linux').rename(path/f'linux.pxe-delete-{number}')
    sql('DELETE FROM os_images WHERE id=3')
    sql("UPDATE init_jobs SET status='running' WHERE id=?",(number,))
    worker.reap_running_zombies(); run(number)
    assert state(number)=='success' and not (path/f'linux.pxe-delete-{number}').exists()
    assert (path/'initrd.gz').exists()  # ordinary files are never touched in post-commit recovery

    # Ambiguous rollback, untrusted directory, and a symlink leave all data intact.
    path=image(4); number=queue(4); backup=path/f'linux.pxe-delete-{number}'; backup.write_bytes(b'preserve')
    run(number); assert state(number)=='failed' and (path/'linux').exists() and backup.read_bytes()==b'preserve'
    path=image(5); number=queue(5); path.chmod(0o777)
    run(number); assert state(number)=='failed' and (path/'linux').exists(); path.chmod(0o755)
    path=image(6); number=queue(6); (path/'linux').unlink(); (path/'linux').symlink_to('/etc/passwd')
    run(number); assert state(number)=='failed' and (path/'linux').is_symlink()

    # A newly started operation after enqueue blocks the worker's revalidation.
    path=image(7); number=queue(7)
    with sqlite3.connect(database) as db:
        db.execute("INSERT INTO machines(id,mac,status,install_config_json) VALUES(7,'02:00:00:00:00:07','installing',?)",(json.dumps({'distro':'fixture','version_full':'7'}),))
        db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES(7,'install','pending','fixture-token')")
    run(number); assert state(number)=='failed' and (path/'linux').exists()

    # Kill the actual worker and PHP reservation after a staging rename, while
    # an expensive DELETE trigger keeps the SQLite transaction uncommitted.
    path=image(8); number=queue(8)
    sql("CREATE TRIGGER fixture_slow_delete BEFORE DELETE ON os_images WHEN OLD.id=8 BEGIN SELECT randomblob(64000000); END")
    child = '''import importlib.util,sqlite3,sys
from pathlib import Path
root=Path(sys.argv[1]);sys.path.insert(0,str(root/'app'))
spec=importlib.util.spec_from_file_location('worker_child',root/'app/worker.py')
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
w.HTTP_ROOT=sys.argv[2];w.DB_PATH=sys.argv[3]
from image_delete import require_trusted_http_root
w.require_trusted_http_root=lambda value: require_trusted_http_root(value,sys.argv[4])
with sqlite3.connect(w.DB_PATH) as db:
 db.row_factory=sqlite3.Row;job=dict(db.execute('SELECT * FROM init_jobs WHERE id=?',(int(sys.argv[5]),)).fetchone())
w.run_bootstrap_job(job)
'''
    process=subprocess.Popen([sys.executable,'-c',child,str(ROOT),str(http),str(database),str(marker),str(number)],
                             env=os.environ.copy(),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    staged=path/f'linux.pxe-delete-{number}'
    deadline=time.monotonic()+10
    while time.monotonic()<deadline and not staged.exists() and process.poll() is None:
        time.sleep(.001)
    assert staged.exists() and process.poll() is None, 'could not observe live staged deletion'
    os.killpg(process.pid, 9); process.wait(timeout=10)
    assert sql('SELECT id FROM os_images WHERE id=8') and staged.exists()
    sql('DROP TRIGGER fixture_slow_delete')
    worker.reap_running_zombies(); assert state(number)=='pending'
    run(number); assert state(number)=='success' and not list(path.glob('*.pxe-delete-*'))

    # Stop the actual worker after SQLite has committed but before cleanup.
    # The test-only barrier makes the otherwise very short interval observable.
    path=image(11); number=queue(11); barrier=root/'after-commit'
    postcommit_child=child.replace(
        'w.run_bootstrap_job(job)',
        "import time\ndef pause_before_cleanup(*args):\n Path(sys.argv[6]).touch()\n time.sleep(60)\nw.cleanup_committed=pause_before_cleanup\nw.run_bootstrap_job(job)")
    process=subprocess.Popen([sys.executable,'-c',postcommit_child,str(ROOT),str(http),str(database),str(marker),str(number),str(barrier)],
                             env=os.environ.copy(),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
    deadline=time.monotonic()+10
    while time.monotonic()<deadline and not barrier.exists() and process.poll() is None:
        time.sleep(.001)
    assert barrier.exists() and process.poll() is None, 'could not observe committed deletion before cleanup'
    os.killpg(process.pid, 9); process.wait(timeout=10)
    assert not sql('SELECT id FROM os_images WHERE id=11')
    assert len(list(path.glob(f'*.pxe-delete-{number}'))) == 3
    worker.reap_running_zombies(); assert state(number)=='pending'
    run(number); assert state(number)=='success' and not list(path.glob('*.pxe-delete-*'))

    # The unprivileged child uses the parent's selected SQLite path even if
    # the historical web-writable configuration changes before it starts.
    path=image(9); number=queue(9)
    config=Path('/etc/pxe-tools/db.conf'); config.parent.mkdir(exist_ok=True)
    config.write_text('{"driver":"mysql","host":"fixture.invalid"}')
    try:
        run(number)
        assert state(number)=='success' and not sql('SELECT id FROM os_images WHERE id=9')
    finally:
        config.unlink()
    path=image(10); number=queue(10)
    sql("CREATE TRIGGER fixture_ignore_delete BEFORE DELETE ON os_images WHEN OLD.id=10 BEGIN SELECT RAISE(IGNORE); END")
    run(number)
    assert state(number)=='failed' and sql('SELECT id FROM os_images WHERE id=10') and (path/'linux').exists()
    sql('DROP TRIGGER fixture_ignore_delete')

    # Exercise the actual authenticated retry route, not just the SQL update.
    # An image job's log contains its immutable deletion metadata; a normal
    # job's log is still cleared when retried.
    path=image(12); number=queue(12); metadata=job(number)['log']
    path.chmod(0o777); run(number); path.chmod(0o755)
    assert state(number)=='failed' and (path/'linux').exists()
    stale_path=image(13); stale=queue(13); stale_metadata=job(stale)['log']
    sql("UPDATE init_jobs SET status='running',started_at='2000-01-01 00:00:00',updated_at='2000-01-01 00:00:00' WHERE id=?",(stale,))
    bulk_path=image(14); bulk=queue(14); bulk_metadata=job(bulk)['log']
    bulk_path.chmod(0o777); run(bulk); bulk_path.chmod(0o755)
    assert state(bulk)=='failed'
    with sqlite3.connect(database) as db:
        db.executemany('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)', [
            ('wizard_completed','1'), ('db_init_done','1'),
            ('admin_created','1'), ('init_completed','1')])
        cursor=db.execute("INSERT INTO init_jobs(job_type,status,log) VALUES('bootstrap:fixture','failed','ordinary-log')")
        ordinary=cursor.lastrowid
        cursor=db.execute("INSERT INTO init_jobs(job_type,status,log,started_at,updated_at) VALUES('bootstrap:recent','running','recent-log',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        recent=cursor.lastrowid
    sessions=root/'sessions'; sessions.mkdir(mode=0o700)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    server_env=dict(os.environ,AUTH_MODE='authentik',AUTHENTIK_TRUSTED_PROXY_CIDRS='127.0.0.1/32',
                    PXE_SESSION_DIR=str(sessions))
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(ROOT/'app/public/index.php')],
                            cwd=ROOT/'app/public',env=server_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl): return None
        client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()),NoRedirect)
        base=f'http://127.0.0.1:{port}'; identity={'X-Forwarded-User':'fixture-admin'}
        for attempt in range(80):
            try:
                with client.open(urllib.request.Request(base+'/settings',headers=identity),timeout=1) as response:
                    html=response.read().decode()
                break
            except urllib.error.URLError:
                time.sleep(.05)
        else: raise AssertionError('fixture web server unavailable')
        import re
        match=re.search(r'name="csrf_token" value="([^"]+)"',html)
        assert match, 'CSRF token missing from authenticated settings'
        for ident in (number,ordinary,stale,recent):
            request=urllib.request.Request(base+f'/api/init-retry-job/{ident}',
                data=urllib.parse.urlencode({'csrf_token':match.group(1)}).encode(),
                headers={**identity,'Origin':base})
            try: response=client.open(request,timeout=5)
            except urllib.error.HTTPError as error: response=error
            assert response.code==302, f'unexpected retry HTTP status: {response.code}'
            response.close()
        assert state(number)=='pending' and job(number)['log']==metadata, 'image retry discarded deletion metadata'
        assert state(ordinary)=='pending' and job(ordinary)['log']=='', 'ordinary retry did not clear its log'
        assert state(stale)=='pending' and job(stale)['log']==stale_metadata, 'stale image retry discarded metadata'
        assert state(recent)=='running' and job(recent)['log']=='recent-log', 'recent job was incorrectly retried'
        request=urllib.request.Request(base+'/api/init-retry',
            data=urllib.parse.urlencode({'csrf_token':match.group(1),'jobs':str(bulk)}).encode(),
            headers={**identity,'Origin':base})
        try: response=client.open(request,timeout=5)
        except urllib.error.HTTPError as error: response=error
        assert response.code==302, f'unexpected bulk retry HTTP status: {response.code}'
        response.close()
        assert state(bulk)=='pending' and job(bulk)['log']==bulk_metadata, 'bulk retry discarded deletion metadata'
        run(number)
        assert state(number)=='success' and not sql('SELECT id FROM os_images WHERE id=12')
        assert not any((path/name).exists() for name in ('linux','initrd.gz','firmware.cpio.gz'))
        run(stale)
        assert state(stale)=='success' and not sql('SELECT id FROM os_images WHERE id=13')
        assert not any((stale_path/name).exists() for name in ('linux','initrd.gz','firmware.cpio.gz'))
        run(bulk)
        assert state(bulk)=='success' and not sql('SELECT id FROM os_images WHERE id=14')
        assert not any((bulk_path/name).exists() for name in ('linux','initrd.gz','firmware.cpio.gz'))
    finally:
        server.terminate(); server.wait(timeout=5)
    print('Image deletion queue, guarded paths, restart recovery and dependency revalidation verified.')
