#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Extract SBAT from Debian's signed GRUB matching the installed GRUB modules."""
from __future__ import annotations

import csv
from pathlib import Path
import struct
import subprocess
import sys

SIGNED_GRUB = Path('/usr/lib/grub/x86_64-efi-signed/grubnetx64.efi.signed')


def main() -> None:
    data = SIGNED_GRUB.read_bytes()
    if len(data) > 16 * 1024 * 1024 or data[:2] != b'MZ':
        raise ValueError('invalid signed GRUB image')
    pe = struct.unpack_from('<I', data, 0x3c)[0]
    if data[pe:pe + 4] != b'PE\0\0':
        raise ValueError('invalid PE header')
    count = struct.unpack_from('<H', data, pe + 6)[0]
    table = pe + 24 + struct.unpack_from('<H', data, pe + 20)[0]
    if count > 96 or table + 40 * count > len(data):
        raise ValueError('invalid PE section table')
    matches = []
    for i in range(count):
        pos = table + 40 * i
        if data[pos:pos + 8].rstrip(b'\0') != b'.sbat':
            continue
        size, offset = struct.unpack_from('<II', data, pos + 16)
        if not size or size > 65536 or offset + size > len(data):
            raise ValueError('invalid SBAT section')
        matches.append(data[offset:offset + size].rstrip(b'\0').decode('utf-8'))
    if len(matches) != 1:
        raise ValueError('Debian signed GRUB must contain one SBAT section')
    rows = list(csv.reader(matches[0].splitlines()))
    version = subprocess.check_output(
        ['dpkg-query', '-W', '-f=${Version}', 'grub-efi-amd64-bin'], text=True).strip()
    if not any(len(row) >= 5 and row[0] == 'grub.debian' and row[4] == version for row in rows):
        raise ValueError('signed GRUB SBAT does not match the installed GRUB modules')
    sys.stdout.write(matches[0].rstrip('\n') + '\n')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, UnicodeError, struct.error, subprocess.CalledProcessError) as exc:
        raise SystemExit(f'Cannot obtain matching Debian GRUB SBAT metadata: {exc}')
