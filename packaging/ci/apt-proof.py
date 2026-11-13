#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Signature and provenance checks shared by APT publication tools."""
import re
import subprocess
from pathlib import Path

def verify_signature(keyring: Path, infile: Path, signing: str, primary: str,
                     *more: Path, output: Path | None = None) -> None:
    args = ['gpgv', '--status-fd', '1', '--keyring', str(keyring)]
    if output is not None:
        args += ['--output', str(output)]
    result = subprocess.run([*args, str(infile), *map(str, more)], capture_output=True)
    if result.returncode:
        raise ValueError('APT signature verification failed')
    rows = [line.split() for line in result.stdout.decode('utf-8').splitlines()
            if line.startswith('[GNUPG:] VALIDSIG ')]
    if len(rows) != 1 or rows[0][2].upper() != signing or rows[0][-1].upper() != primary:
        raise ValueError('Unexpected APT signing subkey or primary')


def validate_keyring(keyring: Path, primary: str, signing: str) -> None:
    if keyring.is_symlink() or not keyring.is_file() or keyring.stat().st_size > 131072:
        raise ValueError('Public keyring must be a small regular file')
    shown = subprocess.run(['gpg', '--batch', '--with-colons', '--show-keys', str(keyring)], capture_output=True)
    packets = subprocess.run(['gpg', '--batch', '--list-packets', str(keyring)], capture_output=True)
    if shown.returncode or packets.returncode:
        raise ValueError('Cannot inspect public keyring')
    rows = [line.split(':') for line in shown.stdout.decode('utf-8').splitlines()]
    fingerprints = [row[9].upper() for row in rows if row[0] == 'fpr']
    if (len([row for row in rows if row[0] == 'pub']) != 1
            or len([row for row in rows if row[0] == 'sub']) != 1
            or fingerprints != [primary, signing]
            or any(row[0] in ('sec', 'ssb') for row in rows)
            or re.search(rb':secret (?:sub )?key packet:', packets.stdout)):
        raise ValueError('Keyring must contain only the expected public primary and signing subkey')


def package_identity(snapshot: dict[str, bytes]) -> tuple[str, str]:
    path = 'dists/stable/main/binary-amd64/Packages'
    paragraphs = snapshot[path].decode('utf-8').strip().split('\n\n')
    if len(paragraphs) != 1:
        raise ValueError('A release must contain exactly one base package')
    fields = {}
    for line in paragraphs[0].splitlines():
        if line and not line[0].isspace() and ': ' in line:
            key, value = line.split(': ', 1)
            if key in fields: raise ValueError('Duplicate package field')
            fields[key] = value
    version, sha = fields.get('Version', ''), fields.get('SHA256', '')
    if (fields.get('Package') != 'pxe-tools' or fields.get('Architecture') != 'amd64'
            or not re.fullmatch(r'[0-9][A-Za-z0-9.+~_-]{0,100}', version)
            or not re.fullmatch(r'[a-f0-9]{64}', sha)):
        raise ValueError('Unexpected package identity')
    return version, sha


def verify_provenance(release: bytes, version: str, sha: str, source: str, build_run: str) -> None:
    if (not re.fullmatch(r'[a-f0-9]{40}', source) or not re.fullmatch(r'[1-9][0-9]{0,19}', build_run)
            or not re.fullmatch(r'[a-f0-9]{64}', sha)):
        raise ValueError('Invalid approved build provenance')
    text = release.decode('utf-8').splitlines()
    for field, value in (('X-PXE-Source-Commit', source), ('X-PXE-Build-Run', build_run),
                         ('X-PXE-Package-SHA256', sha), ('X-PXE-Package-Version', version)):
        matches = [line for line in text if line.startswith(field+':')]
        if matches != [field+': '+value]:
            raise ValueError('Signed APT provenance does not match the approved build')


