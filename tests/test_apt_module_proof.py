#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise exact package-set and signed-provenance checks without secret keys."""
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[1] / 'packaging/ci/apt-proof.py'
spec = importlib.util.spec_from_file_location('apt_proof', path)
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)

VERSION = '0.1.0~beta1'
BASE = 'a' * 64
MODULE = 'b' * 64
SOURCE = 'c' * 40
INDEX = ('Package: pxe-tools\nVersion: ' + VERSION + '\nArchitecture: amd64\nSHA256: ' + BASE + '\n')
ANSIBLE = ('Package: pxe-tools-ansible\nVersion: ' + VERSION + '\nArchitecture: all\nSHA256: ' + MODULE + '\n')
RELEASE = ('X-PXE-Source-Commit: ' + SOURCE + '\nX-PXE-Build-Run: 123\n'
           + 'X-PXE-Package-SHA256: ' + BASE + '\nX-PXE-Package-Version: ' + VERSION + '\n')
MODULE_RELEASE = ('X-PXE-Ansible-SHA256: ' + MODULE + '\nX-PXE-Ansible-Version: ' + VERSION + '\n')


class AptModuleProof(unittest.TestCase):
    def identity(self, text, module=None):
        return proof.package_identity({'dists/stable/main/binary-amd64/Packages': text.encode()}, module)

    def test_only_approved_packages_are_accepted(self):
        self.assertEqual(self.identity(INDEX), (VERSION, BASE))
        self.assertEqual(self.identity(INDEX + '\n' + ANSIBLE, (VERSION, MODULE)), (VERSION, BASE))
        for index, module in ((INDEX + '\n' + ANSIBLE, None), (INDEX, (VERSION, MODULE)),
                              (INDEX + '\n' + ANSIBLE, (VERSION, 'd' * 64)),
                              (INDEX + '\n' + ANSIBLE + '\n' + ANSIBLE, (VERSION, MODULE))):
            with self.subTest(index=index, module=module), self.assertRaises(ValueError):
                self.identity(index, module)

    def test_signed_module_provenance_must_match_exactly(self):
        proof.verify_provenance(RELEASE.encode(), VERSION, BASE, SOURCE, '123')
        proof.verify_provenance((RELEASE + MODULE_RELEASE).encode(), VERSION, BASE, SOURCE, '123', (VERSION, MODULE))
        for content, module in ((RELEASE, (VERSION, MODULE)), (RELEASE + MODULE_RELEASE, None),
                                (RELEASE + MODULE_RELEASE, (VERSION, 'd' * 64)),
                                (RELEASE + MODULE_RELEASE + MODULE_RELEASE, (VERSION, MODULE))):
            with self.subTest(module=module), self.assertRaises(ValueError):
                proof.verify_provenance(content.encode(), VERSION, BASE, SOURCE, '123', module)


if __name__ == '__main__':
    unittest.main()
