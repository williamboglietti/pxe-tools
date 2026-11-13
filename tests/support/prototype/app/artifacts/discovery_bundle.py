# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare a separate experimental BIOS discovery set; never start a service.

The caller supplies an independently trusted keyring and protected roots.
Authenticated files at rest do not authenticate the BIOS network boot path.
"""
from __future__ import annotations

import os
from pathlib import Path
import secrets
import shutil
import tempfile

from .boot_bundle import (MAX_INDEX, _copy_verified_package,
                          _package_records, _write_menus, build_debian_bundle)
from .publish import _roots
from .verify import VerificationError, _bounded_read
from discovery.runtime_overlay import PACKAGE_ARCH, PYTHON_PACKAGES, build_discovery_overlay

# No partitioning settings, credentials, targets, or automatic reboot. The
# unconditional loop prevents a successful or failed client from advancing to
# installer steps. The operator must power off or restart the machine.
PRESEED = b'''d-i debian-installer/locale string en_US.UTF-8
d-i keyboard-configuration/xkb-keymap select us
d-i netcfg/choose_interface select auto
d-i preseed/early_command string /bin/sh /usr/lib/pxe-tools/discovery/discover </dev/console >/dev/console 2>&1; echo 'Decouverte terminee. Redemarrez ou eteignez cette machine.' >/dev/console; while :; do sleep 3600; done
'''


def build_discovery_bundle(kernel: Path, initrd: Path, sums: Path, release: Path, index: Path,
                           boot_packages: dict[str, Path], runtime_packages: dict[str, Path],
                           keyring: Path, staging_root: Path, served_root: Path, server_ip: str) -> Path:
    """Create a new inactive set, keeping its authenticated inputs for rechecking."""
    staging, served = _roots(staging_root, served_root)
    if set(runtime_packages) != set(PYTHON_PACKAGES):
        raise VerificationError('exact discovery runtime packages required')
    try:
        with tempfile.TemporaryDirectory(prefix='pxe-discovery-stage-', dir=staging) as directory:
            root = Path(directory)
            base_staging, base_served = root / 'staging', root / 'served'
            base_staging.mkdir(mode=0o700)
            base_served.mkdir(mode=0o700)
            base = build_debian_bundle(kernel, initrd, sums, release, index, boot_packages, keyring,
                                       base_staging, base_served, server_ip)
            stage = root / 'content'
            shutil.copytree(base, stage)
            metadata = stage / 'metadata'
            release_bytes = _bounded_read(metadata / 'InRelease', 8 * 1024 * 1024)
            index_bytes = _bounded_read(metadata / 'Packages.xz', MAX_INDEX)
            records = _package_records(release_bytes, index_bytes, PACKAGE_ARCH)
            package_dir = metadata / 'discovery-packages'
            package_dir.mkdir()
            copied = {}
            for name in PYTHON_PACKAGES:
                target = package_dir / (name + '.deb')
                _copy_verified_package(Path(runtime_packages[name]), target, records[name])
                copied[name] = target
            images = stage / 'http/netboot/debian'
            overlay = build_discovery_overlay(images / 'linux', images / 'initrd.gz', metadata / 'SHA256SUMS',
                                               metadata / 'InRelease', metadata / 'Packages.xz', keyring, copied)
            with (images / 'discovery-initrd.gz').open('xb') as output, (images / 'initrd.gz').open('rb') as original:
                shutil.copyfileobj(original, output, length=1024 * 1024)
                output.write(overlay)
            discovery = stage / 'http/discovery'
            discovery.mkdir()
            (discovery / 'preseed.cfg').write_bytes(PRESEED)
            menus = root / 'menus'
            menus.mkdir()
            _write_menus(menus, server_ip, discovery=True)
            shutil.copyfile(menus / 'pxelinux.cfg/default', stage / 'tftp/pxelinux.cfg/default')
            for parent, dirs, files in os.walk(stage, followlinks=False):
                for name in dirs:
                    (Path(parent) / name).chmod(0o755)
                for name in files:
                    (Path(parent) / name).chmod(0o644)
            stage.chmod(0o755)
            target = served / ('bundle-' + secrets.token_hex(16))
            target.mkdir(mode=0o700)
            try:
                os.rename(stage, target / 'content')
                target.chmod(0o755)
                return target / 'content'
            except OSError:
                try:
                    target.rmdir()
                except OSError:
                    pass
                raise
    except VerificationError:
        raise
    except (OSError, ValueError) as error:
        raise VerificationError('discovery bundle could not be prepared') from error
