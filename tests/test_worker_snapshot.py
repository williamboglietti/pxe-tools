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
import urllib.error
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
                metadata = {'tag_name':'apt-snapshots', 'draft':False, 'id': 123, 'assets':assets}
                result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
                with patch.dict(os.environ, {'GITHUB_REPOSITORY':'example/project'}), \
                     patch.object(module.subprocess, 'run', return_value=result), \
                     patch.object(module, 'live_release', return_value=live), \
                     patch.object(module, 'gh', side_effect=[json.dumps([assets]).encode(), data]), \
                     patch.object(module, 'validate_recovered'):
                    target = Path(d) / 'previous'
                    if succeeds:
                        module.recover(target, False)
                        self.assertEqual((target / 'snapshot/dists/stable/InRelease').read_bytes(), live)
                    else:
                        with self.assertRaises(ValueError): module.recover(target, False)
                        self.assertFalse(target.exists())

    def test_stale_live_release_cannot_select_an_older_snapshot(self):
        older = archive(content=b'old release')
        newer = archive(content=b'new release')
        assets = [{'name': f'snapshot-{run}-1.tar.gz', 'id': run, 'size': len(data),
                   'digest': 'sha256:' + hashlib.sha256(data).hexdigest()}
                  for run, data in [(1, older), (2, newer)]]
        metadata = {'tag_name': 'apt-snapshots', 'draft': False, 'id': 123, 'assets': assets}
        result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
        with tempfile.TemporaryDirectory() as d, \
             patch.dict(os.environ, {'GITHUB_REPOSITORY': 'example/project'}), \
             patch.object(module.subprocess, 'run', return_value=result), \
             patch.object(module, 'live_release', return_value=b'old release'), \
             patch.object(module, 'gh', side_effect=[json.dumps([assets]).encode(), newer]):
            with self.assertRaises(ValueError): module.recover(Path(d) / 'previous', False)

    def test_over_100_assets_are_paginated(self):
        data = archive()
        assets = [{'name': f'snapshot-{n}-1.tar.gz', 'id': n, 'size': len(data),
                   'digest': 'sha256:' + hashlib.sha256(data).hexdigest()} for n in range(1, 151)]
        metadata = {'tag_name': 'apt-snapshots', 'draft': False, 'id': 123}
        result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
        with tempfile.TemporaryDirectory() as d, \
             patch.dict(os.environ, {'GITHUB_REPOSITORY': 'example/project'}, clear=False), \
             patch.object(module.subprocess, 'run', return_value=result), \
             patch.object(module, 'live_release', return_value=b'signed release'), \
             patch.object(module, 'gh', side_effect=[json.dumps([assets[:100], assets[100:]]).encode(), data]) as gh, \
             patch.object(module, 'validate_recovered'):
            module.recover(Path(d) / 'previous', False)
            self.assertTrue(gh.call_args.args[-1].endswith('/150'))

    def test_503_does_not_authorize_bootstrap(self):
        error = urllib.error.HTTPError('https://example.invalid', 503, 'unavailable', {}, None)
        with patch.object(module.urllib.request, 'urlopen', side_effect=error):
            with self.assertRaises(urllib.error.HTTPError): module.live_release()

    def test_bootstrap_only_when_no_prior_assets(self):
        metadata = {'tag_name':'apt-snapshots', 'draft':False, 'id': 123, 'assets':[]}
        result = subprocess.CompletedProcess([], 0, json.dumps(metadata).encode())
        with tempfile.TemporaryDirectory() as d, \
             patch.dict(os.environ, {'GITHUB_REPOSITORY':'example/project'}), \
             patch.object(module.subprocess, 'run', return_value=result), \
             patch.object(module, 'live_release', return_value=None), \
             patch.object(module, 'gh', return_value=b'[[]]'):
            module.recover(Path(d) / 'previous', True)
            with self.assertRaises(ValueError): module.recover(Path(d) / 'previous', False)
        with tempfile.TemporaryDirectory() as d, \
             patch.dict(os.environ, {'GITHUB_REPOSITORY':'example/project'}), \
             patch.object(module.subprocess, 'run', return_value=result), \
             patch.object(module, 'live_release', side_effect=urllib.error.URLError('DNS failure')), \
             patch.object(module, 'gh', return_value=b'[[]]'):
            with self.assertRaises(urllib.error.URLError): module.recover(Path(d) / 'previous', True)


if __name__ == '__main__': unittest.main()
