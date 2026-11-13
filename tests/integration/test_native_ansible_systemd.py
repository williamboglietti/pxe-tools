#!/usr/bin/env python3
"""Exercise the optional distribution Ansible through the real native worker."""
# SPDX-License-Identifier: LGPL-3.0-only
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('PXE_NATIVE_ANSIBLE_IMAGE','')
if (os.geteuid()!=0 or os.environ.get('PXE_NATIVE_ANSIBLE_TEST')!='1'
        or not re.fullmatch(r'pxe-native-ansible-[a-f0-9]{10}:local',IMAGE)):
    raise SystemExit('Use only the dedicated test VM and a uniquely named image')


def docker(*args,check=True):
    p=subprocess.run(['docker',*map(str,args)],text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if check and p.returncode: raise RuntimeError('Disposable Docker command failed: '+p.stderr[-1200:])
    return p


def wait_for(predicate,description,timeout=120):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if predicate():return
        time.sleep(1)
    raise RuntimeError('Timed out: '+description)


def test_fresh_database_mode(root):
    name='pxe-native-ansible-fresh-'+secrets.token_hex(5)
    try:
        docker('run','-d','--name',name,'--network','none','--privileged','--cgroupns','private',
               '--tmpfs','/run','--tmpfs','/run/lock','--tmpfs','/tmp',
               '--mount',f'type=bind,src={root},dst=/fixture,readonly',
               '--entrypoint','/lib/systemd/systemd',IMAGE,'--unit=basic.target')
        wait_for(lambda:docker('exec',name,'systemctl','list-units','--no-legend',check=False).returncode==0,'fresh systemd')
        docker('exec',name,'dpkg','-i','/fixture/base.deb')
        docker('exec',name,'systemctl','start','pxe-tools.target')
        wait_for(lambda:docker('exec',name,'curl','-sS','--max-time','2','-o','/dev/null',
                               'http://127.0.0.1:8080/init/db',check=False).returncode==0,'fresh setup HTTP')
        client='''import http.cookiejar,re,urllib.parse,urllib.request
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base='http://127.0.0.1:8080'
page=opener.open(base+'/init/db',timeout=5).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
opener.open(base+'/init/db',urllib.parse.urlencode({'csrf_token':token,'driver':'sqlite'}).encode(),timeout=5).read()
'''
        docker('exec',name,'python3','-c',client)
        mode=docker('exec',name,'stat','-c','%a','/var/lib/pxe-tools/pxe-tools.sqlite').stdout.strip()
        if mode!='600':raise RuntimeError('Fresh SQLite database is not private: '+mode)
        print('OK freshly initialized SQLite database has mode 600',flush=True)
    finally:
        docker('rm','-f',name,check=False)


def main():
    name='pxe-native-ansible-fixture-'+secrets.token_hex(5)
    with tempfile.TemporaryDirectory(prefix='pxe-native-ansible-fixture-',dir='/var/tmp') as tmp:
        root=Path(tmp);root.chmod(0o755)
        data=root/'data';conf=root/'conf';data.mkdir();conf.mkdir()
        tftp=data/'tftp';tftp.mkdir()
        for filename in ('grubx64.efi','lpxelinux.0'):(tftp/filename).write_bytes(b'fixture')
        (conf/'master.key').write_bytes(os.urandom(32));(conf/'master.key').chmod(0o600)
        (conf/'db.conf').write_text(json.dumps({'driver':'sqlite','path':'/var/lib/pxe-tools/pxe-tools.sqlite'}))
        (conf/'db.conf').chmod(0o600)
        password=docker('run','--rm','--network','none','--entrypoint','php',IMAGE,'-r',
                        'echo password_hash("fixture-login-password", PASSWORD_DEFAULT);').stdout
        database=data/'pxe-tools.sqlite'
        with sqlite3.connect(database) as db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.executemany('INSERT INTO settings(key,value) VALUES(?,?)',[
                ('ansible_enabled','off'),('wizard_completed','1'),('db_init_done','1'),
                ('admin_created','1'),('init_completed','1'),('https_enabled','off'),
                ('catalog_auto_refresh_enabled','off')])
            db.execute('INSERT INTO users(username,password_hash) VALUES(?,?)',('fixture-admin',password))
            db.execute("INSERT INTO machines(id,mac,hostname,status,install_config_json) VALUES(1,'02:00:00:00:00:01','fixture','installed',?)",
                       (json.dumps({'ansible_role':'base'}),))
        database.chmod(0o600)
        base=root/'base.deb';module=root/'module.deb'
        subprocess.run(['python3',str(ROOT/'packaging/native/build-apt-package-candidate.py'),str(base),'--version','0.1.0~beta1','--maintainer','PXE Tools <security@pxetools.com>'],check=True,stdout=subprocess.DEVNULL)
        subprocess.run(['python3',str(ROOT/'packaging/native/build-local-ansible-package.py'),str(module),'--release','--version','0.1.0~beta1'],check=True,stdout=subprocess.DEVNULL)
        base.chmod(0o644);module.chmod(0o644)
        test_fresh_database_mode(root)
        try:
            docker('run','-d','--name',name,'--network','none','--privileged','--cgroupns','private',
                   '--tmpfs','/run','--tmpfs','/run/lock','--tmpfs','/tmp',
                   '--mount',f'type=bind,src={root},dst=/fixture,readonly',
                   '--mount',f'type=bind,src={data},dst=/var/lib/pxe-tools',
                   '--mount',f'type=bind,src={conf},dst=/etc/pxe-tools',
                   '--entrypoint','/lib/systemd/systemd',IMAGE,'--unit=basic.target')
            def systemd_ready():
                state=json.loads(docker('inspect',name).stdout)[0]
                if not state['State']['Running'] or state['HostConfig']['NetworkMode']!='none':
                    raise RuntimeError('Fixture stopped or lost network isolation')
                return docker('exec',name,'systemctl','list-units','--no-legend',check=False).returncode==0
            wait_for(systemd_ready,'systemd')
            docker('exec',name,'dpkg','-i','/fixture/base.deb')
            if docker('exec',name,'getent','passwd','pxe-tools-ansible',check=False).returncode==0:
                raise RuntimeError('Base package created the optional account')
            docker('exec',name,'systemctl','start','pxe-tools.target')
            def state():
                with sqlite3.connect(database,timeout=10) as db:
                    value=db.execute("SELECT value FROM settings WHERE key='ansible_runtime_status'").fetchone()
                return json.loads(value[0]).get('state') if value else None
            wait_for(lambda:state()=='not_configured','module absent observation')
            old_pid=docker('exec',name,'systemctl','show','--property=MainPID','--value','pxe-tools-worker.service').stdout.strip()
            # A pre-existing account with extra groups must be rejected without mutation.
            docker('exec',name,'groupadd','pxe-fixture-extra')
            docker('exec',name,'useradd','--system','--user-group','--no-create-home',
                   '--home-dir','/var/lib/pxe-tools-ansible/home','--shell','/usr/sbin/nologin',
                   '--groups','pxe-fixture-extra','pxe-tools-ansible')
            before=docker('exec',name,'id','pxe-tools-ansible').stdout
            if docker('exec',name,'dpkg','-i','/fixture/module.deb',check=False).returncode==0:
                raise RuntimeError('Account with supplementary group unexpectedly accepted')
            if docker('exec',name,'id','pxe-tools-ansible').stdout!=before:
                raise RuntimeError('Rejected pre-existing account was modified')
            docker('exec',name,'test','!','-e','/usr/lib/systemd/system/pxe-tools-worker.service.d/50-ansible.conf')
            docker('exec',name,'userdel','pxe-tools-ansible')
            docker('exec',name,'groupdel','pxe-fixture-extra')
            docker('exec',name,'dpkg','-i','/fixture/module.deb')
            if docker('exec',name,'systemctl','show','--property=MainPID','--value','pxe-tools-worker.service').stdout.strip()!=old_pid:
                raise RuntimeError('Module installation restarted the worker')
            uid=docker('exec',name,'id','-u','pxe-tools-ansible').stdout.strip()
            gid=docker('exec',name,'id','-g','pxe-tools-ansible').stdout.strip()
            if uid=='0' or gid=='0':raise RuntimeError('Module account is privileged')
            for path in ('/etc/pxe-tools/master.key','/var/lib/pxe-tools/pxe-tools.sqlite'):
                if docker('exec',name,'runuser','-u','pxe-tools-ansible','--','test','-r',path,check=False).returncode==0:
                    raise RuntimeError('Module account can read protected appliance data')
            setup='''set -eu
work=/var/lib/pxe-tools-ansible/workspace
install -d -o pxe-tools-ansible -g pxe-tools-ansible -m 0700 "$work/playbooks"
printf '%s\\n' '[defaults]' 'inventory = inventory.ini' 'host_key_checking = True' > "$work/ansible.cfg"
printf '%s\\n' 'fixture ansible_connection=local ansible_python_interpreter=/usr/bin/python3' > "$work/inventory.ini"
printf '%s\\n' 'fixture_vault_value: fixture-only-secret' > "$work/playbooks/vault.yml"
cat > "$work/playbooks/base.yml" <<'PLAYBOOK'
- hosts: fixture
  gather_facts: false
  vars_files:
    - vault.yml
  tasks:
    - ansible.builtin.command: id -u
      register: local_uid
      changed_when: false
    - ansible.builtin.command: id -G
      register: local_groups
      changed_when: false
    - ansible.builtin.assert:
        that:
          - local_uid.stdout == 'FIXTURE_UID'
          - local_groups.stdout == 'FIXTURE_GID'
          - fixture_vault_value == 'fixture-only-secret'
    - ansible.builtin.copy:
        content: 'fixture completed'
        dest: /tmp/pxe-native-ansible-result
        mode: '0600'
PLAYBOOK
chown pxe-tools-ansible:pxe-tools-ansible "$work/ansible.cfg" "$work/inventory.ini" "$work/playbooks/base.yml" "$work/playbooks/vault.yml"
chmod 0600 "$work/ansible.cfg" "$work/inventory.ini" "$work/playbooks/base.yml" "$work/playbooks/vault.yml"
printf '%s' 'fixture-only-vault-password' > /tmp/fixture-vault-password
chown pxe-tools-ansible:pxe-tools-ansible /tmp/fixture-vault-password
chmod 0600 /tmp/fixture-vault-password
runuser -u pxe-tools-ansible -- ansible-vault encrypt "$work/playbooks/vault.yml" --vault-password-file /tmp/fixture-vault-password
rm /tmp/fixture-vault-password
'''.replace('FIXTURE_UID',uid).replace('FIXTURE_GID',gid)
            docker('exec',name,'sh','-c',setup)
            docker('exec',name,'systemctl','restart','pxe-tools-worker.service')
            wait_for(lambda:state()=='ready','system Ansible runtime observation')
            runtime_check=('env','PXE_ANSIBLE_MODE=system','PYTHONPATH=/usr/lib/pxe-tools/app',
                           'python3','-c','import worker; worker.resolve_ansible_runtime()')
            docker('exec',name,*runtime_check)
            try:
                database.chmod(0o644)
                if docker('exec',name,*runtime_check,check=False).returncode==0:
                    raise RuntimeError('Module accepted an exposed existing SQLite database')
            finally:
                database.chmod(0o600)
            wait_for(lambda:docker('exec',name,'curl','-sS','--max-time','2','-o','/dev/null',
                                   'http://127.0.0.1:8080/login',check=False).returncode==0,'HTTP login')
            ui='''import http.cookiejar,re,urllib.parse,urllib.request
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base='http://127.0.0.1:8080'
page=opener.open(base+'/login',timeout=5).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
opener.open(base+'/login',urllib.parse.urlencode({'username':'fixture-admin','password':'fixture-login-password','csrf_token':token}).encode(),timeout=5).read()
page=opener.open(base+'/settings/ansible',timeout=5).read().decode()
assert 'environnement Ansible compatible' in page and '/var/lib/pxe-tools-ansible/workspace' in page
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
values={'csrf_token':token,'ansible_enabled':'1','repo_url':'','repo_branch':'main','repo_profile':'',
        'playbooks_dir':'/var/lib/pxe-tools-ansible/workspace'}
page=opener.open(base+'/settings/ansible',urllib.parse.urlencode(values).encode(),timeout=5).read().decode()
assert 'Playbooks locaux' in page
page=opener.open(base+'/settings/ansible/vault-unlock',urllib.parse.urlencode({'csrf_token':token,'vault_password':'fixture-only-vault-password'}).encode(),timeout=5).read().decode()
assert 'déverrouillage est en attente' in page
'''
            docker('exec',name,'python3','-c',ui)
            with sqlite3.connect(database,timeout=10) as db:
                if db.execute("SELECT value FROM settings WHERE key='ansible_enabled'").fetchone()!=('on',):raise RuntimeError('Module not enabled')
                db.execute("INSERT INTO installations(id,machine_id,install_status,ansible_status) VALUES(1,1,'success','pending')")
            def installation():
                with sqlite3.connect(database,timeout=10) as db:return db.execute('SELECT ansible_status FROM installations WHERE id=1').fetchone()[0]
            wait_for(lambda:installation() in ('success','failed'),'real native Ansible run',timeout=180)
            if installation()!='success':raise RuntimeError('Native Ansible run failed')
            docker('exec',name,'test','-f','/tmp/pxe-native-ansible-result')
            docker('exec',name,'test','!','-e','/dev/shm/pxe-vault/unlock')
            if docker('exec',name,'dpkg','--purge','pxe-tools-ansible',check=False).returncode==0:
                raise RuntimeError('Module removal succeeded while worker was running')
            docker('exec',name,'systemctl','stop','pxe-tools-worker.service')
            docker('exec',name,'dpkg','--purge','pxe-tools-ansible')
            docker('exec',name,'test','-f','/var/lib/pxe-tools-ansible/workspace/playbooks/vault.yml')
            docker('exec',name,'id','pxe-tools-ansible')
            docker('exec',name,'systemctl','start','pxe-tools-worker.service')
            wait_for(lambda:state()=='not_configured','module removal observation')
            print('OK real native worker executes a Vault playbook as unprivileged account; removal preserves workspace',flush=True)
        except Exception:
            logs=docker('exec',name,'journalctl','-u','pxe-tools-worker.service','-u','pxe-tools-init.service','-n','35','--no-pager',check=False)
            text=re.sub(r'(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])','[IPv4]',logs.stdout)
            print('Native module fixture diagnostic:\n'+text[-3500:],flush=True)
            with sqlite3.connect(database) as db:
                row=db.execute('SELECT ansible_log FROM installations WHERE id=1').fetchone()
                if row and row[0]:print('Fixture playbook diagnostic:\n'+row[0][-2000:],flush=True)
            raise
        finally:
            docker('rm','-f',name,check=False)


if __name__=='__main__':main()
