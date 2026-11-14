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
    def test_base_detection_requires_consistent_evidence(self):
        cases = [
            ({'ID': 'debian', 'VERSION_ID': '12', 'VERSION_CODENAME': 'bookworm'}, 'debian:bookworm'),
            ({'ID': 'debian', 'VERSION_ID': '13', 'VERSION_CODENAME': 'trixie'}, 'debian:trixie'),
            ({'ID': 'ubuntu', 'VERSION_ID': '26.04', 'VERSION_CODENAME': 'resolute'}, 'ubuntu:resolute'),
            ({'ID': 'example', 'ID_LIKE': 'debian ubuntu', 'UBUNTU_CODENAME': 'noble'}, 'ubuntu:noble'),
            ({'ID': 'example', 'ID_LIKE': 'ubuntu'}, ''),
            ({'ID': 'example', 'ID_LIKE': 'notubuntu', 'UBUNTU_CODENAME': 'noble'}, ''),
            ({'ID': 'ubuntu', 'VERSION_ID': '24.04', 'VERSION_CODENAME': 'resolute'}, ''),
        ]
        for release, expected in cases:
            result = subprocess.run(['/bin/sh', '-c', '. "$1"; pxe_tools_detect_base',
                                     'fixture', str(worker.BASE_HELPER)], env=release,
                                    capture_output=True, text=True, check=True)
            self.assertEqual(result.stdout.strip(), expected)

    def test_only_affected_noble_package_selects_fallback(self):
        for release in ({'ID': 'ubuntu', 'VERSION_ID': '24.04', 'VERSION_CODENAME': 'noble'},
                        {'ID': 'example', 'ID_LIKE': 'ubuntu debian', 'UBUNTU_CODENAME': 'noble'}):
            self.assertEqual(worker.worker_environment({}, release, '2023.4ubuntu1'),
                             {'DEBIAN_ARCHIVE_KEYRING': worker.FALLBACK})
            for version in ('2025.1ubuntu1', '2023.4ubuntu1.1', ''):
                self.assertEqual(worker.worker_environment({}, release, version), {})
        for release in ({'ID': 'debian', 'VERSION_ID': '13'},
                        {'ID': 'ubuntu', 'VERSION_ID': '26.04', 'VERSION_CODENAME': 'resolute'},
                        {'ID': 'example', 'ID_LIKE': 'ubuntu'},
                        {'ID': 'example', 'UBUNTU_CODENAME': 'noble'},
                        {'ID': 'ubuntu', 'VERSION_ID': '26.04', 'VERSION_CODENAME': 'noble'}, {}):
            self.assertEqual(worker.worker_environment({}, release, '2023.4ubuntu1'), {})

    def test_explicit_keyring_even_empty_is_never_overridden(self):
        release = {'ID': 'ubuntu', 'VERSION_ID': '24.04', 'VERSION_CODENAME': 'noble'}
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
