#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the read-only identity guard on disposable, read-only QEMU disks.

No host disk or network is attached. Kernel and executable packages are
verified against independently supplied Debian archive keys before execution.
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
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from install.partman_overlay import build_identity_overlay  # noqa: E402
from debian_disk_modules import verify_disk_module  # noqa: E402
from test_qemu_dnsmasq_tftp import PACKAGES, build_overlay, verify_packages  # noqa: E402
from test_qemu_proxy_boot import archive_tree  # noqa: E402


def modules(raw: bytes, tree: Path, driver_tree: Path) -> list[str]:
    listing = subprocess.run(['cpio', '-it'], input=raw, stdout=subprocess.PIPE,
                             stderr=subprocess.DEVNULL, check=True).stdout.decode().splitlines()
    names = [name.removeprefix('./') for name in listing]
    builtins = [name for name in names if re.fullmatch(r'usr/lib/modules/[^/]+/modules\.builtin', name)]
    if len(builtins) != 1:
        raise ValueError('kernel built-in module list unavailable or ambiguous')
    member = './' + builtins[0] if './' + builtins[0] in listing else builtins[0]
    text = subprocess.run(['bsdtar', '-xOf', '-', member], input=raw, stdout=subprocess.PIPE,
                          stderr=subprocess.DEVNULL, check=True).stdout.decode('utf-8')
    if any(name not in text.splitlines() for name in ('kernel/drivers/virtio/virtio.ko',
                                                       'kernel/drivers/virtio/virtio_ring.ko',
                                                       'kernel/drivers/virtio/virtio_pci.ko')):
        raise ValueError('virtio transport is not built into this kernel')
    matches = list(driver_tree.rglob('virtio_blk.ko.xz')) + list(driver_tree.rglob('virtio_blk.ko'))
    if len(matches) != 1:
        raise ValueError('installer virtio disk module unavailable or ambiguous')
    destination = tree / 'kmods'
    destination.mkdir()
    data = matches[0].read_bytes()
    (destination / 'virtio_blk.ko').write_bytes(lzma.decompress(data) if matches[0].suffix == '.xz' else data)
    return ['/kmods/virtio_blk.ko']


