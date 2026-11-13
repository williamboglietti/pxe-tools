#!/usr/bin/env python3
"""Exercise the restored publication tree check without changing real assets."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('guard', Path(__file__).resolve().parents[2] / 'app/cli/check-restored-publication.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


@unittest.skipUnless(os.geteuid() == 0, 'requires root-owned fixture under /root')
class GuardTests(unittest.TestCase):
    def test_safe_and_rejected_entries_are_preserved(self):
        with tempfile.TemporaryDirectory(prefix='pxe-publication-check-', dir='/root') as tmp:
            root = Path(tmp) / 'http'
            root.mkdir(mode=0o755)
            asset = root / 'image'
            asset.write_text('fixture')
            asset.chmod(0o644)
            guard.check(root)
            asset.chmod(0o666)
            with self.assertRaises(ValueError):
                guard.check(root)
            self.assertEqual(asset.read_text(), 'fixture')
            asset.chmod(0o644)
            link = root / 'link'
            link.symlink_to('image')
            with self.assertRaises(ValueError):
                guard.check(root)
            link.unlink()
            os.link(asset, link)
            with self.assertRaises(ValueError):
                guard.check(root)
            link.unlink()
            root.chmod(0o777)
            with self.assertRaises(ValueError):
                guard.check(root)
            self.assertEqual(asset.read_text(), 'fixture')

    def test_tftp_exception_and_service_readability(self):
        with tempfile.TemporaryDirectory(prefix='pxe-publication-check-', dir='/root') as tmp:
            root = Path(tmp) / 'tftp'
            root.mkdir(mode=0o755)
            menu = root / 'pxelinux.cfg'
            menu.mkdir(mode=0o775)
            menu.chmod(0o775)
            lock = root / '.pxe-menu.lock'
            lock.touch(mode=0o660)
            lock.chmod(0o660)
            asset = menu / 'default'
            asset.write_text('fixture')
            asset.chmod(0o644)
            guard.check(root, tftp=True)
            with self.assertRaises(ValueError):
                guard.check(root)
            asset.chmod(0o600)
            with self.assertRaises(ValueError):
                guard.check(root, tftp=True)
            asset.chmod(0o664)
            with self.assertRaises(ValueError):
                guard.check(root, tftp=True)
            self.assertEqual(asset.read_text(), 'fixture')

if __name__ == '__main__':
    unittest.main()
