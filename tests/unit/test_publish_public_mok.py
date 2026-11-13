# SPDX-License-Identifier: LGPL-3.0-only
import errno
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest import mock

path = Path(__file__).resolve().parents[2] / 'docker/worker/scripts/publish-public-mok.py'
spec = importlib.util.spec_from_file_location('publish_public_mok', path)
mok = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mok)


class PublishMok(unittest.TestCase):
    def test_destination_link_is_replaced_not_followed(self):
        with tempfile.TemporaryDirectory(prefix='pxe-mok-unit-', dir=Path.cwd()) as temp:
            root = Path(temp); source = root / 'source'; target = root / 'target'
            source.mkdir(); target.mkdir()
            (source / 'MOK.der').write_bytes(b'fixture-certificate')
            witness = root / 'witness'; witness.write_bytes(b'unchanged')
            (target / 'MOK.der').symlink_to(witness)
            mok.publish(source, target)
            self.assertEqual(witness.read_bytes(), b'unchanged')
            self.assertFalse((target / 'MOK.der').is_symlink())
            self.assertEqual((target / 'MOK.der').read_bytes(), b'fixture-certificate')

    def test_directory_symlink_or_cross_device_refuses_without_changes(self):
        with tempfile.TemporaryDirectory(prefix='pxe-mok-unit-', dir=Path.cwd()) as temp:
            root = Path(temp); source = root / 'source'; target = root / 'target'
            source.mkdir(); target.mkdir(); (source / 'MOK.der').write_bytes(b'certificate')
            original = target / 'MOK.der'; original.write_bytes(b'old')
            link = root / 'link'; link.symlink_to(target, target_is_directory=True)
            with self.assertRaises(OSError):
                mok.publish(source, link)
            with mock.patch.object(mok.os, 'replace', side_effect=OSError(errno.EXDEV, 'cross-device')):
                with self.assertRaises(OSError):
                    mok.publish(source, target)
            self.assertEqual(original.read_bytes(), b'old')
            self.assertEqual(sorted(p.name for p in source.iterdir()), ['MOK.der'])


if __name__ == '__main__':
    unittest.main()
