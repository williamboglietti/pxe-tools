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
          release_candidate: bool = False, passphrase_file: Path | None = None,
          previous: Path | None = None, source_commit: str | None = None,
          build_run_id: str | None = None, approved_package_sha256: str | None = None) -> None:
    if release_candidate and ansible_package is not None:
        raise ValueError('the optional production module is not prepared')
    provenance = (source_commit, build_run_id, approved_package_sha256)
    if any(value is not None for value in provenance):
        if (not release_candidate or not all(value is not None for value in provenance)
                or not re.fullmatch(r'[a-f0-9]{40}', source_commit or '')
                or not re.fullmatch(r'[1-9][0-9]{0,19}', build_run_id or '')
                or not re.fullmatch(r'[a-f0-9]{64}', approved_package_sha256 or '')):
            raise ValueError('complete approved build provenance is required')
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
    if previous is not None and (not previous.is_absolute() or previous.is_symlink() or not previous.is_dir()):
        raise ValueError('previous repository must be an absolute directory')
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
            if expected == 'pxe-tools' and approved_package_sha256 is not None and digest.hexdigest() != approved_package_sha256:
                raise ValueError('package differs from approved build provenance')
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
        # APT chooses the strongest advertised digest (typically SHA512).
        # Serving only SHA256 silently falls back to the mutable path.
        for algorithm in ('sha256', 'sha512'):
            by_hash = index / 'by-hash' / algorithm.upper()
            by_hash.mkdir(parents=True)
            for name in ('Packages', 'Packages.gz'):
                data = (index / name).read_bytes()
                (by_hash / hashlib.new(algorithm, data).hexdigest()).write_bytes(data)
        now = datetime.now(timezone.utc)
        settings = {
            'Origin': 'PXE Tools' if release_candidate else 'PXE Tools local test',
            'Label': 'PXE Tools' if release_candidate else 'PXE Tools local test',
            'Suite': 'stable',
            'Codename': 'stable',
            'Architectures': 'amd64',
            'Components': 'main',
            'Acquire-By-Hash': 'yes',
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
        provenance_fields = ''
        if source_commit is not None:
            provenance_fields = (f'X-PXE-Source-Commit: {source_commit}\n'
                                 f'X-PXE-Build-Run: {build_run_id}\n'
                                 f'X-PXE-Package-SHA256: {approved_package_sha256}\n'
                                 f'X-PXE-Package-Version: {versions["pxe-tools"]}\n')
        release = (f'Valid-Until: {expires}\n' + provenance_fields).encode('ascii') + release
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

        if previous is not None:
            def previous_path(relative: str) -> Path:
                result = previous
                for part in Path(relative).parts:
                    result = result / part
                    if result.is_symlink():
                        raise ValueError('previous snapshot contains a symlink')
                return result

            old_release = previous_path('dists/stable/InRelease')
            if old_release.is_symlink() or not old_release.is_file():
                raise ValueError('previous signed release is missing')
            status = command('gpg', '--homedir', str(gnupg_home), '--batch', '--status-fd', '1',
                             '--verify', str(old_release)).decode('utf-8')
            signatures = [line.split() for line in status.splitlines() if line.startswith('[GNUPG:] VALIDSIG ')]
            if len(signatures) != 1 or signatures[0][2].upper() != fingerprint.upper():
                raise ValueError('previous snapshot was not signed by the selected key')
            cleartext = command('gpg', '--homedir', str(gnupg_home), '--batch', '--decrypt',
                                str(old_release)).decode('utf-8')
            if '\nAcquire-By-Hash: yes\n' not in '\n' + cleartext:
                raise ValueError('previous release does not support by-hash')
            sha_section = re.search(r'(?ms)^SHA256:\s*\n((?: [^\n]+\n)+)', cleartext)
            if not sha_section:
                raise ValueError('previous release lacks SHA256 checksums')
            checksums = {}
            for line in sha_section.group(1).splitlines():
                digest, size, name = line.split()
                checksums[name] = (digest, int(size))
            old_index_dir = 'dists/stable/main/binary-amd64'
            for name in ('Packages', 'Packages.gz'):
                rel = f'main/binary-amd64/{name}'
                if rel not in checksums:
                    raise ValueError('previous release does not describe its package indexes')
                digest, size = checksums[rel]
                if not re.fullmatch(r'[0-9a-f]{64}', digest):
                    raise ValueError('invalid previous index checksum')
                canonical = previous_path(f'{old_index_dir}/{name}')
                immutable = previous_path(f'{old_index_dir}/by-hash/SHA256/{digest}')
                if not canonical.is_file() or not immutable.is_file():
                    raise ValueError('previous signed index or its immutable copy is missing')
                data = canonical.read_bytes()
                if len(data) != size or hashlib.sha256(data).hexdigest() != digest or immutable.read_bytes() != data:
                    raise ValueError('previous signed index is inconsistent')
            old_packages = previous_path(f'{old_index_dir}/Packages').read_text(encoding='utf-8')
            for paragraph in old_packages.strip().split('\n\n'):
                fields = dict(line.split(': ', 1) for line in paragraph.splitlines() if ': ' in line)
                filename, digest = fields.get('Filename', ''), fields.get('SHA256', '')
                if not re.fullmatch(r'pool/main/p/pxe-tools/pxe-tools-[0-9a-f]{64}\.deb', filename):
                    raise ValueError('previous index refers to an unexpected package path')
                old_package = previous_path(filename)
                if not old_package.is_file() or not re.fullmatch(r'[0-9a-f]{64}', digest):
                    raise ValueError('previous package is missing or has no checksum')
                with old_package.open('rb') as old:
                    if hashlib.file_digest(old, 'sha256').hexdigest() != digest:
                        raise ValueError('previous package does not match its signed index')
            # Only immutable, content-addressed objects are carried forward. Old
            # mutable Release and Packages paths must never overwrite the new ones.
            for relative, pattern, algorithm, optional in (
                    ('pool/main/p/pxe-tools', r'pxe-tools-([0-9a-f]{64})\.deb', 'sha256', False),
                    ('dists/stable/main/binary-amd64/by-hash/SHA256', r'([0-9a-f]{64})', 'sha256', False),
                    ('dists/stable/main/binary-amd64/by-hash/SHA512', r'([0-9a-f]{128})', 'sha512', True)):
                source = previous_path(relative)
                # Older approved snapshots contain SHA256 only.
                if optional and not source.exists() and not source.is_symlink():
                    continue
                if source.is_symlink() or not source.is_dir():
                    raise ValueError('previous snapshot lacks immutable objects')
                target = stage / relative
                target.mkdir(parents=True, exist_ok=True)
                for item in source.iterdir():
                    match = re.fullmatch(pattern, item.name)
                    if not match or item.is_symlink() or not item.is_file():
                        raise ValueError('invalid previous immutable object')
                    with item.open('rb') as old:
                        digest = hashlib.file_digest(old, algorithm).hexdigest()
                    if digest != match.group(1):
                        raise ValueError('previous immutable object has an invalid digest')
                    if relative == 'pool/main/p/pxe-tools' and release_candidate:
                        old_name = command('dpkg-deb', '--field', str(item), 'Package').decode().strip()
                        old_version = command('dpkg-deb', '--field', str(item), 'Version').decode().strip()
                        if old_name != 'pxe-tools':
                            raise ValueError('unexpected package in previous release pool')
                        if (old_version == versions['pxe-tools']
                                and digest != hashlib.sha256(package.read_bytes()).hexdigest()):
                            raise ValueError('Debian version already has different package bytes')
                    dest = target / item.name
                    if dest.exists():
                        if dest.read_bytes() != item.read_bytes():
                            raise ValueError('immutable object collision')
                    else:
                        shutil.copyfile(item, dest)
        # Cover retained objects as well as the current APT index. The manifest
        # is embedded in signed Release and calculated from canonical paths.
        if release_candidate:
            rows = []
            for path in sorted(stage.rglob('*')):
                if path.is_file() and path.parent != release_dir:
                    with path.open('rb') as stream:
                        checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
                    rows.append(f'{checksum} {path.stat().st_size} {path.relative_to(stage).as_posix()}\n')
            manifest_hash = hashlib.sha256(''.join(rows).encode('utf-8')).hexdigest()
            release = f'X-PXE-Snapshot-SHA256: {manifest_hash}\n'.encode('ascii') + release
            (release_dir / 'Release').write_bytes(release)
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
