#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run the HTTPS launcher inside a diskless and network-disconnected Debian guest."""
from __future__ import annotations

import os
from pathlib import Path
import selectors
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]


def add_discovery_dependencies(tree: Path, extracted: Path) -> None:
    for relative in ('usr/sbin/nginx', 'usr/sbin/php-fpm8.4', 'usr/sbin/ip'):
        destination = tree / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(extracted / relative, destination)
    zone = tree / 'usr/share/zoneinfo/Etc/UTC'
    zone.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(extracted / 'usr/share/zoneinfo/Etc/UTC', zone)
    (tree / 'usr/share/zoneinfo/UTC').symlink_to('Etc/UTC')
    conf = tree / 'etc/php/8.4/cli/conf.d'
    for position, name in (('10', 'pdo'), ('20', 'pdo_sqlite'), ('20', 'sqlite3'), ('20', 'posix')):
        matches = list((extracted / 'usr/lib/php').glob('*/' + name + '.so'))
        if len(matches) != 1:
            raise ValueError('missing or ambiguous PHP module: ' + name)
        relative = matches[0].relative_to(extracted)
        destination = tree / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(matches[0], destination)
        (conf / (position + '-' + name + '.ini')).write_text('extension=/' + str(relative) + '\n', encoding='ascii')
    (tree / 'etc/passwd').write_text('root:x:0:0:root:/root:/bin/sh\nnobody:x:65534:65534:nobody:/:/bin/sh\n'
                                     'fixture:x:1001:1001:fixture:/srv/discovery-fixture:/bin/sh\n', encoding='ascii')
    (tree / 'etc/group').write_text('root:x:0:\nnogroup:x:65534:\nfixture:x:1001:\n', encoding='ascii')


def run_discovery_guest(root: Path, kernel: Path, initrd: Path, tree: Path, overlay: Path,
                        extracted: Path, timeout: int, archive_tree, discovery_overlay: bytes) -> int:
    add_discovery_dependencies(tree, extracted)
    source = ROOT / 'tests/integration/discovery_guest_fixture.py'
    test = tree / 'opt/pxe-tools/discovery_guest_fixture.py'
    shutil.copy2(source, test)
    fixture_key, fixture_cert = tree / 'opt/pxe-tools/fixture-key.pem', tree / 'opt/pxe-tools/fixture-cert.pem'
    generated = subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                                '-subj', '/CN=pxe-fixture.invalid', '-addext', 'subjectAltName=IP:127.0.0.1',
                                '-keyout', str(fixture_key), '-out', str(fixture_cert)], capture_output=True)
    if generated.returncode != 0:
        raise ValueError('could not create disposable TLS identity')
    fixture_key.chmod(0o600)
    (tree / 'init').write_text('''#!/bin/sh
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d
/usr/bin/busybox --install -s /usr/bin
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev
mkdir -p /dev/pts
mount -t devpts devpts /dev/pts
mkdir -p /run /tmp /root /srv
/usr/sbin/ip link set lo up || { echo PXE_DISCOVERY_GUEST_FAILED; exec sleep 600; }
/usr/bin/python3 /opt/pxe-tools/discovery_guest_fixture.py || echo PXE_DISCOVERY_GUEST_FAILED
exec sleep 600
''', encoding='ascii')
    archive_tree(tree, overlay)
    combined = root / 'discovery-initrd.gz'
    with combined.open('wb') as output:
        with initrd.open('rb') as source:
            shutil.copyfileobj(source, output)
        output.write(discovery_overlay)
        with overlay.open('rb') as source:
            shutil.copyfileobj(source, output)
    command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
               '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-net', 'none', '-m', '1024',
               '-kernel', str(kernel), '-initrd', str(combined), '-append', 'console=ttyS0,115200n8']
    print('Starting isolated Debian discovery guest, without external network or disks', flush=True)
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    logs = bytearray()
    try:
        assert process.stdout is not None
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if chunk:
                    logs.extend(chunk)
            if b'PXE_DISCOVERY_GUEST_OK' in logs:
                print('Disposable Debian guest: discovery HTTPS and conflict checks passed', flush=True)
                return 0
            if b'PXE_DISCOVERY_GUEST_FAILED' in logs or process.poll() is not None:
                break
        print(logs[-4000:].decode('utf-8', errors='replace'))
        return 1
    finally:
        selector.close()
        if process.poll() is None:
            process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
