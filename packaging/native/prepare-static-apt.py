#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Validate and stage a signed APT snapshot for a static Worker; never deploy it."""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
import gzip
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

LIMIT = 25 * 1024 * 1024
HEX = r'[0-9a-f]{64}'


def run(*args: str) -> bytes:
    result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise ValueError(f'{Path(args[0]).name} failed while validating the snapshot')
    return result.stdout


def digest(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def allowed(relative: str) -> bool:
    fixed = {'dists/stable/InRelease', 'dists/stable/Release', 'dists/stable/Release.gpg',
             'dists/stable/main/binary-amd64/Packages', 'dists/stable/main/binary-amd64/Packages.gz'}
    return (relative in fixed
            or re.fullmatch(rf'dists/stable/main/binary-amd64/by-hash/SHA256/{HEX}', relative) is not None
            or re.fullmatch(rf'pool/main/p/pxe-tools/pxe-tools-{HEX}\.deb', relative) is not None)


def validate(root: Path, keyring: Path, primary: str, signing: str) -> None:
    if root.is_symlink() or not root.is_dir() or keyring.is_symlink() or not keyring.is_file():
        raise ValueError('snapshot and trusted public keyring must exist and not be symlinks')
    if not all(re.fullmatch(r'(?:[0-9A-F]{40}|[0-9A-F]{64})', value) for value in (primary, signing)):
        raise ValueError('full uppercase primary and signing fingerprints are required')
    files = set()
    for directory, dirs, names in os.walk(root, followlinks=False):
        for name in dirs + names:
            path = Path(directory) / name
            if path.is_symlink():
                raise ValueError('snapshot must not contain symlinks')
        for name in names:
            path = Path(directory) / name
            relative = path.relative_to(root).as_posix()
            if not path.is_file() or not allowed(relative) or path.stat().st_size > LIMIT:
                raise ValueError(f'unexpected or oversized asset: {relative}')
            files.add(relative)
            match = re.search(rf'({HEX})(?:\.deb)?$', relative)
            if match and digest(path) != match.group(1):
                raise ValueError(f'content-addressed object is corrupt: {relative}')

    release = root / 'dists/stable/Release'
    signed = root / 'dists/stable/InRelease'
    detached = root / 'dists/stable/Release.gpg'
    if not all(path.is_file() for path in (release, signed, detached)):
        raise ValueError('signed release metadata is incomplete')

    def verify(*arguments: str) -> None:
        status = run('gpgv', '--keyring', str(keyring), '--status-fd', '1', *arguments).decode()
        signatures = [line.split() for line in status.splitlines() if line.startswith('[GNUPG:] VALIDSIG ')]
        if len(signatures) != 1 or signatures[0][2] != signing or signatures[0][-1] != primary:
            raise ValueError('unexpected release signer')

    with tempfile.TemporaryDirectory(prefix='pxe-release-verify-') as temporary:
        clear = Path(temporary) / 'release'
        verify('--output', str(clear), str(signed))
        verify(str(detached), str(release))
        if clear.read_bytes() != release.read_bytes():
            raise ValueError('InRelease and Release differ')

    text = release.read_text(encoding='utf-8')
    for line in ('Suite: stable', 'Codename: stable', 'Acquire-By-Hash: yes'):
        if line not in text.splitlines():
            raise ValueError('release metadata is not suitable for static publication')
    expires = re.findall(r'(?m)^Valid-Until: (.+)$', text)
    if len(expires) != 1 or parsedate_to_datetime(expires[0]) <= datetime.now(timezone.utc) + timedelta(hours=1):
        raise ValueError('release metadata expires too soon')
    section = re.search(r'(?ms)^SHA256:\s*\n((?: [^\n]+\n)+)', text)
    if not section:
        raise ValueError('release metadata lacks SHA256 checksums')
    entries = {}
    for line in section.group(1).splitlines():
        checksum, size, name = line.split()
        if name in entries or not re.fullmatch(HEX, checksum):
            raise ValueError('invalid release checksums')
        entries[name] = checksum, int(size)
    index_dir = root / 'dists/stable/main/binary-amd64'
    for name in ('Packages', 'Packages.gz'):
        index = index_dir / name
        checksum, size = entries.get(f'main/binary-amd64/{name}', ('', -1))
        by_hash = index_dir / 'by-hash/SHA256' / checksum
        if not index.is_file() or not by_hash.is_file() or index.stat().st_size != size:
            raise ValueError('signed index or by-hash object is missing')
        if digest(index) != checksum or digest(by_hash) != checksum:
            raise ValueError('signed index or by-hash object is corrupt')
    with gzip.open(index_dir / 'Packages.gz', 'rb') as compressed:
        if compressed.read(LIMIT + 1) != (index_dir / 'Packages').read_bytes():
            raise ValueError('compressed index differs from its uncompressed form')
    paragraphs = (index_dir / 'Packages').read_text(encoding='utf-8').strip().split('\n\n')
    if not paragraphs or not paragraphs[0]:
        raise ValueError('the signed package index is empty')
    for paragraph in paragraphs:
        fields = dict(line.split(': ', 1) for line in paragraph.splitlines() if ': ' in line)
        name, checksum = fields.get('Filename', ''), fields.get('SHA256', '')
        if not re.fullmatch(rf'pool/main/p/pxe-tools/pxe-tools-{HEX}\.deb', name) or not re.fullmatch(HEX, checksum):
            raise ValueError('package index has an unexpected path or checksum')
        package = root / name
        if name not in files or digest(package) != checksum or package.stat().st_size != int(fields.get('Size', '-1')):
            raise ValueError('package is absent or differs from its signed index')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--keyring', type=Path, required=True)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--signing-fingerprint', required=True)
    args = parser.parse_args()
    snapshot, output, keyring = (path.absolute() for path in (args.snapshot, args.output, args.keyring))
    primary, signing = args.primary_fingerprint.upper(), args.signing_fingerprint.upper()
    if (output.exists() or output.is_symlink() or not output.parent.is_dir() or output.parent.is_symlink()
            or output.resolve().is_relative_to(snapshot.resolve())):
        raise ValueError('output must be a new path in an existing directory')
    validate(snapshot, keyring, primary, signing)
    with tempfile.TemporaryDirectory(prefix='.pxe-static-', dir=output.parent) as temporary:
        candidate = Path(temporary) / 'assets'
        shutil.copytree(snapshot, candidate, symlinks=True)
        validate(candidate, keyring, primary, signing)
        # Export only the public key. Never copy the supplied keyring verbatim.
        home = Path(temporary) / 'gpg'
        home.mkdir(mode=0o700)
        public = run('gpg', '--homedir', str(home), '--batch', '--no-default-keyring', '--keyring', str(keyring),
                     '--export', primary)
        if not public or len(public) > LIMIT:
            raise ValueError('cannot export the expected public key')
        (candidate / 'pxe-tools-archive-keyring.gpg').write_bytes(public)
        os.rename(candidate, output)
    print('Validated static APT assets staged locally; no deployment performed.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, UnicodeError, EOFError) as exc:
        raise SystemExit(str(exc))
