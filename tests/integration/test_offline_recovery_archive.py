#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise encrypted recovery archives using only isolated fixture data."""
import base64
from contextlib import closing
import io
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT/'app'))
from nacl.secret import SecretBox  # noqa: E402
from backup.openpgp import BackupError, _gpg_home, encrypt_file  # noqa: E402
from backup.recovery import MANIFEST, export_recovery, restore_recovery  # noqa: E402
import backup.recovery as recovery  # noqa: E402


def rejected(action):
    try:
        action()
    except BackupError:
        return
    raise AssertionError('Unsafe recovery operation was accepted')


def main():
    if (os.environ.get('PXE_OFFLINE_RECOVERY_TEST') != '1'
            or {p.name for p in Path('/sys/class/net').iterdir()} != {'lo'}):
        raise SystemExit('Run only in a dedicated networkless test environment')
    with tempfile.TemporaryDirectory(prefix='pxe-recovery-test-') as directory:
        root = Path(directory)
        database, key = root/'source ?#.sqlite', root/'fixture-master.key'
        secret = 'fixture-secret-not-for-logs'
        master_key = os.urandom(32); key.write_bytes(master_key); key.chmod(0o600)
        cipher = base64.b64encode(bytes(SecretBox(master_key).encrypt(secret.encode()))).decode()
        with closing(sqlite3.connect(database)) as db, db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.execute("INSERT INTO users(username,password_hash) VALUES('fixture-user','fixture-hash')")
            db.execute("INSERT INTO machines(id,mac,hostname,root_password_cipher) VALUES(1,'02:00:00:00:00:01','fixture-machine',?)",(cipher,))
            db.execute("INSERT INTO actions(machine_id,type,status,token) VALUES(1,'install','running','fixture-action-token')")
            db.execute("INSERT INTO boot_sessions(machine_id,mac,default_menu,token) VALUES(1,'02:00:00:00:00:01','install','fixture-boot-token')")
            db.execute("UPDATE machines SET next_action='reboot', rescue_mode=1 WHERE id=1")
            db.execute("INSERT INTO init_jobs(job_type,status) VALUES('fixture','pending')")
            db.execute("INSERT INTO init_jobs(job_type,status,log,ended_at) VALUES('preset:push_github:1','failed','fixture-log','2020-01-01 00:00:00')")
            db.execute("INSERT INTO os_images(distro,version,arch,enabled,status) VALUES('debian','fixture','amd64',1,'downloaded')")
            db.execute("INSERT INTO settings(key,value) VALUES('wizard_completed','1'),('dhcp_proxy_enabled','on'),('ansible_enabled','on'),('ansible_repo_webhook_enabled','on'),('ansible_repo_url','https://example.invalid/fixture.git')")
        database.chmod(0o600)
        original_db, original_key = database.read_bytes(), key.read_bytes()
        password = b'fixture-password-long-enough'
        selection = dict(confirm_stopped=True, include_accounts=True, include_inventory=True,
                         include_secrets=True, include_master_key=True)
        for flag in selection:
            values = {**selection, flag:False}
            output = root/('missing-'+flag+'.gpg')
            rejected(lambda: export_recovery(database,key,output,password,**values))
            assert not output.exists()
        archive = root/'recovery.gpg'
        export_recovery(database,key,archive,password,**selection)
        assert archive.stat().st_mode & 0o777 == 0o600
        assert all(value not in archive.read_bytes() for value in (master_key,secret.encode(),b'fixture-action-token'))
        assert database.read_bytes() == original_db and key.read_bytes() == original_key

        # Optional configuration and current MOK files are selected separately.
        config = root/'fixture-db.conf'
        config_data = b'{"driver":"sqlite","path":"/var/lib/pxe-tools/pxe-tools.sqlite","fixture":"sensitive"}'
        config.write_bytes(config_data)
        mok = root/'fixture-mok'; mok.mkdir()
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(mok/'MOK.key'),
                        '-out',str(mok/'MOK.pem'),'-subj','/CN=fixture-only','-days','1'],
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
        subprocess.run(['openssl','x509','-in',str(mok/'MOK.pem'),'-outform','DER','-out',str(mok/'MOK.der')],
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
        (mok/'backup-old').mkdir(); (mok/'backup-old/MOK.key').write_bytes(b'fixture-old-key-not-selected')
        mok_names = ('MOK.key','MOK.pem','MOK.der')
        options = dict(db_config=config,include_db_config=True,confirm_db_config_matches=True,
                       mok_dir=mok,include_mok=True)
        for bad in ({'db_config':config}, {'include_db_config':True},
                    {'db_config':config,'include_db_config':True},
                    {'mok_dir':mok}, {'include_mok':True}, {'confirm_db_config_matches':True}):
            target = root/('unconfirmed-'+str(len(list(root.glob('unconfirmed-*'))))+'.gpg')
            rejected(lambda: export_recovery(database,key,target,password,**selection,**bad))
            assert not target.exists()
        for label, extra, expected in (
                ('config-only',dict(db_config=config,include_db_config=True,confirm_db_config_matches=True),{'db.conf'}),
                ('mok-only',dict(mok_dir=mok,include_mok=True),set(mok_names)),
                ('config-and-mok',options,{'db.conf',*mok_names})):
            target = root/(label+'.gpg'); unpacked = root/(label+'-restore')
            export_recovery(database,key,target,password,**selection,**extra)
            encrypted = target.read_bytes()
            assert config_data not in encrypted and (mok/'MOK.key').read_bytes() not in encrypted
            restore_recovery(target,unpacked,password)
            contents = {p.name for p in unpacked.iterdir()}
            assert contents == {'database.sqlite','master.key','manifest.json'} | expected
            if 'db.conf' in expected: assert (unpacked/'db.conf').read_bytes() == config_data
            for name in set(mok_names) & expected:
                assert (unpacked/name).read_bytes() == (mok/name).read_bytes()
                assert (unpacked/name).stat().st_mode & 0o777 == 0o600
            manifest = json.loads((unpacked/'manifest.json').read_text())
            assert manifest['version'] == 2
        for invalid in (b'{"driver":"mysql"}', b'{"driver":"sqlite","driver":"sqlite"}',
                        b'{"driver":"sqlite","path":"relative"}', b'{"driver":"sqlite","x":NaN}'):
            config.write_bytes(invalid)
            rejected(lambda: export_recovery(database,key,root/'invalid-config.gpg',password,**selection,**options))
            assert not (root/'invalid-config.gpg').exists()
        config.write_bytes(config_data)
        der_data = (mok/'MOK.der').read_bytes()
        (mok/'MOK.der').write_bytes(der_data[:-1]+bytes([der_data[-1]^1]))
        rejected(lambda: export_recovery(database,key,root/'mismatched-mok.gpg',password,**selection,**options))
        assert not (root/'mismatched-mok.gpg').exists()
        (mok/'MOK.der').write_bytes(der_data)
        (mok/'MOK.der').unlink()
        rejected(lambda: export_recovery(database,key,root/'missing-mok.gpg',password,**selection,**options))
        (mok/'MOK.der').write_bytes(der_data)
        original_mok_key = (mok/'MOK.key').read_bytes()
        other_key = root/'other-mok.key'
        subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048',
                        '-out',str(other_key)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
        (mok/'MOK.key').write_bytes(other_key.read_bytes())
        rejected(lambda: export_recovery(database,key,root/'wrong-mok-key.gpg',password,**selection,**options))
        (mok/'MOK.key').write_bytes(original_mok_key)
        config_link = root/'linked-db.conf'; config_link.symlink_to(config)
        rejected(lambda: export_recovery(database,key,root/'linked-config.gpg',password,**selection,
                                         **{**options,'db_config':config_link}))
        mok_link = root/'linked-mok'; mok_link.symlink_to(mok, target_is_directory=True)
        rejected(lambda: export_recovery(database,key,root/'linked-mok.gpg',password,**selection,
                                         **{**options,'mok_dir':mok_link}))
        (mok/'MOK.key').unlink(); (mok/'MOK.key').symlink_to(other_key)
        rejected(lambda: export_recovery(database,key,root/'linked-mok-key.gpg',password,**selection,**options))
        (mok/'MOK.key').unlink(); (mok/'MOK.key').write_bytes(original_mok_key)
        real_snapshot = recovery._snapshot
        def change_fixture_config(source, target):
            real_snapshot(source, target)
            config.write_bytes(b'{"driver":"sqlite","path":"/different"}')
        try:
            with patch('backup.recovery._snapshot',side_effect=change_fixture_config):
                rejected(lambda: export_recovery(database,key,root/'changed-config.gpg',password,**selection,**options))
        finally:
            config.write_bytes(config_data)
        assert not (root/'changed-config.gpg').exists()
        existing = root/'existing'; existing.write_bytes(b'keep')
        rejected(lambda: export_recovery(database,key,existing,password,**selection))
        rejected(lambda: restore_recovery(archive,existing,password))
        assert existing.read_bytes() == b'keep'
        existing_dir = root/'existing-dir'; existing_dir.mkdir(); (existing_dir/'sentinel').write_text('keep')
        rejected(lambda: restore_recovery(archive,existing_dir,password))
        assert (existing_dir/'sentinel').read_text() == 'keep'
        partial = root/'fixture-partial'
        with patch('backup.recovery.os.link',side_effect=OSError('fixture publish failure')):
            rejected(lambda: restore_recovery(archive,partial,password))
        assert partial.is_dir() and not list(partial.iterdir())
        symlink = root/'linked-key'; symlink.symlink_to(key)
        rejected(lambda: export_recovery(database,symlink,root/'linked.gpg',password,**selection))
        symlink = root/'linked-db'; symlink.symlink_to(database)
        rejected(lambda: export_recovery(symlink,key,root/'linked-db.gpg',password,**selection))
        symlink = root/'linked-archive'; symlink.symlink_to(archive)
        rejected(lambda: restore_recovery(symlink,root/'linked-restore',password))
        short_key = root/'short-key'; short_key.write_bytes(b'not-a-master-key')
        rejected(lambda: export_recovery(database,short_key,root/'short-key.gpg',password,**selection))
        unsafe_parent = root/'unsafe'; unsafe_parent.mkdir(); unsafe_parent.chmod(0o777)
        rejected(lambda: export_recovery(database,key,unsafe_parent/'out.gpg',password,**selection))
        rejected(lambda: restore_recovery(archive,unsafe_parent/'restore',password))
        rejected(lambda: restore_recovery(archive,root/'wrong',b'wrong-password-long'))
        assert not (root/'wrong').exists()
        data = archive.read_bytes()
        for name, altered in [('truncated',data[:-12]),('altered',data[:len(data)//2]+bytes([data[len(data)//2]^1])+data[len(data)//2+1:])]:
            bad = root/(name+'.gpg'); bad.write_bytes(altered)
            target = root/(name+'-restore')
            rejected(lambda: restore_recovery(bad,target,password))
            assert not target.exists()
        with patch('backup.recovery.encrypt_file',side_effect=OSError('fixture full disk')):
            rejected(lambda: export_recovery(database,key,root/'disk-full.gpg',password,**selection))
        assert not (root/'disk-full.gpg').exists()
        real_snapshot = recovery._snapshot
        def change_fixture_key(source, target):
            real_snapshot(source, target)
            key.write_bytes(b'x' * 32)
        try:
            with patch('backup.recovery._snapshot',side_effect=change_fixture_key):
                rejected(lambda: export_recovery(database,key,root/'changed-key.gpg',password,**selection))
        finally:
            key.write_bytes(master_key)
        assert not (root/'changed-key.gpg').exists()
        assert not [p for p in root.iterdir() if p.name.startswith(('.pxe-recovery-export-','.pxe-recovery-restore-'))]
        restored = root/'restored'
        restore_recovery(archive,restored,password)
        assert restored.stat().st_mode & 0o777 == 0o700
        assert all((restored/name).stat().st_mode & 0o777 == 0o600 for name in ('database.sqlite','master.key','manifest.json'))
        assert (restored/'master.key').read_bytes() == master_key
        assert json.loads((restored/'manifest.json').read_text()) == MANIFEST
        with closing(sqlite3.connect(restored/'database.sqlite')) as db:
            stored = db.execute('SELECT root_password_cipher FROM machines WHERE id=1').fetchone()[0]
            assert SecretBox((restored/'master.key').read_bytes()).decrypt(base64.b64decode(stored)).decode() == secret
            assert db.execute('SELECT username FROM users').fetchone() == ('fixture-user',)
            assert db.execute('SELECT token FROM actions').fetchone()[0] != 'fixture-action-token'
            assert db.execute('SELECT status FROM actions').fetchone() == ('aborted',)
            assert db.execute('SELECT token FROM boot_sessions').fetchone()[0] != 'fixture-boot-token'
            assert db.execute('SELECT next_action,rescue_mode FROM machines').fetchone() == (None,0)
            assert db.execute("SELECT status FROM init_jobs WHERE job_type='fixture'").fetchone() == ('failed',)
            assert db.execute("SELECT status FROM init_jobs WHERE job_type='preset:push_github:1' ORDER BY id DESC LIMIT 1").fetchone() == ('cancelled',)
            assert db.execute("SELECT log FROM init_jobs WHERE job_type='preset:push_github:1' ORDER BY id LIMIT 1").fetchone() == ('fixture-log',)
            assert db.execute("SELECT status,enabled FROM os_images WHERE version='fixture'").fetchone() == ('failed',0)
            assert db.execute("SELECT value FROM settings WHERE key='wizard_completed'").fetchone() == ('0',)
            assert db.execute("SELECT value FROM settings WHERE key='dhcp_proxy_enabled'").fetchone() == ('off',)
            assert db.execute("SELECT value FROM settings WHERE key='ansible_enabled'").fetchone() == ('off',)
            assert db.execute("SELECT value FROM settings WHERE key='ansible_repo_webhook_enabled'").fetchone() == ('off',)
            assert db.execute("SELECT value FROM settings WHERE key='ansible_repo_url'").fetchone() == ('https://example.invalid/fixture.git',)
        php = '''require $argv[1]; $db = new PDO('sqlite:' . $argv[2], null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
$v = $db->query('SELECT root_password_cipher FROM machines WHERE id=1')->fetchColumn();
if (\\Admin\\Util\\Crypto::decrypt($v) !== 'fixture-secret-not-for-logs') exit(2);'''
        result = subprocess.run(['php','-r',php,str(ROOT/'app/src/Util/Crypto.php'),str(restored/'database.sqlite')],
                                env={**os.environ,'PXE_MASTER_KEY_PATH':str(restored/'master.key')},capture_output=True)
        assert result.returncode == 0 and not result.stdout
        assert database.read_bytes() == original_db and key.read_bytes() == original_key

        # Authenticated hostile archives must not be extracted outside the staging directory.
        bad_schema = root/'bad-schema.sqlite'
        with closing(sqlite3.connect(bad_schema)) as db, db:
            db.execute('CREATE TABLE fixture(id INTEGER)')
        for name, kind in [('traversal','file'),('symlink','symlink'),('duplicate','duplicate'),
                           ('version-bool','boolean'),('duplicate-meta','duplicate-meta'),
                           ('wrong-schema','schema'),('partial-mok','partial-mok'),
                           ('extra-config','extra-config'),('bad-mok','bad-mok')]:
            plain = root/(name+'.tar')
            with tarfile.open(plain,'w') as tar:
                manifest = json.dumps(MANIFEST).encode()
                if kind == 'boolean': manifest = json.dumps({**MANIFEST, 'version':True}).encode()
                if kind == 'duplicate-meta':
                    manifest = b'{"format":"pxe-tools-offline-recovery","version":1,"version":1,"includes":["accounts","inventory","secrets","master_key"]}'
                if kind in ('partial-mok','bad-mok'):
                    manifest = json.dumps({**MANIFEST, 'version':2, 'includes':[*MANIFEST['includes'],'mok']}).encode()
                entries = [('manifest.json',manifest),('master.key',master_key),
                           ('database.sqlite',bad_schema.read_bytes() if kind == 'schema' else database.read_bytes())]
                for filename, content in entries:
                    item=tarfile.TarInfo(filename); item.size=len(content); tar.addfile(item,io.BytesIO(content))
                if kind == 'file':
                    item=tarfile.TarInfo('../escape'); item.size=1; tar.addfile(item,io.BytesIO(b'x'))
                elif kind == 'symlink':
                    item=tarfile.TarInfo('link'); item.type=tarfile.SYMTYPE; item.linkname='../escape'; tar.addfile(item)
                elif kind == 'duplicate':
                    item=tarfile.TarInfo('master.key'); item.size=32; tar.addfile(item,io.BytesIO(master_key))
                elif kind in ('partial-mok','bad-mok','extra-config'):
                    extra = {'db.conf':config_data} if kind == 'extra-config' else {'MOK.key':(mok/'MOK.key').read_bytes()}
                    if kind == 'bad-mok':
                        extra.update({'MOK.pem':(mok/'MOK.pem').read_bytes(),
                                      'MOK.der':der_data[:-1]+bytes([der_data[-1]^1])})
                    for filename, content in extra.items():
                        item=tarfile.TarInfo(filename); item.size=len(content); tar.addfile(item,io.BytesIO(content))
            hostile = root/(name+'-hostile.gpg')
            with _gpg_home() as home: encrypt_file(plain,hostile,password,home)
            target = root/(name+'-hostile-output')
            rejected(lambda: restore_recovery(hostile,target,password))
            assert not target.exists() and not (root/'escape').exists()

        with closing(sqlite3.connect(database)) as db:
            assert db.execute('PRAGMA journal_mode=WAL').fetchone()[0].lower() == 'wal'
            db.execute("INSERT INTO machines(mac) VALUES('02:00:00:00:00:02')"); db.commit()
            wal = Path(str(database)+'-wal'); assert wal.exists()
            rejected(lambda: export_recovery(database,key,root/'wal.gpg',password,**selection))
            assert wal.exists() and not (root/'wal.gpg').exists()
        cli = [sys.executable,str(ROOT/'app/cli/recovery-backup.py'),'--passphrase-fd','0']
        flags = ['--confirm-stopped','--include-accounts','--include-inventory','--include-secrets','--include-master-key']
        # A caller may select the previously released CLI for an independent
        # compatibility check. Otherwise exercise the version-1 format here.
        legacy_cli = Path(os.environ.get('PXE_RECOVERY_V1_CLI',str(ROOT/'app/cli/recovery-backup.py')))
        if not legacy_cli.is_absolute() or not legacy_cli.is_file():
            raise AssertionError('The independently saved previous backup CLI is required')
        legacy_archive = root/'legacy-v1.gpg'; legacy_output = root/'legacy-v1-restored'
        result = subprocess.run([sys.executable,str(legacy_cli),'--passphrase-fd','0','export','--db',str(database),
                                 '--master-key',str(key),'--output',str(legacy_archive),*flags],
                                input=password+b'\n',capture_output=True)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        restore_recovery(legacy_archive,legacy_output,password)
        assert json.loads((legacy_output/'manifest.json').read_text()) == MANIFEST
        assert (legacy_output/'master.key').read_bytes() == master_key
        cli_archive = root/'cli.gpg'; cli_dir = root/'cli-restored'
        result = subprocess.run(cli+['export','--db',str(database),'--master-key',str(key),'--output',str(cli_archive),*flags[:-1]],
                                input=password+b'\n',capture_output=True)
        assert result.returncode != 0 and not cli_archive.exists()
        wal_mode_before = database.read_bytes()
        result = subprocess.run(cli+['export','--db',str(database),'--master-key',str(key),'--output',str(cli_archive),*flags],
                                input=password+b'\n',capture_output=True)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        assert database.read_bytes() == wal_mode_before and not Path(str(database)+'-wal').exists() and not Path(str(database)+'-shm').exists()
        result = subprocess.run(cli+['restore','--input',str(cli_archive),'--output-dir',str(cli_dir)],
                                input=password+b'\n',capture_output=True)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        assert password not in result.stdout+result.stderr and secret.encode() not in result.stdout+result.stderr
        assert (cli_dir/'master.key').read_bytes() == master_key
        full_cli_archive = root/'cli-with-options.gpg'; full_cli_dir = root/'cli-with-options'
        option_flags = ['--db-config',str(config),'--include-db-config','--confirm-db-config-matches',
                        '--mok-dir',str(mok),'--include-mok']
        result = subprocess.run(cli+['export','--db',str(database),'--master-key',str(key),'--output',str(full_cli_archive),
                                     *flags,'--db-config',str(config)],input=password+b'\n',capture_output=True)
        assert result.returncode != 0 and not full_cli_archive.exists()
        result = subprocess.run(cli+['export','--db',str(database),'--master-key',str(key),'--output',str(full_cli_archive),
                                     *flags,*option_flags],input=password+b'\n',capture_output=True)
        assert result.returncode == 0, result.stderr.decode(errors='replace')
        result = subprocess.run(cli+['restore','--input',str(full_cli_archive),'--output-dir',str(full_cli_dir)],
                                input=password+b'\n',capture_output=True)
        assert result.returncode == 0 and (full_cli_dir/'db.conf').read_bytes() == config_data
        assert all((full_cli_dir/name).read_bytes() == (mok/name).read_bytes() for name in mok_names)
        assert not [p for p in root.iterdir() if p.name.startswith(('.pxe-recovery-export-','.pxe-recovery-restore-'))]
        print('OK encrypted full SQLite + key, explicit selection, offline restore, authentication and no overwrite')


if __name__ == '__main__': main()
