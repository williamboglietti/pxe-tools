#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build an experimental local-only APT repository for the native test packages.

This command does not create a signing key, publish files, or establish trust in
its signer. It expects an experimental pxe-tools-local-test .deb and an existing GnuPG home.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
import gzip
import hashlib
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile


def command(*args: str, cwd: Path | None = None, pass_fds: tuple[int, ...] = ()) -> bytes:
    return subprocess.run(args, cwd=cwd, check=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, pass_fds=pass_fds).stdout


def build(package: Path, destination: Path, gnupg_home: Path, fingerprint: str,
          valid_days: int = 7, ansible_package: Path | None = None, *,
          release_candidate: bool = False, passphrase_file: Path | None = None) -> None:
    if release_candidate and ansible_package is not None:
        raise ValueError('the optional production module is not prepared')
    for candidate in (package, ansible_package):
        if candidate is not None and (not candidate.is_absolute() or candidate.is_symlink() or not candidate.is_file()):
            raise ValueError('each package must be an absolute regular file')
    if not destination.is_absolute() or not destination.parent.is_dir() or destination.parent.is_symlink():
        raise ValueError('destination must be an absolute path in an existing directory')
    if destination.exists() or destination.is_symlink():
        raise FileExistsError('destination already exists')
    if not gnupg_home.is_absolute() or gnupg_home.is_symlink() or not gnupg_home.is_dir():
        raise ValueError('an explicit GnuPG home is required')
    if not re.fullmatch(r'(?:[0-9A-Fa-f]{40}|[0-9A-Fa-f]{64})', fingerprint):
        raise ValueError('a complete signing-key fingerprint is required')
    if passphrase_file is not None and (not passphrase_file.is_absolute() or passphrase_file.is_symlink()
                                        or not passphrase_file.is_file()):
        raise ValueError('passphrase file must be an absolute regular file')
    if not 1 <= valid_days <= 31:
        raise ValueError('validity must be between 1 and 31 days')
    for binary in ('apt-ftparchive', 'dpkg-deb', 'dpkg', 'gpg'):
        if not shutil.which(binary):
            raise RuntimeError(f'{binary} is required')

    with tempfile.TemporaryDirectory(prefix='.pxe-apt-', dir=destination.parent) as temporary:
        stage = Path(temporary) / 'repository'
        pool = stage / 'pool/main/p/pxe-tools'
        index = stage / 'dists/stable/main/binary-amd64'
        pool.mkdir(parents=True)
        index.mkdir(parents=True)
        # A content-addressed filename keeps old package URLs immutable when
        # a later repository revision contains a different package.
        versions = {}
        for candidate, expected, architectures in (
                (package, 'pxe-tools' if release_candidate else 'pxe-tools-local-test', ('all', 'amd64')),
                (ansible_package, 'pxe-tools-ansible-local-test', ('all',))):
            if candidate is None:
                continue
            partial = pool / '.package-copy'
            digest = hashlib.sha256()
            descriptor = os.open(candidate, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(descriptor, 'rb') as source, partial.open('xb') as target:
                if not stat.S_ISREG(os.fstat(source.fileno()).st_mode):
                    raise ValueError('package must be a regular file')
                while block := source.read(1024 * 1024):
                    digest.update(block)
                    target.write(block)
            copied = pool / f'pxe-tools-{digest.hexdigest()}.deb'
            if copied.exists():
                raise ValueError('the same package was supplied twice')
            os.rename(partial, copied)

            # Validate the exact copied bytes that will enter the signed index.
            def field(name: str) -> str:
                return command('dpkg-deb', '--field', str(copied), name).decode('utf-8').strip()

            if field('Package') != expected or field('Architecture') not in architectures:
                raise ValueError(f'expected {expected} for {" or ".join(architectures)}')
            version = field('Version')
            if not re.fullmatch(r'[0-9][A-Za-z0-9.+:~\-]{0,100}', version):
                raise ValueError('unsupported package version')
            subprocess.run(['dpkg', '--validate-version', version], check=True, capture_output=True)
            versions[expected] = version
            if expected == 'pxe-tools-ansible-local-test':
                depends = [item.strip() for item in field('Depends').split(',')]
                if 'pxe-tools-local-test (>= 0.0.1~local2)' not in depends or 'ansible-core' not in depends:
                    raise ValueError('the optional package must depend on the compatible base and Ansible Core')
        if ansible_package is not None and subprocess.run(
                ['dpkg', '--compare-versions', versions['pxe-tools-local-test'], 'ge', '0.0.1~local2'],
                check=False).returncode:
            raise ValueError('the optional module requires base version 0.0.1~local2 or later')
        packages = command('apt-ftparchive', 'packages', 'pool', cwd=stage)
        if not packages:
            raise RuntimeError('APT package index is empty')
        (index / 'Packages').write_bytes(packages)
        (index / 'Packages.gz').write_bytes(gzip.compress(packages, mtime=0))
        now = datetime.now(timezone.utc)
        settings = {
            'Origin': 'PXE Tools' if release_candidate else 'PXE Tools local test',
            'Label': 'PXE Tools' if release_candidate else 'PXE Tools local test',
            'Suite': 'stable',
            'Codename': 'stable',
            'Architectures': 'amd64',
            'Components': 'main',
            'Description': 'PXE Tools release candidate repository' if release_candidate else 'PXE Tools experimental local test repository',
        }
        options = [item for name, value in settings.items()
                   for item in ('-o', f'APT::FTPArchive::Release::{name}={value}')]
        release_dir = stage / 'dists/stable'
        release = command('apt-ftparchive', *options, 'release', str(release_dir), cwd=stage)
        if b'\nValid-Until:' in b'\n' + release or b'\nSHA256:' not in b'\n' + release:
            raise RuntimeError('unexpected APT Release metadata')
        # apt-ftparchive does not emit Valid-Until from its Release options.
        expires = format_datetime(now + timedelta(days=valid_days), usegmt=True)
        release = f'Valid-Until: {expires}\n'.encode('ascii') + release
        (release_dir / 'Release').write_bytes(release)
        common = ['gpg', '--homedir', str(gnupg_home), '--batch', '--no-tty',
                  '--local-user', fingerprint.upper() + '!', '--digest-algo', 'SHA256']

        def sign(*options: str) -> None:
            if passphrase_file is None:
                command(*common, *options)
                return
            fd = os.open(passphrase_file, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                info = os.fstat(fd)
                if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o077:
                    raise ValueError('passphrase file must be regular and inaccessible to group and others')
                command(*common, '--pinentry-mode', 'loopback', '--passphrase-fd', str(fd),
                        *options, pass_fds=(fd,))
            finally:
                os.close(fd)

        sign('--output', str(release_dir / 'InRelease'), '--clearsign', str(release_dir / 'Release'))
        sign('--armor', '--output', str(release_dir / 'Release.gpg'), '--detach-sign',
             str(release_dir / 'Release'))
        command('gpg', '--homedir', str(gnupg_home), '--batch', '--verify', str(release_dir / 'InRelease'))
        command('gpg', '--homedir', str(gnupg_home), '--batch', '--verify', str(release_dir / 'Release.gpg'),
                str(release_dir / 'Release'))
        # Reserve the output name exclusively; do not replace an existing tree.
        destination.mkdir(mode=0o700)
        try:
            for item in stage.iterdir():
                os.rename(item, destination / item.name)
            destination.chmod(0o755)
        except BaseException:
            shutil.rmtree(destination)
            raise


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path, help='absolute path to an experimental pxe-tools-local-test .deb')
    parser.add_argument('destination', type=Path, help='new absolute repository directory')
    parser.add_argument('--gnupg-home', type=Path, required=True, help='explicit GnuPG home for signing')
    parser.add_argument('--fingerprint', required=True, help='full fingerprint of the selected signing key')
    parser.add_argument('--passphrase-file', type=Path, help='private file containing the signing passphrase')
    parser.add_argument('--ansible-package', type=Path, help='optional absolute path to the native Ansible test package')
    parser.add_argument('--valid-days', type=int, default=7, help='release validity in days (1–31; default 7)')
    args = parser.parse_args()
    build(args.package, args.destination, args.gnupg_home, args.fingerprint, args.valid_days, args.ansible_package,
          passphrase_file=args.passphrase_file)
    print('Built local signed repository:', args.destination)


if __name__ == '__main__':
    main()
