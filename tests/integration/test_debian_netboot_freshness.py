#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Check signed Release freshness and integrity with an ephemeral test key."""
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
LIBRARY = ROOT / 'docker/worker/scripts/lib-image-verify.sh'
if os.environ.get('PXE_FRESHNESS_ISOLATED') != '1' or not Path('/.dockerenv').exists():
    raise SystemExit('Use a disposable container and PXE_FRESHNESS_ISOLATED=1')


class SignedNetbootFreshness(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='pxe-debian-freshness-')
        cls.root = Path(cls.temp.name)
        cls.home = cls.root / 'gnupg'
        cls.home.mkdir(mode=0o700)
        cls.gpg = ['gpg', '--homedir', str(cls.home), '--batch', '--no-tty']
        subprocess.run([*cls.gpg, '--pinentry-mode', 'loopback', '--passphrase', '',
                        '--quick-generate-key', 'Fixture <fixture@example.invalid>',
                        'ed25519', 'sign', '1d'], check=True, capture_output=True)
        keys = subprocess.run([*cls.gpg, '--with-colons', '--list-secret-keys'],
                              check=True, capture_output=True, text=True).stdout
        cls.fingerprint = next(line.split(':')[9] for line in keys.splitlines()
                               if line.startswith('fpr:'))
        cls.keyring = cls.root / 'fixture.gpg'
        cls.keyring.write_bytes(subprocess.run([*cls.gpg, '--export', cls.fingerprint],
                                               check=True, capture_output=True).stdout)
        cls.release_root = cls.root / 'dists/trixie'
        cls.images = cls.release_root / 'main/installer-amd64/current/images'
        cls.netboot = cls.images / 'netboot/debian-installer/amd64'
        cls.netboot.mkdir(parents=True)
        (cls.netboot / 'linux').write_bytes(b'fixture-kernel')
        (cls.netboot / 'initrd.gz').write_bytes(b'fixture-initrd')
        cls.sums = ''.join(
            hashlib.sha256((cls.netboot / name).read_bytes()).hexdigest()
            + '  ./netboot/debian-installer/amd64/' + name + '\n'
            for name in ('linux', 'initrd.gz')).encode()
        (cls.images / 'SHA256SUMS').write_bytes(cls.sums)
        cls.addClassCleanup(cls.temp.cleanup)
        cls.addClassCleanup(lambda: subprocess.run(
            ['gpgconf', '--homedir', str(cls.home), '--kill', 'gpg-agent'],
            check=False, capture_output=True))

    def signed_release(self, dates: str) -> None:
        digest = hashlib.sha256(self.sums).hexdigest()
        text = ('Origin: Debian\nCodename: trixie\n' + dates + '\nSHA256:\n '
                + digest + ' ' + str(len(self.sums))
                + ' main/installer-amd64/current/images/SHA256SUMS\n')
        plain = self.root / 'Release'
        plain.write_text(text, encoding='ascii')
        subprocess.run([*self.gpg, '--yes', '--local-user', self.fingerprint + '!',
                        '--output', str(self.release_root / 'InRelease'), '--clearsign',
                        str(plain)], check=True, capture_output=True)

    def verify(self) -> subprocess.CompletedProcess[str]:
        env = dict(os.environ, DEBIAN_ARCHIVE_KEYRING=str(self.keyring))
        command = 'set -euo pipefail; source "$1"; verify_debian_netboot "$2" "$3" amd64'
        return subprocess.run(['bash', '-c', command, 'fixture', str(LIBRARY),
                               str(self.netboot), (self.netboot / 'linux').as_uri()],
                              env=env, text=True, capture_output=True, timeout=30)

    def date(self, value: datetime) -> str:
        return format_datetime(value, usegmt=True)

    def test_fresh_signed_metadata_and_matching_artifacts_are_accepted(self):
        now = datetime.now(timezone.utc)
        self.signed_release('Date: ' + self.date(now - timedelta(days=61)))
        result = self.verify()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_replayed_stable_metadata_is_rejected(self):
        now = datetime.now(timezone.utc)
        self.signed_release('Date: ' + self.date(now - timedelta(days=181)))
        result = self.verify()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('trop anciennes', result.stderr)

    def test_signed_expiry_and_future_date_are_checked(self):
        now = datetime.now(timezone.utc)
        cases = [
            ('Date: ' + self.date(now - timedelta(days=2)) + '\nValid-Until: '
             + self.date(now - timedelta(minutes=1)), 'expirées'),
            ('Date: ' + self.date(now + timedelta(minutes=6)), 'futur'),
            ('Date: ' + self.date(now) + '\nDate: ' + self.date(now), 'répétées'),
            ('Date: invalid', 'invalide'),
        ]
        for dates, expected in cases:
            with self.subTest(dates=dates):
                self.signed_release(dates)
                result = self.verify()
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(expected, result.stderr)
        self.signed_release('Date: ' + self.date(now - timedelta(days=1))
                            + '\nValid-Until: ' + self.date(now + timedelta(days=1)))
        result = self.verify()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_tampered_kernel_or_sums_fail(self):
        self.signed_release('Date: ' + self.date(datetime.now(timezone.utc)))
        kernel = self.netboot / 'linux'
        original = kernel.read_bytes()
        try:
            kernel.write_bytes(b'altered-kernel')
            self.assertNotEqual(self.verify().returncode, 0)
        finally:
            kernel.write_bytes(original)
        sums = self.images / 'SHA256SUMS'
        try:
            sums.write_bytes(self.sums + b'altered')
            self.assertNotEqual(self.verify().returncode, 0)
        finally:
            sums.write_bytes(self.sums)

    def test_unsigned_prefix_cannot_supply_checksum(self):
        self.signed_release('Date: ' + self.date(datetime.now(timezone.utc)))
        sums = self.images / 'SHA256SUMS'
        forged = self.sums + b'unsigned forged bytes\n'
        sums.write_bytes(forged)
        signature = self.release_root / 'InRelease'
        original = signature.read_bytes()
        fake = (b'SHA256:\n ' + hashlib.sha256(forged).hexdigest().encode() + b' '
                + str(len(forged)).encode()
                + b' main/installer-amd64/current/images/SHA256SUMS\n')
        try:
            signature.write_bytes(fake + original)
            self.assertNotEqual(self.verify().returncode, 0)
        finally:
            signature.write_bytes(original)
            sums.write_bytes(self.sums)


if __name__ == '__main__':
    unittest.main()
