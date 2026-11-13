#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run the actual selected-bundle manager in an isolated disposable Debian VM.

Debian executables and packages are verified against the supplied archive
keyring before execution. Two diskless guests communicate exclusively through a
private Unix socket. The only DHCP probe is performed on that virtual network.
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
import sqlite3
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.boot_bundle import build_debian_bundle  # noqa: E402
from artifacts.discovery_bundle import build_discovery_bundle  # noqa: E402
from artifacts.verify_bundle import verify_bundle  # noqa: E402
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from discovery.runtime_overlay import PYTHON_PACKAGES, build_discovery_overlay  # noqa: E402
from debian_runtime_packages import BACKUP_PACKAGES, DISCOVERY_PACKAGES, RUNTIME_PACKAGES, verify_runtime_packages  # noqa: E402
from test_qemu_dnsmasq_tftp import PACKAGES, build_overlay, verify_packages  # noqa: E402
from test_qemu_proxy_boot import PrivateSwitch, archive_tree, guest_overlay  # noqa: E402
from test_qemu_runtime_proxy_boot import run_proxy_boot  # noqa: E402
from test_qemu_backup_guest import run_backup_guest  # noqa: E402
from test_qemu_discovery_guest import run_discovery_guest  # noqa: E402
from test_qemu_discovery_pxe import run_discovery_pxe  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb_dir',
                 'pxelinux_deb', 'syslinux_deb', 'grub_deb'):
        parser.add_argument('--' + name.replace('_', '-'), type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=360)
    parser.add_argument('--proxy', action='store_true', help='test the manager with proxy DHCP enabled explicitly')
    parser.add_argument('--proxy-boot', action='store_true', help='use a separate DHCP and a BIOS PXE client')
    parser.add_argument('--kernel-boot', action='store_true', help='select the installer and observe its kernel')
    parser.add_argument('--saved-network', action='store_true', help='use the saved desired configuration')
    parser.add_argument('--backup-guest', action='store_true', help='exercise encrypted backups in a diskless Debian guest')
    parser.add_argument('--discovery-guest', action='store_true', help='exercise discovery HTTPS in a diskless Debian guest')
    parser.add_argument('--discovery-pxe', action='store_true', help='exercise experimental BIOS discovery on an isolated LAN')
    args = parser.parse_args()
    if args.proxy_boot and not args.proxy:
        parser.error('--proxy-boot requires --proxy')
    if args.kernel_boot and not args.proxy_boot:
        parser.error('--kernel-boot requires --proxy-boot')
    if args.backup_guest and (args.proxy or args.proxy_boot or args.kernel_boot or args.saved_network):
        parser.error('--backup-guest cannot be combined with PXE runtime options')
    if args.discovery_guest and (args.backup_guest or args.proxy or args.proxy_boot or args.kernel_boot or args.saved_network):
        parser.error('--discovery-guest cannot be combined with other runtime options')
    if args.discovery_pxe and (args.discovery_guest or args.backup_guest or args.proxy or args.proxy_boot or args.kernel_boot or args.saved_network):
        parser.error('--discovery-pxe cannot be combined with other runtime options')
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
        release, index = args.release.read_bytes(), args.index.read_bytes()
        if len(index) > 40 * 1024 * 1024:
            raise ValueError('package index too large')
        verify_packages(release, index, args.deb_dir)
        optional = BACKUP_PACKAGES if args.backup_guest else (DISCOVERY_PACKAGES if args.discovery_guest or args.discovery_pxe else ())
        runtime_packages = RUNTIME_PACKAGES + optional
        verify_runtime_packages(release, index, args.deb_dir, runtime_packages)
        staging, served = root / 'staging', root / 'served'
        staging.mkdir(mode=0o700)
        served.mkdir(mode=0o700)
        bundle = build_debian_bundle(kernel, initrd, args.sums, args.release, args.index,
                                     {'pxelinux': args.pxelinux_deb, 'syslinux-common': args.syslinux_deb,
                                      'grub-efi-amd64-signed': args.grub_deb}, args.keyring,
                                     staging, served, '192.0.2.1')
        loader_hash = hashlib.sha256((bundle / 'tftp/lpxelinux.0').read_bytes()).hexdigest()
        kernel_hash = hashlib.sha256((bundle / 'http/netboot/debian/linux').read_bytes()).hexdigest()
        extracted = root / 'extracted'
        extracted.mkdir()
        for name in (*PACKAGES, *runtime_packages):
            subprocess.run(['dpkg-deb', '-x', str(args.deb_dir / (name + '.deb')), str(extracted)],
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        raw = gzip.decompress(initrd.read_bytes())
        listing = subprocess.run(['cpio', '-it'], input=raw, stdout=subprocess.PIPE,
                                 stderr=subprocess.DEVNULL, check=True).stdout.decode().splitlines()
        modules = [name for name in listing if re.fullmatch(r'(?:\./)?usr/lib/modules/[^/]+/kernel/drivers/net/ethernet/intel/e1000/e1000\.ko\.xz', name)]
        if len(modules) != 1:
            raise ValueError('e1000 module unavailable or ambiguous')
        module = lzma.decompress(subprocess.run(['bsdtar', '-xOf', '-', modules[0]], input=raw,
                                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True).stdout)
        del raw
        server_dir, client_dir = root / 'server', root / 'client'
        server_dir.mkdir()
        client_dir.mkdir()
        server_overlay, client_overlay = server_dir / 'overlay.gz', client_dir / 'overlay.gz'
        build_overlay(extracted, module, '', server=True, target=server_overlay)
        build_overlay(extracted, module, '', server=False, target=client_overlay)
        server_tree, client_tree = server_dir / 'overlay-server', client_dir / 'overlay-client'
        # Import only executable runtime components and their signed libraries.
        for relative in ('usr/bin/python3.13', 'usr/bin/php8.4', 'usr/bin/dpkg-deb', 'usr/bin/gpgv'):
            destination = server_tree / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(extracted / relative, destination)
        shutil.copytree(extracted / 'usr/lib/python3.13', server_tree / 'usr/lib/python3.13', symlinks=True)
        # php8.4-cli depends on php8.4-common, whose ctype module is normally
        # enabled by Debian's package configuration. Recreate that in initramfs.
        ctype_modules = list((extracted / 'usr/lib/php').glob('*/ctype.so'))
        if len(ctype_modules) != 1:
            raise ValueError('PHP ctype module unavailable or ambiguous')
        ctype_relative = ctype_modules[0].relative_to(extracted)
        ctype_destination = server_tree / ctype_relative
        ctype_destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ctype_modules[0], ctype_destination)
        php_conf = server_tree / 'etc/php/8.4/cli/conf.d'
        php_conf.mkdir(parents=True, exist_ok=True)
        (php_conf / '20-ctype.ini').write_text('extension=/' + str(ctype_relative) + '\n', encoding='ascii')
        (server_tree / 'usr/bin/python3').symlink_to('python3.13')
        (server_tree / 'usr/bin/php').symlink_to('php8.4')
        app = server_tree / 'opt/pxe-tools/app'
        shutil.copytree(ROOT / 'app', app, ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        if args.backup_guest:
            return run_backup_guest(root, kernel, initrd, server_tree, server_overlay, extracted, args.timeout, archive_tree)
        if args.discovery_guest or args.discovery_pxe:
            if args.discovery_pxe:
                bundle = build_discovery_bundle(kernel, initrd, args.sums, args.release, args.index,
                           {'pxelinux': args.pxelinux_deb, 'syslinux-common': args.syslinux_deb,
                            'grub-efi-amd64-signed': args.grub_deb},
                           {name: args.deb_dir / (name + '.deb') for name in PYTHON_PACKAGES},
                           args.keyring, staging, served, '192.0.2.1')
                verify_bundle(served, bundle.relative_to(served.resolve()).as_posix(), args.keyring, '192.0.2.1', discovery=True)
                return run_discovery_pxe(root, kernel, initrd, server_tree, server_overlay, extracted, module,
                                         bundle, args.timeout, archive_tree, PrivateSwitch, guest_overlay)
            discovery_overlay = build_discovery_overlay(kernel, initrd, args.sums, args.release, args.index,
                                                         args.keyring, {name: args.deb_dir / (name + '.deb') for name in PYTHON_PACKAGES})
            return run_discovery_guest(root, kernel, initrd, server_tree, server_overlay, extracted, args.timeout, archive_tree,
                                       discovery_overlay)
        original = server_tree / 'srv/original'
        original.mkdir(parents=True, mode=0o700)
        shutil.copytree(bundle.parent, original / bundle.parent.name, symlinks=False)
        state = server_tree / 'srv/state'
        state.mkdir(mode=0o700)
        db_path = state / 'test.sqlite'
        with sqlite3.connect(db_path) as db:
            db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            db.execute("INSERT INTO netboot_jobs(kind,status,artifact_path,server_ip) VALUES('debian13-amd64','succeeded',?,?)",
                       (bundle.parent.name + '/content', '192.0.2.1'))
            db.execute('UPDATE pxe_boot_selection SET job_id=1 WHERE id=1')
        db_path.chmod(0o600)
        if args.saved_network:
            # Use the same PHP validation and storage path as the local console.
            php_code = ("require $argv[1]; require $argv[2]; require $argv[3]; "
                        "$db=new PDO('sqlite:'.$argv[4],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); "
                        "(new Admin\\Pxe\\NetworkSettings($db))->saveDesired($argv[5],"
                        "['pxe_interface'=>'eth0','pxe_ip'=>'192.0.2.1','dhcp_proxy_cidr'=>'192.0.2.0/24']);")
            subprocess.run(['php', '-r', php_code, str(ROOT / 'app/src/Util/NetworkConfiguration.php'),
                            str(ROOT / 'app/src/Pxe/DnsmasqConfiguration.php'), str(ROOT / 'app/src/Pxe/NetworkSettings.php'),
                            str(db_path), 'proxy' if args.proxy else 'tftp'], check=True, capture_output=True)
        keyring = server_tree / 'root/archive.gpg'
        keyring.parent.mkdir(exist_ok=True)
        shutil.copyfile(args.keyring, keyring)
        key_digest = hashlib.sha256(keyring.read_bytes()).hexdigest()
        mode_options = ('--use-saved-network' if args.saved_network else
                        ('--mode proxy --proxy-cidr 192.0.2.0/24 --interface eth0' if args.proxy else '--mode tftp --interface eth0'))
        # Diagnostic is confined to synthetic guest inputs and only runs on failure.
        debug_mode = "mode='proxy', interface='eth0', proxy_cidr='192.0.2.0/24'" if args.proxy else "mode='tftp', interface='eth0', proxy_cidr=None"
        debug_source = '''import sys, traceback
sys.path[:0] = ['/opt/pxe-tools/app', '/opt/pxe-tools/app/cli']
try:
 from netboot_job import open_database
 from verify_selected_bundle import selected
 from artifacts.verify_bundle import verify_bundle
 from run_selected_pxe import prepared_runtime
 from pathlib import Path
 db = open_database(Path('/srv/state/test.sqlite'))
 print('PXE_DEBUG_SELECTION', selected(db), flush=True)
 verify_bundle(Path('/srv/original'), selected(db)[1], Path('/root/archive.gpg'), selected(db)[2])
 print('PXE_DEBUG_VERIFIED', flush=True)
 with prepared_runtime(db, Path('/srv/original'), Path('/root/archive.gpg'), MODE_OPTIONS):
  print('PXE_DEBUG_PREPARED', flush=True)
except BaseException:
 traceback.print_exc()
'''
        (server_tree / 'opt/pxe-tools/debug_runtime.py').write_text(debug_source.replace('MODE_OPTIONS', debug_mode), encoding='ascii')
        common = (server_tree / 'init').read_text(encoding='ascii').split('ip addr add', 1)[0]
        (server_tree / 'init').write_text(common + f'''ip addr add 192.0.2.1/24 dev eth0
export PHP_INI_SCAN_DIR=/etc/php/8.4/cli/conf.d
chmod 700 /srv/state /srv/original
chmod 600 /srv/state/test.sqlite
chown -R 0:0 /srv/state /srv/original /root/archive.gpg /opt/pxe-tools
available_before=$(awk '/^MemAvailable:/ {{ print $2 }}' /proc/meminfo)
/usr/bin/python3 /opt/pxe-tools/app/cli/run_selected_pxe.py --db /srv/state/test.sqlite --served /srv/original --keyring /root/archive.gpg --keyring-sha256 {key_digest} {mode_options} --confirm-activation &
manager=$!
i=0
while [ "$i" -lt 180 ]; do
  kill -0 "$manager" 2>/dev/null || {{ /usr/bin/php -v; /usr/bin/php -r 'var_export(function_exists("ctype_digit"));'; /usr/bin/dpkg-deb --version; /usr/bin/gpgv --version; /usr/bin/python3 /opt/pxe-tools/debug_runtime.py; echo PXE_TEST_MANAGER_FAILED; exec sleep 600; }}
  if pidof dnsmasq >/dev/null 2>&1 && ps | grep -q '[h]ttpd -f'; then break; fi
  i=$((i + 1)); sleep 1
done
[ "$i" -lt 180 ] || {{ echo PXE_TEST_MANAGER_FAILED; exec sleep 600; }}
dnsid=$(pidof dnsmasq) || {{ echo PXE_TEST_MANAGER_FAILED; exec sleep 600; }}
awk '/^Uid:/ {{ if ($2 == 65534 && $3 == 65534) ok=1 }} END {{ exit !ok }}' "/proc/$dnsid/status" || {{ echo PXE_TEST_MANAGER_FAILED; exec sleep 600; }}
available_after=$(awk '/^MemAvailable:/ {{ print $2 }}' /proc/meminfo)
manager_peak=$(awk '/^VmHWM:/ {{ print $2 }}' "/proc/$manager/status")
dns_rss=$(awk '/^VmRSS:/ {{ print $2 }}' "/proc/$dnsid/status")
dns_cap=$(awk '/^CapEff:/ {{ print $2 }}' "/proc/$dnsid/status")
httpid=$(ps | awk '$2 == "nobody" && /httpd -f/ {{ print $1; exit }}')
http_rss=$(awk '/^VmRSS:/ {{ print $2 }}' "/proc/$httpid/status")
echo "PXE_TEST_MEMORY $available_before $available_after $manager_peak $dns_rss $http_rss"
echo "PXE_TEST_DNS_CAP $dns_cap"
echo PXE_TEST_MANAGER_READY
read -r command </dev/console
kill -TERM "$manager"
wait "$manager" || {{ echo PXE_TEST_MANAGER_FAILED; exec sleep 600; }}
set -- /tmp/pxe-service.* /tmp/pxe-runtime-* /tmp/pxe-trust-*
if pidof dnsmasq >/dev/null 2>&1 || ps | grep -q '[h]ttpd -f'; then echo PXE_TEST_CLEANUP_FAILED; exec sleep 600; fi
for path in "$@"; do [ ! -e "$path" ] || {{ echo PXE_TEST_CLEANUP_FAILED; exec sleep 600; }}; done
[ -f /srv/original/{bundle.parent.name}/content/tftp/lpxelinux.0 ] || {{ echo PXE_TEST_CLEANUP_FAILED; exec sleep 600; }}
echo PXE_TEST_MANAGER_STOPPED
exec sleep 600
''', encoding='ascii')
        client_common = (client_tree / 'init').read_text(encoding='ascii').split('ip addr add', 1)[0]
        (client_tree / 'init').write_text(client_common + f'''ip addr add 192.0.2.2/24 dev eth0
attempt=0
while [ "$attempt" -lt 20 ]; do
  if tftp -g -r lpxelinux.0 -l /tmp/loader 192.0.2.1; then
    echo '{loader_hash}  /tmp/loader' | sha256sum -c - || {{ echo PXE_TEST_CLIENT_FAILED; exec sleep 600; }}
    if udhcpc -i eth0 -n -q -t 2 -T 1 -s /usr/bin/true >/dev/null 2>&1; then
      echo PXE_TEST_UNEXPECTED_DHCP; exec sleep 600
    fi
    wget -q -T 10 -O /tmp/linux http://192.0.2.1/netboot/debian/linux || {{ echo PXE_TEST_CLIENT_FAILED; exec sleep 600; }}
    echo '{kernel_hash}  /tmp/linux' | sha256sum -c - || {{ echo PXE_TEST_CLIENT_FAILED; exec sleep 600; }}
    echo PXE_TEST_CLIENT_OK
    exec sleep 600
  fi
  attempt=$((attempt + 1)); sleep 1
done
echo PXE_TEST_CLIENT_FAILED
exec sleep 600
''', encoding='ascii')
        archive_tree(server_tree, server_overlay)
        archive_tree(client_tree, client_overlay)
        if args.proxy_boot:
            return run_proxy_boot(root, kernel, initrd, server_overlay, extracted, module, bundle, args.timeout,
                                  PrivateSwitch, guest_overlay, kernel_boot=args.kernel_boot)
        combined = []
        for name, overlay in (('server-initrd.gz', server_overlay), ('client-initrd.gz', client_overlay)):
            target = root / name
            with target.open('wb') as output:
                for part in (initrd, overlay):
                    with part.open('rb') as source:
                        shutil.copyfileobj(source, output)
            combined.append(target)
        path = str(root / 'lan.sock')
        common_qemu = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
                       '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-kernel', str(kernel),
                       '-append', 'console=ttyS0,115200n8 net.ifnames=0 biosdevname=0']
        processes = []
        try:
            print('Starting the isolated runtime and client VMs', flush=True)
            for server, guest_initrd, mac in ((True, combined[0], '52:54:00:00:00:01'),
                                              (False, combined[1], '52:54:00:00:00:02')):
                net = ('stream,id=lan,server=' + ('on' if server else 'off') + ',addr.type=unix,addr.path='
                       + path.replace(',', ',,') + ('' if server else ',reconnect-ms=1000'))
                command = common_qemu + ['-m', '1024' if server else '512', '-initrd', str(guest_initrd),
                                         '-netdev', net, '-device', 'e1000,netdev=lan,mac=' + mac]
                processes.append(subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT))
            selector = selectors.DefaultSelector()
            logs = [bytearray(), bytearray()]
            for i, process in enumerate(processes):
                assert process.stdout is not None
                selector.register(process.stdout, selectors.EVENT_READ, i)
            deadline, stopping, ready_reported = time.monotonic() + args.timeout, False, False
            while time.monotonic() < deadline:
                for key, _ in selector.select(timeout=1):
                    data = os.read(key.fileobj.fileno(), 65536)
                    if data:
                        logs[key.data].extend(data)
                    else:
                        selector.unregister(key.fileobj)
                if any(process.poll() is not None for process in processes):
                    break
                if not ready_reported and b'PXE_TEST_MANAGER_READY' in logs[0]:
                    print('Selected bundle manager started the isolated services', flush=True)
                    ready_reported = True
                if not stopping and b'PXE_TEST_MANAGER_READY' in logs[0] and b'PXE_TEST_CLIENT_OK' in logs[1]:
                    assert processes[0].stdin
                    processes[0].stdin.write(b'stop\n')
                    processes[0].stdin.flush()
                    stopping = True
                if stopping and b'PXE_TEST_MANAGER_STOPPED' in logs[0]:
                    print('Isolated manager:', 'proxy' if args.proxy else 'tftp',
                          'selected bundle verified, TFTP/HTTP served, no DHCP lease and shutdown passed')
                    memory = re.search(rb'PXE_TEST_MEMORY (\d+) (\d+) (\d+) (\d+) (\d+)', logs[0])
                    if memory:
                        print('VM memory KiB: available before/after, manager peak RSS, dnsmasq RSS, httpd RSS:',
                              *(int(value) for value in memory.groups()))
                    capabilities = re.search(rb'PXE_TEST_DNS_CAP ([0-9a-fA-F]+)', logs[0])
                    if capabilities:
                        mask = int(capabilities.group(1), 16)
                        known = {0: 'CHOWN', 1: 'DAC_OVERRIDE', 6: 'SETGID', 7: 'SETUID',
                                 10: 'NET_BIND_SERVICE', 12: 'NET_ADMIN', 13: 'NET_RAW'}
                        print('dnsmasq effective capabilities:', ', '.join(known.get(bit, f'bit-{bit}')
                              for bit in range(mask.bit_length()) if mask & (1 << bit)) or 'none')
                    return 0
                if any(marker in log for log in logs for marker in (b'PXE_TEST_MANAGER_FAILED', b'PXE_TEST_CLEANUP_FAILED',
                                                                      b'PXE_TEST_CLIENT_FAILED', b'PXE_TEST_UNEXPECTED_DHCP')):
                    break
            for label, log in zip(('runtime server', 'client'), logs):
                print(label + ':\n' + log[-6000:].decode('utf-8', errors='replace'), file=sys.stderr)
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


if __name__ == '__main__':
    raise SystemExit(main())
