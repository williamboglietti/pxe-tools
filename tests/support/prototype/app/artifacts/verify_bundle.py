# SPDX-License-Identifier: LGPL-3.0-only
"""Recheck a prepared bundle without activating services or modifying it.

The bundle and every parent under the supplied served root must remain owned by
this user and inaccessible to untrusted writers during and after verification.
This check alone does not make a mutable directory safe to serve.
"""
from __future__ import annotations

import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import tarfile
import tempfile

from .boot_bundle import (MAX_INDEX, PACKAGE_CONTENTS, _copy_verified_package, _extract_package,
                          _package_records, _validate_server_ip, _write_menus)
from .debian_netboot import verify_debian_netboot
from .publish import _safe_root
from .verify import VerificationError, _bounded_read, _open_regular

TFTP_FILES = {'lpxelinux.0', 'ldlinux.c32', 'menu.c32', 'libcom32.c32', 'libutil.c32',
              'bootx64.efi', 'pxelinux.cfg/default', 'grub/grub.cfg',
              'netboot/debian/linux', 'netboot/debian/initrd.gz'}
HTTP_FILES = {'netboot/debian/linux', 'netboot/debian/initrd.gz'}


def _owned(path: Path, *, directory: bool) -> None:
    info = path.lstat()
    if (info.st_uid != os.geteuid() or info.st_mode & 0o022
            or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))):
        raise VerificationError('bundle content must be owned and protected from other writers')


def _tree(root: Path, expected: set[str]) -> None:
    _owned(root, directory=True)
    found = set()
    directories = {str(Path(name).parent) for name in expected}
    directories.discard('.')
    directories |= {str(parent) for item in list(directories) for parent in Path(item).parents if str(parent) != '.'}
    found_directories: set[str] = set()
    for parent, dirs, files in os.walk(root, followlinks=False):
        for name in dirs:
            path = Path(parent) / name
            _owned(path, directory=True)
            found_directories.add(path.relative_to(root).as_posix())
        for name in files:
            path = Path(parent) / name
            _owned(path, directory=False)
            found.add(path.relative_to(root).as_posix())
    if found != expected or found_directories != directories:
        raise VerificationError('bundle contains missing or unexpected served paths')


def _digest(path: Path, maximum: int) -> tuple[int, bytes]:
    with _open_regular(path) as stream:
        before = os.fstat(stream.fileno())
        if before.st_size > maximum:
            raise VerificationError('bundle file exceeds limit')
        digest = hashlib.file_digest(stream, 'sha256').digest()
        after = os.fstat(stream.fileno())
    if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
        raise VerificationError('bundle file changed while checking')
    return before.st_size, digest


def verify_bundle(served_root: Path, relative: str, keyring: Path, server_ip: str, *, discovery: bool = False) -> None:
    """Authenticate the selected set and every path that its servers would expose."""
    if not isinstance(relative, str) or not re.fullmatch(r'bundle-[0-9a-f]{32}/content', relative):
        raise VerificationError('invalid bundle location')
    _validate_server_ip(server_ip)
    try:
        root = _safe_root(served_root)
        parent = root / relative.split('/')[0]
        bundle = parent / 'content'
        _owned(parent, directory=True)
        _owned(bundle, directory=True)
        metadata, http, tftp = bundle / 'metadata', bundle / 'http', bundle / 'tftp'
        _owned(metadata, directory=True)
        for name in ('InRelease', 'SHA256SUMS', 'Packages.xz', *(name + '.deb' for name in PACKAGE_CONTENTS)):
            _owned(metadata / name, directory=False)
        _tree(http, HTTP_FILES | ({'netboot/debian/discovery-initrd.gz', 'discovery/preseed.cfg'} if discovery else set()))
        _tree(tftp, TFTP_FILES)
        http_images = http / 'netboot/debian'
        tftp_images = tftp / 'netboot/debian'
        release = _bounded_read(metadata / 'InRelease', 8 * 1024 * 1024)
        sums = _bounded_read(metadata / 'SHA256SUMS', 8 * 1024 * 1024)
        index = _bounded_read(metadata / 'Packages.xz', MAX_INDEX)
        with tempfile.TemporaryDirectory(prefix='pxe-reverify-') as directory:
            temp = Path(directory)
            (temp / 'InRelease').write_bytes(release)
            (temp / 'SHA256SUMS').write_bytes(sums)
            (temp / 'Packages.xz').write_bytes(index)
            verify_debian_netboot(http_images / 'linux', http_images / 'initrd.gz', temp / 'SHA256SUMS',
                                  temp / 'InRelease', keyring)
            for name, maximum in (('linux', 128 * 1024 * 1024), ('initrd.gz', 256 * 1024 * 1024)):
                if _digest(http_images / name, maximum) != _digest(tftp_images / name, maximum):
                    raise VerificationError('TFTP and HTTP boot artifacts differ')
            records = _package_records(release, index)
            extracted = temp / 'extracted'
            extracted.mkdir()
            for name, wanted in PACKAGE_CONTENTS.items():
                package = temp / (name + '.deb')
                _copy_verified_package(metadata / (name + '.deb'), package, records[name])
                _extract_package(package, wanted, extracted)
                for output in wanted.values():
                    if _digest(tftp / output, 16 * 1024 * 1024) != _digest(extracted / output, 16 * 1024 * 1024):
                        raise VerificationError('bootloader differs from verified package')
            menus = temp / 'menus'
            menus.mkdir()
            _write_menus(menus, server_ip, discovery=discovery)
            for name in ('pxelinux.cfg/default', 'grub/grub.cfg'):
                if _bounded_read(tftp / name, 16384) != _bounded_read(menus / name, 16384):
                    raise VerificationError('boot menu differs from the selected address')
            if discovery:
                from .discovery_bundle import PRESEED
                from discovery.runtime_overlay import PYTHON_PACKAGES, build_discovery_overlay
                package_dir = metadata / 'discovery-packages'
                _owned(package_dir, directory=True)
                packages = {name: package_dir / (name + '.deb') for name in PYTHON_PACKAGES}
                for package in packages.values():
                    _owned(package, directory=False)
                if _bounded_read(http / 'discovery/preseed.cfg', 16384) != PRESEED:
                    raise VerificationError('discovery preseed differs from the expected configuration')
                overlay = build_discovery_overlay(http_images / 'linux', http_images / 'initrd.gz',
                                                   temp / 'SHA256SUMS', temp / 'InRelease', temp / 'Packages.xz',
                                                   keyring, packages)
                expected = hashlib.sha256()
                with _open_regular(http_images / 'initrd.gz') as original:
                    for block in iter(lambda: original.read(1024 * 1024), b''):
                        expected.update(block)
                expected.update(overlay)
                combined = _digest(http_images / 'discovery-initrd.gz', 384 * 1024 * 1024)
                if combined != ((http_images / 'initrd.gz').stat().st_size + len(overlay), expected.digest()):
                    raise VerificationError('discovery initrd differs from verified inputs')
    except VerificationError:
        raise
    except (OSError, ValueError, tarfile.TarError, subprocess.SubprocessError) as error:
        raise VerificationError('bundle could not be reverified') from error
