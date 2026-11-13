#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the root init script against disposable paths in an isolated container."""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
if (os.environ.get('PXE_INIT_PATH_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').exists()):
    raise SystemExit('Requires a disposable root container and PXE_INIT_PATH_TEST=1')


class NativeInitSqlitePathGuard(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='pxe-init-path-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.root.chmod(0o755)
        self.conf = self.root / 'conf'
        self.data = self.root / 'data'
        self.victim = self.root / 'victim'
        self.app = self.root / 'app'
        for path in (self.conf, self.data, self.victim, self.app / 'db'):
            path.mkdir(parents=True)
        (self.conf / 'master.key').write_bytes(bytes(range(32)))
        (self.conf / 'master.key').chmod(0o600)
        (self.app / 'db/schema.sqlite.sql').write_text('CREATE TABLE fixture (id INTEGER);\n')
        marker = self.data / 'migrated'
        (self.app / 'db/migrate.php').write_text(
            '<?php file_put_contents(' + json.dumps(str(marker)) + ', "ok");\n')
        source = (ROOT / 'docker/pxe-tools/init/php-init.sh').read_text()
        for original, replacement in (('/etc/pxe-tools', self.conf),
                                      ('/var/lib/pxe-tools', self.data),
                                      ('/run/php', self.root / 'run-php')):
            source = source.replace(original, str(replacement))
        self.script = self.root / 'php-init.sh'
        self.script.write_text(source)
        self.env = dict(os.environ, PXE_APP_DIR=str(self.app), PXE_SERVICE_MANAGER='systemd')
        for name in ('PXE_DB_DRIVER', 'PXE_DB_PATH', 'ORCHESTRATOR_DB'):
            self.env.pop(name, None)

    def run_init(self, path):
        (self.conf / 'db.conf').write_text(json.dumps({'driver': 'sqlite', 'path': str(path)}))
        (self.conf / 'db.conf').chmod(0o600)
        return subprocess.run(['/bin/sh', str(self.script)], env=self.env,
                              text=True, capture_output=True, timeout=20)

    def test_external_parent_is_refused_without_changing_ownership(self):
        result = self.run_init(self.victim / 'other.sqlite')
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('chemin SQLite hors du répertoire', result.stderr)
        self.assertEqual(self.victim.stat().st_uid, 0)
        self.assertFalse((self.victim / 'other.sqlite').exists())

    def test_database_symlink_is_refused_without_following_it(self):
        target = self.victim / 'keep'
        target.write_bytes(b'root-owned fixture')
        (self.data / 'linked.sqlite').symlink_to(target)
        result = self.run_init(self.data / 'linked.sqlite')
        self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(target.read_bytes(), b'root-owned fixture')
        self.assertEqual(target.stat().st_uid, 0)

    def test_normal_database_and_migration_run_as_web_user(self):
        database = self.data / 'chosen.sqlite'
        result = self.run_init(database)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        web_uid = subprocess.check_output(['id', '-u', 'www-data'], text=True).strip()
        self.assertEqual(database.stat().st_uid, int(web_uid))
        marker = self.data / 'migrated'
        self.assertEqual(marker.read_text(), 'ok')
        self.assertEqual(marker.stat().st_uid, int(web_uid))
        with sqlite3.connect(database) as connection:
            self.assertEqual(connection.execute(
                "SELECT name FROM sqlite_master WHERE name='fixture'").fetchone(), ('fixture',))

    def test_native_init_does_not_require_a_docker_http_volume(self):
        self.env['HTTP_ROOT'] = str(self.root / 'missing-http-root')
        result = self.run_init(self.data / 'native.sqlite')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.root / 'missing-http-root').exists())

    def test_restored_root_owned_database_remains_usable(self):
        database = self.data / 'restored.sqlite'
        with sqlite3.connect(database) as connection:
            connection.execute('CREATE TABLE fixture (id INTEGER)')
        database.chmod(0o600)
        result = self.run_init(database)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        web_uid = int(subprocess.check_output(['id', '-u', 'www-data'], text=True).strip())
        self.assertEqual(database.stat().st_uid, web_uid)
        self.assertEqual((self.data / 'migrated').stat().st_uid, web_uid)

    def test_empty_legacy_database_remains_available_to_wizard(self):
        database = self.data / 'pxe-tools.sqlite'
        database.touch()
        result = subprocess.run(['/bin/sh', str(self.script)], env=self.env,
                                text=True, capture_output=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        web_uid = int(subprocess.check_output(['id', '-u', 'www-data'], text=True).strip())
        self.assertEqual(self.data.stat().st_uid, web_uid)
        self.assertEqual(database.stat().st_uid, web_uid)
        self.assertEqual(database.stat().st_size, 0)


if __name__ == '__main__':
    unittest.main()
