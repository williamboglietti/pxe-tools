#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise real PHP/SQLite log appends under concurrent writer contention.

Run only in a disposable fixture with no appliance database configuration.
"""
from contextlib import closing
import json
import os
from pathlib import Path
import select
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
if os.environ.get('PXE_INSTALL_LOG_ISOLATED') != '1' or Path('/etc/pxe-tools/db.conf').exists():
    raise SystemExit('Run only in an isolated fixture without appliance data')

# The process connects before reporting READY. GO then enters the real model;
# Python controls a separate SQLite connection, with no injected model hooks.
PHP = r'''
$root = getenv('PXE_TEST_ROOT');
spl_autoload_register(static function ($class) use ($root) {
    if (str_starts_with($class, 'Admin\\')) {
        require_once $root . '/app/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    }
});
Admin\Db::get();
echo "READY\n"; flush();
if (trim(fgets(STDIN)) !== 'GO') exit(2);
echo "START\n"; flush();
try {
    if ($argv[4] === 'recover') {
        try { Admin\Models\Installation::appendInstallLog(1, 'FAIL', 100); exit(3); }
        catch (Throwable $e) { /* Expected fixture constraint failure. */ }
    }
    $result = Admin\Models\Installation::appendInstallLog((int)$argv[1], $argv[2], (int)$argv[3]);
    file_put_contents($argv[5], json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}
'''


class InstallLogConcurrency(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='pxe-install-log-race-')
        self.addCleanup(self.tmp.cleanup)
        self.db = Path(self.tmp.name) / 'fixture.sqlite'
        with closing(sqlite3.connect(self.db)) as connection, connection:
            self.assertEqual(connection.execute('PRAGMA journal_mode=WAL').fetchone()[0], 'wal')
            connection.executescript('''
                CREATE TABLE installations (id INTEGER PRIMARY KEY, install_log TEXT);
                INSERT INTO installations VALUES (1, NULL);
                CREATE TABLE lock_probe (value INTEGER);
                INSERT INTO lock_probe VALUES (0);
            ''')
        self.env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(self.db), PXE_TEST_ROOT=str(ROOT))
        self.children = []
        self.results = {}
        self.addCleanup(self.stop_children)

    def stop_children(self):
        for child in self.children:
            if child.poll() is None:
                child.kill()
            child.communicate(timeout=5)

    def child(self, value, limit=1000, install_id=1, mode='append'):
        result_path = Path(self.tmp.name) / f'result-{len(self.children)}.json'
        child = subprocess.Popen(['php', '-r', PHP, str(install_id), value, str(limit), mode, str(result_path)],
                                 env=self.env, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                 stderr=subprocess.PIPE, text=True, bufsize=1)
        self.children.append(child)
        self.results[child] = result_path
        self.assertEqual(child.stdout.readline().strip(), 'READY')
        return child

    def go(self, child):
        child.stdin.write('GO\n')
        child.stdin.flush()
        self.assertEqual(child.stdout.readline().strip(), 'START')

    def result(self, child):
        stdout, stderr = child.communicate(timeout=10)
        self.assertEqual(child.returncode, 0, stderr)
        self.assertEqual(stdout, '')
        return json.loads(self.results[child].read_text())

    def test_short_concurrent_writer_waits_before_reading(self):
        child = self.child('first-log')
        with closing(sqlite3.connect(self.db, timeout=5)) as writer, writer:
            writer.execute('BEGIN IMMEDIATE')
            writer.execute('UPDATE lock_probe SET value=value+1')
            self.go(child)
            # While the writer is held, an old deferred SELECT→UPDATE fails
            # immediately; the fixed transaction must wait for the writer.
            ready, _, _ = select.select([child.stdout], [], [], 0.5)
            self.assertFalse(ready, 'append finished before the writer released its lock')
            self.assertIsNone(child.poll())
            writer.commit()
        self.assertEqual(self.result(child), {'status': 'ok', 'bytes': 9, 'appended': 9})
        with closing(sqlite3.connect(self.db)) as db, db:
            self.assertEqual(db.execute('SELECT install_log FROM installations').fetchone()[0], 'first-log')

    def test_parallel_appends_preserve_every_chunk_once(self):
        chunks = [f'<chunk-{n}>' for n in range(8)]
        children = [self.child(chunk) for chunk in chunks]
        for child in children: self.go(child)
        results = [self.result(child) for child in children]
        self.assertTrue(all(result['status'] == 'ok' for result in results))
        with closing(sqlite3.connect(self.db)) as db, db:
            log = db.execute('SELECT install_log FROM installations').fetchone()[0]
        self.assertEqual(len(log), sum(map(len, chunks)))
        for chunk in chunks: self.assertEqual(log.count(chunk), 1)
        self.assertEqual(sorted(result['bytes'] for result in results), [9 * i for i in range(1, 9)])

    def test_concurrent_cap_and_single_truncation_marker(self):
        chunks = ['A' * 70, 'B' * 70]
        children = [self.child(chunk, limit=100) for chunk in chunks]
        for child in children: self.go(child)
        results = [self.result(child) for child in children]
        self.assertEqual(sorted(result['status'] for result in results), ['ok', 'truncated'])
        with closing(sqlite3.connect(self.db)) as db, db:
            log = db.execute('SELECT install_log FROM installations').fetchone()[0]
        self.assertEqual(len(log.encode()), 100)
        self.assertEqual(log.count('\n[LOG TRUNCATED AT 5MB]\n'), 1)
        self.assertEqual(max(result['bytes'] for result in results), 100)
        child = self.child('extra', limit=100)
        self.go(child)
        self.assertEqual(self.result(child), {'status': 'truncated', 'bytes': 100, 'appended': 0})

    def test_exact_cap_and_marker_when_only_few_bytes_remain(self):
        exact = self.child('abcde', limit=5)
        self.go(exact)
        self.assertEqual(self.result(exact), {'status': 'ok', 'bytes': 5, 'appended': 5})
        extra = self.child('x', limit=5)
        self.go(extra)
        self.assertEqual(self.result(extra), {'status': 'truncated', 'bytes': 5, 'appended': 0})
        with closing(sqlite3.connect(self.db)) as db, db:
            self.assertEqual(db.execute('SELECT install_log FROM installations').fetchone()[0], 'abcde')
            db.execute('UPDATE installations SET install_log = NULL')
        short = self.child('abcdefgh', limit=5)
        self.go(short)
        self.assertEqual(self.result(short), {'status': 'truncated', 'bytes': 5, 'appended': 0})
        with closing(sqlite3.connect(self.db)) as db:
            self.assertEqual(db.execute('SELECT install_log FROM installations').fetchone()[0], '\n[LOG')

    def test_not_found_and_failed_update_leave_connection_usable(self):
        missing = self.child('x', install_id=999)
        self.go(missing)
        self.assertEqual(self.result(missing), {'status': 'not_found', 'bytes': 0, 'appended': 0})
        with closing(sqlite3.connect(self.db)) as db, db:
            db.executescript("""CREATE TRIGGER fail_fixture BEFORE UPDATE OF install_log ON installations
                WHEN NEW.install_log LIKE '%FAIL%' BEGIN SELECT RAISE(ABORT, 'fixture failure'); END;""")
        child = self.child('recovered', mode='recover')
        self.go(child)
        self.assertEqual(self.result(child), {'status': 'ok', 'bytes': 9, 'appended': 9})
        with closing(sqlite3.connect(self.db)) as db, db:
            self.assertEqual(db.execute('SELECT install_log FROM installations').fetchone()[0], 'recovered')


if __name__ == '__main__':
    unittest.main()
