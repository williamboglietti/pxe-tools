#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Atomic output must not follow substituted targets or parent directories."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('atomic_publish', ROOT/'docker/worker/scripts/atomic_publish.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class AtomicPublish(unittest.TestCase):
    def test_last_component_symlink_replaced_without_touching_target(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            root = Path(temp); victim = root/'victim'; victim.write_bytes(b'unchanged')
            target = root/'output'; target.symlink_to(victim)
            publisher.publish(target, b'rendered')
            self.assertEqual(victim.read_bytes(), b'unchanged')
            self.assertFalse(target.is_symlink())
            self.assertEqual(target.read_bytes(), b'rendered')

    def test_parent_symlink_is_refused(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            root = Path(temp); outside = root/'outside'; outside.mkdir()
            (root/'link').symlink_to(outside, target_is_directory=True)
            with self.assertRaises(OSError):
                publisher.publish(root/'link'/'output', b'rendered')
            self.assertFalse((outside/'output').exists())

    def test_parent_swap_during_publish_never_writes_through_new_link(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            root = Path(temp); parent = root/'parent'; parent.mkdir()
            moved = root/'moved'; outside = root/'outside'; outside.mkdir()
            victim = outside/'output'; victim.write_bytes(b'unchanged')
            original_replace = os.replace
            def swap(*args, **kwargs):
                parent.rename(moved)
                parent.symlink_to(outside, target_is_directory=True)
                return original_replace(*args, **kwargs)
            with mock.patch.object(publisher.os, 'replace', side_effect=swap):
                with self.assertRaises(OSError):
                    publisher.publish(parent/'output', b'rendered')
            self.assertEqual(victim.read_bytes(), b'unchanged')
            self.assertEqual((moved/'output').read_bytes(), b'rendered')

    def test_existing_owner_and_permissions_preserved(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            target = Path(temp)/'secret'; target.write_bytes(b'old'); target.chmod(0o600)
            before = target.stat()
            publisher.publish(target, b'new')
            after = target.stat()
            self.assertEqual(after.st_mode & 0o777, 0o600)
            self.assertEqual((before.st_uid,before.st_gid), (after.st_uid,after.st_gid))
            self.assertEqual(target.read_bytes(), b'new')

    def test_new_output_is_readable_under_restrictive_umask(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            target = Path(temp)/'output'
            old = os.umask(0o077)
            try:
                publisher.publish(target, b'rendered')
            finally:
                os.umask(old)
            self.assertEqual(target.stat().st_mode & 0o777, 0o644)

    def test_parent_traversal_is_refused(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            with self.assertRaises(ValueError):
                publisher.publish(Path(temp)/'..'/'output', b'rendered')

    def test_cleanup_removes_only_stale_mac_names_without_following_links(self):
        with tempfile.TemporaryDirectory(dir=os.path.realpath(tempfile.gettempdir())) as temp:
            root=Path(temp); dest=root/'menus'; batch=root/'batch'
            dest.mkdir(); batch.mkdir(mode=0o700)
            keep='01-02-00-00-00-00-01'; stale='01-02-00-00-00-00-02'
            (batch/keep).write_text('new'); (dest/keep).write_text('current')
            sentinel=root/'sentinel'; sentinel.write_text('unchanged')
            (dest/stale).symlink_to(sentinel); (dest/'01-other').write_text('preserved')
            publisher.clean_menus(dest,batch)
            self.assertEqual(sentinel.read_text(),'unchanged')
            self.assertFalse((dest/stale).exists())
            self.assertEqual((dest/keep).read_text(),'current')
            self.assertEqual((dest/'01-other').read_text(),'preserved')


if __name__ == '__main__':
    unittest.main()
