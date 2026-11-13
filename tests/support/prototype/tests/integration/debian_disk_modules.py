# SPDX-License-Identifier: LGPL-3.0-only
"""Resolve a disk-driver udeb from the authenticated Debian installer index."""
from __future__ import annotations

import gzip
import hashlib
import lzma
from pathlib import Path
import re
import subprocess

from artifacts.debian_netboot import _clearsigned_payload


def disk_module_record(release: bytes, index: bytes, initrd: Path) -> dict[str, str]:
    expected = []
    active = False
    for line in _clearsigned_payload(release):
        if line == 'SHA256:':
            active = True
            continue
        if active and not line.startswith(' '):
            active = False
        if active:
            match = re.fullmatch(r'\s+([a-fA-F0-9]{64})\s+(\d+)\s+(\S+)', line)
            if match and match.group(3) == 'main/debian-installer/binary-amd64/Packages.xz':
                expected.append((match.group(1).lower(), int(match.group(2))))
    if expected != [(hashlib.sha256(index).hexdigest(), len(index))] or len(index) > 16 * 1024 * 1024:
        raise ValueError('installer index does not match signed release')
    raw = gzip.decompress(initrd.read_bytes())
    names = subprocess.run(['cpio', '-it'], input=raw, stdout=subprocess.PIPE,
                           stderr=subprocess.DEVNULL, check=True).stdout.decode().splitlines()
    versions = {match.group(1) for name in names
                if (match := re.fullmatch(r'(?:\./)?usr/lib/modules/([^/]+)/modules\.builtin', name))}
    if len(versions) != 1:
        raise ValueError('installer kernel version is unavailable or ambiguous')
    name = 'scsi-modules-' + next(iter(versions)) + '-di'
    text = lzma.decompress(index, memlimit=128 * 1024 * 1024).decode('utf-8')
    if len(text) > 32 * 1024 * 1024:
        raise ValueError('installer index is too large')
    entries = []
    for paragraph in text.split('\n\n'):
        if paragraph.startswith('Package: ' + name + '\n'):
            fields = dict(line.split(': ', 1) for line in paragraph.splitlines() if ': ' in line)
            if fields.get('Architecture') == 'amd64':
                entries.append(fields)
    if len(entries) != 1:
        raise ValueError('matching installer disk modules missing or ambiguous')
    record = entries[0]
    if (not re.fullmatch(r'pool/[A-Za-z0-9_+./~%-]+\.udeb', record.get('Filename', ''))
            or '..' in record['Filename'].split('/')
            or not re.fullmatch(r'[a-fA-F0-9]{64}', record.get('SHA256', ''))
            or not record.get('Size', '').isdigit() or int(record['Size']) > 16 * 1024 * 1024):
        raise ValueError('invalid installer module metadata')
    return record


def verify_disk_module(release: bytes, index: bytes, initrd: Path, package: Path) -> None:
    record = disk_module_record(release, index, initrd)
    if package.stat().st_size != int(record['Size']):
        raise ValueError('installer module package size mismatch')
    with package.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != record['SHA256'].lower():
            raise ValueError('installer module package checksum mismatch')
