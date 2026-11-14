# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise public-only fixture exports captured from GnuPG 2.4.4."""
import base64
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('compare_fixture', ROOT / 'tests/support/compare_openpgp_fixture.py')
compare = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compare)
FIXTURES = ROOT / 'tests/support/fixtures'


class FixtureComparison(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.original = base64.b64decode((FIXTURES / 'eddsa-public-original.b64').read_bytes(), validate=False)
        cls.reimported = base64.b64decode((FIXTURES / 'eddsa-public-reimported.b64').read_bytes(), validate=False)

    def test_identical_and_authentic_padding_difference(self):
        self.assertNotEqual(self.original, self.reimported)
        self.assertEqual(compare.comparable_packets(self.original), compare.comparable_packets(self.original))
        self.assertEqual(compare.comparable_packets(self.original), compare.comparable_packets(self.reimported))

    def test_altered_signature_key_and_packet_order_are_not_equal(self):
        for offset in (30, len(self.original) - 1):
            changed = bytearray(self.original)
            changed[offset] ^= 1
            self.assertNotEqual(compare.comparable_packets(self.original), compare.comparable_packets(bytes(changed)))
        reordered = self.original[53:] + self.original[:53]
        self.assertNotEqual(compare.comparable_packets(self.original), compare.comparable_packets(reordered))
        self.assertNotEqual(compare.comparable_packets(self.original),
                            compare.comparable_packets(self.original + b'\xb4\x01x'))

    def test_truncated_or_bad_mpi_length_fails(self):
        for missing in (1, 2, 50):
            with self.assertRaises(ValueError):
                compare.comparable_packets(self.original[:-missing])
        changed = bytearray(self.reimported)
        changed[-34:-32] = b'\x00\xff'
        with self.assertRaises(ValueError):
            compare.comparable_packets(bytes(changed))


if __name__ == '__main__':
    unittest.main()
