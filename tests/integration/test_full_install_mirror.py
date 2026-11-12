# SPDX-License-Identifier: LGPL-3.0-only
"""Fixture-only checks for the isolated Debian binary-package mirror."""
import hashlib
import lzma
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

PROTOTYPE = Path(__file__).resolve().parents[1] / 'support/prototype'
sys.path.insert(0, str(PROTOTYPE / 'tests/integration'))
sys.path.insert(0, str(PROTOTYPE / 'app'))
from full_install_mirror import FullInstallMirror, binary_records


def index(name='pool/main/f/fixture/fixture_1_amd64.deb', digest='a' * 64, size='123'):
    return lzma.compress(f'Package: fixture\nFilename: {name}\nSize: {size}\nSHA256: {digest}\n\n'.encode())


class BinaryMirror(unittest.TestCase):
    def test_index_rejects_invalid_or_ambiguous_package_records(self):
        self.assertEqual(binary_records(index()), {'pool/main/f/fixture/fixture_1_amd64.deb': ('a'*64, 123)})
        for bad in [index('../escape.deb'), index('pool/../escape.deb'), index(digest='bad'),
                    index(size='0'), index(size=str(33*1024*1024*1024)), b'bad-xz']:
            with self.subTest(case=len(bad)):
                with self.assertRaises((ValueError, lzma.LZMAError)):
                    binary_records(bad)
        self.assertEqual(next(iter(binary_records(index(size=str(257*1024*1024))).values()))[1], 257*1024*1024)
        one = 'Package: a\nFilename: pool/main/f/a.deb\nSize: 2\nSHA256: ' + 'a'*64 + '\n\n'
        two = 'Package: b\nFilename: pool/main/f/a.deb\nSize: 2\nSHA256: ' + 'b'*64 + '\n\n'
        with self.assertRaises(ValueError):
            binary_records(lzma.compress((one+two).encode()))

    def test_by_hash_resolves_only_a_digest_in_signed_release(self):
        instance = object.__new__(FullInstallMirror)
        content = b'fixture index'
        digest = hashlib.sha256(content).hexdigest()
        instance.release_entries = {'main/binary-amd64/Packages.xz': (digest, len(content))}
        with patch.object(instance, '_fetch_signed', return_value=Path('/fixture')) as fetch:
            value = instance.file_for('/debian/dists/trixie/main/binary-amd64/by-hash/SHA256/' + digest)
            self.assertEqual(value, (None, Path('/fixture')))
            fetch.assert_called_once_with('dists/trixie/main/binary-amd64/Packages.xz', (digest, len(content)))
            with self.assertRaises(ValueError):
                instance.file_for('/debian/dists/trixie/main/binary-amd64/by-hash/SHA256/' + 'b'*64)

    def test_package_path_must_be_present_in_verified_index(self):
        instance = object.__new__(FullInstallMirror)
        instance.package_entries = {'pool/main/f/fixture/fixture_1%3a2_amd64.deb': ('a'*64, 3)}
        with patch.object(instance, '_fetch_signed', return_value=Path('/fixture')) as fetch:
            self.assertEqual(instance.file_for('/debian/pool/main/f/fixture/fixture_1%3a2_amd64.deb'),
                             (None, Path('/fixture')))
            fetch.assert_called_once()
            with self.assertRaises(ValueError):
                instance.file_for('/debian/pool/main/f/fixture/other%3a2_amd64.deb')

    def test_oversize_package_is_refused_before_download(self):
        instance = object.__new__(FullInstallMirror)
        with patch.object(instance, '_fetch_unsigned') as fetch:
            with self.assertRaises(ValueError):
                instance._fetch_signed('pool/main/f/fixture/fixture.deb', ('a'*64, 257*1024*1024))
            fetch.assert_not_called()

    def test_apt_encoded_names_require_exact_signed_package(self):
        instance = object.__new__(FullInstallMirror)
        path = 'pool/main/f/fixture/fixture_1+deb13_amd64.deb'
        tilde = 'pool/main/f/fixture/fixture_1~deb13_amd64.deb'
        instance.package_entries = {path: ('a'*64, 3), tilde: ('b'*64, 4)}
        with patch.object(instance, '_fetch_signed', return_value=Path('/fixture')) as fetch:
            self.assertEqual(instance.file_for('/debian/pool/main/f/fixture/fixture_1%2bdeb13_amd64.deb'),
                             (None, Path('/fixture')))
            fetch.assert_called_once_with(path, ('a'*64, 3))
            self.assertEqual(instance.file_for('/debian/pool/main/f/fixture/fixture_1%7edeb13_amd64.deb'),
                             (None, Path('/fixture')))
            fetch.assert_called_with(tilde, ('b'*64, 4))
            with self.assertRaises(ValueError):
                instance.file_for('/debian/pool/main/f/fixture/other_1%2bdeb13_amd64.deb')
            with self.assertRaises(ValueError):
                instance.file_for('/debian/pool%2fmain/f/fixture/fixture_1%2bdeb13_amd64.deb')
            with self.assertRaises(ValueError):
                instance.file_for('/debian/pool/main/f/fixture/bad%xx.deb')


if __name__ == '__main__':
    unittest.main()
