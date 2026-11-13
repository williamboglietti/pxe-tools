#!/usr/bin/env python3
"""Disposable-container checks for verified image staging and publication."""
from datetime import datetime, timezone
from email.utils import format_datetime
from contextlib import closing
import hashlib
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest


ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / 'docker/worker/scripts'
if os.environ.get('PXE_DOWNLOAD_ISOLATED') != '1' or not Path('/.dockerenv').exists():
    raise SystemExit('Run only in a disposable container on the isolated PXE test VM')
if os.geteuid() != 0:
    raise SystemExit('The isolated test must exercise the root worker boundary')


class ImagePublication(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pxe-image-publication-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.mirror = self.root / 'mirror'
        self.mirror.mkdir()
        self.http = self.root / 'http'
        self.http.mkdir()
        self.app = self.root / 'app'
        self.app.mkdir()
        (self.app / 'database_selection.py').write_text(
            'import os\nprint(os.environ["PXE_DB_PATH"])\n', encoding='ascii')
        self.db_path = self.root / 'images.sqlite'
        with closing(sqlite3.connect(self.db_path)) as db:
            with db:
                db.executescript('''
                CREATE TABLE os_images (
                    id INTEGER PRIMARY KEY, distro TEXT, version TEXT, version_full TEXT,
                    arch TEXT, kernel_url TEXT, initrd_url TEXT, codename TEXT,
                    firmware_url TEXT, iso_url TEXT, extract_method TEXT,
                    status TEXT, message TEXT, progress_percent INTEGER,
                    updated_at TEXT, error_msg TEXT, downloaded_at TEXT, size_bytes INTEGER);
                CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
                CREATE TABLE init_jobs (job_type TEXT, status TEXT, message TEXT);
                ''')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.entered = self.root / 'verify-entered'
        self.release = self.root / 'verify-release'
        (self.bin / 'gpgv').write_text('''#!/usr/bin/env bash
set -euo pipefail
touch "$PXE_VERIFY_ENTERED"
until test -e "$PXE_VERIFY_RELEASE"; do sleep 0.05; done
for arg in "$@"; do
    if [[ "$arg" == --output ]]; then want_output=1; continue; fi
    if [[ "${want_output:-0}" == 1 ]]; then
        cp "$PXE_FAKE_RELEASE" "$arg"
        break
    fi
done
''', encoding='ascii')
        (self.bin / 'gpgv').chmod(0o755)
        (self.bin / 'wget').write_text('''#!/usr/bin/env python3
import sys
import urllib.request
args = sys.argv[1:]
output = args[args.index('-O') + 1]
with urllib.request.urlopen(args[-1], timeout=10) as response, open(output, 'wb') as target:
    while chunk := response.read(65536):
        target.write(chunk)
''', encoding='ascii')
        (self.bin / 'wget').chmod(0o755)
        (self.bin / '7z').write_text('''#!/usr/bin/env bash
set -euo pipefail
for arg in "$@"; do
    case "$arg" in -o*) dest="${arg#-o}" ;; esac
done
cp "$PXE_FAKE_KERNEL" "$dest/vmlinuz"
cp "$PXE_FAKE_INITRD" "$dest/initrd.gz"
''', encoding='ascii')
        (self.bin / '7z').chmod(0o755)
        self.keyring = self.root / 'fixture.gpg'
        self.keyring.write_bytes(b'fixture-keyring')
        class QuietHandler(SimpleHTTPRequestHandler):
            def __init__(handler_self, *args, **kwargs):
                super().__init__(*args, directory=str(self.mirror), **kwargs)

            def log_message(handler_self, _format, *args):
                pass

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), QuietHandler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.server.shutdown)
        self.base_url = f'http://127.0.0.1:{self.server.server_port}'
        self.env = dict(os.environ, PXE_APP_DIR=str(self.app), PXE_DB_PATH=str(self.db_path),
                        HTTP_ROOT=str(self.http), IMAGE_ID='1',
                        DEBIAN_ARCHIVE_KEYRING=str(self.keyring),
                        DEBIAN_CD_KEYRING=str(self.keyring),
                        PXE_VERIFY_ENTERED=str(self.entered),
                        PXE_VERIFY_RELEASE=str(self.release),
                        PXE_FAKE_RELEASE=str(self.root / 'Release'),
                        PATH=str(self.bin) + os.pathsep + os.environ['PATH'])

    def wait_for_verify(self, process):
        deadline = time.monotonic() + 20
        while time.monotonic() < deadline:
            if self.entered.exists():
                return
            if process.poll() is not None:
                self.fail(f'download exited before verification: {process.returncode}')
            time.sleep(0.05)
        process.kill()
        self.fail('verification was not reached')

    def run_download(self, script, destination, *, accepted):
        process = subprocess.Popen(['bash', str(SCRIPTS / script)], env=self.env,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            self.wait_for_verify(process)
            # The old scripts published the direct image before gpgv ran.
            self.assertFalse((destination / 'linux').exists())
            self.assertFalse((destination / 'initrd.gz').exists())
            self.release.touch()
            _, stderr = process.communicate(timeout=30)
            self.assertEqual(process.returncode == 0, accepted, stderr.decode(errors='replace'))
            self.assertEqual((destination / 'linux').exists(), accepted)
            self.assertEqual((destination / 'initrd.gz').exists(), accepted)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()

    def seed_direct(self):
        images = self.mirror / 'dists/trixie/main/installer-amd64/current/images'
        netboot = images / 'netboot/debian-installer/amd64'
        netboot.mkdir(parents=True)
        kernel, initrd = b'fixture-kernel', b'fixture-initrd'
        (netboot / 'linux').write_bytes(kernel)
        (netboot / 'initrd.gz').write_bytes(initrd)
        sums = ''.join(hashlib.sha256(data).hexdigest() + '  ./netboot/debian-installer/amd64/'
                       + name + '\n' for name, data in [('linux', kernel), ('initrd.gz', initrd)]).encode()
        (images / 'SHA256SUMS').write_bytes(sums)
        release = ('Origin: Debian\nCodename: trixie\nDate: '
                   + format_datetime(datetime.now(timezone.utc), usegmt=True) + '\nSHA256:\n '
                   + hashlib.sha256(sums).hexdigest() + ' ' + str(len(sums))
                   + ' main/installer-amd64/current/images/SHA256SUMS\n')
        (self.root / 'Release').write_text(release, encoding='ascii')
        (self.mirror / 'dists/trixie/InRelease').write_text('fixture', encoding='ascii')
        kernel_url = self.base_url + '/dists/trixie/main/installer-amd64/current/images/netboot/debian-installer/amd64/linux'
        with closing(sqlite3.connect(self.db_path)) as db:
            with db:
                db.execute('INSERT INTO os_images (id,distro,version,version_full,arch,kernel_url,initrd_url,codename,status) '
                           'VALUES (1,?,?,?,?,?,?,?,?)', ('debian','trixie','13.5.0','amd64', kernel_url,
                           kernel_url.replace('/linux', '/initrd.gz'), 'trixie', 'pending'))
        destination = self.http / 'netboot/debian/13.5.0/amd64'
        return destination, kernel

    def test_direct_image_stays_private_until_verification(self):
        destination, kernel = self.seed_direct()
        self.run_download('bootstrap-download-image.sh', destination, accepted=True)
        self.assertEqual((destination / 'linux').read_bytes(), kernel)

    def test_native_guarded_tree_uses_the_same_private_staging(self):
        native = Path(tempfile.mkdtemp(prefix='pxe-native-image-', dir='/root'))
        self.addCleanup(lambda: shutil.rmtree(native))
        self.http = native / 'http'
        self.http.mkdir()
        self.env['HTTP_ROOT'] = str(self.http)
        self.env['PXE_SERVICE_MANAGER'] = 'systemd'
        destination, kernel = self.seed_direct()
        self.run_download('bootstrap-download-image.sh', destination, accepted=True)
        self.assertEqual((destination / 'linux').read_bytes(), kernel)

    def test_tampered_direct_image_never_replaces_existing_files(self):
        destination, kernel = self.seed_direct()
        self.run_download('bootstrap-download-image.sh', destination, accepted=True)
        self.entered.unlink()
        self.release.unlink()
        fixture = self.mirror / 'dists/trixie/main/installer-amd64/current/images/netboot/debian-installer/amd64/linux'
        fixture.write_bytes(b'tampered-kernel')
        # The existing verified image must remain readable throughout failure.
        process = subprocess.Popen(['bash', str(SCRIPTS / 'bootstrap-download-image.sh')], env=self.env,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            self.wait_for_verify(process)
            self.assertEqual((destination / 'linux').read_bytes(), kernel)
            self.release.touch()
            process.communicate(timeout=30)
            self.assertNotEqual(process.returncode, 0)
            self.assertEqual((destination / 'linux').read_bytes(), kernel)
        finally:
            if process.poll() is None:
                process.kill(); process.wait()

    def seed_iso(self):
        iso_dir = self.mirror / 'iso'
        iso_dir.mkdir()
        iso = iso_dir / 'debian-13.5.0-amd64-netinst.iso'
        iso.write_bytes(b'fixture-iso')
        (iso_dir / 'SHA256SUMS').write_text(hashlib.sha256(iso.read_bytes()).hexdigest()
                                               + '  ' + iso.name + '\n', encoding='ascii')
        (iso_dir / 'SHA256SUMS.sign').write_bytes(b'fixture-signature')
        (self.root / 'Release').write_text('unused', encoding='ascii')
        fake_kernel = self.root / 'vmlinuz'; fake_kernel.write_bytes(b'fixture-kernel')
        fake_initrd = self.root / 'initrd.gz'; fake_initrd.write_bytes(b'fixture-initrd')
        self.env['PXE_FAKE_KERNEL'] = str(fake_kernel)
        self.env['PXE_FAKE_INITRD'] = str(fake_initrd)
        with closing(sqlite3.connect(self.db_path)) as db:
            with db:
                db.execute('INSERT INTO os_images (id,distro,version,version_full,arch,iso_url,status,extract_method) '
                           'VALUES (1,?,?,?,?,?,?,?)', ('debian','trixie','13.5.0','amd64',
                           self.base_url + '/iso/' + iso.name, 'pending', 'iso_extract'))
        destination = self.http / 'netboot/debian/13.5.0/amd64'
        return destination, iso

    def test_iso_image_stays_private_until_verification(self):
        destination, iso = self.seed_iso()
        with closing(sqlite3.connect(self.db_path)) as db:
            with db:
                db.execute("INSERT INTO settings(key,value) VALUES('keep_iso','on')")
        self.run_download('bootstrap-download-image-iso.sh', destination, accepted=True)
        self.assertEqual((destination / 'linux').read_bytes(), b'fixture-kernel')
        self.assertEqual((destination / 'source.iso').read_bytes(), iso.read_bytes())

    def test_tampered_iso_never_reaches_the_http_tree(self):
        destination, iso = self.seed_iso()
        iso.write_bytes(b'tampered-iso')
        self.run_download('bootstrap-download-image-iso.sh', destination, accepted=False)

    def test_publisher_replaces_last_link_and_rejects_linked_parent(self):
        stage = Path(tempfile.mkdtemp(prefix='pxe-image.', dir='/var/tmp'))
        self.addCleanup(lambda: shutil.rmtree(stage))
        source = stage / 'linux'; source.write_bytes(b'verified')
        parent = self.http / 'netboot/debian/13.5.0/amd64'
        parent.mkdir(parents=True)
        parent.chmod(0o777)
        witness = self.root / 'witness'; witness.write_bytes(b'untouched')
        (parent / 'linux').symlink_to(witness)
        publisher = SCRIPTS / 'publish-verified-image.py'
        subprocess.run(['python3', str(publisher), str(source), str(parent / 'linux')], check=True)
        self.assertEqual(witness.read_bytes(), b'untouched')
        self.assertEqual((parent / 'linux').read_bytes(), b'verified')
        link = self.http / 'netboot/link'; link.symlink_to(parent, target_is_directory=True)
        result = subprocess.run(['python3', str(publisher), str(source), str(link / 'linux')],
                                capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(witness.read_bytes(), b'untouched')
        firmware = parent / 'firmware.cpio.gz'
        firmware.symlink_to(witness)
        subprocess.run(['python3', str(publisher), '--remove', str(firmware)], check=True)
        self.assertFalse(firmware.is_symlink())
        self.assertEqual(witness.read_bytes(), b'untouched')
        alias = self.http / 'netboot/trixie/amd64'
        subprocess.run(['python3', str(publisher), '--alias', '../debian/13.5.0/amd64',
                        str(alias)], check=True)
        self.assertTrue(alias.is_symlink())
        result = subprocess.run(['python3', str(publisher), '--alias', '../../etc', str(alias)],
                                capture_output=True)
        self.assertNotEqual(result.returncode, 0)


if __name__ == '__main__':
    unittest.main()
