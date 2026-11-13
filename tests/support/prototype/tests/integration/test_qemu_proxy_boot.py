#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Boot the project BIOS menu through isolated DHCP, proxy DHCP, TFTP and HTTP VMs.

The only network connections are private Unix sockets to an in-process Ethernet
switch. No guest disk or host network interface is attached. All Debian inputs
and executable guest packages are checked against the supplied archive keyring.
This is not an OS installation test.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import lzma
import os
from pathlib import Path
import re
import selectors
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import threading
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from artifacts.boot_bundle import build_debian_bundle  # noqa: E402
from artifacts.download_bundle import download_and_build_bundle  # noqa: E402
from test_qemu_dnsmasq_tftp import PACKAGES, build_overlay, verify_packages  # noqa: E402
from test_qemu_project_menu import package_checks  # noqa: E402


class PrivateSwitch:
    """Forward QEMU length-prefixed Ethernet frames only among connected VMs."""
    def __init__(self, path: Path, tftp_address: str = '192.0.2.2'):
        self.tftp_address = socket.inet_aton(tftp_address)
        self.listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.listener.bind(str(path))
        self.listener.listen(3)
        self.listener.settimeout(0.2)
        self.peers: list[socket.socket] = []
        self.tftp_requests: set[bytes] = set()
        self.lock = threading.Lock()
        self.stop = threading.Event()
        self.threads: list[threading.Thread] = []
        self.accept_thread = threading.Thread(target=self.accept, daemon=True)
        self.accept_thread.start()

    def accept(self) -> None:
        while not self.stop.is_set():
            try:
                peer, _ = self.listener.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            peer.settimeout(0.5)
            with self.lock:
                self.peers.append(peer)
            thread = threading.Thread(target=self.forward, args=(peer,), daemon=True)
            self.threads.append(thread)
            thread.start()

    def receive(self, peer: socket.socket, length: int) -> bytes:
        data = bytearray()
        while len(data) < length and not self.stop.is_set():
            try:
                part = peer.recv(length - len(data))
            except socket.timeout:
                continue
            if not part:
                raise EOFError
            data.extend(part)
        return bytes(data)

    def forward(self, peer: socket.socket) -> None:
        try:
            while not self.stop.is_set():
                header = self.receive(peer, 4)
                if len(header) != 4:
                    break
                length = struct.unpack('!I', header)[0]
                if not 1 <= length <= 65536:
                    raise ValueError('invalid Ethernet frame length')
                frame = self.receive(peer, length)
                with self.lock:
                    # Record only TFTP requests on this private synthetic LAN.
                    if len(frame) >= 42 and frame[12:14] == b'\x08\x00' and frame[23] == 17:
                        header_length = (frame[14] & 15) * 4
                        udp = 14 + header_length
                        if len(frame) >= udp + 10 and frame[30:34] == self.tftp_address:
                            destination = struct.unpack('!H', frame[udp + 2:udp + 4])[0]
                            payload = frame[udp + 8:]
                            if destination == 69 and payload.startswith(b'\x00\x01'):
                                self.tftp_requests.add(payload[2:].split(b'\x00', 1)[0].lstrip(b'/'))
                    for target in self.peers:
                        if target is not peer:
                            try:
                                target.sendall(header + frame)
                            except OSError:
                                pass
        except (EOFError, OSError, ValueError):
            pass
        finally:
            with self.lock:
                if peer in self.peers:
                    self.peers.remove(peer)
            peer.close()

    def close(self) -> None:
        self.stop.set()
        self.listener.close()
        with self.lock:
            for peer in self.peers:
                try:
                    peer.shutdown(socket.SHUT_RDWR)
                except OSError:
                    pass
        self.accept_thread.join(timeout=2)
        for thread in self.threads:
            thread.join(timeout=2)


def archive_tree(root: Path, target: Path) -> None:
    paths = ['.'] + ['./' + str(path.relative_to(root)) for path in sorted(root.rglob('*'))]
    listing = ('\n'.join(paths) + '\n').encode()
    with tempfile.TemporaryFile() as archive:
        subprocess.run(['cpio', '-o', '--format', 'newc'], cwd=root, input=listing,
                       stdout=archive, stderr=subprocess.PIPE, check=True)
        archive.seek(0)
        with target.open('wb') as output, gzip.GzipFile(fileobj=output, mode='wb', compresslevel=1) as compressed:
            shutil.copyfileobj(archive, compressed)


