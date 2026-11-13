#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""BIOS Discovery through the appliance on a closed virtual bridge.

No guest disk is attached. A fixture mirror exposes authenticated Debian files
through a Unix socket. The actual guest screen is checked through QEMU's
monitor and OCR, without changing Discovery's terminal output.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[2]
if os.environ.get('PXE_L2_ISOLATED_TEST') != '1' or os.geteuid() != 0:
    raise SystemExit('Run this test only as root on a dedicated disposable test VM.')
image=os.environ.get('PXE_L2_TEST_IMAGE','')
if not re.fullmatch(r'pxe-discovery-[a-f0-9]{10}:local',image):
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
    app='pxe-discovery-app-'+suffix; dhcp='pxe-discovery-dhcp-'+suffix; client='pxe-discovery-client-'+suffix
    mirror='pxe-discovery-mirror-'+suffix
    names=[client,dhcp,app,mirror]
    with tempfile.TemporaryDirectory(prefix='pxe-discovery-fixture-',dir='/var/tmp') as temporary:
        root=Path(temporary); root.chmod(0o755)
        fixture=root/'fixture'; fixture.mkdir(mode=0o755)
        data,conf,http,tftp=(root/x for x in ('data','conf','http','tftp'))
        for directory in (data,conf,http,tftp):directory.mkdir(mode=0o755)
        (conf/'master.key').write_bytes(os.urandom(32));(conf/'master.key').chmod(0o600)
        (conf/'db.conf').write_text(json.dumps({'driver':'sqlite','path':'/var/lib/pxe-tools/pxe-tools.sqlite'}))
        (conf/'db.conf').chmod(0o600)
        with sqlite3.connect(data/'pxe-tools.sqlite') as db:
            db.executescript((ROOT/'app/db/schema.sqlite.sql').read_text())
            db.executemany('INSERT INTO settings(key,value) VALUES(?,?)',[
                ('pxe_ip','192.0.2.1'),('pxe_subnet','192.0.2.0/24'),('pxe_interface','br-pxetest'),
                ('dhcp_proxy_enabled','on'),('dhcp_proxy_network','192.0.2.0'),
                ('dhcp_proxy_netmask','255.255.255.0'),('https_enabled','off')])
            db.execute("INSERT INTO os_images(distro,version,arch,codename,version_full,enabled,status) VALUES('debian','13.0','amd64','trixie','13.0',1,'downloaded')")
        netboot=http/'netboot/debian/13.0/amd64';netboot.mkdir(parents=True)
        nginx=(ROOT/'docker/pxe-tools/nginx/pxe.conf').read_text()
        nginx=nginx.replace('    location /netboot/ {',
            '    location ^~ /debian/ { proxy_pass http://unix:/fixture-data/mirror.sock:; proxy_read_timeout 120s; }\n\n    location /netboot/ {')
        nginx_file=root/'pxe.conf'; nginx_file.write_text(nginx)
        # Only the fixture mirror is reachable from the closed guest network.
        preseed=(ROOT/'docker/worker/templates/discovery-preseed.cfg.j2').read_text()
        needle='d-i mirror/http/hostname string deb.debian.org'
        if preseed.count(needle)!=1: raise RuntimeError('unexpected discovery mirror template')
        preseed=preseed.replace(needle,'d-i mirror/http/hostname string 192.0.2.1')
        preseed=preseed.replace('d-i clock-setup/ntp boolean true','d-i clock-setup/ntp boolean false')
        preseed+='\nd-i mirror/udeb/suite string trixie\n'
        preseed_file=root/'discovery-preseed.cfg.j2';preseed_file.write_text(preseed)
        discovery=(ROOT/'docker/worker/templates/discover.sh.j2').read_text()
        discovery_file=root/'discover.sh.j2';discovery_file.write_text(discovery)
        import shutil
        shutil.copytree(ROOT/'docker/pxe-tools/static-bin',http/'static-bin')
        # Dockerfile makes these scripts executable in the real image. Fixture
        # bind mounts must preserve that even when a checkout has mode 0644.
        render_dnsmasq=root/'render-dnsmasq-conf.sh'
        render_per_mac=root/'render-per-mac-pxelinux.sh'
        shutil.copyfile(ROOT/'docker/pxe-tools/init/render-dnsmasq-conf.sh',render_dnsmasq)
        shutil.copyfile(ROOT/'docker/worker/scripts/render-per-mac-pxelinux.sh',render_per_mac)
        render_dnsmasq.chmod(0o755); render_per_mac.chmod(0o755)
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
                       '--mount',f'type=bind,src={preseed_file},dst=/opt/pxe-tools/templates/discovery-preseed.cfg.j2,readonly',
                       '--mount',f'type=bind,src={discovery_file},dst=/opt/pxe-tools/templates/discover.sh.j2,readonly',
                       '--mount',f'type=bind,src={ROOT}/docker/worker/scripts/render_template.py,dst=/opt/pxe-tools/scripts/render_template.py,readonly',
                       '--mount',f'type=bind,src={ROOT}/docker/worker/scripts/atomic_publish.py,dst=/opt/pxe-tools/scripts/atomic_publish.py,readonly',
                       '--mount',f'type=bind,src={ROOT}/docker/worker/scripts/validate-pxe-menu-rows.py,dst=/opt/pxe-tools/scripts/validate-pxe-menu-rows.py,readonly',
                       '--mount',f'type=bind,src={render_per_mac},dst=/opt/pxe-tools/scripts/render-per-mac-pxelinux.sh,readonly',
                       '--mount',f'type=bind,src={render_dnsmasq},dst=/opt/pxe-tools/init/render-dnsmasq-conf.sh,readonly']
            docker('run','-d','--name',app,'--network','none','--cap-add','NET_ADMIN','--cap-add','NET_RAW',
                   '--device','/dev/net/tun','-e','KERNEL_OPTS=language=fr country=FR locale=fr_FR.UTF-8 keymap=fr ipv6.disable=1 net.ifnames=0','--entrypoint','/bin/sh',*mounts,image,'-c',
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
                sockets=docker('exec',app,'sh','-c','test "$(wc -l </proc/net/udp)" -gt 1',check=False)
                return result.returncode==0 and result.stdout.startswith('up') and health.returncode==0 and sockets.returncode==0
            wait_for(ready,90,'appliance services')
            config=docker('exec',app,'cat','/etc/dnsmasq.d/pxe.conf').stdout
            if 'dhcp-range=192.0.2.0,proxy,255.255.255.0' not in config:
                raise RuntimeError('actual proxy DHCP configuration not active')
            docker('exec',app,'bash','/opt/pxe-tools/scripts/bootstrap-install-pxe-bins.sh')
            menu=tftp/'pxelinux.cfg/default'
            if not menu.is_file() or 'DEFAULT discovery' not in menu.read_text():
                raise RuntimeError('unknown machine default discovery menu was not generated')
            if (tftp/'pxelinux.cfg/01-02-00-00-00-00-01').exists():
                raise RuntimeError('client was not initially unknown')
            docker('run','-di','--name',client,'--network','container:'+app,'--cap-add','NET_ADMIN',
                   '--device','/dev/net/tun','--mount',f'type=bind,src={root},dst=/fixture-data','--entrypoint','qemu-system-x86_64',image,
                   '-machine','pc,accel=tcg','-smp','1','-m','1024','-display','none',
                   '-serial','stdio','-monitor','unix:/fixture-data/monitor.sock,server=on,wait=off','-no-reboot','-boot','n',
                   '-netdev','tap,id=lan,ifname=tap-pxetest,script=no,downscript=no',
                   '-device','e1000,netdev=lan,mac=02:00:00:00:00:01')
            print('Actual appliance and isolated Discovery client started, without guest disks',flush=True)
            def discovered():
                if not inspect(app)['State']['Running'] or not inspect(client)['State']['Running']:
                    raise RuntimeError('appliance or Discovery client stopped')
                with sqlite3.connect(data/'pxe-tools.sqlite') as db:
                    row=db.execute('SELECT status,inventory_json FROM machines WHERE mac=?',
                                   ('02:00:00:00:00:01',)).fetchone()
                if not row or row[0]!='discovered' or not row[1]: return False
                inventory=json.loads(row[1])
                return (inventory.get('boot_mac')=='02:00:00:00:00:01'
                        and inventory.get('inventory',{}).get('cpu_arch')=='x86_64')
            wait_for(discovered,1500,'Discovery inventory')
            with sqlite3.connect(data/'pxe-tools.sqlite') as db:
                row=db.execute('SELECT id,inventory_json FROM machines WHERE mac=?',
                               ('02:00:00:00:00:01',)).fetchone()
                inventory=json.loads(row[1])
                observed='PXE DHCP observed '+json.dumps(
                    {'mac':'02:00:00:00:00:01','ip':inventory['discovery_ip']},
                    separators=(',',':'))
                found=db.execute("SELECT 1 FROM machine_events WHERE machine_id=? "
                                 "AND checkpoint='pxe_boot_observed' AND message=? LIMIT 1",
                                 (row[0],observed)).fetchone()
            if not found:
                raise RuntimeError('proxy DHCP did not establish a MAC/IP boot observation')
            wait_for(lambda:'/api/discovery-action' in docker('exec',app,'cat','/var/log/nginx/pxe-access.log').stdout,
                     60,'first Discovery action poll')
            logs=docker('exec',app,'cat','/var/log/nginx/pxe-access.log').stdout
            for path in ('/discovery-preseed.cfg','/discover.sh','/api/discovery-action'):
                if path not in logs: raise RuntimeError('Discovery did not request '+path)
            time.sleep(4)  # let the real status screen complete its first redraw
            with socket.socket(socket.AF_UNIX) as monitor:
                monitor.settimeout(5)
                monitor.connect(str(root/'monitor.sock'));monitor.recv(4096)
                monitor.sendall(b'screendump /fixture-data/discovery-screen.ppm\n')
            wait_for(lambda:(root/'discovery-screen.ppm').is_file(),10,'guest screen capture')
            ocr=docker('run','--rm','--network','none','--mount',f'type=bind,src={root},dst=/fixture-data,readonly',
                       '--entrypoint','tesseract',image,'/fixture-data/discovery-screen.ppm','stdout','--psm','6')
            if 'PXE Tools' not in ocr.stdout:
                visible=re.sub(r'(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])','[IPv4]',ocr.stdout)
                print('Guest screen OCR:\n'+visible[-1200:],flush=True)
                raise RuntimeError('PXE Tools heading not detected on the actual guest screen')
            print('OK unknown BIOS client reached actual Discovery, sent inventory and displayed PXE Tools',flush=True)
            print('No guest disks were attached; application, DHCP and client used isolated namespaces.',flush=True)
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
