#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Test the prototype fixture's signed Debian metadata freshness boundary."""
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
import hashlib
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tests/support/prototype/app'))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from artifacts.verify import VerificationError  # noqa: E402


class SignedFixtureFreshness(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='pxe-test-netboot-freshness-')
        cls.root = Path(cls.temp.name)
        cls.home = cls.root / 'gnupg'
        cls.home.mkdir(mode=0o700)
        cls.env = dict(os.environ, GNUPGHOME=str(cls.home))
        subprocess.run(['gpg', '--batch', '--pinentry-mode', 'loopback', '--passphrase', '',
                        '--quick-generate-key', 'Fixture <fixture@example.invalid>', 'ed25519', 'sign', '0'],
                       env=cls.env, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.keyring = cls.root / 'fixture.gpg'
        cls.keyring.write_bytes(subprocess.check_output(['gpg', '--batch', '--export'], env=cls.env))
        cls.kernel = cls.root / 'linux'; cls.kernel.write_bytes(b'fixture kernel')
        cls.initrd = cls.root / 'initrd.gz'; cls.initrd.write_bytes(b'fixture initrd')
        cls.sums = cls.root / 'SHA256SUMS'
        cls.sums.write_text(
            hashlib.sha256(cls.kernel.read_bytes()).hexdigest() + '  ./netboot/debian-installer/amd64/linux\n' +
            hashlib.sha256(cls.initrd.read_bytes()).hexdigest() + '  ./netboot/debian-installer/amd64/initrd.gz\n')
        cls.release = cls.root / 'Release'
        cls.signed = cls.root / 'InRelease'
        cls.now = datetime.now(timezone.utc).replace(microsecond=0)

    @classmethod
    def tearDownClass(cls):
        subprocess.run(['gpgconf', '--homedir', str(cls.home), '--kill', 'gpg-agent'],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        cls.temp.cleanup()

    def signed_release(self, issued, expires=None):
        expiry = 'Valid-Until: ' + format_datetime(expires, usegmt=True) + '\n' if expires else ''
        self.release.write_text('Origin: Debian\nCodename: trixie\nArchitectures: amd64\n' +
            'Date: ' + format_datetime(issued, usegmt=True) + '\n' + expiry + 'SHA256:\n ' +
            hashlib.sha256(self.sums.read_bytes()).hexdigest() + ' ' + str(self.sums.stat().st_size) +
            ' main/installer-amd64/current/images/SHA256SUMS\n')
        subprocess.run(['gpg', '--batch', '--yes', '--pinentry-mode', 'loopback', '--passphrase', '',
                        '--clearsign', '--output', str(self.signed), str(self.release)], env=self.env,
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def verify(self):
        verify_debian_netboot(self.kernel, self.initrd, self.sums, self.signed, self.keyring, now=self.now)

    def test_exact_180_days_accepted_and_older_rejected(self):
        self.signed_release(self.now - timedelta(days=180)); self.verify()
        self.signed_release(self.now - timedelta(days=180, seconds=1))
        with self.assertRaises(VerificationError): self.verify()

    def test_expiry_and_future_date_still_rejected(self):
        self.signed_release(self.now - timedelta(days=1), self.now - timedelta(seconds=1))
        with self.assertRaises(VerificationError): self.verify()
        self.signed_release(self.now + timedelta(minutes=6))
        with self.assertRaises(VerificationError): self.verify()

    def test_signature_and_checksums_still_required(self):
        self.signed_release(self.now)
        self.verify()
        self.signed.write_bytes(self.signed.read_bytes().replace(b'Codename: trixie', b'Codename: changed'))
        with self.assertRaises(VerificationError): self.verify()
        self.signed_release(self.now)
        self.kernel.write_bytes(b'altered fixture kernel')
        try:
            with self.assertRaises(VerificationError): self.verify()
        finally:
            self.kernel.write_bytes(b'fixture kernel')


if __name__ == '__main__':
    unittest.main()
