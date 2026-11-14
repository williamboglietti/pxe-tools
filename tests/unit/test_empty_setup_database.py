# SPDX-License-Identifier: LGPL-3.0-only
import hashlib
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class EmptySetupDatabase(unittest.TestCase):
    def test_read_only_classification(self):
        source = (ROOT / 'docker/pxe-tools/init/php-init.sh').read_text()
        function = source.split('classify_setup_db() {\n', 1)[1].split('\n}\nDETECTED_DB=', 1)[0]
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            db = root / 'setup.sqlite'
            with sqlite3.connect(db) as conn:
                conn.execute('CREATE TABLE fixture (value TEXT)')
                conn.execute('DROP TABLE fixture')
            digest = hashlib.sha256(db.read_bytes()).hexdigest()
            def classify(path):
                return subprocess.run(['/bin/sh', '-c', 'classify_setup_db() {\n' + function +
                                       '\n}\nclassify_setup_db "$1"', 'fixture', str(path)],
                                      capture_output=True, text=True)
            result = classify(db)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(result.stdout.startswith('empty:'))
            self.assertEqual(hashlib.sha256(db.read_bytes()).hexdigest(), digest)
            (root / 'setup.sqlite-wal').touch()
            (root / 'setup.sqlite-shm').write_bytes(bytes(32768))
            self.assertTrue(classify(db).stdout.startswith('empty:'))
            (root / 'setup.sqlite-wal').write_bytes(b'nonempty')
            self.assertNotEqual(classify(db).returncode, 0)
            (root / 'setup.sqlite-wal').unlink()
            (root / 'setup.sqlite-shm').unlink()
            link = root / 'link'
            link.symlink_to(db)
            self.assertNotEqual(classify(link).returncode, 0)
            with sqlite3.connect(db) as conn:
                conn.execute('CREATE TABLE fixture (value TEXT)')
            self.assertEqual(classify(db).stdout.strip(), 'populated')
            db.write_bytes(b'not sqlite')
            self.assertNotEqual(classify(db).returncode, 0)


if __name__ == '__main__':
    unittest.main()