def guest_overlay(extract: Path, module: bytes, directory: Path, config: str, *, proxy: bool,
                  bundle: Path, menu: str, grub_menu: str | None, dhcp_address: str = '192.0.2.1') -> Path:
    directory.mkdir()
    overlay = directory / 'guest.gz'
    build_overlay(extract, module, config, server=True, target=overlay)
    tree = directory / 'overlay-server'
    init = (tree / 'init').read_text(encoding='ascii').split('ip addr add', 1)[0]
    address = '192.0.2.2' if proxy else dhcp_address
    init += 'ip addr add ' + address + '/24 dev eth0\n'
    if proxy:
        (tree / 'etc/passwd').write_text('root:x:0:0:root:/root:/bin/sh\nnobody:x:65534:65534:nobody:/:/bin/sh\n', encoding='ascii')
        (tree / 'etc/group').write_text('root:x:0:\nnogroup:x:65534:\n', encoding='ascii')
        app_service = tree / 'opt/pxe-tools'
        app_service.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / 'app/service/serve_pxe.sh', app_service / 'serve_pxe.sh')
        tftp = tree / 'srv/tftp'
        tftp.parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(bundle / 'tftp', tftp)
        (tftp / 'pxelinux.cfg/default').write_text(menu, encoding='ascii')
        if grub_menu is not None:
            (tftp / 'grub/grub.cfg').write_text(grub_menu, encoding='ascii')
        shutil.copytree(bundle / 'http', tree / 'srv/http')
        init += '''cp /etc/pxe-test.conf /etc/pxe-bad.conf
echo 'dhcp-range=192.0.2.50,192.0.2.60,255.255.255.0,1h' >> /etc/pxe-bad.conf
if /bin/sh /opt/pxe-tools/serve_pxe.sh --enable proxy eth0 192.0.2.2 /srv/tftp /srv/http /etc/pxe-bad.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 600
fi
if /bin/sh /opt/pxe-tools/serve_pxe.sh --enable tftp eth0 192.0.2.2 /srv/tftp /srv/http /etc/pxe-test.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 600
fi
echo PXE_TEST_POLICY_OK
/bin/sh /opt/pxe-tools/serve_pxe.sh --enable proxy eth0 192.0.2.2 /srv/tftp /srv/http /etc/pxe-test.conf &
pid=$!
sleep 4
kill -0 "$pid" || { echo PXE_TEST_SERVER_FAILED; exec sleep 600; }
dnsid=$(pidof dnsmasq) || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 600; }
awk '/^Uid:/ { if ($2 == 65534 && $3 == 65534) ok=1 } END { exit !ok }' "/proc/$dnsid/status" || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 600; }
ps | awk '$2 == "nobody" && /httpd -f/ { ok=1 } END { exit !ok }' || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 600; }
echo PXE_TEST_SERVER_READY
read -r command </dev/console
kill -TERM "$pid"
wait "$pid" || true
set -- /tmp/pxe-service.*
if pidof dnsmasq >/dev/null 2>&1 || ps | grep -q '[h]ttpd -f' || [ -e "$1" ]; then echo PXE_TEST_SHUTDOWN_FAILED; else echo PXE_TEST_SERVICES_STOPPED; fi
exec sleep 600
'''
    else:
        init += '''/usr/sbin/dnsmasq --keep-in-foreground --conf-file=/etc/pxe-test.conf --user=root --group=root --log-facility=- --log-dhcp --dhcp-leasefile=/run/dnsmasq.leases &
pid=$!
sleep 2
kill -0 "$pid" || { echo PXE_TEST_SERVER_FAILED; exec sleep 600; }
echo PXE_TEST_SERVER_READY
exec sleep 600
'''
    (tree / 'init').write_text(init, encoding='ascii')
    archive_tree(tree, overlay)
    return overlay


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb_dir', 'pxelinux_deb', 'syslinux_deb'):
        parser.add_argument('--' + name.replace('_', '-'), type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=240)
    parser.add_argument('--uefi', action='store_true', help='use UEFI firmware and the GRUB menu')
    parser.add_argument('--download-bundle', action='store_true', help='download the bundle using project code')
    parser.add_argument('--ovmf-deb', type=Path)
    parser.add_argument('--grub-deb', type=Path)
    parser.add_argument('--debug-screen', type=Path, help='save the disposable client screen on failure')
    args = parser.parse_args()
    if args.grub_deb is None:
        parser.error('--grub-deb is required for the complete boot bundle')
    if args.uefi and args.ovmf_deb is None:
        parser.error('--uefi requires --ovmf-deb')
    if args.debug_screen is not None and (not args.debug_screen.is_absolute()
                                          or args.debug_screen.exists() or args.debug_screen.is_symlink()):
        parser.error('--debug-screen must be a new absolute path')
    if not 60 <= args.timeout <= 900:
        parser.error('timeout must be between 60 and 900 seconds')
    for tool in ('qemu-system-x86_64', 'dpkg-deb', 'cpio', 'bsdtar', 'php'):
        if not shutil.which(tool):
            parser.error(tool + ' is required')
    with tempfile.TemporaryDirectory(prefix='pxe-vm-', dir='/tmp') as directory:
        root = Path(directory)
        kernel, initrd = root / 'linux', root / 'initrd.gz'
        shutil.copyfile(args.kernel, kernel)
        shutil.copyfile(args.initrd, initrd)
        verify_debian_netboot(kernel, initrd, args.sums, args.release, args.keyring)
        staging, served = root / 'staging', root / 'served'
        staging.mkdir(mode=0o700)
        served.mkdir(mode=0o700)
        # Exercise the same verified boot bundle intended for the application.
        if args.download_bundle:
            bundle = download_and_build_bundle(args.keyring, staging, served, '192.0.2.2')
        else:
            bundle = build_debian_bundle(kernel, initrd, args.sums, args.release, args.index,
                                         {'pxelinux': args.pxelinux_deb, 'syslinux-common': args.syslinux_deb,
                                          'grub-efi-amd64-signed': args.grub_deb}, args.keyring,
                                         staging, served, '192.0.2.2')
        kernel, initrd = bundle / 'http/netboot/debian/linux', bundle / 'http/netboot/debian/initrd.gz'
        if args.index.stat().st_size > 40 * 1024 * 1024:
            raise ValueError('package index too large')
        release, index = args.release.read_bytes(), args.index.read_bytes()
        verify_packages(release, index, args.deb_dir)
        package_checks(release, index, {'pxelinux': args.pxelinux_deb, 'syslinux-common': args.syslinux_deb})
        if args.uefi:
            index_text = lzma.decompress(index, memlimit=256 * 1024 * 1024).decode('utf-8')
            for name, architecture, package in (('ovmf', 'all', args.ovmf_deb),
                                                ('grub-efi-amd64-signed', 'amd64', args.grub_deb)):
                records = []
                for section in index_text.split('\n\n'):
                    if section.startswith('Package: ' + name + '\n'):
                        fields = dict(line.split(': ', 1) for line in section.splitlines() if ': ' in line)
                        if fields.get('Architecture') == architecture:
                            records.append(fields)
                if (len(records) != 1 or package.stat().st_size > 32 * 1024 * 1024
                        or not re.fullmatch(r'[a-fA-F0-9]{64}', records[0].get('SHA256', ''))
                        or hashlib.sha256(package.read_bytes()).hexdigest() != records[0]['SHA256'].lower()):
                    raise ValueError('firmware or GRUB package checksum invalid')
        extracted = root / 'extracted'
        extracted.mkdir()
        packages = [args.deb_dir / (name + '.deb') for name in PACKAGES]
        if args.uefi:
            packages += [args.ovmf_deb]
        for package in packages:
            subprocess.run(['dpkg-deb', '-x', str(package), str(extracted)], stdout=subprocess.DEVNULL,
                           stderr=subprocess.PIPE, check=True)
        raw_initrd = gzip.decompress(initrd.read_bytes())
        listing = subprocess.run(['cpio', '-it'], input=raw_initrd, stdout=subprocess.PIPE,
                                 stderr=subprocess.DEVNULL, check=True).stdout.decode().splitlines()
        modules = [name for name in listing if re.fullmatch(r'(?:\./)?usr/lib/modules/[^/]+/kernel/drivers/net/ethernet/intel/e1000/e1000\.ko\.xz', name)]
        if len(modules) != 1:
            raise ValueError('e1000 module unavailable or ambiguous')
        compressed = subprocess.run(['bsdtar', '-xOf', '-', modules[0]], input=raw_initrd,
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True).stdout
        module = lzma.decompress(compressed)
        del raw_initrd
        php = ("require $argv[1]; require $argv[2]; echo Admin\\Pxe\\DnsmasqConfiguration::render('proxy',"
               "['pxe_interface'=>'eth0','pxe_ip'=>'192.0.2.2','tftp_root'=>'/srv/tftp',"
               "'dhcp_proxy_cidr'=>'192.0.2.0/24','bios_bootfile'=>'lpxelinux.0','uefi_bootfile'=>'bootx64.efi'],"
               "[['name'=>'eth0','ipv4'=>['192.0.2.2/24']]]);")
        proxy_config = subprocess.check_output(['php', '-r', php, str(ROOT / 'app/src/Util/NetworkConfiguration.php'),
                                                str(ROOT / 'app/src/Pxe/DnsmasqConfiguration.php')], text=True)
        dhcp_config = ('port=0\ninterface=eth0\nbind-interfaces\nno-dhcpv6-interface=*\n'
                       'dhcp-range=192.0.2.100,192.0.2.150,255.255.255.0,1h\n')
        menu = (bundle / 'tftp/pxelinux.cfg/default').read_text(encoding='ascii')
        if 'DEFAULT local\n' not in menu:
            raise ValueError('unexpected default boot selection')
        menu = 'SERIAL 0 115200\n' + menu.replace('APPEND initrd=', 'APPEND console=ttyS0,115200n8 pxe_test=proxy_boot initrd=')
        grub_menu = None
        if args.uefi:
            grub_menu = (bundle / 'tftp/grub/grub.cfg').read_text(encoding='ascii')
            if 'set default=1\n' not in grub_menu:
                raise ValueError('unexpected UEFI default boot selection')
            grub_menu = ('serial --unit=0 --speed=115200\nterminal_input console\nterminal_output serial\n'
                         + grub_menu.replace('/netboot/debian/linux\n', '/netboot/debian/linux console=ttyS0,115200n8 pxe_test=proxy_boot\n'))
        overlays = [guest_overlay(extracted, module, root / name, config, proxy=proxy, bundle=bundle,
                                  menu=menu, grub_menu=grub_menu)
                    for name, config, proxy in (('dhcp', dhcp_config, False), ('proxy', proxy_config, True))]
        combined = []
        for i, overlay in enumerate(overlays):
            target = root / ('initrd-' + str(i) + '.gz')
            with target.open('wb') as output:
                for part in (initrd, overlay):
                    with part.open('rb') as source:
                        shutil.copyfileobj(source, output)
            combined.append(target)
        path = root / 'switch.sock'
        switch = PrivateSwitch(path)
        net = 'stream,id=lan,server=off,addr.type=unix,addr.path=' + str(path).replace(',', ',,') + ',reconnect-ms=1000'
        common = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
                  '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-netdev', net]
        processes: list[subprocess.Popen] = []
        logs = [bytearray(), bytearray(), bytearray()]
        selector = selectors.DefaultSelector()
        try:
            for i in range(2):
                qemu = common + ['-m', '768', '-kernel', str(kernel), '-initrd', str(combined[i]),
                                 '-append', 'console=ttyS0,115200n8 net.ifnames=0 biosdevname=0',
                                 '-device', f'e1000,netdev=lan,mac=52:54:00:00:00:0{i + 1}']
                process = subprocess.Popen(qemu, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                processes.append(process)
                assert process.stdout
                selector.register(process.stdout, selectors.EVENT_READ, i)
            deadline = time.monotonic() + args.timeout
            launched, selected, boot_reported, stopping = False, False, False, False
            menu_seen_at: float | None = None
            while time.monotonic() < deadline:
                for key, _ in selector.select(timeout=1):
                    data = os.read(key.fileobj.fileno(), 65536)
                    if data:
                        logs[key.data].extend(data)
                    else:
                        selector.unregister(key.fileobj)
                if any(p.poll() is not None for p in processes):
                    break
                if not launched and all(b'PXE_TEST_SERVER_READY' in log for log in logs[:2]) and b'PXE_TEST_POLICY_OK' in logs[1]:
                    nic = 'e1000,netdev=lan,mac=52:54:00:00:00:03'
                    if args.uefi:
                        # Use OVMF's built-in virtio network driver, not a competing option ROM.
                        nic = 'virtio-net-pci,disable-legacy=on,netdev=lan,mac=52:54:00:00:00:03,bootindex=1,romfile='
                    qemu = common + ['-m', '768'] + ([] if args.uefi else ['-boot', 'n']) + ['-device', nic]
                    if args.uefi or args.debug_screen is not None:
                        qemu += ['-chardev', 'socket,id=monitor,path=' + str(root / 'monitor.sock') + ',server=on,wait=off',
                                 '-mon', 'chardev=monitor,mode=readline']
                    if args.uefi:
                        code = extracted / 'usr/share/OVMF/OVMF_CODE_4M.fd'
                        variables = root / 'OVMF_VARS.fd'
                        shutil.copyfile(extracted / 'usr/share/OVMF/OVMF_VARS_4M.fd', variables)
                        qemu += ['-device', 'virtio-rng-pci', '-drive', 'if=pflash,format=raw,readonly=on,file=' + str(code),
                                 '-drive', 'if=pflash,format=raw,file=' + str(variables)]
                    client = subprocess.Popen(qemu, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                    processes.append(client)
                    assert client.stdout
                    selector.register(client.stdout, selectors.EVENT_READ, 2)
                    launched = True
                    print('Isolated DHCP and PXE servers ready; starting client', flush=True)
                boot_file = b'bootx64.efi' if args.uefi else b'lpxelinux.0'
                with switch.lock:
                    requested = switch.tftp_requests.copy()
                if not boot_reported and boot_file in requested:
                    print('PXE proxy transferred the requested bootloader', flush=True)
                    boot_reported = True
                if launched and menu_seen_at is None and b'Debian installer (manual)' in logs[2]:
                    # Wait for the menu to finish painting before sending keys.
                    menu_seen_at = time.monotonic()
                if launched and not selected and menu_seen_at is not None and time.monotonic() - menu_seen_at >= 1:
                    if args.uefi:
                        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as monitor:
                            monitor.settimeout(3)
                            monitor.connect(str(root / 'monitor.sock'))
                            monitor.sendall(b'sendkey up\n')
                            time.sleep(0.2)
                            monitor.sendall(b'sendkey ret\n')
                    else:
                        assert processes[2].stdin
                        processes[2].stdin.write(b'\x1b[A\r')
                        processes[2].stdin.flush()
                    selected = True
                    print('Selecting the installer in the project menu', flush=True)
                uefi_transfers = (not args.uefi or (b'netboot/debian/linux' in requested
                                                   and b'netboot/debian/initrd.gz' in requested))
                booted = (selected and b'pxe_test=proxy_boot' in logs[2] and b'Linux version' in logs[2]
                          and b'DHCPACK(eth0)' in logs[0] and boot_file in requested and uefi_transfers)
                if booted and not stopping:
                    assert processes[1].stdin
                    processes[1].stdin.write(b'stop\n')
                    processes[1].stdin.flush()
                    stopping = True
                if booted and b'PXE_TEST_SERVICES_STOPPED' in logs[1]:
                    mode = 'UEFI/TFTP' if args.uefi else 'BIOS/HTTP'
                    print('Isolated proxy PXE: DHCP lease, project services, TFTP, ' + mode + ' kernel boot and shutdown verified')
                    return 0
                if args.uefi and b'EFI Internal Shell' in logs[2] and b'Shell>' in logs[2]:
                    print('UEFI firmware fell back to its shell before starting the installer', file=sys.stderr)
                    break
                if any(marker in log for log in logs[:2] for marker in (b'PXE_TEST_SERVER_FAILED', b'PXE_TEST_MODULE_FAILED', b'PXE_TEST_SHUTDOWN_FAILED', b'PXE_TEST_POLICY_FAILED', b'PXE_TEST_PRIVILEGE_FAILED')):
                    break
            if args.debug_screen is not None and launched and processes[2].poll() is None:
                try:
                    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as monitor:
                        monitor.settimeout(3)
                        monitor.connect(str(root / 'monitor.sock'))
                        monitor.sendall(('screendump ' + str(root / 'screen.ppm') + '\n').encode('ascii'))
                        for _ in range(30):
                            if (root / 'screen.ppm').is_file():
                                break
                            time.sleep(0.1)
                        with args.debug_screen.open('xb') as output, (root / 'screen.ppm').open('rb') as screenshot:
                            shutil.copyfileobj(screenshot, output)
                except OSError:
                    print('Could not save the disposable client screen', file=sys.stderr)
            for label, log in zip(('DHCP server', 'PXE proxy', 'UEFI client' if args.uefi else 'BIOS client'), logs):
                print(label + ':\n' + log[-5000:].decode('utf-8', errors='replace'), file=sys.stderr)
            return 1
        finally:
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


if __name__ == '__main__':
    raise SystemExit(main())
