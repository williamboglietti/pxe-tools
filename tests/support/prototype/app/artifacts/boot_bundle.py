# SPDX-License-Identifier: LGPL-3.0-only
"""Build a local BIOS/UEFI netboot set from independently authenticated Debian files.

The caller must maintain a trusted Debian archive keyring and a trusted clock.
The two storage roots must be worker-owned and protected from untrusted writers.
No service is started, existing set replaced, or active version changed.
"""
from __future__ import annotations

import hashlib
import codecs
import ipaddress
import json
import lzma
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import tempfile

from .debian_netboot import _clearsigned_payload, verify_debian_netboot
from .publish import _roots
from .verify import VerificationError, _bounded_read, _open_regular

PACKAGE_CONTENTS = {
    'pxelinux': {'usr/lib/PXELINUX/lpxelinux.0': 'lpxelinux.0'},
    'syslinux-common': {f'usr/lib/syslinux/modules/bios/{name}': name
                        for name in ('ldlinux.c32', 'menu.c32', 'libcom32.c32', 'libutil.c32')},
    'grub-efi-amd64-signed': {'usr/lib/grub/x86_64-efi-signed/grubnetx64.efi.signed': 'bootx64.efi'},
}
PACKAGE_ARCH = {'pxelinux': 'all', 'syslinux-common': 'all', 'grub-efi-amd64-signed': 'amd64'}
MAX_INDEX = 40 * 1024 * 1024
MAX_INDEX_TEXT = 128 * 1024 * 1024
MAX_PACKAGE = 32 * 1024 * 1024
MAX_BOOT_FILE = 16 * 1024 * 1024


def _package_records(release: bytes, compressed: bytes, package_arch: dict[str, str] | None = None) -> dict[str, dict[str, str]]:
    package_arch = PACKAGE_ARCH if package_arch is None else package_arch
    if (not package_arch or any(re.fullmatch(r'[a-z0-9][a-z0-9+.-]*', name) is None or arch not in ('all', 'amd64')
                                for name, arch in package_arch.items())):
        raise VerificationError('invalid package selection')
    lines = _clearsigned_payload(release)
    found = []
    inside = False
    sections = 0
    for line in lines:
        if line == 'SHA256:':
            inside = True
            sections += 1
            continue
        if inside and not line.startswith(' '):
            inside = False
        if inside:
            entry = re.fullmatch(r'\s+([a-fA-F0-9]{64})\s+(\d+)\s+(\S+)', line)
            if entry and entry.group(3) == 'main/binary-amd64/Packages.xz':
                found.append((entry.group(1).lower(), int(entry.group(2))))
    if sections != 1 or found != [(hashlib.sha256(compressed).hexdigest(), len(compressed))]:
        raise VerificationError('package index does not match signed release')
    candidates: dict[str, list[dict[str, str]]] = {name: [] for name in package_arch}

    def collect(section: str) -> None:
        head = section.split('\n', 1)[0]
        if not head.startswith('Package: ') or head[9:] not in candidates:
            return
        fields: dict[str, str] = {}
        for line in section.splitlines():
            if ': ' not in line or line.startswith((' ', '\t')):
                continue
            key, value = line.split(': ', 1)
            if key in fields:
                raise VerificationError('ambiguous package field')
            fields[key] = value
        name = fields.get('Package', '')
        if name in candidates and fields.get('Architecture') == package_arch[name]:
            candidates[name].append(fields)

    # Stream decompression and UTF-8 decoding in bounded chunks. Only the
    # handful of needed paragraphs are retained, not the full expanded index.
    try:
        decompressor = lzma.LZMADecompressor(memlimit=256 * 1024 * 1024)
        decoder = codecs.getincrementaldecoder('utf-8')('strict')
        pending = ''
        offset = 0
        total = 0
        while not decompressor.eof:
            if decompressor.needs_input:
                if offset >= len(compressed):
                    raise VerificationError('truncated package index')
                chunk = compressed[offset:offset + 65536]
                offset += len(chunk)
            else:
                chunk = b''
            block = decompressor.decompress(chunk, max_length=1024 * 1024)
            total += len(block)
            if total > MAX_INDEX_TEXT:
                raise VerificationError('package index exceeds allowed size')
            sections = (pending + decoder.decode(block, final=False)).split('\n\n')
            pending = sections.pop()
            if len(pending) > 1024 * 1024:
                raise VerificationError('package record exceeds allowed size')
            for section in sections:
                if len(section) > 1024 * 1024:
                    raise VerificationError('package record exceeds allowed size')
                collect(section)
        if decompressor.unused_data or offset != len(compressed):
            raise VerificationError('package index has trailing data')
        pending += decoder.decode(b'', final=True)
        if pending:
            collect(pending)
    except (lzma.LZMAError, UnicodeError) as error:
        raise VerificationError('invalid package index') from error
    if any(len(items) != 1 for items in candidates.values()):
        raise VerificationError('bootloader package missing or ambiguous')
    records = {name: items[0] for name, items in candidates.items()}
    for fields in records.values():
        if not re.fullmatch(r'[a-fA-F0-9]{64}', fields.get('SHA256', '')) or not fields.get('Size', '').isdigit():
            raise VerificationError('invalid package checksum or size')
        if not 1 <= int(fields['Size']) <= MAX_PACKAGE:
            raise VerificationError('bootloader package size is outside allowed limits')
    return records


