#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""A purge may leave known Python caches, but no other file may be removed."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('prune_package_cache', ROOT/'packaging/native/prune-package-cache.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class CacheCleanupTest(unittest.TestCase):
    def test_known_caches_can_be_removed(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)/'pxe-tools'
            cache = root/'app/__pycache__'
            cache.mkdir(parents=True)
            for directory in (root, root/'app', cache): directory.chmod(0o755)
            for name in ('ansible_vault.cpython-313.pyc', 'database_selection.cpython-313.opt-1.pyc',
                         'image_delete.cpython-311.pyc', 'pxe_menu_lock.cpython-311.pyc'):
                item = cache/name; item.write_bytes(b'fixture bytecode'); item.chmod(0o644)
            module.prune(root)
            self.assertFalse(root.exists())

    def test_both_native_cache_roots_are_removed_together(self):
        with tempfile.TemporaryDirectory() as temp:
            root, opt = Path(temp)/'usr', Path(temp)/'opt'
            app = root/'app/__pycache__'
            scripts = opt/'scripts/__pycache__'
            app.mkdir(parents=True)
            scripts.mkdir(parents=True)
            for path in (root, root/'app', app, opt, opt/'scripts', scripts): path.chmod(0o755)
            for path in (app/'image_delete.cpython-311.pyc', scripts/'atomic_publish.cpython-311.pyc',
                         scripts/'prepare-debian-shim.cpython-311.pyc'):
                path.write_bytes(b'fixture bytecode'); path.chmod(0o644)
            module.prune(root, opt)
            self.assertFalse(root.exists())
            self.assertFalse(opt.exists())

    def test_unknown_opt_content_preserves_both_roots(self):
        with tempfile.TemporaryDirectory() as temp:
            root, opt = Path(temp)/'usr', Path(temp)/'opt'
            app = root/'app/__pycache__'; app.mkdir(parents=True)
            scripts = opt/'scripts/__pycache__'; scripts.mkdir(parents=True)
            for path in (root, root/'app', app, opt, opt/'scripts', scripts): path.chmod(0o755)
            cache = app/'image_delete.cpython-311.pyc'; cache.write_bytes(b'fixture'); cache.chmod(0o644)
            unknown = opt/'docker-compose.yml'; unknown.write_text('preserve'); unknown.chmod(0o644)
            with self.assertRaises(ValueError): module.prune(root, opt)
            self.assertEqual(cache.read_bytes(), b'fixture')
            self.assertEqual(unknown.read_text(), 'preserve')

    def test_unknown_file_preserves_everything(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)/'pxe-tools'
            cache = root/'app/__pycache__'
            cache.mkdir(parents=True)
            for directory in (root, root/'app', cache): directory.chmod(0o755)
            known = cache/'ansible_vault.cpython-313.pyc'; known.write_bytes(b'fixture bytecode')
            known.chmod(0o644)
            unknown = root/'app/user-data'; unknown.write_bytes(b'preserve me')
            with self.assertRaises(ValueError): module.prune(root)
            self.assertTrue(known.exists())
            self.assertEqual(unknown.read_bytes(), b'preserve me')

    def test_symlink_and_writable_directory_are_refused(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)/'pxe-tools'; root.mkdir()
            (root/'app').symlink_to(Path(temp), target_is_directory=True)
            with self.assertRaises(ValueError): module.prune(root)
            self.assertTrue((root/'app').is_symlink())
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)/'pxe-tools'; root.mkdir(mode=0o777); root.chmod(0o777)
            with self.assertRaises(ValueError): module.prune(root)
            self.assertTrue(root.exists())


if __name__ == '__main__': unittest.main()
