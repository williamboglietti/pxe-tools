#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Authenticated Debian mirror for the disposable, isolated appliance test."""
from __future__ import annotations

import multiprocessing
import os
from pathlib import Path
import sys
import time
from types import SimpleNamespace

if os.environ.get('PXE_DISPOSABLE_APPLIANCE_MIRROR') != '1' or not Path('/.dockerenv').exists():
    raise SystemExit('This fixture must run in its dedicated disposable container.')
prototype = Path('/fixture-prototype')
sys.path[:0] = [str(prototype/'tests/integration'), str(prototype/'app'), '/fixture-source/tests/integration']
from artifacts.debian_netboot import verify_debian_netboot
from debian_disk_modules import verify_disk_module
from full_install_mirror import FullInstallMirror
from run_qemu_dnsmasq_from_debian import prepare_inputs
from test_qemu_dnsmasq_tftp import verify_packages


def main():
    root = Path('/fixture-data')
    keyring = Path('/usr/share/keyrings/debian-archive-keyring.gpg')
    (root/'debs').mkdir()
    args = SimpleNamespace(keyring=keyring, runtime_manager=False, disk_guard=True,
                           proxy_boot=True, uefi=False, partman_uefi=False,
                           backup_guest=False, discovery_guest=False, discovery_pxe=False)
    child = multiprocessing.get_context('spawn').Process(target=prepare_inputs, args=(args, root))
    child.start(); child.join()
    if child.exitcode != 0:
        raise RuntimeError('Debian input preparation failed')
    verify_debian_netboot(root/'linux', root/'initrd.gz', root/'SHA256SUMS', root/'InRelease', keyring)
    verify_packages((root/'InRelease').read_bytes(), (root/'Packages.xz').read_bytes(), root/'debs')
    verify_disk_module((root/'InRelease').read_bytes(), (root/'installer-Packages.xz').read_bytes(),
                       root/'initrd.gz', root/'disk-modules.udeb')
    cache = root/'mirror-cache'; cache.mkdir()
    mirror = FullInstallMirror(cache, root/'InRelease', keyring, b'', root/'Packages.xz')
    socket = root/'mirror.sock'
    mirror.start(socket); socket.chmod(0o666)
    (root/'ready').write_text('verified Debian inputs and mirror ready\n')
    try:
        while True: time.sleep(60)
    finally:
        mirror.close()


if __name__ == '__main__': main()
