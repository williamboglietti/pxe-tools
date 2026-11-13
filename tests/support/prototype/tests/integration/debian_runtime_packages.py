# SPDX-License-Identifier: LGPL-3.0-only
"""Signed Debian packages required only for the disposable runtime guest."""
from __future__ import annotations

import hashlib
import lzma
from pathlib import Path
import re

from test_qemu_dnsmasq_tftp import package_records

RUNTIME_PACKAGES = (
    'python3.13-minimal', 'libpython3.13-minimal', 'libpython3.13-stdlib',
    'php8.4-cli', 'php8.4-common', 'dpkg', 'gpgv', 'libssl3t64', 'libexpat1', 'libbz2-1.0',
    'libdb5.3t64', 'libffi8', 'liblzma5', 'libncursesw6', 'libreadline8t64',
    'libsqlite3-0', 'libtinfo6', 'libuuid1', 'libargon2-1', 'libpcre2-8-0',
    'libsodium23', 'libxml2', 'libedit2', 'libbsd0', 'libmd0', 'libcrypt1',
    'zlib1g', 'libzstd1', 'libgcrypt20', 'libgpg-error0',
)
BACKUP_PACKAGES = ('gpg', 'gpg-agent', 'gpgconf', 'libassuan9', 'libksba8', 'libnpth0t64', 'php8.4-sqlite3')
DISCOVERY_PACKAGES = ('php8.4-sqlite3', 'php8.4-fpm', 'nginx', 'libacl1', 'libapparmor1',
                      'liblz4-1', 'iproute2', 'libbpf1', 'libelf1t64', 'tzdata')


def runtime_records(release: bytes, index: bytes, names: tuple[str, ...] = RUNTIME_PACKAGES) -> dict[str, dict[str, str]]:
    # This validates the compressed index against the previously authenticated release.
    package_records(release, index)
    text = lzma.decompress(index, memlimit=256 * 1024 * 1024).decode('utf-8')
    found = {name: [] for name in names}
    pattern = '|'.join(re.escape(name) for name in names)
    for match in re.finditer(r'(?m)^Package: (' + pattern + r')$', text):
        end = text.find('\n\n', match.start())
        section = text[match.start():end if end >= 0 else len(text)]
        fields = dict(line.split(': ', 1) for line in section.splitlines() if ': ' in line and not line.startswith(' '))
        if fields.get('Architecture') in ('amd64', 'all'):
            found[fields['Package']].append(fields)
    missing = [name for name, records in found.items() if len(records) != 1]
    if missing:
        raise ValueError('runtime package missing or ambiguous: ' + ', '.join(missing))
    return {name: records[0] for name, records in found.items()}


def verify_runtime_packages(release: bytes, index: bytes, directory: Path, names: tuple[str, ...] = RUNTIME_PACKAGES) -> None:
    for name, record in runtime_records(release, index, names).items():
        file = directory / (name + '.deb')
        expected = record.get('SHA256', '')
        if (not re.fullmatch(r'[a-fA-F0-9]{64}', expected) or file.stat().st_size > 32 * 1024 * 1024
                or hashlib.sha256(file.read_bytes()).hexdigest() != expected.lower()):
            raise ValueError('runtime package checksum invalid')
