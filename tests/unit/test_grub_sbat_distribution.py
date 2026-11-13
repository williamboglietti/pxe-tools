# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[2] / 'docker/worker/scripts/extract-grub-sbat.py'
spec = importlib.util.spec_from_file_location('extract_grub_sbat', path)
extract = importlib.util.module_from_spec(spec)
spec.loader.exec_module(extract)


class SbatDistributionTests(unittest.TestCase):
    def test_signed_image_vendor_and_module_version_must_both_match(self):
        debian = [['grub.debian', '4', 'Debian', 'grub2', '2.12-9']]
        ubuntu = [['grub.ubuntu', '2', 'Ubuntu', 'grub2', '2.12-1ubuntu7.3']]
        self.assertTrue(extract.matching_sbat(debian, 'debian', '2.12-9'))
        self.assertTrue(extract.matching_sbat(ubuntu, 'ubuntu', '2.12-1ubuntu7.3'))
        self.assertFalse(extract.matching_sbat(ubuntu, 'debian', '2.12-1ubuntu7.3'))
        self.assertFalse(extract.matching_sbat(debian, 'ubuntu', '2.12-9'))
        self.assertFalse(extract.matching_sbat(ubuntu, 'ubuntu', '2.12-1ubuntu7.2'))
        self.assertFalse(extract.matching_sbat(ubuntu, 'unknown', '2.12-1ubuntu7.3'))
        self.assertFalse(extract.matching_sbat([['grub.ubuntu']], 'ubuntu', '2.12'))


if __name__ == '__main__':
    unittest.main()
