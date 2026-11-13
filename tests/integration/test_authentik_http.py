"""Exercise SSO trust and CSRF through the real front controller, on loopback only."""
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[2]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class AuthentikHttp(unittest.TestCase):
    def setUp(self):
        if Path('/etc/pxe-tools/db.conf').exists():
            raise RuntimeError('Refusing to run with appliance database configuration present')
        self.tmp = tempfile.TemporaryDirectory(prefix='pxe-authentik-http-')
        self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        self.db = self.directory / 'test.sqlite'
        with sqlite3.connect(self.db) as conn:
            conn.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            conn.executemany('INSERT INTO settings(key,value) VALUES(?,?)', [
                ('wizard_completed', '1'), ('db_init_done', '1'),
                ('admin_created', '1'), ('init_completed', '1'),
            ])
            conn.execute("INSERT INTO machines(id,mac,hostname) VALUES (1,'02:00:00:00:00:01','fixture')")
        self.sessions = self.directory / 'sessions'
        self.sessions.mkdir(mode=0o700)
        self.router = self.directory / 'router.php'
        self.router.write_text('<?php require ' + repr(str(ROOT / 'app/public/index.php')) + ';')

    def serve(self, trusted, mode='authentik'):
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(self.db),
                   PXE_SESSION_DIR=str(self.sessions), AUTH_MODE=mode,
                   AUTHENTIK_TRUSTED_PROXY_CIDRS=trusted)
        log = (self.directory / f'server-{port}.log').open('w')
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', str(self.router)],
                                  env=env, stdout=log, stderr=log)
        self.addCleanup(log.close)
        self.addCleanup(lambda: (server.terminate(), server.wait(timeout=5)) if server.poll() is None else None)
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
        base = f'http://127.0.0.1:{port}'
        for _ in range(40):
            try:
                client.open(base + '/healthz', timeout=1)
                break
            except urllib.error.HTTPError:
                # Any HTTP response proves the local server is listening.
                break
            except urllib.error.URLError:
                time.sleep(.05)
        return client, base

    @staticmethod
    def request(client, base, path, data=None, headers=None):
        req = urllib.request.Request(base + path, headers=headers or {},
                                     data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try:
            response = client.open(req, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        return response.code, response.headers, response.read().decode()

    def test_direct_forged_identity_and_forwarded_source_are_rejected(self):
        client, base = self.serve('192.0.2.7/32')
        status, headers, _ = self.request(client, base, '/settings', headers={
            'X-Forwarded-User': 'admin-fixture', 'X-Forwarded-For': '192.0.2.7',
        })
        self.assertEqual(status, 302)
        self.assertTrue(headers['Location'].startswith('/login'))
        status, headers, _ = self.request(client, base, '/api/machines/1suffix/task-progress', headers={
            'X-Forwarded-User': 'admin-fixture', 'X-Forwarded-For': '192.0.2.7',
        })
        self.assertEqual(status, 302)
        self.assertTrue(headers['Location'].startswith('/login'))
        status, _, _ = self.request(client, base, '/api/health')
        self.assertEqual(status, 200, 'PXE health endpoint remains public')
        status, _, _ = self.request(client, base, '/api/task-phase')
        self.assertEqual(status, 400, 'PXE callback reaches its own validation without admin auth')
        status, headers, _ = self.request(client, base, '/api/machines/1/task-progress', headers={
            'X-Forwarded-User': 'admin-fixture', 'X-Forwarded-For': '192.0.2.7',
        })
        self.assertEqual(status, 302)
        self.assertTrue(headers['Location'].startswith('/login'))

    def test_fresh_sso_instance_does_not_expose_initialization_to_direct_clients(self):
        with sqlite3.connect(self.db) as conn:
            conn.execute('DELETE FROM settings')
            conn.execute('DELETE FROM users')
        client, base = self.serve('192.0.2.7/32')
        status, headers, _ = self.request(client, base, '/init', headers={'X-Forwarded-User': 'forged'})
        self.assertEqual(status, 302)
        self.assertTrue(headers['Location'].startswith('/login'))

    def test_fresh_local_instance_keeps_initialization_available(self):
        with sqlite3.connect(self.db) as conn:
            conn.execute('DELETE FROM settings')
            conn.execute('DELETE FROM users')
        client, base = self.serve('', mode='local')
        status, headers, _ = self.request(client, base, '/init')
        self.assertIn(status, (200, 302))
        if status == 302:
            self.assertFalse(headers['Location'].startswith('/login'))

    def test_trusted_proxy_identity_still_requires_csrf_for_mutations(self):
        client, base = self.serve('127.0.0.1/32')
        identity = {'X-Forwarded-User': 'admin-fixture'}
        status, _, html = self.request(client, base, '/settings', headers=identity)
        self.assertEqual(status, 200)
        token = re.search(r'name="csrf_token" value="([^"]+)"', html)
        self.assertIsNotNone(token, 'SSO session must receive a CSRF token')
        status, _, _ = self.request(client, base, '/api/machines/1suffix/task-progress', headers=identity)
        self.assertEqual(status, 404, 'malformed machine IDs are rejected even for admins')
        status, _, _ = self.request(client, base, '/api/machines/1/task-progress', headers=identity)
        self.assertEqual(status, 200, 'a trusted administrator can request a valid progress fragment')
        status, _, _ = self.request(client, base, '/settings', data={}, headers=identity)
        self.assertEqual(status, 403)
        status, _, _ = self.request(client, base, '/api/init-retry', data={}, headers=identity)
        self.assertEqual(status, 403, 'administrative API mutations also require CSRF')
        status, _, _ = self.request(client, base, '/settings', data={'csrf_token': token.group(1)},
                                    headers={**identity, 'Origin': 'https://foreign.example'})
        self.assertEqual(status, 403)
        status, headers, _ = self.request(client, base, '/settings', data={'csrf_token': token.group(1)},
                                          headers={**identity, 'Origin': base})
        self.assertEqual(status, 302)
        self.assertEqual(headers['Location'], '/settings')

    def test_local_login_uses_forwarded_host_only_from_the_trusted_peer(self):
        hashed = subprocess.check_output(
            ['php', '-r', 'echo password_hash("fixture-password", PASSWORD_BCRYPT);'], text=True)
        with sqlite3.connect(self.db) as conn:
            conn.execute('INSERT INTO users(username,password_hash) VALUES(?,?)',
                         ('fixture-admin', hashed))
        for trusted, expected in (('192.0.2.7/32', 403), ('127.0.0.1/32', 302)):
            with self.subTest(trusted=trusted):
                client, base = self.serve(trusted, mode='local')
                status, _, html = self.request(client, base, '/login')
                self.assertEqual(status, 200)
                match = re.search(r'name="csrf_token" value="([^"]+)"', html)
                self.assertIsNotNone(match)
                status, _, _ = self.request(client, base, '/login', data={
                    'csrf_token': match.group(1), 'username': 'fixture-admin',
                    'password': 'fixture-password',
                })
                self.assertEqual(status, 302, 'local login must succeed')
                status, _, html = self.request(client, base, '/settings')
                self.assertEqual(status, 200, 'local administrator session must work')
                match = re.search(r'name="csrf_token" value="([^"]+)"', html)
                self.assertIsNotNone(match)
                forwarded = {'Origin': 'https://public.example',
                             'X-Forwarded-Host': 'public.example',
                             'X-Forwarded-For': '192.0.2.7'}
                status, _, _ = self.request(client, base, '/settings', data={}, headers=forwarded)
                self.assertEqual(status, 403, 'CSRF token remains mandatory')
                status, _, _ = self.request(client, base, '/settings',
                                            data={'csrf_token': match.group(1)}, headers=forwarded)
                self.assertEqual(status, expected, 'only the real trusted peer may supply forwarded host')

    def test_session_cookie_secure_follows_forwarded_protocol(self):
        # The deployment proxy must replace this header with the verified
        # external protocol; this test does not authenticate an upstream proxy.
        for protocol, expected_secure in (('http', False), ('https', True)):
            with self.subTest(protocol=protocol):
                client, base = self.serve('127.0.0.1/32')
                status, headers, _ = self.request(client, base, '/settings', headers={
                    'X-Forwarded-User': 'fixture-admin',
                    'X-Forwarded-Proto': protocol,
                })
                self.assertEqual(status, 200)
                cookies = headers.get_all('Set-Cookie', [])
                session = next((value for value in cookies if value.startswith('pxe_admin_sid=')), None)
                self.assertIsNotNone(session)
                flags = {part.strip().lower() for part in session.split(';')[1:]}
                self.assertEqual('secure' in flags, expected_secure)
                self.assertIn('httponly', flags)
                self.assertIn('samesite=strict', flags)

    def test_sso_identity_change_rotates_the_session_csrf_token(self):
        client, base = self.serve('127.0.0.1/32')
        status, _, first = self.request(client, base, '/settings', headers={'X-Forwarded-User': 'first-admin'})
        self.assertEqual(status, 200)
        first_token = re.search(r'name="csrf_token" value="([^"]+)"', first).group(1)
        status, _, second = self.request(client, base, '/settings', headers={'X-Forwarded-User': 'second-admin'})
        self.assertEqual(status, 200)
        second_token = re.search(r'name="csrf_token" value="([^"]+)"', second).group(1)
        self.assertFalse(first_token == second_token, 'CSRF token must change when the SSO identity changes')
        status, _, _ = self.request(client, base, '/settings', data={'csrf_token': first_token},
                                    headers={'X-Forwarded-User': 'second-admin', 'Origin': base})
        self.assertEqual(status, 403)

    def test_missing_sso_identity_invalidates_the_previous_session_token(self):
        client, base = self.serve('127.0.0.1/32')
        identity = {'X-Forwarded-User': 'first-admin'}
        status, _, html = self.request(client, base, '/settings', headers=identity)
        self.assertEqual(status, 200)
        token = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
        status, headers, _ = self.request(client, base, '/settings')
        self.assertEqual(status, 302)
        self.assertTrue(headers['Location'].startswith('/login'))
        status, _, _ = self.request(client, base, '/settings', data={'csrf_token': token},
                                    headers={**identity, 'Origin': base})
        self.assertEqual(status, 403)


if __name__ == '__main__':
    unittest.main()
