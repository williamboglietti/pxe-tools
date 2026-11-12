#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build a local public-keyring package; does not create keys or publish."""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile


def run(*args: str) -> bytes:
    return subprocess.run(args, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('public_keyring', type=Path, help='absolute path to binary OpenPGP public keyring')
    parser.add_argument('destination', type=Path, help='new absolute .deb file path')
    parser.add_argument('--fingerprint', required=True, help='complete primary key fingerprint')
    parser.add_argument('--version', required=True, help='Debian package version')
    parser.add_argument('--maintainer', required=True, help='Debian maintainer identity')
    args = parser.parse_args()
    keyring, destination = args.public_keyring, args.destination
    if not keyring.is_absolute() or keyring.is_symlink() or not keyring.is_file():
        raise ValueError('public keyring must be an absolute regular file')
    if not destination.is_absolute() or not destination.parent.is_dir() or destination.parent.is_symlink():
        raise ValueError('destination must be in an existing absolute directory')
    if destination.exists() or destination.is_symlink():
        raise FileExistsError('destination already exists')
    if not re.fullmatch(r'(?:[0-9A-Fa-f]{40}|[0-9A-Fa-f]{64})', args.fingerprint):
        raise ValueError('a complete primary fingerprint is required')
    if not re.fullmatch(r'[^\x00-\x1f\x7f]{1,200}', args.maintainer):
        raise ValueError('invalid maintainer')
    subprocess.run(['dpkg', '--validate-version', args.version], check=True, capture_output=True)
    for binary in ('gpg', 'dpkg-deb'):
        if not shutil.which(binary):
            raise RuntimeError(f'{binary} is required')
    with tempfile.TemporaryDirectory(prefix='.pxe-keyring-', dir=destination.parent) as tmp:
        root = Path(tmp)
        home = root / 'gnupg'
        home.mkdir(mode=0o700)
        fd = os.open(keyring, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb') as source:
            if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):
                raise ValueError('public keyring must be regular')
            data = source.read(1024 * 1024 + 1)
        if not data or len(data) > 1024 * 1024 or data.startswith(b'-----BEGIN PGP'):
            raise ValueError('a binary public keyring up to 1 MiB is required')
        checked = root / 'public.gpg'
        checked.write_bytes(data)
        common = ['gpg', '--homedir', str(home), '--batch', '--no-tty']
        packets = run(*common, '--list-packets', str(checked)).decode('utf-8', 'replace')
        if re.search(r'^:secret (?:sub )?key packet:', packets, re.MULTILINE):
            raise ValueError('secret key material is prohibited')
        info = run(*common, '--with-colons', '--show-keys', str(checked)).decode('utf-8', 'replace')
        fingerprints = []
        primary = False
        for line in info.splitlines():
            fields = line.split(':')
            if fields[0] in ('sec', 'ssb'):
                raise ValueError('secret key material is prohibited')
            if fields[0] == 'pub':
                primary = True
            elif primary and fields[0] == 'fpr':
                fingerprints.append(fields[9].upper())
                primary = False
        if fingerprints != [args.fingerprint.upper()]:
            raise ValueError('keyring must contain exactly the selected primary public key')
        package = root / 'package'
        (package / 'DEBIAN').mkdir(parents=True)
        keydir = package / 'usr/share/keyrings'
        keydir.mkdir(parents=True)
        (keydir / 'pxe-tools-archive-keyring.gpg').write_bytes(data)
        (package / 'DEBIAN/control').write_text(
            f'Package: pxe-tools-archive-keyring\nVersion: {args.version}\nSection: misc\n'
            f'Priority: optional\nArchitecture: all\nMaintainer: {args.maintainer}\n'
            'Description: Public signing key for the PXE Tools APT repository\n'
            ' Public OpenPGP keyring for authentication of PXE Tools APT metadata.\n', encoding='utf-8')
        candidate = root / 'keyring.deb'
        run('dpkg-deb', '-Zxz', '--build', '--root-owner-group', str(package), str(candidate))
        os.link(candidate, destination, follow_symlinks=False)
    print('Built local public keyring candidate:', destination)


if __name__ == '__main__':
    main()
