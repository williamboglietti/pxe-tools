#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Verify uninstall ownership guards after a fixture keyring changes."""
import hashlib
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

if os.environ.get('PXE_APT_ROTATION_FIXTURE') != '1':
    raise SystemExit('Run only in the disposable APT rotation fixture')
ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(os.environ['PXE_ROTATION_FIXTURE_DIR'])
spec = importlib.util.spec_from_file_location('pxe_uninstall_fixture', ROOT / 'packaging/native/uninstall-apt.py')
uninstall = importlib.util.module_from_spec(spec)
spec.loader.exec_module(uninstall)


class RotatedKeyOwnershipFixture(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='pxe-rotated-key-uninstall-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        keydir = self.root / 'etc/apt/keyrings'
        sources = self.root / 'etc/apt/sources.list.d'
        keydir.mkdir(parents=True)
        sources.mkdir(parents=True)
        self.key = keydir / 'pxe-tools-archive-keyring.gpg'
        self.old = (FIXTURE / 'old-public.gpg').read_bytes()
        self.new = (FIXTURE / 'new-public.gpg').read_bytes()
        self.assertNotEqual(self.old, self.new)
        self.key.write_bytes(self.old)
        self.anchor = keydir / ('.pxe-tools-archive-keyring.' + hashlib.sha256(self.old).hexdigest() + '.created')
        os.link(self.key, self.anchor)
        self.source = sources / 'pxe-tools.sources'
        self.source.write_text('Types: deb\nURIs: https://packages.pxetools.com/apt\nSuites: stable\n'
                               'Components: main\nArchitectures: amd64\n'
                               'Signed-By: /etc/apt/keyrings/pxe-tools-archive-keyring.gpg\n')
        source_anchor = sources / ('.pxe-tools-sources.' + hashlib.sha256(self.source.read_bytes()).hexdigest() + '.created')
        os.link(self.source, source_anchor)
        self.config = {'SOURCE': '/etc/apt/sources.list', 'PARTS': '/etc/apt/sources.list.d',
                       'TRUSTED': '/etc/apt/trusted.gpg', 'TRUSTEDPARTS': '/etc/apt/trusted.gpg.d/', 'ROOT': ''}

    def assert_rotation_is_preserved(self):
        self.assertEqual(uninstall.remove_apt_config(self.root, mounts=(), owner=None, apt_config=self.config),
                         (True, False))
        self.assertEqual(self.key.read_bytes(), self.new)
        self.assertTrue(self.anchor.exists())

    def test_in_place_update_preserves_the_modified_keyring(self):
        self.key.write_bytes(self.new)
        self.assert_rotation_is_preserved()

    def test_atomic_replacement_preserves_the_modified_keyring(self):
        replacement = self.key.with_name('new-keyring')
        replacement.write_bytes(self.new)
        os.replace(replacement, self.key)
        self.assert_rotation_is_preserved()


if __name__ == '__main__':
    unittest.main()