def _copy_verified_package(source: Path, target: Path, fields: dict[str, str]) -> None:
    expected_size = int(fields['Size'])
    digest = hashlib.sha256()
    count = 0
    with _open_regular(source) as input_file, target.open('xb') as output:
        while chunk := input_file.read(1024 * 1024):
            count += len(chunk)
            if count > expected_size:
                raise VerificationError('package is larger than signed size')
            digest.update(chunk)
            output.write(chunk)
    if count != expected_size or digest.hexdigest() != fields['SHA256'].lower():
        raise VerificationError('bootloader package checksum mismatch')


def _extract_package(package: Path, wanted: dict[str, str], destination: Path) -> None:
    """Extract only exact regular files from a verified package, never archive paths."""
    found: set[str] = set()
    with tempfile.TemporaryFile() as errors:
        process = subprocess.Popen(['dpkg-deb', '--fsys-tarfile', str(package)], stdout=subprocess.PIPE, stderr=errors)
        try:
            assert process.stdout is not None
            with tarfile.open(fileobj=process.stdout, mode='r|*') as archive:
                for member in archive:
                    name = member.name.removeprefix('./')
                    if name not in wanted:
                        continue
                    if name in found or not member.isfile() or not 1 <= member.size <= MAX_BOOT_FILE:
                        raise VerificationError('unexpected bootloader package content')
                    source = archive.extractfile(member)
                    if source is None:
                        raise VerificationError('cannot read bootloader file')
                    with source, (destination / wanted[name]).open('xb') as output:
                        shutil.copyfileobj(source, output, 1024 * 1024)
                    found.add(name)
            # dpkg-deb may still write tar padding after tarfile reaches EOF.
            while process.stdout.read(1024 * 1024):
                pass
            process.stdout.close()
            if process.wait(timeout=30) != 0 or found != set(wanted):
                raise VerificationError('bootloader extraction incomplete')
        except BaseException:
            if process.poll() is None:
                process.kill()
            process.wait()
            raise


def _validate_server_ip(server_ip: str) -> None:
    try:
        ip = ipaddress.IPv4Address(server_ip)
        if str(ip) != server_ip or ip.packed[0] == 0 or ip.is_multicast or ip.is_loopback or ip.is_reserved:
            raise ValueError('server must have a usable unicast IPv4 address')
    except (ValueError, TypeError) as error:
        raise VerificationError('invalid server IPv4 address') from error


