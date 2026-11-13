#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Parse a verified installer index in a short-lived process to release RAM."""
import json
import lzma
from pathlib import Path
import re
import sys


def parse_index(compressed: bytes) -> dict[str, tuple[str, int]]:
    text = lzma.decompress(compressed, memlimit=128 * 1024 * 1024).decode('utf-8')
    if len(text) > 32 * 1024 * 1024:
        raise ValueError('installer package index too large')
    entries = {}
    for paragraph in text.split('\n\n'):
        fields = dict(line.split(': ', 1) for line in paragraph.splitlines() if ': ' in line and not line.startswith(' '))
        name, digest, size = fields.get('Filename', ''), fields.get('SHA256', ''), fields.get('Size', '')
        if not name:
            continue
        if (not re.fullmatch(r'pool/[A-Za-z0-9_+./~%-]+\.udeb', name) or '..' in name.split('/')
                or not re.fullmatch(r'[a-fA-F0-9]{64}', digest) or not size.isdigit() or int(size) > 32 * 1024 * 1024):
            raise ValueError('invalid installer package metadata')
        metadata = digest.lower(), int(size)
        if name in entries and entries[name] != metadata:
            raise ValueError('ambiguous installer package path')
        entries[name] = metadata
    return entries


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('One verified index path is required')
    path = Path(sys.argv[1])
    if path.stat().st_size > 32 * 1024 * 1024:
        raise SystemExit('Compressed index is too large')
    print(json.dumps(parse_index(path.read_bytes()), separators=(',', ':')))
