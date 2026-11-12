# SPDX-License-Identifier: LGPL-3.0-only
"""Build a fixed, data-free initramfs overlay for experimental partman hooks.

The caller must authenticate the original initrd and the BusyBox binary before
appending this overlay. BusyBox must be extracted from the verified Debian
amd64 busybox-static package; this function cannot authenticate it itself.
No target or serial is included. Without later trusted provisioning of a
private target file, both hooks refuse. This is not an installation workflow.
"""
from __future__ import annotations

import gzip
import io
import os
from pathlib import Path
import stat

APP = Path(__file__).resolve().parents[1]
MAX_SCRIPT = 64 * 1024
MAX_BUSYBOX = 8 * 1024 * 1024


def _read_file(path: Path, limit: int) -> bytes:
    if not hasattr(os, 'O_NOFOLLOW'):
        raise ValueError('safe script opening unavailable')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
            raise ValueError('installer script is not a regular file')
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise ValueError('installer file is too large')
    return data


def _read_script(path: Path) -> bytes:
    data = _read_file(path, MAX_SCRIPT)
    if not data.startswith(b'#!/bin/sh\n'):
        raise ValueError('invalid installer script')
    return data


def _entry(name: str, mode: int, data: bytes, inode: int) -> bytes:
    encoded = name.encode('ascii') + b'\0'
    # newc header fields: owner is deliberately root, regardless of builder.
    fields = (inode, mode, 0, 0, 1, 0, len(data), 0, 0, 0, 0, len(encoded), 0)
    header = b'070701' + b''.join(f'{value:08x}'.encode('ascii') for value in fields)
    result = header + encoded
    result += b'\0' * (-len(result) % 4)
    result += data
    return result + b'\0' * (-len(data) % 4)


def build_identity_overlay(verified_busybox: Path) -> bytes:
    """Return a deterministic gzip/newc archive with only fixed hook files."""
    guard = _read_script(APP / 'install/verify_disk_identity.sh')
    hook = _read_script(APP / 'install/partman_identity_gate.sh')
    scope = _read_script(APP / 'install/partman_scope_gate.sh')
    busybox = _read_file(Path(verified_busybox), MAX_BUSYBOX)
    if (len(busybox) < 20 or busybox[:6] != b'\x7fELF\x02\x01'
            or int.from_bytes(busybox[18:20], 'little') != 62):
        raise ValueError('expected an amd64 ELF BusyBox binary')
    directories = ('usr', 'usr/lib', 'usr/lib/pxe-tools', 'usr/lib/partman',
                   'usr/lib/partman/init.d', 'usr/lib/partman/commit.d')
    files = (('usr/lib/pxe-tools/busybox', busybox),
             ('usr/lib/pxe-tools/verify_disk_identity.sh', guard),
             ('usr/lib/partman/init.d/00-pxe-identity', hook),
             ('usr/lib/partman/commit.d/00-pxe-identity', hook),
             ('usr/lib/partman/commit.d/01-pxe-scope', scope))
    parts = [_entry(name, stat.S_IFDIR | 0o755, b'', number)
             for number, name in enumerate(directories, start=1)]
    parts.extend(_entry(name, stat.S_IFREG | 0o500, content, number)
                 for number, (name, content) in enumerate(files, start=len(directories) + 1))
    parts.append(_entry('TRAILER!!!', 0, b'', len(directories) + len(files) + 1))
    output = io.BytesIO()
    with gzip.GzipFile(fileobj=output, mode='wb', filename='', compresslevel=9, mtime=0) as stream:
        stream.write(b''.join(parts))
    return output.getvalue()
