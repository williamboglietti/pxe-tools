#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Discover, schedule and install Debian through the real isolated appliance.

Only a fresh qcow2 disk is attached. A separately connected fixture mirror
exposes authenticated Debian files over a Unix socket, not a network bridge.
The test adds a serial console to observe the first boot of the installed OS.
"""
from __future__ import annotations

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
if os.environ.get('PXE_L2_ISOLATED_TEST') != '1' or os.geteuid() != 0:
    raise SystemExit('Run this test only as root on a dedicated disposable test VM.')
image=os.environ.get('PXE_L2_TEST_IMAGE','')
if not re.fullmatch(r'pxe-l2-appliance-[a-f0-9]{10}:local',image):
    raise SystemExit('A uniquely named local fixture image is required.')


def run(*args, check=True, **kwargs):
    return subprocess.run(args,check=check,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,**kwargs)


def docker(*args, check=True, **kwargs):
    return run('docker',*args,check=check,**kwargs)


def inspect(name):
    return json.loads(docker('inspect',name).stdout)[0]


def endpoint(name):
    data=inspect(name)
    if not data['State']['Running'] or data['HostConfig']['NetworkMode']!='none':
        raise RuntimeError('fixture container is not running in a dedicated network namespace')
    pid=int(data['State']['Pid'])
    inode=os.stat(f'/proc/{pid}/ns/net').st_ino
    if inode==os.stat('/proc/1/ns/net').st_ino:
        raise RuntimeError('refusing to touch the VM host network namespace')
    return pid,inode


def net(name,identity,*args):
    if endpoint(name)!=identity:
        raise RuntimeError('fixture namespace changed during test')
    return run('nsenter','-t',str(identity[0]),'-n','ip',*args)


def links(name,identity):
    return {item['ifname'] for item in json.loads(net(name,identity,'-j','link','show').stdout)}


def no_default_route(name,identity):
    routes=json.loads(net(name,identity,'-j','route','show').stdout)
    if any(item.get('dst')=='default' for item in routes):
        raise RuntimeError('fixture namespace unexpectedly has a default route')


def wait_for(predicate,timeout,description):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        if predicate():return
        time.sleep(1)
    raise RuntimeError('timed out: '+description)


def main():
    suffix=secrets.token_hex(5)
    app='pxe-l2-app-'+suffix; dhcp='pxe-l2-dhcp-'+suffix; client='pxe-l2-client-'+suffix
    mirror='pxe-l2-mirror-'+suffix
    names=[client,dhcp,app,mirror]
    fixture_parent = os.environ.get('PXE_TEST_FIXTURE_PARENT', '/var/tmp')
    with tempfile.TemporaryDirectory(prefix='pxe-l2-fixture-',dir=fixture_parent) as temporary:
        root=Path(temporary); root.chmod(0o755)
        fixture=root/'fixture'; fixture.mkdir(mode=0o755)
        data,conf,http,tftp=(root/x for x in ('data','conf','http','tftp'))
        for directory in (data,conf,http,tftp):directory.mkdir(mode=0o755)
        (conf/'master.key').write_bytes(os.urandom(32));(conf/'master.key').chmod(0o600)
        (conf/'db.conf').write_text(json.dumps({'driver':'sqlite','path':'/var/lib/pxe-tools/chosen.sqlite'}))
        (conf/'db.conf').chmod(0o600)
        login_hash=docker('run','--rm','--network','none','--entrypoint','php',image,'-r',
                          'echo password_hash("fixture-login-password", PASSWORD_DEFAULT);').stdout
        with sqlite3.connect(data/'chosen.sqlite') as db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.executemany('INSERT INTO settings(key,value) VALUES(?,?)',[
                ('pxe_ip','192.0.2.1'),('pxe_subnet','192.0.2.0/24'),('pxe_interface','br-pxetest'),
                ('dhcp_proxy_enabled','on'),('dhcp_proxy_network','192.0.2.0'),
                ('dhcp_proxy_netmask','255.255.255.0'),('https_enabled','off'),
                ('wizard_completed','1'),('db_init_done','1'),('admin_created','1'),('init_completed','1'),
                ('ansible_enabled','off'),('catalog_auto_refresh_enabled','off')])
            db.execute('INSERT INTO users(username,password_hash) VALUES(?,?)',('fixture-admin',login_hash))
            db.execute("INSERT INTO os_images(distro,version,arch,codename,version_full,enabled,status) VALUES('debian','13.0','amd64','trixie','13.0',1,'downloaded')")
        # A stale but valid DB must never drive the appliance, even when its
        # path is present in the inherited environment.
        with sqlite3.connect(data/'stale.sqlite') as db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.executemany('INSERT INTO settings(key,value) VALUES(?,?)', [
                ('pxe_subnet','198.51.100.0/24'), ('pxe_ip','198.51.100.1'),
                ('pxe_interface','fixture-stale'), ('fixture_marker','preserved')])
            db.execute("INSERT INTO machines(id,mac,hostname,status) VALUES(1,'02:00:00:00:00:01','stale-fixture','wiping')")
        netboot=http/'netboot/debian/13.0/amd64';netboot.mkdir(parents=True)
        nginx=(ROOT/'docker/pxe-tools/nginx/pxe.conf').read_text()
        nginx=nginx.replace('    location /netboot/ {',
            '    location ^~ /debian/ { proxy_pass http://unix:/fixture-data/mirror.sock:; proxy_read_timeout 120s; }\n\n    location /netboot/ {')
        nginx_file=root/'pxe.conf'; nginx_file.write_text(nginx)
        instrument=(ROOT/'docker/worker/templates/preseed-base.cfg.j2').read_text()
        needle="{{ '{{ADMIN_USER_BLOCK}}' }}"
        if instrument.count(needle)!=1: raise RuntimeError('unexpected preseed template')
        serial=(" ; in-target /bin/sh -c 'printf \"\\nGRUB_CMDLINE_LINUX=\\\"console=tty0 console=ttyS0,115200n8\\\"\\n\" >> /etc/default/grub; update-grub' ; printf 'PXE_FULL_LATE_DONE\\n' >/dev/ttyS0")
        instrument=instrument.replace(needle,needle+serial)+"\nd-i mirror/udeb/suite string trixie\n"
        template=root/'preseed-base.cfg.j2';template.write_text(instrument)
        discovery=(ROOT/'docker/worker/templates/discovery-preseed.cfg.j2').read_text()
        discovery_needle='d-i mirror/http/hostname string deb.debian.org'
        if discovery.count(discovery_needle)!=1: raise RuntimeError('unexpected discovery mirror template')
        discovery=discovery.replace(discovery_needle,'d-i mirror/http/hostname string 192.0.2.1')
        discovery=discovery.replace('d-i clock-setup/ntp boolean true','d-i clock-setup/ntp boolean false')
        discovery+='\nd-i mirror/udeb/suite string trixie\n'
        discovery_template=root/'discovery-preseed.cfg.j2';discovery_template.write_text(discovery)
        # Report only which sysfs paths contain this synthetic disk's serial.
        # The production collector remains otherwise unchanged.
        discover_script=(ROOT/'docker/worker/templates/discover.sh.j2').read_text()
        marker='DISKS_JSON=""'
        if discover_script.count(marker)!=1: raise RuntimeError('unexpected discovery collector')
        probe='''for fixture_path in /sys/block/vda/device/serial /sys/block/vda/serial /sys/block/vda/device/device/serial; do
    if [ -r "$fixture_path" ] && [ "$(cat "$fixture_path")" = "PXE-TEST-ONE" ]; then
        printf 'FIXTURE_SERIAL_LOCATION=%s\\n' "$fixture_path" >/dev/ttyS0
    fi
done
'''
        discover_script=discover_script.replace(marker,probe+marker)
        discover_template=root/'discover.sh.j2';discover_template.write_text(discover_script)
        import shutil
        shutil.copytree(ROOT/'docker/pxe-tools/static-bin',http/'static-bin')
        docker('run','--rm','--network','none','--mount',f'type=bind,src={root},dst=/fixture-data',
               '--entrypoint','qemu-img',image,'create','-f','qcow2','/fixture-data/fixture.qcow2','12G')
        mounts=[]
        for src,dst in ((data,'/var/lib/pxe-tools'),(conf,'/etc/pxe-tools'),(http,'/srv/http'),(tftp,'/srv/tftp')):
            mounts+=['--mount',f'type=bind,src={src},dst={dst}']
        try:
            prototype=str(ROOT/'tests/support/prototype')
            docker('run','-d','--name',mirror,'--mount',f'type=bind,src={fixture},dst=/fixture-data',
                   '--mount',f'type=bind,src={ROOT},dst=/fixture-source,readonly',
                   '--mount',f'type=bind,src={prototype},dst=/fixture-prototype,readonly',
                   '-e','PXE_DISPOSABLE_APPLIANCE_MIRROR=1','--entrypoint','python3',image,
                   '/fixture-source/tests/integration/appliance_pxe_fixture_mirror.py')
            def mirror_ready():
                if not inspect(mirror)['State']['Running']: raise RuntimeError('fixture mirror stopped')
                return (fixture/'ready').exists()
            wait_for(mirror_ready,600,'authenticated fixture mirror')
            for item in ('linux','initrd.gz'):
                import shutil
                shutil.copyfile(fixture/item,netboot/item)
            mounts += ['--mount',f'type=bind,src={fixture},dst=/fixture-data',
                       '--mount',f'type=bind,src={nginx_file},dst=/etc/nginx/conf.d/pxe.conf,readonly',
                       '--mount',f'type=bind,src={template},dst=/opt/pxe-tools/templates/preseed-base.cfg.j2,readonly',
                       '--mount',f'type=bind,src={discovery_template},dst=/opt/pxe-tools/templates/discovery-preseed.cfg.j2,readonly',
                       '--mount',f'type=bind,src={discover_template},dst=/opt/pxe-tools/templates/discover.sh.j2,readonly']
            docker('run','-d','--name',app,'--network','none','--cap-add','NET_ADMIN','--cap-add','NET_RAW',
                   '--device','/dev/net/tun','-e','KERNEL_OPTS=language=fr country=FR locale=fr_FR.UTF-8 keymap=fr ipv6.disable=1 net.ifnames=0',
                   '-e','WORKER_POLL_S=1','-e','PXE_DB_PATH=/var/lib/pxe-tools/stale.sqlite','--entrypoint','/bin/sh',*mounts,image,'-c',
                   'while [ ! -e /run/pxe-test-net-ready ]; do sleep 0.2; done; exec /init')
            docker('run','-d','--name',dhcp,'--network','none','--cap-add','NET_ADMIN','--cap-add','NET_RAW',
                   '--entrypoint','/bin/sh',image,'-c','exec sleep 4200')
            app_ns=endpoint(app); dhcp_ns=endpoint(dhcp)
            if app_ns[1]==dhcp_ns[1] or links(app,app_ns)!={'lo'} or links(dhcp,dhcp_ns)!={'lo'}:
                raise RuntimeError('fixture interfaces are not isolated')
            no_default_route(app,app_ns);no_default_route(dhcp,dhcp_ns)
            net(app,app_ns,'link','add','br-pxetest','type','bridge')
            net(app,app_ns,'addr','add','192.0.2.1/24','dev','br-pxetest')
            net(app,app_ns,'link','set','br-pxetest','up')
            net(app,app_ns,'link','add','veth-pxe','type','veth','peer','name','veth-dhcp')
            net(app,app_ns,'link','set','veth-pxe','master','br-pxetest')
            net(app,app_ns,'link','set','veth-pxe','up')
            net(app,app_ns,'link','set','veth-dhcp','netns',str(dhcp_ns[0]))
            net(dhcp,dhcp_ns,'addr','add','192.0.2.2/24','dev','veth-dhcp')
            net(dhcp,dhcp_ns,'link','set','veth-dhcp','up')
            net(app,app_ns,'tuntap','add','dev','tap-pxetest','mode','tap')
            net(app,app_ns,'link','set','tap-pxetest','master','br-pxetest')
            net(app,app_ns,'link','set','tap-pxetest','up')
            if links(app,app_ns)!={'lo','br-pxetest','veth-pxe','tap-pxetest'} or links(dhcp,dhcp_ns)!={'lo','veth-dhcp'}:
                raise RuntimeError('unexpected interface attached to fixture')
            no_default_route(app,app_ns);no_default_route(dhcp,dhcp_ns)
            docker('exec','-d',dhcp,'dnsmasq','-k','--port=0','--interface=veth-dhcp','--bind-interfaces',
                   '--dhcp-range=192.0.2.100,192.0.2.120,255.255.255.0,1h',
                   '--dhcp-option=6,192.0.2.1','--log-dhcp','--log-facility=/tmp/fixture-dhcp.log')
            docker('exec',app,'touch','/run/pxe-test-net-ready')
            def ready():
                if not inspect(app)['State']['Running']:raise RuntimeError('appliance stopped during startup')
                result=docker('exec',app,'/command/s6-svstat','/run/service/dnsmasq',check=False)
                health=docker('exec',app,'curl','-sS','--max-time','2','http://127.0.0.1/healthz',check=False)
                return result.returncode==0 and result.stdout.startswith('up') and health.returncode==0
            wait_for(ready,90,'appliance services')
            config=docker('exec',app,'cat','/etc/dnsmasq.d/pxe.conf').stdout
            if 'dhcp-range=192.0.2.0,proxy,255.255.255.0' not in config:
                raise RuntimeError('actual proxy DHCP configuration not active')
            docker('exec',app,'bash','/opt/pxe-tools/scripts/bootstrap-install-pxe-bins.sh')
            menu=tftp/'pxelinux.cfg/01-02-00-00-00-00-01'
            default_menu=tftp/'pxelinux.cfg/default'
            if not default_menu.is_file() or 'DEFAULT discovery' not in default_menu.read_text() or menu.exists():
                raise RuntimeError('client must start unknown using the discovery menu')
            outside=docker('exec',app,'curl','-sS','-o','/dev/null','-w','%{http_code}',
                           'http://127.0.0.1/preseed.php?mac=02:00:00:00:00:ff',check=False)
            if outside.returncode!=0 or outside.stdout!='403':
                raise RuntimeError('preseed did not reject an address outside the configured client subnet')
            allowed=docker('exec',dhcp,'curl','-sS','-w','\\n%{http_code}',
                           'http://192.0.2.1/preseed.php?mac=02:00:00:00:00:ff',check=False)
            if allowed.returncode!=0 or not allowed.stdout.endswith('\n404') or 'machine introuvable' not in allowed.stdout:
                raise RuntimeError('preseed did not accept the isolated client subnet before machine lookup')
            # Discovery runs on the same isolated virtual hardware and blank
            # disk later used by the installation; it must supply the serial.
            docker('run','-d','--name',client,'--network','container:'+app,'--cap-add','NET_ADMIN',
                   '--device','/dev/net/tun','--mount',f'type=bind,src={root},dst=/fixture-data','--entrypoint','qemu-system-x86_64',image,
                   '-machine','pc,accel=tcg','-smp','1','-m','1024','-display','none',
                   '-drive','file=/fixture-data/fixture.qcow2,if=none,id=disk,format=qcow2',
                   '-device','virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE',
                   '-serial','stdio','-monitor','none','-no-reboot','-boot','n',
                   '-netdev','tap,id=lan,ifname=tap-pxetest,script=no,downscript=no',
                   '-device','e1000,netdev=lan,mac=02:00:00:00:00:01')
            print('Unknown BIOS client booted into Discovery on isolated virtual hardware',flush=True)
            def discovered():
                if not inspect(client)['State']['Running']: raise RuntimeError('Discovery client stopped prematurely')
                with sqlite3.connect(data/'chosen.sqlite') as db:
                    row=db.execute('SELECT status,inventory_json FROM machines WHERE mac=?',
                                   ('02:00:00:00:00:01',)).fetchone()
                if not row or row[0]!='discovered' or not row[1]: return False
                inventory=json.loads(row[1])
                disks=inventory.get('disks',[])
                if not any(disk.get('identity',{}).get('value')=='PXE-TEST-ONE'
                           and disk.get('identity',{}).get('status')=='valid' for disk in disks):
                    output=docker('logs',client,check=False).stdout
                    print('Fixture serial locations:',re.findall(r'FIXTURE_SERIAL_LOCATION=([^\r\n]+)',output),flush=True)
                    print('Fixture disk identity states:',[(d.get('name'),d.get('identity',{}).get('source'),d.get('identity',{}).get('status')) for d in disks],flush=True)
                    raise RuntimeError('Discovery did not verify the fixture disk serial')
                return True
            wait_for(discovered,1500,'Discovery inventory with verified fixture disk')
            print('Real Discovery reported the disposable disk identity',flush=True)
            ui='''import http.cookiejar,json,re,time,urllib.parse,urllib.request
from pathlib import Path
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base='http://127.0.0.1:8080'
page=opener.open(base+'/login',timeout=5).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
def post(path,values):
    return opener.open(base+path,urllib.parse.urlencode(values).encode(),timeout=20)
post('/login',{'username':'fixture-admin','password':'fixture-login-password','csrf_token':token}).read()
page=opener.open(base+'/machines/1/install',timeout=10).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
values={'csrf_token':token,'hostname':'pxe-fixture','domain':'example.test','distro':'debian',
 'codename':'trixie','version_full':'13.0','network_mode':'dhcp','partition_mode':'default',
 'admin_user_login':'fixture','admin_user_password':'fixture-password','root_login_mode':'disabled',
 'pre_wipe_mode':'aucun','wake_now':'1','adv[localization][locale]':'fr_FR.UTF-8',
 'adv[localization][keymap]':'fr','adv[mirror][country]':'manual',
 'adv[mirror][hostname]':'192.0.2.1','adv[mirror][directory]':'/debian',
 'adv[timezone][utc]':'1','adv[timezone][zone]':'UTC','adv[packages][tasksel]':'ssh-server',
 'adv[packages][pkgsel_include]':'sudo,openssh-server,curl','adv[packages][upgrade]':'none',
 'adv[apt][disable_services]':'1','adv[bootloader][only_debian]':'1','adv[finish][reboot]':'quiet'}
preview=json.load(post('/machines/1/install-preview',values))
if preview.get('errors'): raise RuntimeError('Preflight refused fixture: '+str(preview['errors']))
response=post('/machines/1/install',values)
if not re.fullmatch(re.escape(base)+r'/tasks/[0-9]+',response.geturl()):
    raise RuntimeError('Install submission did not redirect to activity')
response.read()
menu=Path('/srv/tftp/pxelinux.cfg/01-02-00-00-00-00-01')
deadline=time.monotonic()+90
while time.monotonic()<deadline:
    if menu.is_file() and 'DEFAULT install' in menu.read_text(): break
    time.sleep(1)
else: raise RuntimeError('Install menu not generated')
page=opener.open(base+'/machines/1',timeout=10).read().decode()
token=re.search(r'name="csrf_token" value="([^"]+)"',page).group(1)
post('/machines/1/reboot',{'csrf_token':token}).read()
print('Authenticated UI scheduled installation and requested reboot after menu generation')
'''
            docker('exec',app,'python3','-c',ui)
            with sqlite3.connect(data/'chosen.sqlite') as db:
                config=json.loads(db.execute('SELECT install_config_json FROM machines WHERE id=1').fetchone()[0])
                if (config.get('system_disk_identity',{}).get('value')!='PXE-TEST-ONE'
                        or config.get('advanced',{}).get('apt',{}).get('services_select')!=[]):
                    raise RuntimeError('Scheduled snapshot lost disk identity or explicit APT choice')
            def discovery_rebooted():
                state=inspect(client)['State']
                if state['Running']: return False
                if state['ExitCode']!=0: raise RuntimeError('Discovery reboot failed')
                return True
            wait_for(discovery_rebooted,180,'Discovery reboot requested by the interface')
            docker('rm','-f',client)
            docker('run','-d','--name',client,'--network','container:'+app,'--cap-add','NET_ADMIN',
                   '--device','/dev/net/tun','--mount',f'type=bind,src={root},dst=/fixture-data','--entrypoint','qemu-system-x86_64',image,
                   '-machine','pc,accel=tcg','-smp','1','-m','1024','-display','none',
                   '-drive','file=/fixture-data/fixture.qcow2,if=none,id=disk,format=qcow2',
                   '-device','virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE',
                   '-serial','stdio','-monitor','none','-no-reboot','-boot','n',
                   '-netdev','tap,id=lan,ifname=tap-pxetest,script=no,downscript=no',
                   '-device','e1000,netdev=lan,mac=02:00:00:00:00:01')
            print('Discovery rebooted and the real installer started',flush=True)
            seen=False; deadline=time.monotonic()+3000
            while time.monotonic()<deadline:
                output=docker('logs',client,check=False).stdout
                if 'PXE_FULL_LATE_DONE' in output: seen=True
                state=inspect(client)['State']
                if not state['Running']:
                    if not seen or state['ExitCode']!=0: raise RuntimeError('installer exited before successful late command')
                    break
                time.sleep(5)
            else: raise RuntimeError('installer timed out')
            with sqlite3.connect(data/'chosen.sqlite') as db:
                result=db.execute('SELECT install_status FROM installations ORDER BY id DESC LIMIT 1').fetchone()
                machine=db.execute('SELECT status,local_boot_source FROM machines WHERE id=1').fetchone()
            if result!=('success',) or machine!=('installed','pxe'):
                raise RuntimeError('application did not record successful installation and local boot policy')
            with sqlite3.connect(data/'stale.sqlite') as db:
                if (db.execute("SELECT value FROM settings WHERE key='fixture_marker'").fetchone()!=('preserved',)
                        or db.execute('SELECT status FROM machines WHERE id=1').fetchone()!=('wiping',)):
                    raise RuntimeError('stale fixture database was modified')
            docker('rm','-f',client)
            docker('run','-di','--name',client,'--network','none',
                   '--mount',f'type=bind,src={root},dst=/fixture-data','--entrypoint','qemu-system-x86_64',image,
                   '-machine','pc,accel=tcg','-smp','1','-m','1024','-display','none',
                   '-serial','stdio','-monitor','none','-no-reboot','-net','none',
                   '-drive','file=/fixture-data/fixture.qcow2,if=none,id=disk,format=qcow2',
                   '-device','virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE')
            serial_wakeup_sent=False
            def login():
                nonlocal serial_wakeup_sent
                state=inspect(client)['State']
                if not state['Running']: raise RuntimeError('installed guest stopped before login')
                output=docker('logs',client,check=False).stdout
                if not serial_wakeup_sent and 'Debian GNU/Linux 13 pxe-fixture ttyS0' in output:
                    serial_wakeup_sent=True
                    try:
                        subprocess.run(['docker','attach','--sig-proxy=false',client], input='\n',
                                       text=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                                       timeout=5,check=False)
                    except subprocess.TimeoutExpired:
                        pass
                return 'pxe-fixture login:' in output
            wait_for(login,600,'installed Debian login')
            print('OK Discovery, authenticated scheduling, PXE install callback and Debian first boot',flush=True)
            print('Only the disposable qcow2 disk and isolated bridge were used.',flush=True)
        except Exception:
            for name, args in ((app, ('logs','--tail','35',app)),
                               (dhcp, ('exec',dhcp,'cat','/tmp/fixture-dhcp.log')),
                               (client, ('logs','--tail','20',client)),
                               (mirror, ('logs','--tail','20',mirror))):
                result=docker(*args,check=False)
                text=(result.stdout+'\n'+result.stderr)[-3000:]
                text=re.sub(r'https?://[^\s]+','[URL]',text)
                text=re.sub(r'(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])','[IPv4]',text)
                text=re.sub(r'(?i)(?<![0-9a-f])(?:[0-9a-f]{2}:){5}[0-9a-f]{2}(?![0-9a-f])','[MAC]',text)
                text=re.sub(r'[A-Za-z0-9+/=]{100,}','[encoded]',text)
                print('Fixture diagnostic ('+name.split('-')[2]+'):\n'+text,flush=True)
            raise
        finally:
            for name in names:docker('rm','-f',name,check=False)


if __name__=='__main__':main()
