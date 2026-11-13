#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run real Ansible from a persistent volume in the existing worker container.

The wheelhouse is prepared separately for this disposable test. The worker and
playbook run without network access, against synthetic local data only.
"""
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
PREP = os.environ.get('PXE_TEST_ANSIBLE_PREP_IMAGE', '')
BASE = os.environ.get('PXE_TEST_ANSIBLE_APPLIANCE_IMAGE', '')
WHEELS = Path(os.environ.get('PXE_TEST_WHEELHOUSE', ''))
if (os.geteuid() != 0 or os.environ.get('PXE_ANSIBLE_VOLUME_TEST') != '1'
        or not re.fullmatch(r'pxe-volume-prep-[a-f0-9]{10}:local', PREP)
        or not re.fullmatch(r'pxe-volume-app-[a-f0-9]{10}:local', BASE)
        or not WHEELS.is_absolute() or not WHEELS.is_dir()):
    raise SystemExit('Use only a dedicated disposable VM and explicitly named fixture inputs.')


def docker(*args, check=True):
    result = subprocess.run(['docker', *args], text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, check=False)
    if check and result.returncode:
        raise RuntimeError(f'disposable Docker command failed ({result.returncode}): {result.stderr[-1500:]}')
    return result


def wait_for(predicate, description, timeout=180):
    until = time.monotonic()+timeout
    while time.monotonic()<until:
        if predicate(): return
        time.sleep(1)
    raise RuntimeError('timed out: '+description)


def main():
    suffix=secrets.token_hex(5)
    volume='pxe-volume-test-'+suffix
    worker='pxe-volume-worker-'+suffix
    password='fixture-only-vault-password'
    with tempfile.TemporaryDirectory(prefix='pxe-volume-test-',dir='/var/tmp') as tmp:
        root=Path(tmp);root.chmod(0o755)
        work=root/'workspace';work.mkdir(mode=0o700);os.chown(work,1001,1001)
        playbooks=work/'playbooks';playbooks.mkdir();os.chown(playbooks,1001,1001)
        files={
            work/'ansible.cfg':'[defaults]\ninventory = inventory.ini\nhost_key_checking = True\n',
            work/'inventory.ini':'fixture ansible_connection=local ansible_python_interpreter=/usr/bin/python3\n',
            playbooks/'vault.yml':'fixture_vault_value: fixture-only-secret\n',
            playbooks/'base.yml':'''- hosts: fixture
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
    - ansible.builtin.command: cat /var/lib/pxe-tools/pxe-tools.sqlite
      register: database_read
      failed_when: false
      changed_when: false
      no_log: true
    - ansible.builtin.command: cat /etc/pxe-tools/master.key
      register: master_key_read
      failed_when: false
      changed_when: false
      no_log: true
    - ansible.builtin.command: touch /var/lib/pxe-ansible/fixture-write
      register: runtime_write
      failed_when: false
      changed_when: false
      no_log: true
    - ansible.builtin.assert:
        that:
          - local_uid.stdout == '1001'
          - local_groups.stdout == '1001'
          - database_read.rc != 0
          - master_key_read.rc != 0
          - runtime_write.rc != 0
          - lookup('env', 'PXE_TEST_SECRET') == ''
          - fixture_vault_value == 'fixture-only-secret'
    - ansible.builtin.copy:
        content: "fixture completed\\n"
        dest: /tmp/pxe-ansible-volume-result
        mode: '0600'
'''}
        for path, content in files.items():
            path.write_text(content);path.chmod(0o600);os.chown(path,1001,1001)
        tftp=root/'tftp';tftp.mkdir(mode=0o755)
        for name in ('lpxelinux.0','grubx64.efi'):(tftp/name).write_bytes(b'fixture')
        data=root/'data';data.mkdir(mode=0o755)
        conf=root/'conf';conf.mkdir(mode=0o755)
        http=root/'http';http.mkdir(mode=0o755)
        (conf/'master.key').write_bytes(os.urandom(32))
        (conf/'db.conf').write_text(json.dumps({'driver':'sqlite','path':'/var/lib/pxe-tools/pxe-tools.sqlite'}))
        dbfile=data/'pxe-tools.sqlite'
        password_hash=docker('run','--rm','--network','none','--entrypoint','php',BASE,'-r',
                             'echo password_hash("fixture-login-password", PASSWORD_DEFAULT);').stdout
        with sqlite3.connect(dbfile) as db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.executemany('INSERT INTO settings(key,value) VALUES(?,?)',[
                ('ansible_enabled','off'),('wizard_completed','1'),('db_init_done','1'),
                ('admin_created','1'),('init_completed','1'),('https_enabled','off')])
            db.execute('INSERT INTO users(username,password_hash) VALUES(?,?)',('fixture-admin',password_hash))
            db.execute("INSERT INTO machines(id,mac,hostname,status,install_config_json) VALUES(1,'02:00:00:00:00:01','fixture','installed',?)",(json.dumps({'ansible_role':'base'}),))
        dbfile.chmod(0o600)
        try:
            base_check=docker('run','--rm','--network','none','--entrypoint','/bin/sh',BASE,
                              '-c','command -v ansible-playbook',check=False)
            if base_check.returncode==0: raise RuntimeError('base image unexpectedly includes Ansible')
            docker('volume','create',volume)
            common=['--rm','--network','none','--read-only','--tmpfs','/tmp:rw,exec,size=256m',
                    '--cap-drop','ALL','--security-opt','no-new-privileges',
                    '--mount',f'type=volume,src={volume},dst=/var/lib/pxe-ansible',
                    '--mount',f'type=bind,src={WHEELS},dst=/wheelhouse,readonly']
            installed=json.loads(docker('run',*common,PREP,'install','--wheelhouse','/wheelhouse').stdout)
            identifier=installed.get('installed','')
            if not re.fullmatch(r'env-[a-z0-9_]{8}',identifier): raise RuntimeError('invalid installed runtime identifier')
            docker('run',*common,PREP,'activate',identifier)
            status=json.loads(docker('run',*common,PREP,'status').stdout)
            if status.get('state')!='ready': raise RuntimeError('runtime did not become ready')
            vault=f'/var/lib/pxe-ansible/runtimes/{identifier}/bin/ansible-vault'
            encrypt="printf '%s' '"+password+"' >/tmp/password; exec \"$1\" encrypt /workspace/playbooks/vault.yml --vault-password-file /tmp/password"
            docker('run','--rm','--network','none','--read-only','--tmpfs','/tmp:rw,exec,size=64m','-e','HOME=/tmp',
                   '--mount',f'type=volume,src={volume},dst=/var/lib/pxe-ansible,readonly',
                   '--mount',f'type=bind,src={work},dst=/workspace','--entrypoint','/bin/sh',
                   PREP,'-c',encrypt,'fixture',vault)
            if not (playbooks/'vault.yml').read_bytes().startswith(b'$ANSIBLE_VAULT;'):
                raise RuntimeError('fixture Vault encryption failed')

            def start_worker():
                docker('run','-d','--name',worker,'--network','none',
                       '--mount',f'type=volume,src={volume},dst=/var/lib/pxe-ansible,readonly',
                       '--mount',f'type=bind,src={data},dst=/var/lib/pxe-tools',
                       '--mount',f'type=bind,src={conf},dst=/etc/pxe-tools',
                       '--mount',f'type=bind,src={tftp},dst=/srv/tftp',
                       '--mount',f'type=bind,src={http},dst=/srv/http',
                       '--mount',f'type=bind,src={work},dst=/home/ansible/ansible',
                       '-e','PXE_ANSIBLE_RUNTIME=/var/lib/pxe-ansible','-e','WORKER_POLL_S=1',
                       '-e','PXE_TEST_SECRET=fixture-not-for-playbooks',
                       BASE)
                details=json.loads(docker('inspect',worker).stdout)[0]
                mounts=[item for item in details['Mounts'] if item['Destination']=='/var/lib/pxe-ansible']
                if len(mounts)!=1 or mounts[0]['RW'] or details['HostConfig']['NetworkMode']!='none':
                    raise RuntimeError('worker runtime mount or network is not isolated')
                wait_for(lambda:'[worker] artefacts PXE déjà présents' in docker('logs',worker,check=False).stdout,
                         'worker startup after unlock cleanup',60)
                def runtime_ready():
                    with sqlite3.connect(dbfile,timeout=10) as db:
                        row=db.execute("SELECT value FROM settings WHERE key='ansible_runtime_status'").fetchone()
                    return bool(row and json.loads(row[0]).get('state')=='ready')
                wait_for(runtime_ready,'worker runtime observation',60)
                wait_for(lambda:docker('exec',worker,'python3','-c',
                                      "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8080/login',timeout=2)",
                                      check=False).returncode==0,
                         'appliance HTTP login',60)
            def unlock():
                script="umask 077; mkdir -p /dev/shm/pxe-vault; printf '%s' '"+password+"' > /dev/shm/pxe-vault/unlock"
                docker('exec',worker,'/bin/sh','-c',script)
            def alive():
                return json.loads(docker('inspect',worker).stdout)[0]['State']['Running']
            def state(sql):
                if not alive(): raise RuntimeError('worker stopped unexpectedly')
                with sqlite3.connect(dbfile,timeout=10) as db:return db.execute(sql).fetchone()[0]
            start_worker()
            ui='''import http.cookiejar,re,urllib.parse,urllib.request
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base='http://127.0.0.1:8080'
page=opener.open(base+'/login',timeout=5).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
data=urllib.parse.urlencode({'username':'fixture-admin','password':'fixture-login-password','csrf_token':token}).encode()
opener.open(base+'/login',data,timeout=5).read()
page=opener.open(base+'/settings/ansible',timeout=5).read().decode()
assert 'environnement Ansible compatible' in page
assert 'Playbooks locaux' in page
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
data=urllib.parse.urlencode({'csrf_token':token,'ansible_enabled':'1','repo_url':'',
                             'repo_branch':'main','repo_profile':'','playbooks_dir':'/home/ansible/ansible'}).encode()
page=opener.open(base+'/settings/ansible',data,timeout=5).read().decode()
assert 'Playbooks locaux' in page
data=urllib.parse.urlencode({'csrf_token':token,'vault_password':'fixture-only-vault-password'}).encode()
page=opener.open(base+'/settings/ansible/vault-unlock',data,timeout=5).read().decode()
assert 'déverrouillage est en attente' in page
'''
            docker('exec',worker,'python3','-c',ui)
            with sqlite3.connect(dbfile) as db:
                assert db.execute("SELECT value FROM settings WHERE key='ansible_enabled'").fetchone()[0]=='on'
                assert db.execute("SELECT value FROM settings WHERE key='ansible_repo_url'").fetchone()[0]==''
                db.execute("INSERT INTO installations(id,machine_id,install_status,ansible_status) VALUES(1,1,'success','pending')")
            wait_for(lambda:state('SELECT ansible_status FROM installations WHERE id=1') in ('success','failed'),'initial playbook')
            if state('SELECT ansible_status FROM installations WHERE id=1')!='success':
                raise RuntimeError('initial playbook failed')
            wait_for(lambda:state('SELECT status FROM machines WHERE id=1')=='provisioned',
                     'provisioned machine state',30)
            docker('exec',worker,'test','!','-e','/dev/shm/pxe-vault/unlock')
            docker('exec',worker,'test','-f','/tmp/pxe-ansible-volume-result')
            docker('rm','-f',worker)
            with sqlite3.connect(dbfile) as db:
                db.execute("INSERT INTO init_jobs(id,job_type,status) VALUES(2,'machine:ansible_redeploy:1','pending')")
            start_worker();unlock()
            wait_for(lambda:state('SELECT status FROM init_jobs WHERE id=2') in ('success','failed'),'redeployment')
            if state('SELECT status FROM init_jobs WHERE id=2')!='success': raise RuntimeError('redeployment failed')
            docker('exec',worker,'test','-f','/tmp/pxe-ansible-volume-result')
            print('OK actual appliance worker ran a Vault-protected local playbook as uid 1001 from the read-only volume',flush=True)
            print('OK authenticated UI activated local playbooks and unlocked Vault; recreation and the real redeploy queue succeeded',flush=True)
        except Exception:
            logs=docker('logs','--tail','30',worker,check=False)
            print('Disposable worker diagnostic:\n'+(logs.stdout+'\n'+logs.stderr)[-3000:],flush=True)
            with sqlite3.connect(dbfile) as db:
                row=db.execute('SELECT ansible_log FROM installations WHERE id=1').fetchone()
                if row and row[0]:print('Fixture installation log:\n'+row[0][-3000:],flush=True)
                row=db.execute('SELECT log FROM init_jobs WHERE id=2').fetchone()
                if row and row[0]:print('Fixture redeploy log:\n'+row[0][-3000:],flush=True)
            raise
        finally:
            docker('rm','-f',worker,check=False)
            docker('volume','rm',volume,check=False)


if __name__=='__main__':main()
