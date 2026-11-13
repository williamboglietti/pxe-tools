#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise selected PXE trees and adversarial members in isolated recovery archives."""
from contextlib import closing
import hashlib
import io
import json
import os
from pathlib import Path
import resource
import shutil
import sqlite3
import subprocess
import sys
import tarfile
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT/'app'))
from backup.openpgp import BackupError, _gpg_home, encrypt_file  # noqa: E402
from backup.recovery import MANIFEST, export_recovery, restore_recovery  # noqa: E402
import backup.recovery as recovery  # noqa: E402


def rejected(action):
    try: action()
    except BackupError: return
    raise AssertionError('Unsafe asset recovery operation was accepted')


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').digest()


def main():
    if (os.environ.get('PXE_RECOVERY_ASSET_TEST') != '1'
            or {p.name for p in Path('/sys/class/net').iterdir()} != {'lo'}):
        raise SystemExit('Run only in a dedicated networkless test environment')
    with tempfile.TemporaryDirectory(prefix='pxe-recovery-assets-') as temporary:
        root=Path(temporary); db=root/'database.sqlite'; key=root/'key'; key.write_bytes(os.urandom(32))
        with closing(sqlite3.connect(db)) as conn, conn:
            conn.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            conn.execute("INSERT INTO os_images(distro,version,arch,enabled,status) VALUES('debian','fixture','amd64',1,'downloaded')")
        password=b'fixture-asset-passphrase'; flags=dict(confirm_stopped=True,include_accounts=True,
            include_inventory=True,include_secrets=True,include_master_key=True)
        http=root/'http-source'; tftp=root/'tftp-source'
        tls=root/'tls-source'; tls.mkdir(); (tls/'fixture.key').write_bytes(b'fixture-tls-key')
        ansible=root/'ansible-source'; (ansible/'home/.ssh').mkdir(parents=True)
        (ansible/'home/.ssh/fixture-key').write_bytes(b'fixture-ansible-key')
        (http/'netboot/debian/13/amd64').mkdir(parents=True)
        (http/'netboot/trixie').mkdir(parents=True)
        (http/'static-bin').symlink_to('/usr/lib/pxe-tools/static-bin')
        (http/'empty').mkdir(); (http/'preseed example.php').write_text('fixture-generated-secret')
        large=http/'netboot/debian/13/amd64/linux'
        with large.open('wb') as stream:
            for _ in range(32): stream.write(b'fixture-image-block'+b'x'*(1024*1024-19))
        (tftp/'pxelinux.cfg').mkdir(parents=True)
        (tftp/'pxelinux.cfg/default').write_text('fixture-boot-menu')
        (tftp/'pxelinux.cfg/default').chmod(0o640)
        tftp.chmod(0o750)
        for options in ({'http_root':http}, {'include_http':True}, {'tftp_root':tftp}, {'include_tftp':True}, {'tls_root':tls}, {'include_tls':True}, {'ansible_root':ansible}, {'include_ansible':True}):
            rejected(lambda:export_recovery(db,key,root/'unconfirmed.gpg',password,**flags,**options))
            assert not (root/'unconfirmed.gpg').exists()
        options=dict(http_root=http,include_http=True,tftp_root=tftp,include_tftp=True,tls_root=tls,include_tls=True,ansible_root=ansible,include_ansible=True)
        archive=root/'assets.gpg'; restored=root/'restored'
        export_recovery(db,key,archive,password,**flags,**options)
        restore_recovery(archive,restored,password)
        manifest=json.loads((restored/'manifest.json').read_text())
        assert manifest['version']==3 and manifest['includes'][-4:]==['http_files','tftp_files','tls_files','ansible_files']
        assert (restored/'tls/fixture.key').read_bytes()==b'fixture-tls-key'
        assert (restored/'ansible/home/.ssh/fixture-key').read_bytes()==b'fixture-ansible-key'
        with closing(sqlite3.connect(restored/'database.sqlite')) as restored_db:
            assert restored_db.execute("SELECT status,enabled FROM os_images WHERE version='fixture'").fetchone() == ('downloaded',1)
        assert not os.path.lexists(restored/'http/static-bin')
        assert digest(restored/'http/netboot/debian/13/amd64/linux') == digest(large)
        assert (restored/'http/empty').is_dir()
        assert (restored/'http/preseed example.php').read_text()=='fixture-generated-secret'
        assert (restored/'tftp/pxelinux.cfg/default').stat().st_mode & 0o777 == 0o640
        assert (restored/'tftp').stat().st_mode & 0o777 == 0o750
        tampered=root/'tampered-assets.gpg'; shutil.copyfile(archive,tampered)
        with tampered.open('r+b') as stream:
            stream.seek(tampered.stat().st_size//2); byte=stream.read(1); stream.seek(-1,1); stream.write(bytes([byte[0]^1]))
        rejected(lambda:restore_recovery(tampered,root/'tampered-assets-output',password))
        assert not (root/'tampered-assets-output').exists()
        # Linux reports ru_maxrss in KiB; the 32 MiB file should be streamed.
        if resource.getrusage(resource.RUSAGE_SELF).ru_maxrss > 256 * 1024:
            raise AssertionError('Recovery used excessive memory for a streamed fixture')
        for category in ('http','tftp'):
            flags_one={category+'_root':http if category=='http' else tftp, 'include_'+category:True}
            a=root/(category+'-only.gpg'); out=root/(category+'-only')
            export_recovery(db,key,a,password,**flags,**flags_one)
            restore_recovery(a,out,password)
            assert (out/category).is_dir() and not (out/('tftp' if category=='http' else 'http')).exists()
        cli=[sys.executable,str(ROOT/'app/cli/recovery-backup.py'),'--passphrase-fd','0']
        cli_archive=root/'cli-assets.gpg'; cli_output=root/'cli-assets'
        consent=['--confirm-stopped','--include-accounts','--include-inventory','--include-secrets','--include-master-key']
        cmd=cli+['export','--db',str(db),'--master-key',str(key),'--output',str(cli_archive),*consent,'--tftp-root',str(tftp)]
        result=subprocess.run(cmd,input=password+b'\n',capture_output=True)
        assert result.returncode!=0 and not cli_archive.exists()
        result=subprocess.run(cmd+['--include-tftp'],input=password+b'\n',capture_output=True)
        assert result.returncode==0, result.stderr.decode(errors='replace')
        result=subprocess.run(cli+['restore','--input',str(cli_archive),'--output-dir',str(cli_output)],
                              input=password+b'\n',capture_output=True)
        assert result.returncode==0 and (cli_output/'tftp/pxelinux.cfg/default').read_text()=='fixture-boot-menu'
        rejected(lambda:export_recovery(db,key,http/'inside.gpg',password,**flags,**options))
        assert not (http/'inside.gpg').exists()
        for label,target in (('outside','../../../outside'),('absolute','/etc/passwd'),('inside','netboot/debian')):
            bad=http/label; bad.symlink_to(target)
            rejected(lambda:export_recovery(db,key,root/(label+'.gpg'),password,**flags,**options))
            bad.unlink()
        # Each link is lexically inside the tree, but the first link changes
        # the meaning of '..' in the second one.
        (http/'chain-a').symlink_to('.')
        (http/'chain-z').symlink_to('chain-a/../outside')
        rejected(lambda:export_recovery(db,key,root/'chain-escape.gpg',password,**flags,**options))
        (http/'chain-a').unlink(); (http/'chain-z').unlink()
        (http/'cycle-a').symlink_to('cycle-b'); (http/'cycle-b').symlink_to('cycle-a')
        rejected(lambda:export_recovery(db,key,root/'cycle.gpg',password,**flags,**options))
        (http/'cycle-a').unlink(); (http/'cycle-b').unlink()
        (http/'reserved-link').symlink_to('static-bin/../app')
        rejected(lambda:export_recovery(db,key,root/'reserved.gpg',password,**flags,**options))
        (http/'reserved-link').unlink()
        fifo=http/'fixture-fifo'; os.mkfifo(fifo)
        rejected(lambda:export_recovery(db,key,root/'fifo.gpg',password,**flags,**options)); fifo.unlink()
        with patch('backup.assets.MAX_ASSET_BYTES',1024):
            rejected(lambda:export_recovery(db,key,root/'too-large.gpg',password,**flags,**options))
        assert not (root/'too-large.gpg').exists()
        badname=http/'bad\nname'; badname.write_text('fixture')
        rejected(lambda:export_recovery(db,key,root/'bad-name.gpg',password,**flags,**options)); badname.unlink()
        large.chmod(0o4755)
        rejected(lambda:export_recovery(db,key,root/'special-mode.gpg',password,**flags,**options)); large.chmod(0o644)
        real_add=recovery.add_tree
        def mutate_asset(tar,path,prefix,records):
            real_add(tar,path,prefix,records)
            if prefix=='http': (http/'new-file').write_text('fixture change')
        try:
            with patch('backup.recovery.add_tree',side_effect=mutate_asset):
                rejected(lambda:export_recovery(db,key,root/'changing.gpg',password,**flags,**options))
        finally: (http/'new-file').unlink(missing_ok=True)
        assert not (root/'changing.gpg').exists()
        # Encrypted archives with malicious paths or link types must not escape staging.
        manifest={**MANIFEST,'version':3,'includes':[*MANIFEST['includes'],'http_files']}
        for variant in ('escape','absolute-link','relative-link','symlink-parent','hardlink','fifo','unannounced','chain-escape','cycle','reserved'):
            plain=root/(variant+'.tar')
            with tarfile.open(plain,'w') as tar:
                base=[('manifest.json',json.dumps(MANIFEST if variant=='unannounced' else manifest).encode()),
                      ('master.key',key.read_bytes()),('database.sqlite',db.read_bytes())]
                for name,data in base:
                    m=tarfile.TarInfo(name);m.size=len(data);tar.addfile(m,io.BytesIO(data))
                m=tarfile.TarInfo('http');m.type=tarfile.DIRTYPE;tar.addfile(m)
                if variant=='escape':
                    m=tarfile.TarInfo('http/../escape');m.size=1;tar.addfile(m,io.BytesIO(b'x'))
                elif variant=='absolute-link':
                    m=tarfile.TarInfo('http/link');m.type=tarfile.SYMTYPE;m.linkname='/etc/passwd';tar.addfile(m)
                elif variant=='relative-link':
                    m=tarfile.TarInfo('http/link');m.type=tarfile.SYMTYPE;m.linkname='../../outside';tar.addfile(m)
                elif variant=='symlink-parent':
                    m=tarfile.TarInfo('http/link');m.type=tarfile.SYMTYPE;m.linkname='safe';tar.addfile(m)
                    m=tarfile.TarInfo('http/link/file');m.size=1;tar.addfile(m,io.BytesIO(b'x'))
                elif variant=='hardlink':
                    m=tarfile.TarInfo('http/link');m.type=tarfile.LNKTYPE;m.linkname='master.key';tar.addfile(m)
                elif variant=='fifo':
                    m=tarfile.TarInfo('http/pipe');m.type=tarfile.FIFOTYPE;tar.addfile(m)
                elif variant in ('chain-escape','cycle'):
                    m=tarfile.TarInfo('http/a');m.type=tarfile.SYMTYPE;m.linkname='.' if variant=='chain-escape' else 'z';tar.addfile(m)
                    m=tarfile.TarInfo('http/z');m.type=tarfile.SYMTYPE;m.linkname='a/../outside' if variant=='chain-escape' else 'a';tar.addfile(m)
                elif variant=='reserved':
                    m=tarfile.TarInfo('http/z');m.type=tarfile.SYMTYPE;m.linkname='static-bin/../app';tar.addfile(m)
            encrypted=root/(variant+'-hostile.gpg')
            with _gpg_home() as home: encrypt_file(plain,encrypted,password,home)
            target=root/(variant+'-hostile-output')
            rejected(lambda:restore_recovery(encrypted,target,password))
            assert not target.exists() and not (root/'escape').exists()
        with patch('backup.recovery.MAX_ASSET_ENTRIES',2):
            rejected(lambda:restore_recovery(archive,root/'too-many',password))
        assert not (root/'too-many').exists()
        # The released CLI may be supplied for independent compatibility tests.
        legacy=Path(os.environ.get('PXE_RECOVERY_V2_CLI',str(ROOT/'app/cli/recovery-backup.py')))
        if not legacy.is_absolute() or not legacy.is_file(): raise AssertionError('Saved v2 CLI required')
        cfg=root/'db.conf'; cfg.write_text('{"driver":"sqlite","path":"/var/lib/pxe-tools/pxe-tools.sqlite"}')
        v2=root/'legacy-v2.gpg'; v2out=root/'legacy-v2'
        args=[sys.executable,str(legacy),'--passphrase-fd','0','export','--db',str(db),'--master-key',str(key),
              '--output',str(v2),'--confirm-stopped','--include-accounts','--include-inventory','--include-secrets',
              '--include-master-key','--db-config',str(cfg),'--include-db-config','--confirm-db-config-matches']
        result=subprocess.run(args,input=password+b'\n',capture_output=True)
        assert result.returncode==0, result.stderr.decode(errors='replace')
        restore_recovery(v2,v2out,password)
        assert json.loads((v2out/'manifest.json').read_text())['version']==2 and (v2out/'db.conf').read_bytes()==cfg.read_bytes()
        assert not [p for p in root.iterdir() if p.name.startswith(('.pxe-recovery-export-','.pxe-recovery-restore-'))]
        print('OK selected asset trees, link refusal, adversarial archives, streaming and v2 compatibility')


if __name__=='__main__': main()
