#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the selected-bundle manager with separate DHCP and a BIOS client.

This helper is called by test_qemu_runtime_manager after all guest binaries and
the boot bundle have been authenticated. The switch has only a Unix socket.
"""
from __future__ import annotations

import os
from pathlib import Path
import selectors
import shutil
import socket
import subprocess
import time


def run_proxy_boot(root: Path, kernel: Path, initrd: Path, server_overlay: Path,
                    extracted: Path, module: bytes, bundle: Path, timeout: int,
                    switch_class, guest_overlay, *, kernel_boot: bool = False) -> int:
    dhcp_config = ('port=0\ninterface=eth0\nbind-interfaces\nno-dhcpv6-interface=*\n'
                   'dhcp-range=192.0.2.100,192.0.2.150,255.255.255.0,1h\n')
    dhcp_overlay = guest_overlay(extracted, module, root / 'dhcp', dhcp_config, proxy=False,
                                 bundle=bundle, menu='', grub_menu=None, dhcp_address='192.0.2.3')
    combined = []
    for name, overlay in (('manager-boot-initrd.gz', server_overlay), ('dhcp-initrd.gz', dhcp_overlay)):
        target = root / name
        with target.open('wb') as output:
            for part in (initrd, overlay):
                with part.open('rb') as source:
                    shutil.copyfileobj(source, output)
        combined.append(target)
    path = root / 'switch.sock'
    switch = switch_class(path, tftp_address='192.0.2.1')
    net = 'stream,id=lan,server=off,addr.type=unix,addr.path=' + str(path).replace(',', ',,') + ',reconnect-ms=1000'
    common = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
              '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-netdev', net]
    processes: list[subprocess.Popen] = []
    logs = [bytearray(), bytearray(), bytearray()]
    selector = selectors.DefaultSelector()
    try:
        print('Starting isolated selected-bundle manager and separate DHCP server', flush=True)
        for index, image in enumerate(combined):
            command = common + ['-m', '1024' if index == 0 else '512', '-kernel', str(kernel),
                                '-initrd', str(image), '-append', 'console=ttyS0,115200n8 net.ifnames=0 biosdevname=0',
                                '-device', f'e1000,netdev=lan,mac=52:54:00:00:00:0{index + 1}']
            process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            processes.append(process)
            assert process.stdout is not None
            selector.register(process.stdout, selectors.EVENT_READ, index)
        deadline = time.monotonic() + timeout
        launched = stopping = selected = False
        menu_seen_at: float | None = None
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                data = os.read(key.fileobj.fileno(), 65536)
                if data:
                    logs[key.data].extend(data)
                else:
                    selector.unregister(key.fileobj)
            if any(process.poll() is not None for process in processes):
                break
            if not launched and b'PXE_TEST_MANAGER_READY' in logs[0] and b'PXE_TEST_SERVER_READY' in logs[1]:
                client_command = common + ['-m', '512', '-boot', 'n', '-device',
                                           'e1000,netdev=lan,mac=52:54:00:00:00:03']
                if kernel_boot:
                    client_command += ['-chardev', 'socket,id=monitor,path=' + str(root / 'monitor.sock') + ',server=on,wait=off',
                                       '-mon', 'chardev=monitor,mode=readline']
                client = subprocess.Popen(client_command,
                                          stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                processes.append(client)
                assert client.stdout is not None
                selector.register(client.stdout, selectors.EVENT_READ, 2)
                launched = True
                print('Manager and DHCP server ready; starting isolated BIOS client', flush=True)
            with switch.lock:
                requested = switch.tftp_requests.copy()
            menu_requested = any(path.startswith(b'pxelinux.cfg/') for path in requested)
            if kernel_boot and menu_seen_at is None and b'pxelinux.cfg/default' in requested:
                menu_seen_at = time.monotonic()
            if kernel_boot and not selected and menu_seen_at is not None and time.monotonic() - menu_seen_at >= 1:
                # Leave the signed bundle untouched. Choose the installer in the
                # real menu and append only serial observation arguments there.
                keys = ['up', 'tab', 'spc']
                for char in 'console=ttyS0,115200n8 pxe_test=runtime':
                    keys.append({'=': 'equal', ',': 'comma', '_': 'shift-minus',
                                 ' ': 'spc', 'S': 'shift-s'}.get(char, char))
                keys.append('ret')
                with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as monitor:
                    monitor.settimeout(3)
                    monitor.connect(str(root / 'monitor.sock'))
                    for key in keys:
                        monitor.sendall(('sendkey ' + key + '\n').encode('ascii'))
                        time.sleep(0.12)
                selected = True
                print('Selected installer and appended serial observation arguments', flush=True)
            kernel_started = (not kernel_boot or (selected and b'Linux version' in logs[2]
                                                   and b'pxe_test=runtime' in logs[2]))
            if (not stopping and launched and b'DHCPACK(eth0)' in logs[1]
                    and b'lpxelinux.0' in requested and menu_requested and kernel_started):
                assert processes[0].stdin is not None
                processes[0].stdin.write(b'stop\n')
                processes[0].stdin.flush()
                stopping = True
            if stopping and b'PXE_TEST_MANAGER_STOPPED' in logs[0]:
                print('Isolated manager proxy: separate DHCP lease, BIOS bootloader and menu request, '
                      + ('kernel started, ' if kernel_boot else '') + 'shutdown passed')
                return 0
            if any(marker in log for log in logs[:2] for marker in
                   (b'PXE_TEST_MANAGER_FAILED', b'PXE_TEST_CLEANUP_FAILED', b'PXE_TEST_SERVER_FAILED', b'PXE_TEST_MODULE_FAILED')):
                break
        for label, log in zip(('runtime server', 'DHCP server', 'BIOS client'), logs):
            print(label + ':\n' + log[-6000:].decode('utf-8', errors='replace'))
        return 1
    finally:
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
