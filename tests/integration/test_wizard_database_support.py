#!/usr/bin/env python3
"""Exercise the real first-run HTTP routes with disposable database fixtures."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
CONFIG = Path('/etc/pxe-tools/db.conf')
if (os.environ.get('PXE_WIZARD_DB_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').exists() or CONFIG.exists()):
    raise SystemExit('Only run in a disposable, isolated container without db.conf')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


with tempfile.TemporaryDirectory(prefix='pxe-wizard-db-') as temporary:
    root = Path(temporary)
    db = root / 'fixture.sqlite'
    with sqlite3.connect(db) as conn:
        conn.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
    env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(db), PXE_SESSION_DIR=str(root / 'sessions'))
    router = root / 'router.php'
    router.write_text('<?php require ' + repr(str(ROOT / 'app/public/index.php')) + ';')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with (root / 'server.log').open('w') as log:
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(router)], env=env, stdout=log, stderr=log)
        try:
            client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
            def request(path, data=None):
                payload = urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    response = client.open(urllib.request.Request(f'http://127.0.0.1:{port}{path}', data=payload), timeout=5)
                except urllib.error.HTTPError as error:
                    response = error
                return response.code, response.headers, response.read().decode()
            for _ in range(50):
                try:
                    code, _, page = request('/init/db')
                    break
                except urllib.error.URLError:
                    time.sleep(.1)
            assert code == 200
            for driver in ('mysql', 'mariadb'):
                field = re.search(r'<input type="radio" name="driver" id="driver-' + driver + r'"[^>]*>', page).group()
                assert 'disabled' in field
            assert 'ne sont pas encore' in page
            token = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
            for driver in ('mysql', 'mariadb'):
                code, headers, _ = request('/init/db', {'driver':driver, 'csrf_token':token})
                assert code == 302 and headers['Location'] == '/init/db?error=unsupported'
                assert not CONFIG.exists()
                code, _, body = request('/api/init-db-test', {'driver':driver, 'csrf_token':token})
                assert code == 400 and 'pas encore pris en charge' in body
            CONFIG.parent.mkdir(parents=True, exist_ok=True)
            existing = json.dumps({'driver':'mysql', 'host':'127.0.0.1', 'database':'fixture',
                                   'username':'fixture', 'password':'fixture-secret'}).encode()
            CONFIG.write_bytes(existing)
            code, _, page = request('/init/db')
            assert code == 200 and 'configuration de base externe existante est conservée' in page
            assert 'fixture-secret' not in page and 'id="db-form"' not in page
            code, headers, _ = request('/init/db', {'driver':'sqlite', 'csrf_token':token})
            assert code == 302 and headers['Location'] == '/init/db?error=external_existing'
            assert CONFIG.read_bytes() == existing
            with sqlite3.connect(db) as conn:
                assert conn.execute('SELECT COUNT(*) FROM settings').fetchone()[0] == 0
            for invalid in (b'{"fixture-invalid":', b'{"driver":"sqlite","path":null}'):
                CONFIG.write_bytes(invalid)
                code, headers, _ = request('/init/db', {'driver':'sqlite', 'csrf_token':token})
                assert code == 302 and headers['Location'] == '/init/db?error=invalid_existing'
                assert CONFIG.read_bytes() == invalid
            custom_db = root / 'custom.sqlite'
            with sqlite3.connect(custom_db) as conn:
                conn.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            custom = json.dumps({'driver':'sqlite', 'path':str(custom_db), 'fixture_marker':'preserve'}).encode()
            CONFIG.write_bytes(custom)
            code, headers, body = request('/init/db', {'driver':'sqlite', 'csrf_token':token})
            assert code == 302 and headers['Location'] == '/init/admin', (code, headers.get('Location'), body[-500:])
            assert CONFIG.read_bytes() == custom
            with sqlite3.connect(custom_db) as conn:
                assert conn.execute("SELECT value FROM settings WHERE key='db_init_done'").fetchone() == ('1',)
            with sqlite3.connect(db) as conn:
                assert conn.execute('SELECT COUNT(*) FROM settings').fetchone()[0] == 0
            # Initial publication is atomic and refuses an already present file.
            CONFIG.unlink()
            php = '''spl_autoload_register(function($c){$f="/src/app/src/".str_replace("\\\\","/",substr($c,6)).".php";if(is_file($f))require_once $f;});
Admin\\Db::writeInitialConfig(['driver'=>'sqlite','path'=>$argv[1]]);
try { Admin\\Db::writeInitialConfig(['driver'=>'sqlite','path'=>'/different.sqlite']); exit(3); }
catch (RuntimeException $e) { echo "existing configuration preserved"; }
'''
            php = php.replace('/src/app/src/', str(ROOT / 'app/src') + '/')
            result = subprocess.run(['php','-r',php,str(custom_db)], capture_output=True, text=True, check=True)
            assert 'existing configuration preserved' in result.stdout
            assert json.loads(CONFIG.read_text()) == {'driver':'sqlite', 'path':str(custom_db)}
            print('Wizard rejects unsupported databases and preserves existing, invalid and custom SQLite configurations')
        finally:
            server.terminate()
            server.wait(timeout=10)
            if CONFIG.exists():
                CONFIG.unlink()
