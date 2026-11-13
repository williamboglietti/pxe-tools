#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Validate the complete SQLite result before changing any BIOS menu."""
import json
import base64
import re
import sys
from pathlib import Path


def validate(path: Path) -> list[str]:
    text = path.read_text(encoding='utf-8')
    rows = json.loads(text) if text.strip() else []
    if not isinstance(rows, list):
        raise ValueError('invalid machine query result')
    result, seen = [], set()
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('invalid machine row')
        values = [row.get(key) for key in ('mac', 'hostname', 'status', 'rescue_mode',
                                            'local_boot_source', 'm_distro', 'm_version_full')]
        values = ['' if value is None else str(value) for value in values]
        mac, hostname, status, rescue, boot, distro, version = values
        if not (re.fullmatch(r'(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}', mac)
                or re.fullmatch(r'(?:[0-9a-fA-F]{2}-){5}[0-9a-fA-F]{2}', mac)):
            raise ValueError('invalid stored MAC address')
        normalized = mac.lower().replace('-', ':')
        if normalized in seen:
            raise ValueError('duplicate stored MAC address')
        seen.add(normalized)
        if len(hostname.encode('utf-8')) > 1024 or any(ord(char) < 32 or ord(char) == 127 for char in hostname):
            raise ValueError('invalid stored hostname')
        for value in (status, boot, distro, version):
            if value and (not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._+-]{0,127}', value) or '..' in value):
                raise ValueError('invalid stored menu field')
        if rescue not in ('', '0', '1'):
            raise ValueError('invalid rescue mode')
        values[1] = base64.b64encode(hostname.encode('utf-8')).decode('ascii')
        result.append('|'.join(values))
    return result


if __name__ == '__main__':
    try:
        for line in validate(Path(sys.argv[1])):
            print(line)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        raise SystemExit(f'PXE menu data refused: {error}')
