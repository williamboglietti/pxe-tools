#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Experimental BIOS discovery end-to-end on an isolated QEMU Ethernet switch.

The prepared menu is modified only to attach the test serial console. No disks
or host network are attached; the token and certificate are disposable.
"""
from __future__ import annotations

import hashlib
import os
from pathlib import Path
import secrets
import selectors
import shutil
import socket
import sqlite3
import ssl
import subprocess
import time

from test_qemu_discovery_guest import add_discovery_dependencies

ROOT = Path(__file__).resolve().parents[2]


def run_discovery_pxe(root: Path, kernel: Path, initrd: Path, tree: Path, overlay: Path, extracted: Path,
                      module: bytes, bundle: Path, timeout: int, archive_tree, switch_class, guest_overlay) -> int:
    add_discovery_dependencies(tree, extracted)
    content = tree / 'srv/content'
    content.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(bundle, content)
    # Add serial observability only to the disposable copy of the menu.
    menu = (content / 'tftp/pxelinux.cfg/default').read_text(encoding='ascii')
    start = menu.find('LABEL discovery\n')
    if 'DEFAULT local\n' not in menu or start < 0:
        raise ValueError('unexpected fixture menu')
    menu = ('SERIAL 0 115200\n' + menu[:start] + menu[start:].replace('  APPEND initrd=',
            '  APPEND console=ttyS0,115200n8 net.ifnames=0 ipv6.disable=1 initrd=', 1))
    (content / 'tftp/pxelinux.cfg/default').write_text(menu, encoding='ascii')

    state = tree / 'srv/discovery-state'
    state.mkdir(mode=0o700)
    database, cert, key = state / 'fixture.sqlite', state / 'cert.pem', state / 'key.pem'
    token = secrets.token_urlsafe(32)
    if len(token) != 43:
        raise ValueError('unexpected fixture token format')
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        now = int(time.time())
        db.execute('INSERT INTO discovery_enrollment_tokens(token_hash,created_at,expires_at) VALUES(?,?,?)',
                   (hashlib.sha256(token.encode()).hexdigest(), now, now + 3600))
    database.chmod(0o600)
    generated = subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                                '-subj', '/CN=pxe-fixture.invalid', '-addext', 'subjectAltName=IP:192.0.2.1',
                                '-keyout', str(key), '-out', str(cert)], capture_output=True)
    if generated.returncode != 0:
        raise ValueError('could not create disposable TLS identity')
    key.chmod(0o600)
    fingerprint = hashlib.sha256(ssl.PEM_cert_to_DER_cert(cert.read_text())).hexdigest()
    runtime = state / 'runtime'
    runtime.mkdir(mode=0o700)

    php = ("require $argv[1]; require $argv[2]; echo Admin\\Pxe\\DnsmasqConfiguration::render('proxy',"
           "['pxe_interface'=>'eth0','pxe_ip'=>'192.0.2.1','tftp_root'=>'/srv/content/tftp',"
           "'dhcp_proxy_cidr'=>'192.0.2.0/24','bios_bootfile'=>'lpxelinux.0','uefi_bootfile'=>'bootx64.efi'],"
           "[['name'=>'eth0','ipv4'=>['192.0.2.1/24']]]);")
    config = subprocess.check_output(['php', '-r', php, str(ROOT / 'app/src/Util/NetworkConfiguration.php'),
                                      str(ROOT / 'app/src/Pxe/DnsmasqConfiguration.php')], text=True)
    (tree / 'etc/pxe-discovery.conf').write_text(config, encoding='ascii')
    runner = tree / 'opt/pxe-tools/discovery_server_fixture.py'
    runner.write_text('''import json,os,selectors,signal,sqlite3,subprocess,sys,time
def drop():
 os.setgroups([]); os.setgid(1001); os.setuid(1001); os.umask(0o077)
base=['/usr/bin/python3','/opt/pxe-tools/app/cli/run_discovery_api.py','--db','/srv/discovery-state/fixture.sqlite',
 '--cert','/srv/discovery-state/cert.pem','--key','/srv/discovery-state/key.pem',
 '--runtime-parent','/srv/discovery-state/runtime','--interface','eth0','--listen-ip','192.0.2.1','--port','18443','--confirm-activation']
p=subprocess.Popen(base,preexec_fn=drop,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
try:
 s=selectors.DefaultSelector(); s.register(p.stdout,selectors.EVENT_READ); ready=b''; deadline=time.monotonic()+90
 while time.monotonic()<deadline and p.poll() is None:
  if s.select(timeout=1):
   ready+=os.read(p.stdout.fileno(),4096)
   if b'Discovery HTTPS started explicitly' in ready: break
 s.close()
 if b'Discovery HTTPS started explicitly' not in ready:
  print('PXE_HTTPS_FIXTURE_FAILED',flush=True); sys.exit(1)
 print('PXE_HTTPS_FIXTURE_READY',flush=True)
 sys.stdin.readline()
 with sqlite3.connect('/srv/discovery-state/fixture.sqlite') as db:
  if db.execute('SELECT COUNT(*) FROM discovery_reports').fetchone() != (1,):
   print('PXE_HTTPS_FIXTURE_FAILED',flush=True); sys.exit(1)
  row=db.execute('SELECT mac,inventory_json FROM machines').fetchone()
  if row is None or row[0]!='52:54:00:00:00:03' or json.loads(row[1]).get('interface')!='eth0':
   print('PXE_HTTPS_FIXTURE_FAILED',flush=True); sys.exit(1)
 p.send_signal(signal.SIGTERM); out,err=p.communicate(timeout=20)
 if p.returncode != 0:
  print('PXE_HTTPS_FIXTURE_FAILED',flush=True); sys.exit(1)
 print('PXE_HTTPS_FIXTURE_STOPPED',flush=True)
finally:
 if p.poll() is None:
  p.terminate()
  try: p.wait(timeout=10)
  except subprocess.TimeoutExpired: p.kill(); p.wait()
''', encoding='ascii')
    common = (tree / 'init').read_text(encoding='ascii').split('ip addr add', 1)[0]
    (tree / 'init').write_text(common + '''ip addr add 192.0.2.1/24 dev eth0
export PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d
chown -R 1001:1001 /srv/discovery-state
chmod 700 /srv/discovery-state /srv/discovery-state/runtime
chmod 600 /srv/discovery-state/fixture.sqlite /srv/discovery-state/key.pem
/bin/sh /opt/pxe-tools/app/service/serve_pxe.sh --enable proxy eth0 192.0.2.1 /srv/content/tftp /srv/content/http /etc/pxe-discovery.conf &
pxe=$!
sleep 3
kill -0 "$pxe" || { echo PXE_DISCOVERY_SERVER_FAILED; exec sleep 600; }
/usr/bin/python3 /opt/pxe-tools/discovery_server_fixture.py || echo PXE_DISCOVERY_SERVER_FAILED
kill -TERM "$pxe"
wait "$pxe" || true
echo PXE_DISCOVERY_SERVER_STOPPED
exec sleep 600
''', encoding='ascii')
    archive_tree(tree, overlay)
    server_initrd = root / 'discovery-pxe-server.gz'
    with server_initrd.open('wb') as output:
        for part in (initrd, overlay):
            with part.open('rb') as source:
                shutil.copyfileobj(source, output)
    dhcp_config = ('port=0\ninterface=eth0\nbind-interfaces\nno-dhcpv6-interface=*\n'
                   'dhcp-range=192.0.2.100,192.0.2.150,255.255.255.0,1h\n'
                   'dhcp-option=6,192.0.2.3\n'
                   'dhcp-option=12,pxe-fixture\n'
                   'dhcp-option=15,example.invalid\n')
    dhcp_overlay = guest_overlay(extracted, module, root / 'dhcp-discovery', dhcp_config, proxy=False,
                                 bundle=bundle, menu='', grub_menu=None, dhcp_address='192.0.2.3')
    dhcp_initrd = root / 'discovery-pxe-dhcp.gz'
    with dhcp_initrd.open('wb') as output:
        for part in (initrd, dhcp_overlay):
            with part.open('rb') as source:
                shutil.copyfileobj(source, output)

    switch_path = root / 'discovery-switch.sock'
    switch = switch_class(switch_path, tftp_address='192.0.2.1')
    net = 'stream,id=lan,server=off,addr.type=unix,addr.path=' + str(switch_path).replace(',', ',,') + ',reconnect-ms=1000'
    common_qemu = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
                   '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-netdev', net]
    processes: list[subprocess.Popen] = []
    logs = [bytearray(), bytearray(), bytearray()]
    # Only pre-token output is written for diagnostics. Once the secret prompt
    # is answered, client serial output remains exclusively in memory.
    pretoken = (root / 'discovery-client-before-token.log').open('wb')
    selector = selectors.DefaultSelector()
    monitor_path = root / 'discovery-monitor.sock'
    try:
        print('Starting isolated PXE, HTTPS and separate DHCP guests', flush=True)
        for index, image in enumerate((server_initrd, dhcp_initrd)):
            command = common_qemu + ['-m', '1024' if index == 0 else '512', '-kernel', str(kernel), '-initrd', str(image),
                                     '-append', 'console=ttyS0,115200n8 net.ifnames=0 biosdevname=0',
                                     '-device', f'e1000,netdev=lan,mac=52:54:00:00:00:0{index + 1}']
            process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            processes.append(process)
            assert process.stdout is not None
            selector.register(process.stdout, selectors.EVENT_READ, index)
        deadline = time.monotonic() + timeout
        launched = selected = stopping = sensitive_sent = kernel_seen = low_memory_acknowledged = dns_acknowledged = False
        menu_at = finished_at = None
        cursor = 0
        prompts = [(b'Saisir OUI', b'OUI'), (b'Interface r', b'eth0'),
                   (b'URL HTTPS', b'https://192.0.2.1:18443/discovery/v1/reports'),
                   (b'SHA-256', fingerprint.encode()), (b'Transmettre cet inventaire', b'OUI'),
                   (b'Jeton', token.encode())]
        while time.monotonic() < deadline:
            for event, _ in selector.select(timeout=1):
                data = os.read(event.fileobj.fileno(), 65536)
                if data:
                    logs[event.data].extend(data)
                    if event.data == 2 and not sensitive_sent:
                        pretoken.write(data)
                        pretoken.flush()
                else:
                    selector.unregister(event.fileobj)
            if any(p.poll() is not None for p in processes):
                break
            if not launched and b'PXE_HTTPS_FIXTURE_READY' in logs[0] and b'PXE_TEST_SERVER_READY' in logs[1]:
                command = common_qemu + ['-m', '1024', '-boot', 'n', '-device', 'e1000,netdev=lan,mac=52:54:00:00:00:03',
                                         '-chardev', 'socket,id=monitor,path=' + str(monitor_path) + ',server=on,wait=off',
                                         '-mon', 'chardev=monitor,mode=readline']
                client = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                processes.append(client)
                assert client.stdout is not None
                selector.register(client.stdout, selectors.EVENT_READ, 2)
                launched = True
                print('PXE and HTTPS ready; booting the disposable BIOS client', flush=True)
            with switch.lock:
                requests = switch.tftp_requests.copy()
            if launched and menu_at is None and b'pxelinux.cfg/default' in requests:
                menu_at = time.monotonic()
            if launched and not selected and menu_at is not None and time.monotonic() - menu_at >= 2:
                with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as monitor:
                    monitor.settimeout(5)
                    monitor.connect(str(monitor_path))
                    for keyname in ('up', 'ret'):
                        monitor.sendall(('sendkey ' + keyname + '\n').encode('ascii'))
                        time.sleep(0.2)
                selected = True
                print('Selected the prepared discovery entry', flush=True)
            if selected and not kernel_seen and b'Linux version' in logs[2]:
                kernel_seen = True
                print('Disposable client reached the Linux kernel', flush=True)
            if selected and not low_memory_acknowledged and b'Low memory mode' in logs[2]:
                assert processes[2].stdin is not None
                processes[2].stdin.write(b'\n')
                processes[2].stdin.flush()
                low_memory_acknowledged = True
                print('Acknowledged the disposable installer memory notice', flush=True)
            if selected and not dns_acknowledged and b'Name server addresses:' in logs[2]:
                assert processes[2].stdin is not None
                processes[2].stdin.write(b'\n')
                processes[2].stdin.flush()
                dns_acknowledged = True
                print('Accepted the disposable installer DNS fixture', flush=True)
            if launched and selected and prompts:
                prompt, answer = prompts[0]
                position = logs[2].find(prompt, cursor)
                if position >= 0:
                    cursor = position + len(prompt)
                    assert processes[2].stdin is not None
                    if prompt == b'Jeton':
                        sensitive_sent = True
                        print('Pinned certificate verified; entering the disposable token', flush=True)
                    # A real serial UART can drop a burst that exceeds its
                    # receive FIFO. Type synthetic answers at a bounded rate.
                    for byte in answer + b'\n':
                        processes[2].stdin.write(bytes([byte]))
                        processes[2].stdin.flush()
                        time.sleep(0.025)
                    prompts.pop(0)
            if launched and not prompts and b'Decouverte terminee. Redemarrez ou eteignez cette machine.' in logs[2] and finished_at is None:
                finished_at = time.monotonic()
            if launched and not stopping and not prompts and finished_at is not None and time.monotonic() - finished_at >= 5:
                if b'Inventaire accept' not in logs[2]:
                    raise RuntimeError('discovery ended without accepting the inventory')
                if token.encode() in logs[2]:
                    raise RuntimeError('fixture token was echoed')
                assert processes[0].stdin is not None
                processes[0].stdin.write(b'stop\n')
                processes[0].stdin.flush()
                stopping = True
            if stopping and b'PXE_HTTPS_FIXTURE_STOPPED' in logs[0] and b'PXE_DISCOVERY_SERVER_STOPPED' in logs[0]:
                if (b'lpxelinux.0' not in requests or b'pxelinux.cfg/default' not in requests
                        or b'DHCPACK(eth0)' not in logs[1]):
                    raise RuntimeError('fixture PXE requests missing')
                if any(token.encode() in log for log in logs):
                    raise RuntimeError('fixture token was exposed in guest logs')
                print('Isolated BIOS PXE discovery: DHCP, boot, HTTPS inventory, hidden token and shutdown passed', flush=True)
                return 0
            if b'PXE_DISCOVERY_SERVER_FAILED' in logs[0] or b'PXE_HTTPS_FIXTURE_FAILED' in logs[0]:
                break
        for label, log in zip(('server', 'DHCP', 'client'), logs):
            sanitized = bytes(log).replace(token.encode(), b'[redacted]')
            print(label + ':\n' + sanitized[-4000:].decode('utf-8', errors='replace'))
        return 1
    finally:
        pretoken.close()
        selector.close()
        for process in processes:
            if process.poll() is None:
                process.terminate()
        for process in processes:
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        switch.close()
