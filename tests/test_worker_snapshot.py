# SPDX-License-Identifier: LGPL-3.0-only
"""Tests for persistent snapshot recovery without GitHub or Cloudflare access."""
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worker_snapshot', Path(__file__).resolve().parents[1] / 'packaging/ci/worker-snapshot.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


def archive(name='snapshot/dists/stable/InRelease', content=b'signed release'):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as tar:
        info = tarfile.TarInfo(name)
        info.size = len(content)
        tar.addfile(info, io.BytesIO(content))
    return output.getvalue()


class RecoveryTests(unittest.TestCase):
    def test_recovery_requires_exact_live_content_and_safe_archive(self):
        for name, live, succeeds in [('snapshot/dists/stable/InRelease', b'signed release', True),
                                     ('snapshot/dists/stable/InRelease', b'other', False),
                                     ('snapshot/../../bad', b'signed release', False)]:
            with self.subTest(name=name, live=live), tempfile.TemporaryDirectory() as d:
                data = archive(name)
                assets = [{'name':'snapshot-1-1.tar.gz', 'id': 42, 'size': len(data),
                           'digest':'sha256:' + hashlib.sha256(data).hexdigest()}]
                metadata = {'tag_name':'apt-snapshots', 'draft':False, 'assets':assets}
                result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
                with patch.dict(os.environ, {'GITHUB_REPOSITORY':'example/project'}), \
                     patch.object(module.subprocess, 'run', return_value=result), \
                     patch.object(module, 'live_release', return_value=live), \
                     patch.object(module, 'gh', return_value=data):
                    target = Path(d) / 'previous'
                    if succeeds:
                        module.recover(target, False)
                        self.assertEqual((target / 'snapshot/dists/stable/InRelease').read_bytes(), live)
                    else:
                        with self.assertRaises(ValueError): module.recover(target, False)
                        self.assertFalse(target.exists())

    def test_bootstrap_only_when_no_prior_assets(self):
        metadata = {'tag_name':'apt-snapshots', 'draft':False, 'assets':[]}
        result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
        with tempfile.TemporaryDirectory() as d, \
             patch.dict(os.environ, {'GITHUB_REPOSITORY':'example/project'}), \
             patch.object(module.subprocess, 'run', return_value=result), \
             patch.object(module, 'live_release', return_value=None):
            module.recover(Path(d) / 'previous', True)
            with self.assertRaises(ValueError): module.recover(Path(d) / 'previous', False)


if __name__ == '__main__': unittest.main()
