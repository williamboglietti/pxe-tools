#!/usr/bin/env python3
"""Keep the worker on the same configured database as the web application."""
import contextlib
import io
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
import worker


class DatabaseSelectionTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pxe-worker-db-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.config = self.root / 'db.conf'
        self.stale = self.root / 'stale.sqlite'
        with sqlite3.connect(self.stale) as db:
            db.execute('CREATE TABLE init_jobs (id INTEGER PRIMARY KEY, status TEXT)')
            db.execute("INSERT INTO init_jobs VALUES (1, 'pending')")
        for name, value in [('DB_CONFIG_PATH', str(self.config)), ('DB_PATH', str(self.stale)),
                            ('DB_FALLBACK_PATH', str(self.stale)), ('DB_LEGACY_PATH', str(self.root / 'legacy.sqlite'))]:
            p = patch.object(worker, name, value)
            p.start()
            self.addCleanup(p.stop)
        p = patch.dict(os.environ, {}, clear=True)
        p.start()
        self.addCleanup(p.stop)

    def write_config(self, value):
        self.config.write_text(json.dumps(value))

    def test_configured_sqlite_path_takes_priority_over_environment(self):
        path = str(self.root / 'chosen.sqlite')
        self.write_config({'driver': 'sqlite', 'path': path})
        with patch.dict(os.environ, {'PXE_DB_DRIVER': 'mysql'}):
            self.assertEqual(worker.configured_worker_database(), (path, 'config'))

    def test_external_config_never_consumes_a_stale_sqlite_database(self):
        self.write_config({'driver': 'mysql', 'password': 'fixture-password'})
        before = self.stale.read_bytes()
        output = io.StringIO()
        with patch.object(worker, 'purge_unlock_dir'), \
             patch.object(worker, 'schema_ready', side_effect=AssertionError('stale database accessed')), \
             patch.object(worker.time, 'sleep', side_effect=KeyboardInterrupt), \
             contextlib.redirect_stdout(output):
            with self.assertRaises(KeyboardInterrupt):
                worker.main()
        self.assertEqual(self.stale.read_bytes(), before)
        self.assertIn('ne prend actuellement en charge que SQLite', output.getvalue())
        self.assertNotIn('fixture-password', output.getvalue())

    def test_invalid_config_fails_closed_without_exposing_contents(self):
        for content in ('not-json fixture-password', '{}', '{"driver":"sqlite","path":"relative"}'):
            with self.subTest(content=content):
                self.config.write_text(content)
                with self.assertRaises(RuntimeError) as error:
                    worker.configured_worker_database()
                self.assertNotIn('fixture-password', str(error.exception))

    def test_environment_external_driver_is_not_silently_ignored(self):
        for driver in ('mysql', 'mariadb', 'unknown'):
            with self.subTest(driver=driver), patch.dict(os.environ, {'PXE_DB_DRIVER':driver}):
                with self.assertRaises(RuntimeError):
                    worker.configured_worker_database()

    def test_schema_readiness_accepts_url_special_characters_in_filename(self):
        path = self.root / 'fixture?#.sqlite'
        with sqlite3.connect(path) as db:
            db.execute('CREATE TABLE init_jobs(id INTEGER)')
        with patch.object(worker, 'DB_PATH', str(path)):
            self.assertTrue(worker.schema_ready())

    def test_removed_config_uses_original_fallback_not_previous_config_path(self):
        self.write_config({'driver':'sqlite', 'path':str(self.root / 'configured.sqlite')})
        self.assertNotEqual(worker.configured_worker_database()[0], str(self.stale))
        self.config.unlink()
        with patch.object(worker, 'DB_PATH', str(self.root / 'configured.sqlite')):
            self.assertEqual(worker.configured_worker_database(), (str(self.stale), 'default'))

    def test_legacy_sqlite_matches_php_fallback_priority(self):
        legacy = Path(worker.DB_LEGACY_PATH)
        legacy.touch()
        self.assertEqual(worker.configured_worker_database(), (str(legacy), 'default'))
        with patch.dict(os.environ, {'PXE_DB_PATH':str(self.stale)}):
            self.assertEqual(worker.configured_worker_database(), (str(self.stale), 'default'))
        with patch.dict(os.environ, {'PXE_DB_DRIVER':'sqlite', 'ORCHESTRATOR_DB':str(legacy)}):
            self.assertEqual(worker.configured_worker_database(), ('/var/lib/pxe-tools/pxe-tools.sqlite', 'environment'))

    def test_changed_database_pauses_before_consuming_jobs(self):
        original = (str(self.stale), 'config')
        changed = (str(self.root / 'other.sqlite'), 'config')
        with patch.object(worker, 'configured_worker_database', side_effect=[original, changed]), \
             patch.object(worker, 'purge_unlock_dir'), patch.object(worker, 'schema_ready', return_value=True), \
             patch.object(worker, 'bootstrap_ansible_workspace'), patch.object(worker, 'reap_running_zombies', return_value=0), \
             patch.object(worker, 'reap_image_zombies', return_value=0), patch.object(worker, 'needs_bootstrap', return_value=False), \
             patch.object(worker, 'fetch_pending_init_job') as fetch, \
             patch.object(worker.time, 'sleep', side_effect=KeyboardInterrupt), \
             contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                worker.main()
        fetch.assert_not_called()


if __name__ == '__main__':
    unittest.main()
