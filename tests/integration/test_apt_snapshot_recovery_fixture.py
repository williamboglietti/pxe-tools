#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise real signed snapshot recovery with a disposable APT fixture."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if os.environ.get('PXE_APT_ROTATION_FIXTURE') != '1':
    raise SystemExit('Run only in the disposable APT rotation fixture')
FIXTURE = Path(os.environ['PXE_ROTATION_FIXTURE_DIR'])
PRIMARY = os.environ['PXE_ROTATION_PRIMARY']
OLD_SIGNER = os.environ['PXE_ROTATION_OLD_SIGNER']
NEW_SIGNER = os.environ['PXE_ROTATION_NEW_SIGNER']


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


snapshot = load('pxe_fixture_snapshot', 'packaging/ci/worker-snapshot.py')


def archive(path):
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w:gz') as output:
        output.add(path, arcname='snapshot')
    return buf.getvalue()


def asset(run, data):
    return {'name': f'snapshot-{run}-1.tar.gz', 'id': run, 'size': len(data),
            'digest': 'sha256:' + hashlib.sha256(data).hexdigest()}


class SignedRecoveryFixture(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old = FIXTURE / 'old'
        cls.new = FIXTURE / 'new'
        cls.keyring = FIXTURE / 'new-public.gpg'
        cls.old_release = (cls.old / 'dists/stable/InRelease').read_bytes()
        cls.new_release = (cls.new / 'dists/stable/InRelease').read_bytes()
        cls.old_archive = archive(cls.old)
        cls.new_archive = archive(cls.new)

    def recover(self, destination, live, assets, downloaded, signer):
        metadata = {'tag_name': 'apt-snapshots', 'draft': False, 'id': 123}
        fake_subprocess = SimpleNamespace(PIPE=subprocess.PIPE, run=lambda *a, **k: subprocess.CompletedProcess(
            a, 0, json.dumps(metadata).encode()))
        with patch.dict(os.environ, {'GITHUB_REPOSITORY': 'fixture/project'}, clear=False), \
             patch.object(snapshot, 'subprocess', fake_subprocess), \
             patch.object(snapshot, 'live_release', return_value=live), \
             patch.object(snapshot, 'gh', side_effect=[json.dumps([assets]).encode(), downloaded]):
            snapshot.recover(destination, False, keyring=self.keyring, primary=PRIMARY, signing=signer)

    def test_upload_interrupted_before_deploy_refuses_stale_live_and_resumes_after_deploy(self):
        assets = [asset(1, self.old_archive), asset(2, self.new_archive)]
        with tempfile.TemporaryDirectory(prefix='pxe-recovery-fixture-') as tmp:
            target = Path(tmp) / 'previous'
            with self.assertRaisesRegex(ValueError, 'no persistent snapshot matches'):
                self.recover(target, self.old_release, assets, self.new_archive, NEW_SIGNER)
            self.assertFalse(target.exists())
            self.recover(target, self.new_release, assets, self.new_archive, NEW_SIGNER)
            self.assertEqual((target / 'snapshot/dists/stable/InRelease').read_bytes(), self.new_release)
            before = {p.relative_to(self.new): hashlib.sha256(p.read_bytes()).digest()
                      for p in self.new.rglob('*') if p.is_file()}
            after = {p.relative_to(target / 'snapshot'): hashlib.sha256(p.read_bytes()).digest()
                     for p in (target / 'snapshot').rglob('*') if p.is_file()}
            self.assertEqual(before, after)

    def test_wrong_pinned_signer_is_rejected_even_when_keyring_contains_both(self):
        with tempfile.TemporaryDirectory(prefix='pxe-wrong-signer-') as tmp:
            target = Path(tmp) / 'previous'
            with self.assertRaises(ValueError):
                self.recover(target, self.new_release, [asset(2, self.new_archive)],
                             self.new_archive, OLD_SIGNER)
            self.assertFalse(target.exists())

    def test_modified_object_is_rejected_without_exposing_a_partial_snapshot(self):
        with tempfile.TemporaryDirectory(prefix='pxe-corrupt-fixture-') as tmp:
            tmp = Path(tmp)
            corrupt = tmp / 'corrupt'
            shutil.copytree(self.new, corrupt)
            package = next((corrupt / 'pool').rglob('*.deb'))
            package.write_bytes(package.read_bytes() + b'tampered')
            data = archive(corrupt)
            target = tmp / 'previous'
            with self.assertRaises(ValueError):
                self.recover(target, self.new_release, [asset(2, data)], data, NEW_SIGNER)
            self.assertFalse(target.exists())
            self.assertFalse(any(tmp.glob('.pxe-recover-*')))

    def test_modified_signature_matching_live_is_still_rejected(self):
        with tempfile.TemporaryDirectory(prefix='pxe-invalid-signature-') as tmp:
            tmp = Path(tmp)
            corrupt = tmp / 'corrupt'
            shutil.copytree(self.old, corrupt)
            signed = corrupt / 'dists/stable/InRelease'
            signed.write_bytes(signed.read_bytes().replace(b'PXE Tools', b'PXE Fool!', 1))
            live = signed.read_bytes()
            self.assertNotEqual(live, self.old_release)
            data = archive(corrupt)
            target = tmp / 'previous'
            with self.assertRaises(ValueError):
                self.recover(target, live, [asset(1, data)], data, OLD_SIGNER)
            self.assertFalse(target.exists())
            self.assertFalse(any(tmp.glob('.pxe-recover-*')))


if __name__ == '__main__':
    unittest.main()
