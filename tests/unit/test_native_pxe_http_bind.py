#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the native PXE HTTP renderer with an isolated SQLite fixture."""
from contextlib import closing
import importlib.util
import io
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import tarfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pxe_http_renderer', ROOT / 'packaging/native/render-pxe-http.py')
renderer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(renderer)


class RendererTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        root = Path(self.work.name)
        self.db, self.template, self.output = root / 'db', root / 'template', root / 'pxe.conf'
        self.template.write_text('server { listen @@PXE_IPV4@@:80 default_server; listen 127.0.0.1:80; }\n')
        with closing(sqlite3.connect(self.db)) as db, db:
            db.execute('CREATE TABLE settings (key TEXT, value TEXT)')
            db.executemany('INSERT INTO settings VALUES (?, ?)', [('pxe_interface', 'eth1'), ('pxe_ip', '192.0.2.1')])
        self.ip_result = json.dumps([{'ifname': 'eth1', 'addr_info': [{'family': 'inet', 'local': '192.0.2.1'}]}]).encode()

    def run_renderer(self, ip_result=None):
        with patch.object(renderer.subprocess, 'check_output', return_value=self.ip_result if ip_result is None else ip_result) as call:
            renderer.render(self.db, self.template, self.output, wait_seconds=0)
            call.assert_called_once_with(['ip', '-j', '-4', 'addr', 'show', 'dev', 'eth1'], timeout=10, stderr=subprocess.DEVNULL)

    def change(self, key, value):
        with closing(sqlite3.connect(self.db)) as db, db:
            db.execute('UPDATE settings SET value = ? WHERE key = ?', (value, key))

    def test_real_package_template_keeps_loopback_without_wildcards(self):
        builder_spec = importlib.util.spec_from_file_location('native_builder', ROOT / 'packaging/native/build-local-package.py')
        builder = importlib.util.module_from_spec(builder_spec)
        builder_spec.loader.exec_module(builder)
        package = Path(self.work.name) / 'fixture.deb'
        previous_umask = os.umask(0o002)
        try:
            builder.build(package, '0.1.0~bindingtest')
        finally:
            os.umask(previous_umask)
        pre_depends = subprocess.check_output(['dpkg-deb', '--field', str(package), 'Pre-Depends'], text=True).strip()
        self.assertIn('python3 (>= 3.11)', pre_depends)
        payload = subprocess.check_output(['dpkg-deb', '--fsys-tarfile', str(package)])
        with tarfile.open(fileobj=io.BytesIO(payload)) as archive:
            template = archive.extractfile('./usr/lib/pxe-tools/native/pxe.conf').read().decode()
            for member in archive.getmembers():
                if member.isdir() or member.isfile():
                    self.assertEqual(member.mode & 0o7022, 0, member.name)
        self.assertIn('listen @@PXE_IPV4@@:80 default_server;', template)
        self.assertIn('listen 127.0.0.1:80;', template)
        self.assertNotIn('listen 80 default_server;', template)
        self.assertNotIn('listen [::]:80', template)

    def test_renders_only_selected_ipv4(self):
        self.run_renderer()
        self.assertEqual(self.output.read_text(), 'server { listen 192.0.2.1:80 default_server; listen 127.0.0.1:80; }\n')
        self.assertEqual(self.output.stat().st_mode & 0o777, 0o644)

    def test_rejects_missing_and_duplicate_settings(self):
        with closing(sqlite3.connect(self.db)) as db, db:
            db.execute("DELETE FROM settings WHERE key = 'pxe_ip'")
        with self.assertRaises(ValueError): self.run_renderer()
        with closing(sqlite3.connect(self.db)) as db, db:
            db.executemany('INSERT INTO settings VALUES (?, ?)', [('pxe_ip', '192.0.2.1'), ('pxe_ip', '192.0.2.1')])
        with self.assertRaises(ValueError): self.run_renderer()
        self.assertFalse(self.output.exists())

    def test_rejects_unassigned_address_or_wrong_interface(self):
        for result in (b'[]', b'[{"ifname":"eth0","addr_info":[]}]', b'[{"ifname":"eth1","addr_info":[]}]'):
            with self.subTest(result=result), self.assertRaises(ValueError): self.run_renderer(result)
        self.assertFalse(self.output.exists())

    def test_rejects_injected_invalid_and_wildcard_addresses(self):
        for value in ('0.0.0.0', '::1', '127.0.0.1', '224.0.0.1', '192.0.2.1; listen 80'):
            with self.subTest(value=value):
                self.change('pxe_ip', value)
                with self.assertRaises(ValueError): self.run_renderer()
        self.assertFalse(self.output.exists())

    def test_missing_database_not_created(self):
        self.db.unlink()
        with self.assertRaises(FileNotFoundError): self.run_renderer()
        self.assertFalse(self.db.exists())

    def test_legacy_environment_fallback_and_explicit_empty_value(self):
        with closing(sqlite3.connect(self.db)) as db, db:
            db.execute('DELETE FROM settings')
        env = {'PXE_INTERFACE': 'eth1', 'ORCHESTRATOR_IP': '192.0.2.1'}
        with patch.object(renderer.subprocess, 'check_output', return_value=self.ip_result):
            renderer.render(self.db, self.template, self.output, environ=env)
        self.assertIn('192.0.2.1:80', self.output.read_text())
        with closing(sqlite3.connect(self.db)) as db, db:
            db.execute('INSERT INTO settings VALUES (?, ?)', ('pxe_ip', ''))
        with self.assertRaises(ValueError):
            renderer.render(self.db, self.template, self.output, environ=env)

    def test_legacy_environment_without_database_only_when_explicitly_allowed(self):
        self.db.unlink()
        env = {'PXE_INTERFACE': 'eth1', 'ORCHESTRATOR_IP': '192.0.2.1'}
        with patch.object(renderer.subprocess, 'check_output', return_value=self.ip_result):
            renderer.render(self.db, self.template, self.output, environ=env, allow_missing_db=True)
        self.assertFalse(self.db.exists())
        self.assertIn('192.0.2.1:80', self.output.read_text())

    def test_waits_for_delayed_address(self):
        missing = b'[{"ifname":"eth1","addr_info":[]}]'
        with patch.object(renderer.subprocess, 'check_output', side_effect=[missing, self.ip_result]) as call:
            renderer.render(self.db, self.template, self.output, wait_seconds=1, poll_interval=0.001)
        self.assertEqual(call.call_count, 2)
        self.assertIn('192.0.2.1:80', self.output.read_text())

    def test_wait_is_bounded_when_interface_never_appears(self):
        with patch.object(renderer.subprocess, 'check_output', side_effect=subprocess.CalledProcessError(1, 'ip')) as call:
            with self.assertRaises(ValueError):
                renderer.render(self.db, self.template, self.output, wait_seconds=0.01, poll_interval=0.001)
        self.assertGreater(call.call_count, 1)
        self.assertFalse(self.output.exists())

    def test_invalid_template_not_published(self):
        self.template.write_text('server { listen 80; }')
        with self.assertRaises(ValueError): self.run_renderer()
        self.assertFalse(self.output.exists())

    def test_missing_interface_or_ip_command_fails(self):
        with patch.object(renderer.subprocess, 'check_output', side_effect=subprocess.CalledProcessError(1, 'ip')):
            with self.assertRaises(ValueError):
                renderer.render(self.db, self.template, self.output, wait_seconds=0)
        self.assertFalse(self.output.exists())


if __name__ == '__main__':
    unittest.main()
