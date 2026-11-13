# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
import os
from pathlib import Path
import subprocess
import unittest

path = Path(__file__).resolve().parents[2] / 'packaging/native/start-worker.py'
spec = importlib.util.spec_from_file_location('start_worker', path)
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class NativeWorkerKeyring(unittest.TestCase):
    def test_only_affected_ubuntu_package_selects_fallback(self):
        release = {'ID': 'ubuntu', 'VERSION_ID': '24.04'}
        self.assertEqual(worker.worker_environment({}, release, '2023.4ubuntu1'),
                         {'DEBIAN_ARCHIVE_KEYRING': worker.FALLBACK})
        for version in ('2025.1ubuntu1', '2023.4ubuntu1.1', ''):
            self.assertEqual(worker.worker_environment({}, release, version), {})
        for release in ({'ID': 'debian', 'VERSION_ID': '13'},
                        {'ID': 'ubuntu', 'VERSION_ID': '26.04'}, {}):
            self.assertEqual(worker.worker_environment({}, release, '2023.4ubuntu1'), {})

    def test_explicit_keyring_even_empty_is_never_overridden(self):
        release = {'ID': 'ubuntu', 'VERSION_ID': '24.04'}
        for value in ('/admin/keyring.gpg', ''):
            env = {'DEBIAN_ARCHIVE_KEYRING': value}
            self.assertEqual(worker.worker_environment(env, release, '2023.4ubuntu1'), env)

    def test_empty_explicit_keyring_fails_closed_in_real_verifier(self):
        library = path.parents[2] / 'docker/worker/scripts/lib-image-verify.sh'
        env = dict(os.environ, DEBIAN_ARCHIVE_KEYRING='')
        result = subprocess.run(['bash', '-c',
                                 'source "$1"; _iv_gpgv "$DEBIAN_ARCHIVE_KEYRING" "$2"',
                                 'fixture', str(library), str(path)],
                                env=env, capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_packaged_keyring_matches_reviewed_debian_artifact(self):
        import hashlib
        keyring = path.parent / 'keyrings/debian-archive-2025.1.gpg'
        self.assertEqual(hashlib.sha256(keyring.read_bytes()).hexdigest(),
                         '506b815cbb32d9b6066b4a2aa524071e071761e7e7f68c3ac74f3061ba852017')


if __name__ == '__main__':
    unittest.main()
