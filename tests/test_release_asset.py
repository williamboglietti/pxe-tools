#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Validate release selection and integrity without a GitHub release."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / 'packaging/native/verify-release-asset.py'


class ReleaseAssetTests(unittest.TestCase):
    def test_published_release_and_rejections(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            package = root / 'pxe-tools_1.0_amd64.deb'
            package.write_bytes(b'fixture')
            release = {'tag_name': 'v1.0', 'draft': False, 'prerelease': False,
                       'published_at': '2026-11-12T12:00:00Z', 'assets': [{
                           'name': package.name, 'state': 'uploaded', 'size': 7,
                           'digest': 'sha256:' + hashlib.sha256(b'fixture').hexdigest()}]}
            metadata = root / 'release.json'

            def check(expected):
                metadata.write_text(json.dumps(release))
                result = subprocess.run(['python3', str(VERIFIER), '--release-json', str(metadata),
                                         '--tag', 'v1.0', '--asset', str(package)], capture_output=True)
                self.assertEqual(result.returncode == 0, expected, result.stderr.decode())

            check(True)
            release['draft'] = True
            check(False)
            release['draft'] = False
            release['prerelease'] = True
            check(False)
            release['prerelease'] = False
            release['assets'][0]['digest'] = None
            check(False)
            release['assets'][0]['digest'] = 'sha256:' + '0' * 64
            check(False)
            release['assets'][0]['digest'] = 'sha256:' + hashlib.sha256(b'fixture').hexdigest()
            release['assets'].append(release['assets'][0].copy())
            check(False)


if __name__ == '__main__':
    unittest.main()
