#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""BIOS PXE to Debian installation using the application's generated menu/preseed.

The client has a single new qcow2 disk and a QEMU restricted user network. Its
only forwarded service is a private Unix-socket fixture mirror. QEMU supplies
DHCP/TFTP; the actual appliance dnsmasq and HTTP router are not exercised.
"""
from __future__ import annotations

import argparse
import json
import multiprocessing
import os
from pathlib import Path
import shlex
import sqlite3
import subprocess
import sys
import tempfile
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
# Verification and QEMU bench helpers kept with the tests (LGPL-3.0, reviewed).
PROTOTYPE = ROOT / 'tests/support/prototype'
sys.path.insert(0, str(PROTOTYPE / 'tests/integration'))
sys.path.insert(0, str(PROTOTYPE / 'app'))
from artifacts.boot_bundle import build_debian_bundle
from artifacts.debian_netboot import verify_debian_netboot
from debian_disk_modules import verify_disk_module
from full_install_mirror import FullInstallMirror
from run_qemu_dnsmasq_from_debian import prepare_inputs
from test_private_full_debian_install import observe, rendered_preseed
from test_qemu_dnsmasq_tftp import verify_packages

MAC = '02:00:00:00:00:01'
SERVER = '10.0.2.100'
VERSION = '13.0'


class BootMirror(FullInstallMirror):
    def __init__(self, *args, kernel: Path, initrd: Path, **kwargs):
        super().__init__(*args, **kwargs)
        self.kernel, self.initrd = kernel, initrd

    def file_for(self, request: str):
        if request == '/preseed.php?mac=' + MAC:
            return self.preseed, None
        prefix = '/netboot/debian/' + VERSION + '/amd64/'
        if request == prefix + 'linux':
            return None, self.kernel
        if request == prefix + 'initrd.gz':
            return None, self.initrd
        return super().file_for(request)


def generate_machine_menu(root: Path, tftp: Path) -> tuple[Path, bytes]:
    database = root / 'fixture.sqlite'
    with sqlite3.connect(database) as db:
        db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
        db.execute('INSERT INTO settings(key,value) VALUES(?,?)', ('pxe_ip', SERVER))
        config = json.dumps({'distro': 'debian', 'version_full': VERSION, 'network_mode': 'dhcp'})
        db.execute('INSERT INTO machines(mac,hostname,status,install_config_json) VALUES(?,?,?,?)',
                   (MAC, 'pxe-fixture', 'configured', config))
    env = dict(os.environ, PXE_DB_PATH=str(database), TFTP_ROOT=str(tftp),
               KERNEL_OPTS='console=ttyS0,115200n8 net.ifnames=0 netcfg/get_nameservers=10.0.2.3')
    subprocess.run(['/bin/sh', str(ROOT / 'docker/worker/scripts/render-per-mac-pxelinux.sh')],
                   env=env, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
    menu = tftp / 'pxelinux.cfg' / ('01-' + MAC.replace(':', '-'))
    content = menu.read_bytes()
    if (b'DEFAULT install\n' not in content or ('preseed.php?mac=' + MAC).encode() not in content
            or ('/netboot/debian/' + VERSION + '/amd64/linux').encode() not in content):
        raise RuntimeError('application did not produce the expected per-machine menu')
    # No fallback menu: successful installation must use the actual per-MAC output.
    (tftp / 'pxelinux.cfg/default').unlink()
    return menu, content


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keyring', type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=2400)
    args = parser.parse_args()
    if not 600 <= args.timeout <= 3600:
        parser.error('invalid test timeout')
    if os.environ.get('PXE_DISPOSABLE_PXE_INSTALL') != '1' or not Path('/.dockerenv').exists():
        parser.error('run only in the dedicated disposable test container')
    with tempfile.TemporaryDirectory(prefix='pxe-private-pxe-install-') as directory:
        root = Path(directory)
        (root / 'debs').mkdir()
        parameters = SimpleNamespace(keyring=args.keyring, runtime_manager=False, disk_guard=True,
                                     proxy_boot=True, uefi=False, partman_uefi=False,
                                     backup_guest=False, discovery_guest=False, discovery_pxe=False)
        preparation = multiprocessing.get_context('spawn').Process(target=prepare_inputs, args=(parameters, root))
        preparation.start(); preparation.join()
        if preparation.exitcode != 0:
            raise RuntimeError('authenticated Debian input preparation failed')
        verify_debian_netboot(root/'linux', root/'initrd.gz', root/'SHA256SUMS', root/'InRelease', args.keyring)
        verify_packages((root/'InRelease').read_bytes(), (root/'Packages.xz').read_bytes(), root/'debs')
        verify_disk_module((root/'InRelease').read_bytes(), (root/'installer-Packages.xz').read_bytes(),
                           root/'initrd.gz', root/'disk-modules.udeb')
        staging, served = root / 'staging', root / 'served'
        staging.mkdir(mode=0o700); served.mkdir(mode=0o700)
        packages = {name: root / (name + '.deb') for name in ('pxelinux', 'syslinux-common', 'grub-efi-amd64-signed')}
        bundle = build_debian_bundle(root/'linux', root/'initrd.gz', root/'SHA256SUMS', root/'InRelease',
                                     root/'Packages.xz', packages, args.keyring, staging, served, SERVER)
        tftp = bundle / 'tftp'
        menu, menu_content = generate_machine_menu(root, tftp)
        disk = root / 'fixture.qcow2'
        subprocess.run(['qemu-img', 'create', '-f', 'qcow2', str(disk), '12G'],
                       check=True, stdout=subprocess.DEVNULL)
        cache = root / 'mirror-cache'; cache.mkdir(mode=0o700)
        mirror = BootMirror(cache, root/'InRelease', args.keyring, rendered_preseed(), root/'Packages.xz',
                            kernel=bundle/'http/netboot/debian/linux', initrd=bundle/'http/netboot/debian/initrd.gz')
        mirror.start(root/'mirror.sock')
        try:
            forward = ' '.join(shlex.quote(x) for x in (sys.executable, '-u',
                               str(PROTOTYPE/'tests/integration/qemu_unix_http_forward.py'), str(root/'mirror.sock')))
            net = ('user,id=lan,restrict=on,tftp=' + str(tftp).replace(',', ',,')
                   + ',bootfile=lpxelinux.0,guestfwd=tcp:' + SERVER + ':80-cmd:' + forward.replace(',', ',,'))
            common = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
                      '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-m', '1024',
                      '-drive', 'file=' + str(disk) + ',if=none,id=disk,format=qcow2',
                      '-device', 'virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE']
            print('Starting isolated BIOS PXE client with the application per-machine menu', flush=True)
            observe(common + ['-boot', 'n', '-netdev', net, '-device', 'e1000,netdev=lan,mac=' + MAC],
                    rb'(?m)PXE_FULL_LATE_DONE\r?$', args.timeout, stop_on_match=False)
            expected = ['/netboot/debian/' + VERSION + '/amd64/linux',
                        '/netboot/debian/' + VERSION + '/amd64/initrd.gz', '/preseed.php?mac=' + MAC]
            if menu.read_bytes() != menu_content or any(path not in mirror.requests for path in expected):
                raise RuntimeError('the expected application menu or HTTP transfers were not observed')
            print('Actual per-machine menu loaded Debian and its application-generated preseed', flush=True)
            observe(common + ['-net', 'none'], rb'(?m)^pxe-fixture login:', 600, stop_on_match=True)
            print('PXE-installed Debian reached its local login prompt', flush=True)
        finally:
            mirror.close()


if __name__ == '__main__':
    main()
