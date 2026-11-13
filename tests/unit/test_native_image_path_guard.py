# SPDX-License-Identifier: LGPL-3.0-only
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'docker/worker/scripts'


class ImagePathGuard(unittest.TestCase):
    @unittest.skipIf(Path('/etc/pxe-tools/db.conf').exists(), 'requires a disposable environment')
    def test_rejects_invalid_identifiers_and_catalogue_components_before_writing(self):
        with tempfile.TemporaryDirectory(prefix='pxe-image-guard-') as temporary:
            root = Path(temporary); db = root/'image.db'; http = root/'http'; http.mkdir()
            with sqlite3.connect(db) as conn:
                conn.execute('''CREATE TABLE os_images(id INTEGER, distro TEXT, version TEXT,
                  version_full TEXT, arch TEXT, kernel_url TEXT, initrd_url TEXT,
                  codename TEXT, firmware_url TEXT, iso_url TEXT, extract_method TEXT)''')
                conn.execute('INSERT INTO os_images VALUES (1,?,?,?,?,?,?,?,?,?,?)',
                             ('../outside', 'trixie', '13.7.0', 'amd64', 'fixture', 'fixture',
                              'trixie', '', 'fixture', 'iso_extract'))
            env = dict(os.environ, PXE_APP_DIR=str(ROOT/'app'), PXE_DB_DRIVER='sqlite',
                       PXE_DB_PATH=str(db), HTTP_ROOT=str(http), PXE_SERVICE_MANAGER='systemd')
            for name in ('bootstrap-download-image.sh', 'bootstrap-download-image-iso.sh'):
                for image_id in ('1', '1;touch /tmp/should-not-run'):
                    result = subprocess.run(['bash', str(SCRIPTS/name)],
                                            env=dict(env, IMAGE_ID=image_id), capture_output=True)
                    self.assertNotEqual(result.returncode, 0, name)
                    self.assertEqual(list(http.iterdir()), [], name)


if __name__ == '__main__':
    unittest.main()
