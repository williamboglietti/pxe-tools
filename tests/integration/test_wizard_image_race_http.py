#!/usr/bin/env python3
"""Exercise the real onboarding endpoint with a deterministic insertion race.

Run inside a disposable test container with no appliance configuration mounted.
"""
from pathlib import Path
import http.cookiejar
import os
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[2]
if os.environ.get('PXE_WIZARD_RACE_ISOLATED') != '1' or Path('/etc/pxe-tools/db.conf').exists():
    raise SystemExit('Run only in an isolated fixture without appliance data')

with tempfile.TemporaryDirectory(prefix='pxe-image-race-http-') as temporary:
    work = Path(temporary)
    db = work / 'test.sqlite'
    with sqlite3.connect(db) as connection:
        connection.executescript((root / 'app/db/schema.sqlite.sql').read_text())
        connection.executemany('INSERT INTO settings(key,value) VALUES (?,?)', [
            ('db_init_done', '1'), ('admin_created', '1'), ('wizard_completed', '0')])
    env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(db))
    bootstrap = "spl_autoload_register(function($c){$f=" + repr(str(root / 'app/src') + '/') + ".str_replace('\\\\','/',substr($c,6)).'.php';if(is_file($f))require_once $f;});"
    subprocess.run(['php', '-r', bootstrap + "Admin\\Models\\User::create('fixture','fixture-password');"],
                   env=env, check=True, stdout=subprocess.DEVNULL)
    # Prevent DNS/network calls in Debian discovery while keeping the real
    # catalogue's offline fallback and actual HTTP route execution.
    prepend = work / 'offline.php'
    prepend.write_text('''<?php
class OfflineHttpsFixture {
    public $context;
    public function stream_open($path,$mode,$options,&$openedPath) { return false; }
}
stream_wrapper_unregister('https');
stream_wrapper_register('https', OfflineHttpsFixture::class);
''')
    router = work / 'router.php'
    router.write_text('<?php require ' + repr(str(root / 'app/public/index.php')) + ';')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    log = work / 'server.log'
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl): return None
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
    base = f'http://127.0.0.1:{port}'
    def request(path, values=None):
        data = urllib.parse.urlencode(values).encode() if values is not None else None
        try: response = client.open(base + path, data=data, timeout=25)
        except urllib.error.HTTPError as error: response = error
        return response.code, response.headers, response.read().decode('utf-8', 'replace')
    def token(body):
        match = re.search(r'name="csrf_token" value="([^"]+)"', body)
        if not match: raise RuntimeError('Missing CSRF token')
        return match.group(1)

    with log.open('w') as output:
        server = subprocess.Popen(['php', '-d', 'auto_prepend_file=' + str(prepend), '-S',
                                   f'127.0.0.1:{port}', str(router)], env=env, stdout=output, stderr=output)
        try:
            for _ in range(100):
                try: code, _, body = request('/login'); break
                except urllib.error.URLError: time.sleep(.05)
            else: raise RuntimeError('Fixture HTTP server did not start')
            assert code == 200
            code, headers, _ = request('/login', {'username':'fixture', 'password':'fixture-password', 'csrf_token':token(body)})
            assert code == 302 and headers['Location'] == '/init/network'
            code, _, body = request('/init/network'); assert code == 200
            code, headers, _ = request('/init/network', {'csrf_token':token(body), 'pxe_interface':'lo',
                'pxe_ip':'127.0.0.1', 'pxe_subnet':'127.0.0.0/8', 'network_override':'1'})
            assert code == 302 and headers['Location'] == '/init/images'
            code, _, body = request('/init/images'); assert code == 200
            with sqlite3.connect(db) as connection:
                connection.executescript('''CREATE TRIGGER fixture_competing_insert BEFORE INSERT ON os_images
                    WHEN NEW.distro='debian' AND NEW.status='pending' BEGIN
                      INSERT INTO os_images(distro,version,arch,version_full,status,enabled,error_msg)
                      VALUES(NEW.distro,NEW.version,NEW.arch,NEW.version_full,'downloading',0,'fixture preserved');
                    END;''')
            code, headers, _ = request('/init/images', {'csrf_token':token(body)})
            if code != 302 or headers.get('Location') != '/init/done':
                raise RuntimeError('Image step failed with HTTP ' + str(code))
            with sqlite3.connect(db) as connection:
                rows = connection.execute("SELECT status,enabled,error_msg FROM os_images WHERE distro='debian'").fetchall()
                assert rows == [('downloading', 1, 'fixture preserved')], rows
            print('OK real onboarding POST handles competing image insert without resetting download')
        except BaseException:
            output.flush()
            print('Fixture PHP log:', log.read_text()[-4000:])
            raise
        finally:
            server.terminate()
            try: server.wait(timeout=10)
            except subprocess.TimeoutExpired:
                server.kill(); server.wait()
