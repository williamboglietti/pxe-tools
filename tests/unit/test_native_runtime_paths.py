# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

path = Path(__file__).resolve().parents[2] / 'docker/worker/scripts/validate-native-runtime.py'
spec = importlib.util.spec_from_file_location('native_runtime_guard', path)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


@unittest.skipUnless(os.geteuid() == 0 and Path('/root').is_dir(), 'requires a root-owned Linux fixture')
class NativeRuntimePaths(unittest.TestCase):
    def test_tree_creation_and_rejection_of_traversal_links_and_writable_parent(self):
        with tempfile.TemporaryDirectory(prefix='pxe-runtime-guard-', dir='/root') as temporary:
            root = Path(temporary)
            guard.ensure_http_tree(root, 'netboot/debian/13.7.0/amd64')
            self.assertTrue((root/'netboot/debian/13.7.0/amd64').is_dir())
            with self.assertRaises(ValueError):
                guard.ensure_http_tree(root, 'netboot/../../escape')
            (root/'netboot/bad').symlink_to('/etc', target_is_directory=True)
            with self.assertRaises(OSError):
                guard.ensure_http_tree(root, 'netboot/bad/escape')
            writable = root/'netboot/writable'; writable.mkdir(mode=0o777); writable.chmod(0o777)
            with self.assertRaises(ValueError):
                guard.ensure_http_tree(root, 'netboot/writable/escape')
            self.assertFalse((writable/'escape').exists())

    def test_mok_directory_is_private_and_rejects_symlinks(self):
        with tempfile.TemporaryDirectory(prefix='pxe-runtime-guard-', dir='/root') as temporary:
            root = Path(temporary); mok = root/'mok'
            guard.ensure_mok(mok)
            self.assertEqual(mok.stat().st_mode & 0o777, 0o700)
            link = root/'link'; link.symlink_to(mok, target_is_directory=True)
            with self.assertRaises(OSError):
                guard.ensure_mok(link)


if __name__ == '__main__':
    unittest.main()
