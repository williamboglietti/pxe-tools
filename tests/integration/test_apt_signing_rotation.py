#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Characterize signing-subkey rotation using temporary keys and no APT state."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SIGNER = ROOT / 'packaging/native/ci-sign-apt-candidate.py'
INSPECTOR = ROOT / 'packaging/native/check-apt-key-transition.py'
spec = importlib.util.spec_from_file_location('pxe_apt_rotation_proof', ROOT / 'packaging/ci/apt-proof.py')
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)


class SigningRotationFixture(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='pxe-rotation-fixture-', dir='/tmp')
        cls.addClassCleanup(cls.temporary.cleanup)
        cls.root = Path(cls.temporary.name)
        cls.home = cls.root / 'gnupg'
        cls.home.mkdir(mode=0o700)
        cls.addClassCleanup(subprocess.run,
                            ['gpgconf', '--homedir', str(cls.home), '--kill', 'gpg-agent'],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
        cls.passphrase = 'temporary-fixture-passphrase'
        cls.gpg = ['gpg', '--homedir', str(cls.home), '--batch', '--no-tty',
                   '--pinentry-mode', 'loopback', '--passphrase', cls.passphrase]
        cls.run_checked(*cls.gpg, '--quick-generate-key', 'Rotation fixture <rotation@example.invalid>',
                        'ed25519', 'cert', '1d')
        cls.primary = cls.fingerprints()[0]
        cls.run_checked(*cls.gpg, '--quick-add-key', cls.primary, 'ed25519', 'sign', '1d')
        cls.old_signer = cls.fingerprints()[1]
        cls.old_key = cls.root / 'old-public.gpg'
        cls.old_key.write_bytes(cls.run_checked(*cls.gpg, '--export', cls.primary))
        cls.run_checked(*cls.gpg, '--quick-add-key', cls.primary, 'ed25519', 'sign', '1d')
        cls.new_signer = cls.fingerprints()[2]
        cls.new_key = cls.root / 'updated-public.gpg'
        cls.new_key.write_bytes(cls.run_checked(*cls.gpg, '--export', cls.primary))
        cls.message = cls.root / 'Release'
        cls.message.write_text('Fixture APT release only\n')
        cls.old_signature = cls.sign(cls.old_signer, 'old.InRelease')
        cls.new_signature = cls.sign(cls.new_signer, 'new.InRelease')

    @staticmethod
    def run_checked(*args):
        return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout

    @classmethod
    def fingerprints(cls):
        rows = cls.run_checked(*cls.gpg, '--with-colons', '--with-fingerprint',
                               '--with-subkey-fingerprint', '--list-secret-keys').decode().splitlines()
        return [line.split(':')[9] for line in rows if line.startswith('fpr:')]

    @classmethod
    def sign(cls, fingerprint, filename):
        output = cls.root / filename
        cls.run_checked(*cls.gpg, '--local-user', fingerprint + '!', '--output', str(output),
                        '--clearsign', str(cls.message))
        return output

    def verify(self, keyring, signed):
        return subprocess.run(['gpgv', '--keyring', str(keyring), '--status-fd', '1', str(signed)],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def assert_signer(self, result, signer):
        self.assertEqual(result.returncode, 0, result.stderr)
        signatures = [line.split() for line in result.stdout.splitlines()
                      if line.startswith('[GNUPG:] VALIDSIG ')]
        self.assertEqual(len(signatures), 1)
        self.assertEqual(signatures[0][2], signer)
        self.assertEqual(signatures[0][-1], self.primary)

    def test_old_client_refuses_new_subkey_until_public_certificate_is_updated(self):
        self.assert_signer(self.verify(self.old_key, self.old_signature), self.old_signer)
        self.assertNotEqual(self.verify(self.old_key, self.new_signature).returncode, 0)
        self.assert_signer(self.verify(self.new_key, self.new_signature), self.new_signer)
        self.assert_signer(self.verify(self.new_key, self.old_signature), self.old_signer)

    def test_provenance_keyring_requires_an_explicit_exact_transition_pair(self):
        proof.validate_keyring(self.old_key, self.primary, self.old_signer)
        with self.assertRaises(ValueError):
            proof.validate_keyring(self.new_key, self.primary, self.new_signer)
        proof.validate_keyring(self.new_key, self.primary, self.new_signer, self.old_signer)
        with self.assertRaises(ValueError):
            proof.validate_keyring(self.new_key, self.primary, self.new_signer, '0' * 40)
        with self.assertRaises(ValueError):
            proof.validate_keyring(self.new_key, self.primary, self.new_signer, self.new_signer)

    def test_read_only_inspector_pins_certificate_shapes_and_rejects_wrong_material(self):
        def check(old_key, new_key, primary, old, new):
            return subprocess.run([sys.executable, str(INSPECTOR),
                                   '--old-certificate', str(old_key), '--new-certificate', str(new_key),
                                   '--primary-fingerprint', primary, '--old-signing-fingerprint', old,
                                   '--new-signing-fingerprint', new], capture_output=True, text=True)
        before = (self.old_key.read_bytes(), self.new_key.read_bytes())
        result = check(self.old_key, self.new_key, self.primary, self.old_signer, self.new_signer)
        self.assertEqual(result.returncode, 0, result.stderr)
        import hashlib
        report = json.loads(result.stdout)
        self.assertEqual(report['new_certificate_sha256'], hashlib.sha256(before[1]).hexdigest())
        self.assertTrue(report['independent_fingerprint_verification_required'])
        self.assertFalse(report['client_keyring_modified'])
        self.assertEqual((self.old_key.read_bytes(), self.new_key.read_bytes()), before)
        self.assertNotEqual(check(self.old_key, self.new_key, self.primary, self.old_signer, '0' * 40).returncode, 0)
        self.assertNotEqual(check(self.old_key, self.old_key, self.primary, self.old_signer, self.new_signer).returncode, 0)
        secret = self.root / 'secret-old.gpg'
        secret.write_bytes(self.run_checked(*self.gpg, '--export-secret-keys', self.primary))
        self.assertNotEqual(check(secret, self.new_key, self.primary, self.old_signer, self.new_signer).returncode, 0)
        link = self.root / 'old-link.gpg'
        link.symlink_to(self.old_key)
        self.assertNotEqual(check(link, self.new_key, self.primary, self.old_signer, self.new_signer).returncode, 0)

    def test_transition_imports_only_old_public_subkey_before_calling_builder(self):
        secret = self.run_checked(*self.gpg, '--armor', '--export-secret-subkeys',
                                  self.new_signer + '!').decode()
        env = dict(os.environ, APT_SIGNING_KEY=secret, APT_SIGNING_PASSPHRASE=self.passphrase,
                   TMPDIR='/tmp')
        result = subprocess.run([sys.executable, str(SIGNER), str(self.root / 'missing.deb'),
                                 str(self.root / 'unused-transition'),
                                 '--primary-fingerprint', self.primary,
                                 '--signing-fingerprint', self.new_signer,
                                 '--previous', str(self.root),
                                 '--transition-previous-signing-fingerprint', self.old_signer,
                                 '--transition-previous-public-key', str(self.old_key)],
                                env=env, capture_output=True, text=True)
        # The deliberately absent package makes the builder fail only after the
        # signer has accepted the public-only transition and invoked it.
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('python3 failed', result.stderr)
        self.assertFalse((self.root / 'unused-transition').exists())

    def test_current_ci_signer_refuses_a_bundle_with_two_signing_subkeys(self):
        secret = self.run_checked(*self.gpg, '--armor', '--export-secret-subkeys', self.primary).decode()
        env = dict(os.environ, APT_SIGNING_KEY=secret, APT_SIGNING_PASSPHRASE=self.passphrase)
        result = subprocess.run([sys.executable, str(SIGNER), str(self.root / 'unused.deb'),
                                 str(self.root / 'unused-repository'),
                                 '--primary-fingerprint', self.primary,
                                 '--signing-fingerprint', self.new_signer],
                                env=env, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('CI keyring must contain only the selected signing subkey', result.stderr)
        self.assertFalse((self.root / 'unused-repository').exists())


if __name__ == '__main__':
    unittest.main()
