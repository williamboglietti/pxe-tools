# SPDX-License-Identifier: LGPL-3.0-only
"""Build a fixed experimental Debian Installer discovery runtime overlay.

Every Debian input is checked against an authenticated InRelease and package
index. The caller must independently authenticate and maintain the keyring.
The archive contains no token, certificate, target, or personal configuration.
It is not suitable for redistribution until third-party licenses, sources, and
notices have been reviewed.
"""
from __future__ import annotations

import gzip
import io
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tarfile
import tempfile

from artifacts.boot_bundle import MAX_INDEX, _copy_verified_package, _package_records
from artifacts.debian_netboot import verify_debian_netboot
from artifacts.verify import VerificationError, _bounded_read
from install.partman_overlay import _entry, _read_file

APP = Path(__file__).resolve().parents[1]
BASE = 'usr/lib/pxe-tools/discovery'
PYTHON_PACKAGES = (
    'python3.13-minimal', 'libpython3.13-minimal', 'libpython3.13-stdlib',
    'libc6', 'libssl3t64', 'libexpat1', 'zlib1g', 'libzstd1',
)
PACKAGE_ARCH = {name: 'amd64' for name in PYTHON_PACKAGES}
MAX_ENTRY = 16 * 1024 * 1024
MAX_TOTAL = 128 * 1024 * 1024
MAX_FILES = 5000

WRAPPER = b'''#!/bin/sh
set -eu
root=/usr/lib/pxe-tools/discovery
exec /usr/bin/env -i PATH=/usr/bin:/bin LANG=C PYTHONHOME="$root/python" PYTHONDONTWRITEBYTECODE=1 \\
  "$root/python/lib/ld-linux-x86-64.so.2" --library-path "$root/python/lib" \\
  "$root/python/bin/python3.13" -s -S "$root/submit.py" "$@"
'''
DISCOVER_WRAPPER = WRAPPER.replace(b'"$root/submit.py"', b'"$root/interactive.py"')


def _safe_parts(value: str) -> tuple[str, ...]:
    if not value or re.fullmatch(r'[A-Za-z0-9_./+@-]+', value) is None:
        raise VerificationError('invalid discovery runtime path')
    parts = PurePosixPath(value).parts
    if not parts or any(part in ('.', '..', '') for part in value.split('/')) or value.startswith('/'):
        raise VerificationError('invalid discovery runtime path')
    return parts


def _destination(package: str, name: str) -> str | None:
    if package == 'python3.13-minimal' and name == 'usr/bin/python3.13':
        return BASE + '/python/bin/python3.13'
    if package in ('libpython3.13-minimal', 'libpython3.13-stdlib') and name.startswith('usr/lib/python3.13/'):
        # Debian's sitecustomize points outside the archive. The wrapper uses
        # -S and never loads host/site customization in this fixed runtime.
        if name == 'usr/lib/python3.13/sitecustomize.py':
            return None
        suffix = name[len('usr/lib/python3.13/'):]
        _safe_parts(suffix)
        if suffix.startswith('lib-dynload/') and re.fullmatch(
                r'lib-dynload/(?:_ssl|_hashlib|termios)\.cpython-313-x86_64-linux-gnu\.so', suffix) is None:
            return None
        return BASE + '/python/lib/python3.13/' + suffix
    if name.startswith('usr/lib/x86_64-linux-gnu/'):
        suffix = name[len('usr/lib/x86_64-linux-gnu/'):]
        if '/' not in suffix and re.fullmatch(
                r'(?:ld-linux-x86-64|libc|libm|libz|libzstd|libexpat|libssl|libcrypto)\.so(?:\.[A-Za-z0-9_.-]+)*', suffix):
            return BASE + '/python/lib/' + suffix
    return None


def _extract_selected(package: Path, name: str, files: dict[str, tuple[int, bytes]], total: int) -> int:
    """Read selected regular files and local library symlinks, never extract paths."""
    with tempfile.TemporaryFile() as errors:
        process = subprocess.Popen(['dpkg-deb', '--fsys-tarfile', str(package)], stdout=subprocess.PIPE, stderr=errors)
        try:
            assert process.stdout is not None
            with tarfile.open(fileobj=process.stdout, mode='r|*') as archive:
                for member in archive:
                    if member.isdir():
                        continue
                    name_in_archive = member.name.removeprefix('./')
                    destination = _destination(name, name_in_archive)
                    if destination is None:
                        continue
                    _safe_parts(destination)
                    if destination in files or len(files) >= MAX_FILES:
                        raise VerificationError('duplicate or excessive discovery runtime files')
                    if member.isfile():
                        if not 0 <= member.size <= MAX_ENTRY:
                            raise VerificationError('discovery runtime file too large')
                        source = archive.extractfile(member)
                        if source is None:
                            raise VerificationError('missing discovery runtime file')
                        with source:
                            content = source.read(MAX_ENTRY + 1)
                        if len(content) != member.size:
                            raise VerificationError('incomplete discovery runtime file')
                        mode = stat.S_IFREG | (0o555 if destination.endswith('.so') or '.so.' in destination
                                               or destination.endswith('/python3.13') else 0o444)
                    elif member.issym():
                        # Only a same-directory relative target can be retained.
                        if re.fullmatch(r'[A-Za-z0-9_+.-]+', member.linkname) is None or member.linkname in ('.', '..'):
                            raise VerificationError('unsafe discovery runtime symlink in ' + name + ':' + name_in_archive)
                        content = member.linkname.encode('ascii')
                        mode = stat.S_IFLNK | 0o777
                    else:
                        raise VerificationError('unsupported discovery runtime archive entry in ' + name)
                    total += len(content)
                    if total > MAX_TOTAL:
                        raise VerificationError('discovery runtime exceeds allowed size')
                    files[destination] = (mode, content)
            while process.stdout.read(1024 * 1024):
                pass
            process.stdout.close()
            if process.wait(timeout=30) != 0:
                raise VerificationError('discovery runtime extraction failed')
            return total
        except BaseException:
            if process.poll() is None:
                process.kill()
            process.wait()
            raise


