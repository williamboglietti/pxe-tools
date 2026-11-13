#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise real menu rendering inside an isolated disposable container."""
import os
import fcntl
import pwd
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import threading
import time
import unittest
from unittest import mock
from contextlib import closing
import sys

ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT/'docker/worker/scripts'


class MenuSafety(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if (os.environ.get('PXE_MENU_FIXTURE') != '1' or not Path('/.dockerenv').exists()
                or os.geteuid() != 0 or Path('/etc/pxe-tools/db.conf').exists()):
            raise RuntimeError('Use an isolated root-owned disposable test container')

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pxe-menu-fixture-', dir='/var/lib')
        self.root = Path(self.temp.name)
        self.root.chmod(0o755)
        self.tftp = self.root/'tftp'; self.tftp.mkdir(mode=0o755)
        self.web = pwd.getpwnam('www-data')
        dbdir = self.root/'database'; dbdir.mkdir(mode=0o700)
        os.chown(dbdir, self.web.pw_uid, self.web.pw_gid)
        self.database = dbdir/'db.sqlite'
        with closing(sqlite3.connect(self.database)) as db, db:
            db.executescript('''CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT);
                CREATE TABLE os_images(distro TEXT,arch TEXT,enabled INTEGER,status TEXT,
                                       version_full TEXT,extract_method TEXT);
                CREATE TABLE machines(id INTEGER PRIMARY KEY,mac TEXT,hostname TEXT,status TEXT,
                    rescue_mode INTEGER,local_boot_source TEXT,install_config_json TEXT);
                INSERT INTO settings VALUES('pxe_ip','192.0.2.1');
                INSERT INTO machines VALUES(1,'02:00:00:00:00:01','fixture-one','configured',0,NULL,'{}');
                INSERT INTO machines VALUES(2,'02:00:00:00:00:02','fixture-two','discovered',1,NULL,'{}');''')
        os.chown(self.database, self.web.pw_uid, self.web.pw_gid)
        self.env = dict(os.environ, PXE_APP_DIR=str(ROOT/'app'), PXE_DB_DRIVER='sqlite',
                        PXE_DB_PATH=str(self.database), TFTP_ROOT=str(self.tftp),
                        BOOTSTRAP_SCRIPTS_DIR=str(SCRIPTS), PYTHONDONTWRITEBYTECODE='1')

    def tearDown(self):
        self.temp.cleanup()

    def render(self):
        return subprocess.run(['/bin/sh', str(SCRIPTS/'render-per-mac-pxelinux.sh')],
                              env=self.env, capture_output=True, text=True)

    def test_real_renderer_preserves_target_and_existing_menus_on_bad_data(self):
        menu = self.tftp/'pxelinux.cfg'; menu.mkdir()
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE machines SET hostname='Bureau | Étage' WHERE id=1")
        sentinel = self.root/'sentinel'; sentinel.write_text('unchanged')
        selected = menu/'01-02-00-00-00-00-01'; selected.symlink_to(sentinel)
        stale = menu/'01-02-00-00-00-00-03'; stale.symlink_to(sentinel)
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(sentinel.read_text(), 'unchanged')
        self.assertFalse(selected.is_symlink())
        self.assertIn('LABEL install', selected.read_text())
        self.assertIn('Bureau | Étage', selected.read_text())
        self.assertIn('LABEL rescue', (menu/'01-02-00-00-00-00-02').read_text())
        self.assertFalse(stale.is_symlink())
        before = {p.name:p.read_bytes() for p in menu.iterdir() if p.is_file()}
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE machines SET hostname='invalid' || char(10) || 'extra' WHERE id=2")
        result = self.render()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, {p.name:p.read_bytes() for p in menu.iterdir() if p.is_file()})
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE machines SET hostname='fixture-two', install_config_json='{' WHERE id=2")
        result = self.render()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(before, {p.name:p.read_bytes() for p in menu.iterdir() if p.is_file()})

    def test_delete_waits_for_render_lock_and_rejects_bad_mac(self):
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        menu = self.tftp/'pxelinux.cfg'/'01-02-00-00-00-00-01'
        self.assertTrue(menu.is_file())
        code = ("require $argv[1].'/app/src/Db.php'; "
                "require $argv[1].'/app/src/Models/Machine.php'; "
                "try { \\Admin\\Models\\Machine::delete((int)$argv[2]); } "
                "catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }")
        with (self.tftp/'.pxe-menu.lock').open('r+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            process = subprocess.Popen(['runuser','-u','www-data','--','php','-r',code,'--',str(ROOT),'1'],
                                       env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            time.sleep(0.3)
            if process.poll() is not None:
                stdout, stderr = process.communicate()
                self.fail('delete did not wait for the render: ' + stdout + stderr)
            self.assertTrue(menu.is_file())
            fcntl.flock(lock, fcntl.LOCK_UN)
            stdout, stderr = process.communicate(timeout=15)
            self.assertEqual(process.returncode, 0, stdout+stderr)
        self.assertFalse(menu.exists())
        with closing(sqlite3.connect(self.database)) as db, db:
            self.assertIsNone(db.execute('SELECT id FROM machines WHERE id=1').fetchone())
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(menu.exists(), 'a later render must not recreate the deleted machine menu')
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("INSERT INTO machines VALUES(3,'bad/mac','fixture','discovered',0,NULL,'{}')")
        failed = subprocess.run(['runuser','-u','www-data','--','php','-r',code,'--',str(ROOT),'3'],
                                env=self.env, capture_output=True, text=True)
        self.assertNotEqual(failed.returncode, 0)
        with closing(sqlite3.connect(self.database)) as db, db:
            self.assertIsNotNone(db.execute('SELECT id FROM machines WHERE id=3').fetchone())

    def test_failed_database_delete_restores_the_existing_menu(self):
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        directory = self.tftp/'pxelinux.cfg'
        menu = directory/'01-02-00-00-00-00-02'
        before = menu.read_bytes()
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("CREATE TRIGGER deny_delete BEFORE DELETE ON machines BEGIN SELECT RAISE(ABORT, 'fixture'); END")
        code = ("require $argv[1].'/app/src/Db.php'; "
                "require $argv[1].'/app/src/Models/Machine.php'; "
                "try { \\Admin\\Models\\Machine::delete(2); } "
                "catch (Throwable $e) { exit(1); }")
        failed = subprocess.run(['runuser','-u','www-data','--','php','-r',code,'--',str(ROOT)],
                                env=self.env, capture_output=True, text=True)
        self.assertNotEqual(failed.returncode, 0)
        self.assertEqual(menu.read_bytes(), before)
        self.assertFalse(list(directory.glob('*.pending-delete-*')))
        with closing(sqlite3.connect(self.database)) as db, db:
            self.assertIsNotNone(db.execute('SELECT id FROM machines WHERE id=2').fetchone())

    def test_factory_reset_preserves_the_lock_inode(self):
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        sys.path.insert(0, str(ROOT/'app'))
        try:
            import worker
            http = self.root/'http'; (http/'netboot').mkdir(parents=True)
            mok = self.root/'mok'; mok.mkdir()
            (self.tftp/'fixture').write_text('temporary')
            lock = self.tftp/'.pxe-menu.lock'
            original = lock.stat().st_ino
            with mock.patch.object(worker, 'TFTP_ROOT', str(self.tftp)), \
                 mock.patch.object(worker, 'HTTP_ROOT', str(http)), \
                 mock.patch.object(worker, 'MOK_DIR', str(mok)), \
                 mock.patch.object(worker, 'update_init_job'), \
                 mock.patch.object(worker, 'append_init_log'), \
                 mock.patch.object(worker, 'enqueue_initial_bootstrap'):
                worker.run_factory_reset_wipe_job({'id':1})
            self.assertEqual(lock.stat().st_ino, original)
            self.assertFalse((self.tftp/'fixture').exists())
            with worker.menu_lock(str(self.tftp), timeout=0.2):
                pass
        finally:
            sys.path.pop(0)

    def test_reset_cleanup_waits_for_an_active_render(self):
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        sys.path.insert(0, str(ROOT/'app'))
        try:
            import worker
            menu = self.tftp/'pxelinux.cfg'/'01-02-00-00-00-00-01'
            with mock.patch.dict(os.environ, {'TFTP_ROOT': str(self.tftp)}), \
                 mock.patch.object(worker, 'update_init_job'), \
                 mock.patch.object(worker, 'append_init_log'):
                with (self.tftp/'.pxe-menu.lock').open('r+') as lock:
                    fcntl.flock(lock, fcntl.LOCK_EX)
                    thread = threading.Thread(target=worker.run_cleanup_per_mac_files, args=({'id':1},))
                    thread.start()
                    time.sleep(0.3)
                    self.assertTrue(thread.is_alive())
                    self.assertTrue(menu.exists())
                    fcntl.flock(lock, fcntl.LOCK_UN)
                    thread.join(timeout=15)
                    self.assertFalse(thread.is_alive())
                self.assertFalse(menu.exists())
        finally:
            sys.path.pop(0)

    def test_factory_reset_does_not_reuse_a_running_job_id(self):
        result = self.render()
        self.assertEqual(result.returncode, 0, result.stderr)
        with closing(sqlite3.connect(self.database)) as db, db:
            for table in ('discoveries','installations','wipes','actions','machine_events',
                          'disk_tests','task_phases','presets','catalog_entries','users'):
                db.execute(f'CREATE TABLE {table}(id INTEGER PRIMARY KEY)')
            db.execute('CREATE TABLE init_jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT, status TEXT, message TEXT)')
            db.execute("INSERT INTO init_jobs(id,job_type,status) VALUES(100,'bootstrap:render_templates','running')")
        code = ("spl_autoload_register(function($c) use ($argv) { "
                "if (str_starts_with($c, 'Admin\\\\')) require $argv[1].'/app/src/'.str_replace('\\\\','/',substr($c,6)).'.php'; }); "
                "$_POST['confirm']='REINITIALISER'; (new \\Admin\\Controllers\\SettingsController())->factoryReset([]);")
        with (self.tftp/'.pxe-menu.lock').open('r+') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            process = subprocess.Popen(['runuser','-u','www-data','--','php','-r',code,'--',str(ROOT)],
                                       env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            time.sleep(0.3)
            if process.poll() is not None:
                stdout, stderr = process.communicate()
                self.fail('reset did not wait for the render: ' + stdout + stderr)
            fcntl.flock(lock, fcntl.LOCK_UN)
            stdout, stderr = process.communicate(timeout=15)
            self.assertEqual(process.returncode, 0, stdout+stderr)
        with closing(sqlite3.connect(self.database)) as db, db:
            job = db.execute('SELECT id,job_type,status FROM init_jobs').fetchone()
            self.assertGreater(job[0], 100)
            self.assertEqual(job[1:], ('system:factory_reset_wipe','pending'))
            db.execute("UPDATE init_jobs SET status='success' WHERE id=100")
            self.assertEqual(db.execute('SELECT status FROM init_jobs WHERE id=?',(job[0],)).fetchone()[0], 'pending')


if __name__ == '__main__':
    unittest.main()
