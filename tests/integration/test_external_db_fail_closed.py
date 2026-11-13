#!/usr/bin/env python3
"""Real PHP/socket checks; only run in a disposable, network-isolated container."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
CONFIG = Path('/etc/pxe-tools/db.conf')
if (os.environ.get('PXE_EXTERNAL_DB_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').exists() or CONFIG.exists() or CONFIG.is_symlink()):
    raise SystemExit('Requires a disposable isolated root container with no db.conf')


class ExternalDatabaseFailClosed(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pxe-external-db-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.listener.bind(('127.0.0.1', 0))
        self.listener.listen(4)
        self.listener.settimeout(.25)
        self.addCleanup(self.listener.close)
        self.port = self.listener.getsockname()[1]
        CONFIG.parent.mkdir(parents=True, exist_ok=True)
        self.addCleanup(lambda: CONFIG.unlink(missing_ok=True))
        self.clean_env = dict(os.environ)
        self.clean_env['PXE_SERVICE_MANAGER'] = 'systemd'
        for key in ('PXE_DB_DRIVER', 'PXE_DB_HOST', 'PXE_DB_PORT', 'PXE_DB_DATABASE',
                    'PXE_DB_USERNAME', 'PXE_DB_PASSWORD', 'PXE_DB_PATH', 'ORCHESTRATOR_DB'):
            self.clean_env.pop(key, None)

    def no_connection(self):
        with self.assertRaises(socket.timeout):
            connection, _ = self.listener.accept()
            connection.close()

    def run_php(self, env, command):
        bootstrap = 'require $argv[1]; ' + command
        return subprocess.run(['php', '-r', bootstrap, str(ROOT / 'app/src/Db.php')],
                              env=env, text=True, capture_output=True, timeout=10)

    def test_external_db_never_connects_with_file_or_environment(self):
        for driver in ('mysql', 'mariadb', 'MYSQL'):
            for source in ('file', 'environment'):
                with self.subTest(driver=driver, source=source):
                    CONFIG.unlink(missing_ok=True)
                    env = self.clean_env.copy()
                    cfg = {'driver': driver, 'host': '127.0.0.1', 'port': self.port,
                           'database': 'fixture', 'username': 'fixture', 'password': 'fixture-secret'}
                    if source == 'file':
                        before = json.dumps(cfg).encode()
                        CONFIG.write_bytes(before)
                        # A valid file takes precedence over an unrelated environment.
                        env.update(PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(self.root / 'unused.sqlite'))
                    else:
                        env.update(PXE_DB_DRIVER=driver, PXE_DB_HOST='127.0.0.1',
                                   PXE_DB_PORT=str(self.port), PXE_DB_DATABASE='fixture',
                                   PXE_DB_USERNAME='fixture', PXE_DB_PASSWORD='fixture-secret')
                    result = self.run_php(env, '''try { \\Admin\\Db::get(); exit(10); }
                        catch (RuntimeException $e) { echo $e->getMessage(); }''')
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertIn('pas encore pris en charge', result.stdout)
                    self.assertNotIn('fixture-secret', result.stdout + result.stderr)
                    self.no_connection()
                    if source == 'file': self.assertEqual(CONFIG.read_bytes(), before)
                    else: self.assertFalse(CONFIG.exists())

    def test_test_connection_refuses_external_before_opening_socket(self):
        command = '''$r = \\Admin\\Db::testConnection(['driver'=>'mysql', 'host'=>'127.0.0.1',
            'port'=>(int)$argv[2], 'database'=>'fixture', 'username'=>'fixture', 'password'=>'fixture-secret']);
            if ($r['ok'] || !str_contains($r['error'], 'pas encore pris en charge')) exit(10);'''
        result = subprocess.run(['php', '-r', 'require $argv[1]; '+command,
                                 str(ROOT / 'app/src/Db.php'), str(self.port)],
                                env=self.clean_env, text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.no_connection()

    def test_sqlite_file_overrides_external_environment_and_remains_usable(self):
        path = self.root / 'fixture.sqlite'
        original = json.dumps({'driver':'sqlite', 'path':str(path), 'fixture_marker':'preserve',
                               'metadata': {'driver': 'mysql'}}).encode()
        CONFIG.write_bytes(original)
        env = dict(self.clean_env, PXE_DB_DRIVER='mysql', PXE_DB_HOST='127.0.0.1', PXE_DB_PORT=str(self.port))
        result = self.run_php(env, '''$db=\\Admin\\Db::get(); $db->exec('CREATE TABLE fixture(id INTEGER)');
                                       $db->exec('INSERT INTO fixture VALUES(7)');
                                       if ((int)$db->query('SELECT id FROM fixture')->fetchColumn() !== 7) exit(10);''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(CONFIG.read_bytes(), original)
        self.no_connection()

    def test_default_sqlite_remains_usable(self):
        path = self.root / 'default.sqlite'
        env = dict(self.clean_env, PXE_DB_PATH=str(path))
        result = self.run_php(env, '''$db=\\Admin\\Db::get(); $db->exec('CREATE TABLE fixture(id INTEGER)');
                                       $db->exec('INSERT INTO fixture VALUES(9)');
                                       if ((int)$db->query('SELECT id FROM fixture')->fetchColumn() !== 9) exit(10);''')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(CONFIG.exists())
        self.no_connection()

    def test_ambiguous_or_malformed_configuration_is_preserved_and_never_connects(self):
        cases = [b'{"driver":"mysql","driver":"sqlite"}',
                 b'{"driver":"sqlite","driver":"mysql"}',
                 b'{"driver":"sqlite","\\u0064river":"mysql"}',
                 b'{"driver":"sqlite",', b'{"driver":null}']
        for raw in cases:
            with self.subTest(case=raw):
                CONFIG.write_bytes(raw)
                env = dict(self.clean_env, PXE_DB_DRIVER='mysql', PXE_DB_HOST='127.0.0.1',
                           PXE_DB_PORT=str(self.port))
                result = self.run_php(env, '''try { \\Admin\\Db::get(); exit(10); }
                    catch (RuntimeException $e) { echo $e->getMessage(); }''')
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('invalide ou ambigu', result.stdout)
                self.assertEqual(CONFIG.read_bytes(), raw)
                self.no_connection()

    def test_init_refuses_external_without_spawning_client_or_changing_files(self):
        source = (ROOT / 'docker/pxe-tools/init/php-init.sh').read_text()
        for prefix in ('/etc/pxe-tools', '/var/lib/pxe-tools', '/run/php'):
            source = source.replace(prefix, str(self.root / prefix.lstrip('/')))
        init = self.root / 'init.sh'
        init.write_text(source)
        config = self.root / 'etc/pxe-tools/db.conf'
        config.parent.mkdir(parents=True)
        key = config.parent / 'master.key'
        key.write_bytes(bytes(range(32)))
        data = self.root / 'var/lib/pxe-tools/existing-data'
        data.parent.mkdir(parents=True)
        data.write_bytes(b'fixture-data')
        fake_bin = self.root / 'bin'
        fake_bin.mkdir()
        marker = self.root / 'client-called'
        client = fake_bin / 'mariadb'
        client.write_text('#!/bin/sh\nprintf called > "$PXE_FIXTURE_MARKER"\n')
        client.chmod(0o755)
        for driver in ('mysql', 'mariadb', 'MYSQL'):
            for source_type in ('file', 'environment'):
                with self.subTest(driver=driver, source=source_type):
                    config.unlink(missing_ok=True)
                    env = dict(self.clean_env, PATH=str(fake_bin)+':'+self.clean_env.get('PATH','/usr/bin:/bin'),
                               PXE_FIXTURE_MARKER=str(marker), PXE_DB_DRIVER=driver)
                    if source_type == 'file':
                        before = json.dumps({'driver':driver, 'password':'fixture-secret'}).encode()
                        config.write_bytes(before)
                        env['PXE_DB_DRIVER'] = 'sqlite'
                    result = subprocess.run(['/bin/sh', str(init)], env=env, text=True,
                                            capture_output=True, timeout=10)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('pas encore pris en charge', result.stderr)
                    self.assertNotIn('fixture-secret', result.stdout + result.stderr)
                    self.assertFalse(marker.exists())
                    self.assertEqual(key.read_bytes(), bytes(range(32)))
                    self.assertEqual(data.read_bytes(), b'fixture-data')
                    if source_type == 'file': self.assertEqual(config.read_bytes(), before)
                    else: self.assertFalse(config.exists())
                    self.no_connection()
        for raw in (b'{"driver":"mysql","driver":"sqlite"}',
                    b'{"driver":"sqlite","driver":"mysql"}',
                    b'{"driver":"sqlite","\\u0064river":"mysql"}', b'{"driver":"sqlite",'):
            with self.subTest(ambiguous=raw):
                config.write_bytes(raw)
                env = dict(self.clean_env, PATH=str(fake_bin)+':'+self.clean_env.get('PATH','/usr/bin:/bin'),
                           PXE_FIXTURE_MARKER=str(marker), PXE_DB_DRIVER='mysql')
                result = subprocess.run(['/bin/sh', str(init)], env=env, text=True,
                                        capture_output=True, timeout=10)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('invalide ou ambigu', result.stderr)
                self.assertEqual(config.read_bytes(), raw)
                self.assertEqual(key.read_bytes(), bytes(range(32)))
                self.assertEqual(data.read_bytes(), b'fixture-data')
                self.assertFalse(marker.exists())
                self.no_connection()


if __name__ == '__main__':
    unittest.main()
