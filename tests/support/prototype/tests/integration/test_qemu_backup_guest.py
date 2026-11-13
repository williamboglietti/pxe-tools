#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run backup integration fixtures in a diskless guest using signed Debian packages."""
from __future__ import annotations

import os
from pathlib import Path
import selectors
import shutil
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]


def run_backup_guest(root: Path, kernel: Path, initrd: Path, tree: Path, overlay: Path,
                     extracted: Path, timeout: int, archive_tree) -> int:
    for relative in ('usr/bin/gpg', 'usr/bin/gpgconf', 'usr/bin/gpg-agent'):
        target = tree / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(extracted / relative, target)
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
    test = tree / 'opt/pxe-tools/tests/integration/test_encrypted_backup.py'
    test.parent.mkdir(parents=True)
    shutil.copy2(ROOT / 'tests/integration/test_encrypted_backup.py', test)
    (tree / 'init').write_text('''#!/bin/sh
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d
/usr/bin/busybox --install -s /usr/bin
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev
mkdir -p /run /tmp /root
/usr/bin/gpg --version | head -n 1
/usr/bin/python3 /opt/pxe-tools/tests/integration/test_encrypted_backup.py && echo PXE_BACKUP_TEST_OK || echo PXE_BACKUP_TEST_FAILED
exec sleep 600
''', encoding='ascii')
    archive_tree(tree, overlay)
    combined = root / 'backup-initrd.gz'
    with combined.open('wb') as output:
        for part in (initrd, overlay):
            with part.open('rb') as source:
                shutil.copyfileobj(source, output)
    command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
               '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-net', 'none', '-m', '1024',
               '-kernel', str(kernel), '-initrd', str(combined), '-append', 'console=ttyS0,115200n8']
    print('Starting isolated Debian-package backup guest, without network or disk', flush=True)
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    logs = bytearray()
    try:
        assert process.stdout is not None
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                data = os.read(key.fileobj.fileno(), 65536)
                if not data:
                    break
                logs.extend(data)
            if b'PXE_BACKUP_TEST_OK' in logs:
                print('Disposable Debian guest: encrypted backup and restore integration tests passed')
                return 0
            if b'PXE_BACKUP_TEST_FAILED' in logs or process.poll() is not None:
                break
        print(logs[-12000:].decode('utf-8', errors='replace'))
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