def _write_menus(tftp: Path, server_ip: str, *, discovery: bool = False) -> None:
    try:
        _validate_server_ip(server_ip)
        script = Path(__file__).resolve().parents[1] / 'cli/render_boot_menus.php'
        command = ['php', '-d', 'display_errors=stderr', str(script), server_ip]
        if discovery:
            command.append('--discovery')
        result = subprocess.run(command, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=30, check=True)
        menus = json.loads(result.stdout)
        if not isinstance(menus, dict) or set(menus) != {'bios', 'uefi'} or not all(isinstance(x, str) and len(x) < 16384 for x in menus.values()):
            raise VerificationError('invalid generated boot menus')
        for directory, name in (('pxelinux.cfg', 'bios'), ('grub', 'uefi')):
            folder = tftp / directory
            folder.mkdir()
            (folder / ('default' if name == 'bios' else 'grub.cfg')).write_text(menus[name], encoding='ascii')
    except (ValueError, TypeError, UnicodeError, json.JSONDecodeError, subprocess.SubprocessError) as error:
        raise VerificationError('boot menus could not be generated') from error


def build_debian_bundle(kernel: Path, initrd: Path, sums: Path, release: Path, index: Path,
                        packages: dict[str, Path], keyring: Path, staging_root: Path, served_root: Path,
                        server_ip: str) -> Path:
    """Publish a complete, validated set without changing any active service.

    The returned directory contains independent ``tftp`` and ``http`` roots and
    retained verification inputs under ``metadata``. All three package names
    must be supplied. No Secure Boot chain or automatic installation is set up.
    """
    try:
        staging, served = _roots(staging_root, served_root)
        _validate_server_ip(server_ip)
        if set(packages) != set(PACKAGE_CONTENTS):
            raise VerificationError('exact bootloader packages required')
        stage = Path(tempfile.mkdtemp(prefix='pxe-bundle-', dir=staging))
        try:
            metadata, tftp, http = stage / 'metadata', stage / 'tftp', stage / 'http'
            for folder in (metadata, tftp, http):
                folder.mkdir()
            for source, name, limit in ((release, 'InRelease', 8 * 1024 * 1024),
                                        (sums, 'SHA256SUMS', 8 * 1024 * 1024), (index, 'Packages.xz', MAX_INDEX)):
                (metadata / name).write_bytes(_bounded_read(Path(source), limit))
            http_images = http / 'netboot/debian'
            tftp_images = tftp / 'netboot/debian'
            http_images.mkdir(parents=True)
            tftp_images.mkdir(parents=True)
            for source, name, limit in ((kernel, 'linux', 128 * 1024 * 1024),
                                        (initrd, 'initrd.gz', 256 * 1024 * 1024)):
                with _open_regular(Path(source)) as src, (http_images / name).open('xb') as dst:
                    count = 0
                    while chunk := src.read(1024 * 1024):
                        count += len(chunk)
                        if count > limit:
                            raise VerificationError('netboot file exceeds limit')
                        dst.write(chunk)
            verify_debian_netboot(http_images / 'linux', http_images / 'initrd.gz', metadata / 'SHA256SUMS',
                                  metadata / 'InRelease', keyring)
            records = _package_records((metadata / 'InRelease').read_bytes(), (metadata / 'Packages.xz').read_bytes())
            (metadata / 'notices').mkdir()
            for name, wanted in PACKAGE_CONTENTS.items():
                package = metadata / (name + '.deb')
                _copy_verified_package(Path(packages[name]), package, records[name])
                selected = {source: 'tftp/' + dest for source, dest in wanted.items()}
                selected['usr/share/doc/' + name + '/copyright'] = 'metadata/notices/' + name + '.copyright'
                _extract_package(package, selected, stage)
            for name in ('linux', 'initrd.gz'):
                os.link(http_images / name, tftp_images / name)
            _write_menus(tftp, server_ip)
            # All entries were created by this call in a private directory.
            for parent, dirs, files in os.walk(stage, followlinks=False):
                for name in dirs:
                    (Path(parent) / name).chmod(0o755)
                for name in files:
                    (Path(parent) / name).chmod(0o644)
            stage.chmod(0o755)
            target = served / ('bundle-' + secrets.token_hex(16))
            target.mkdir(mode=0o700)
            try:
                content = target / 'content'
                os.rename(stage, content)
                target.chmod(0o755)
                return content
            except OSError:
                try:
                    target.rmdir()
                except OSError:
                    pass
                raise
        finally:
            if stage.exists():
                shutil.rmtree(stage)
    except VerificationError:
        raise
    except (OSError, ValueError, tarfile.TarError, subprocess.SubprocessError) as error:
        raise VerificationError('boot bundle could not be prepared') from error