def build_discovery_overlay(kernel: Path, initrd: Path, sums: Path, release: Path, index: Path,
                            keyring: Path, packages: dict[str, Path]) -> bytes:
    """Return a fixed gzip/newc overlay after authenticating all Debian inputs."""
    if set(packages) != set(PYTHON_PACKAGES):
        raise VerificationError('exact discovery runtime packages required')
    files: dict[str, tuple[int, bytes]] = {}
    total = 0
    with tempfile.TemporaryDirectory(prefix='pxe-discovery-build-') as directory:
        root = Path(directory)
        # Authenticate and parse the same immutable snapshots. Rereading a
        # mutable source after verifying its signature would permit a swap.
        release_bytes = _bounded_read(release, 8 * 1024 * 1024)
        sums_bytes = _bounded_read(sums, 8 * 1024 * 1024)
        index_bytes = _bounded_read(index, MAX_INDEX)
        signed, signed_sums = root / 'InRelease', root / 'SHA256SUMS'
        signed.write_bytes(release_bytes)
        signed_sums.write_bytes(sums_bytes)
        verify_debian_netboot(kernel, initrd, signed_sums, signed, keyring)
        records = _package_records(release_bytes, index_bytes, PACKAGE_ARCH)
        for name in PYTHON_PACKAGES:
            verified = root / (name + '.deb')
            _copy_verified_package(Path(packages[name]), verified, records[name])
            total = _extract_selected(verified, name, files, total)
    required = {BASE + '/python/bin/python3.13', BASE + '/python/lib/ld-linux-x86-64.so.2',
                BASE + '/python/lib/libc.so.6', BASE + '/python/lib/libssl.so.3',
                BASE + '/python/lib/libcrypto.so.3', BASE + '/python/lib/libm.so.6',
                BASE + '/python/lib/python3.13/encodings/__init__.py',
                BASE + '/python/lib/python3.13/lib-dynload/_ssl.cpython-313-x86_64-linux-gnu.so',
                BASE + '/python/lib/python3.13/lib-dynload/_hashlib.cpython-313-x86_64-linux-gnu.so',
                BASE + '/python/lib/python3.13/lib-dynload/termios.cpython-313-x86_64-linux-gnu.so'}
    if not required.issubset(files) or any(not stat.S_ISREG(files[path][0]) for path in required):
        raise VerificationError('required discovery runtime files missing')
    if not {BASE + '/python/lib/libz.so.1', BASE + '/python/lib/libzstd.so.1',
            BASE + '/python/lib/libexpat.so.1'}.issubset(files):
        raise VerificationError('required discovery runtime libraries missing')
    for name, (mode, _) in files.items():
        if not stat.S_ISLNK(mode):
            continue
        seen = set()
        current = name
        while current in files and stat.S_ISLNK(files[current][0]):
            if current in seen:
                raise VerificationError('discovery runtime symlink cycle')
            seen.add(current)
            current = str(PurePosixPath(current).parent / files[current][1].decode('ascii'))
        if current not in files or not stat.S_ISREG(files[current][0]):
            raise VerificationError('discovery runtime symlink target missing')
    for name, path in (('collect.sh', APP / 'discovery/collect.sh'), ('submit.py', APP / 'discovery/submit.py'),
                       ('interactive.py', APP / 'discovery/interactive.py')):
        data = _read_file(path, 128 * 1024)
        if not data.startswith(b'#!'):
            raise VerificationError('invalid discovery client script')
        files[BASE + '/' + name] = (stat.S_IFREG | 0o555, data)
    files[BASE + '/submit'] = (stat.S_IFREG | 0o555, WRAPPER)
    files[BASE + '/discover'] = (stat.S_IFREG | 0o555, DISCOVER_WRAPPER)
    directories: set[str] = set()
    for name in files:
        parts = _safe_parts(name)
        directories.update('/'.join(parts[:index]) for index in range(1, len(parts)))
    output = io.BytesIO()
    number = 0
    with gzip.GzipFile(fileobj=output, mode='wb', filename='', compresslevel=9, mtime=0) as stream:
        for directory in sorted(directories, key=lambda item: (item.count('/'), item)):
            number += 1
            stream.write(_entry(directory, stat.S_IFDIR | 0o755, b'', number))
        for name, (mode, content) in sorted(files.items()):
            number += 1
            stream.write(_entry(name, mode, content, number))
        stream.write(_entry('TRAILER!!!', 0, b'', number + 1))
    return output.getvalue()