def run_guest(kernel: Path, initrd: Path, root: Path, image: Path, variant: str, timeout: int) -> None:
    serials = (['PXE-TEST-ONE', 'PXE-TEST-TWO', 'PXE-TEST-TWO'] if variant == 'duplicates'
               else ['PXE-TEST-ONE', '000000000000' if variant == 'generic' else None])
    disks = []
    command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
               '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-net', 'none', '-m', '512',
               '-kernel', str(kernel), '-initrd', str(image), '-append', 'console=ttyS0,115200n8']
    for number, serial in enumerate(serials):
        disk = root / f'{variant}-{number}.raw'
        with disk.open('xb') as stream:
            stream.write(b'PXE-TEST-DISK-FIXTURE\n')
            stream.truncate(8 * 1024 * 1024)
        with disk.open('rb') as stream:
            digest = hashlib.file_digest(stream, 'sha256').hexdigest()
        disks.append((disk, digest))
        command += ['-drive', f'file={disk},if=none,id=d{number},format=raw,readonly=on',
                    '-device', f'virtio-blk-pci,drive=d{number}' + (',serial=' + serial if serial else '')]
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    log = bytearray()
    try:
        assert process.stdout is not None
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                block = os.read(key.fileobj.fileno(), 65536)
                if block:
                    log.extend(block)
            marker = b'PXE_GUARD_DUPLICATES_OK' if variant == 'duplicates' else b'PXE_GUARD_UNKNOWN_OK'
            if marker in log:
                break
            if b'PXE_GUARD_FAILED' in log or process.poll() is not None:
                raise RuntimeError(log[-6000:].decode('utf-8', errors='replace'))
        else:
            raise RuntimeError('disk guard VM timed out:\n' + log[-6000:].decode('utf-8', errors='replace'))
    finally:
        selector.close()
        if process.poll() is None:
            process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
    for disk, digest in disks:
        with disk.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                raise AssertionError('read-only virtual disk changed')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb_dir'):
        parser.add_argument('--' + name.replace('_', '-'), type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=180)
    parser.add_argument('--disk-index', type=Path, required=True)
    parser.add_argument('--disk-modules', type=Path, required=True)
    args = parser.parse_args()
    if not 60 <= args.timeout <= 900:
        parser.error('timeout must be between 60 and 900 seconds')
    for tool in ('qemu-system-x86_64', 'dpkg-deb', 'cpio', 'bsdtar'):
        if not shutil.which(tool):
            parser.error(tool + ' is required')
    with tempfile.TemporaryDirectory(prefix='pxe-vm-', dir='/tmp') as directory:
        root = Path(directory)
        verify_debian_netboot(args.kernel, args.initrd, args.sums, args.release, args.keyring)
        verify_packages(args.release.read_bytes(), args.index.read_bytes(), args.deb_dir)
        verify_disk_module(args.release.read_bytes(), args.disk_index.read_bytes(), args.initrd, args.disk_modules)
        extracted = root / 'extracted'
        extracted.mkdir()
        for name in PACKAGES:
            subprocess.run(['dpkg-deb', '-x', str(args.deb_dir / (name + '.deb')), str(extracted)],
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        drivers = root / 'disk-drivers'
        drivers.mkdir()
        subprocess.run(['dpkg-deb', '-x', str(args.disk_modules), str(drivers)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        raw = gzip.decompress(args.initrd.read_bytes())
        overlay = root / 'overlay.gz'
        build_overlay(extracted, b'', '', server=True, target=overlay)
        tree = root / 'overlay-server'
        module_paths = modules(raw, tree, drivers)
        del raw
        guard = tree / 'opt/pxe-tools/verify_disk_identity.sh'
        shutil.copy2(ROOT / 'app/install/verify_disk_identity.sh', guard)
        init_head = '''#!/bin/sh
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
/usr/bin/busybox --install -s /usr/bin
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev
'''
        for module in module_paths:
            init_head += f'insmod {module} || {{ echo PXE_GUARD_FAILED; exec sleep 600; }}\n'
        init_body = '''
guard=/opt/pxe-tools/verify_disk_identity.sh
first=/lib/partman/init.d/00-pxe-identity
commit=/lib/partman/commit.d/00-pxe-identity
mkdir -p /run/pxe-tools
chown 0:0 /run/pxe-tools
chmod 700 /run/pxe-tools
if /bin/sh "$first"; then echo PXE_GUARD_FAILED; exec sleep 600; fi
if /bin/sh "$commit"; then echo PXE_GUARD_FAILED; exec sleep 600; fi
printf '/dev/vda\\nPXE-TEST-ONE\\n' >/run/pxe-tools/disk-identity
chown 0:0 /run/pxe-tools/disk-identity
chmod 600 /run/pxe-tools/disk-identity
deny() { echo PXE_GUARD_FAILED; exec sleep 600; }
if [ -b /dev/vdc ]; then
  printf '/dev/vda\\nPXE-TEST-ONE\\n' | /bin/sh "$guard" || deny
  /bin/sh "$first" || deny
  /bin/sh "$commit" || deny
  chmod 644 /run/pxe-tools/disk-identity || deny
  if /bin/sh "$first"; then deny; fi
  chmod 600 /run/pxe-tools/disk-identity || deny
  chmod 666 /usr/lib/pxe-tools/verify_disk_identity.sh || deny
  if /bin/sh "$first"; then deny; fi
  chmod 500 /usr/lib/pxe-tools/verify_disk_identity.sh || deny
  if printf '/dev/vdb\\nPXE-TEST-TWO\\n' | /bin/sh "$guard"; then deny; fi
  if printf '/dev/vda\\nPXE-TEST-TWO\\n' | /bin/sh "$guard"; then deny; fi
  if printf '/dev/vda1\\nPXE-TEST-ONE\\n' | /bin/sh "$guard"; then deny; fi
  if printf '/dev/vda\\nUNKNOWN\\n' | /bin/sh "$guard"; then deny; fi
  if printf '/dev/vda\\n000000000000\\n' | /bin/sh "$guard"; then deny; fi
  mv /dev/vda /dev/vda-original || deny
  ln -s /dev/vdb /dev/vda || deny
  if printf '/dev/vda\\nPXE-TEST-ONE\\n' | /bin/sh "$guard"; then deny; fi
  if /bin/sh "$commit"; then deny; fi
  rm /dev/vda || deny
  mv /dev/vda-original /dev/vda || deny
  printf '/dev/vda\\nPXE-TEST-ONE\\n' | /bin/sh "$guard" || deny
  echo PXE_GUARD_DUPLICATES_OK
else
  if printf '/dev/vda\\nPXE-TEST-ONE\\n' | /bin/sh "$guard"; then deny; fi
  if /bin/sh "$first"; then deny; fi
  if /bin/sh "$commit"; then deny; fi
  echo PXE_GUARD_UNKNOWN_OK
fi
exec sleep 600
'''
        (tree / 'init').write_text(init_head + init_body, encoding='ascii')
        archive_tree(tree, overlay)
        combined = root / 'guest-initrd.gz'
        identity_overlay = build_identity_overlay(extracted / 'usr/bin/busybox')
        if identity_overlay != build_identity_overlay(extracted / 'usr/bin/busybox'):
            raise ValueError('installer overlay is not reproducible')
        with combined.open('wb') as output:
            for part in (args.initrd, overlay):
                with part.open('rb') as source:
                    shutil.copyfileobj(source, output)
            output.write(identity_overlay)
        print('Testing identity guard on read-only disposable virtual disks', flush=True)
        run_guest(args.kernel, args.initrd, root, combined, 'duplicates', args.timeout)
        run_guest(args.kernel, args.initrd, root, combined, 'unknown', args.timeout)
        run_guest(args.kernel, args.initrd, root, combined, 'generic', args.timeout)
        print('Disk identity: unique match accepted, duplicates and unknown identity refused; disks unchanged')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
