#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare a locally signed APT candidate with an isolated CI subkey.

Never publishes an artifact or grants distribution rights. The caller supplies
APT_SIGNING_KEY and APT_SIGNING_PASSPHRASE in its environment. The main secret
key must not be present in the supplied key material.
"""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import re
import subprocess
import tempfile


def run(args: list[str], *, data: bytes | None = None) -> bytes:
    result = subprocess.run(args, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError(f'{Path(args[0]).name} failed (exit {result.returncode}); details suppressed')
    return result.stdout


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    parser.add_argument('destination', type=Path)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--signing-fingerprint', required=True)
    parser.add_argument('--valid-days', type=int, default=7)
    parser.add_argument('--previous', type=Path, help='previous signed snapshot to retain')
    parser.add_argument('--public-key-output', type=Path, help='new path for the exported public key only')
    parser.add_argument('--source-commit')
    parser.add_argument('--build-run-id')
    parser.add_argument('--approved-package-sha256')
    parser.add_argument('--ansible-package', type=Path)
    parser.add_argument('--approved-ansible-sha256')
    args = parser.parse_args()
    primary = args.primary_fingerprint.upper()
    signing = args.signing_fingerprint.upper()
    if not all(re.fullmatch(r'(?:[0-9A-F]{40}|[0-9A-F]{64})', x) for x in (primary, signing)):
        raise ValueError('complete primary and subkey fingerprints are required')
    if primary == signing:
        raise ValueError('the signing subkey must differ from the primary')
    armored = os.environ.pop('APT_SIGNING_KEY', None)
    passphrase = os.environ.pop('APT_SIGNING_PASSPHRASE', None)
    if not armored or not passphrase or '\n' in passphrase or '\r' in passphrase or '\0' in passphrase:
        raise ValueError('protected signing subkey and single-line passphrase are required')
    if len(armored) > 131072 or not armored.startswith('-----BEGIN PGP PRIVATE KEY BLOCK-----'):
        raise ValueError('an ASCII-armored secret subkey is required')
    with tempfile.TemporaryDirectory(prefix='pxe-ci-sign-') as temp:
        root = Path(temp)
        home = root / 'gnupg'
        home.mkdir(mode=0o700)
        password_file = root / 'passphrase'
        fd = os.open(password_file, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(passphrase + '\n')
        del passphrase
        common = ['gpg', '--homedir', str(home), '--batch', '--no-tty']
        try:
            run(common + ['--pinentry-mode', 'loopback', '--passphrase-file', str(password_file), '--import'],
                data=armored.encode('ascii'))
            del armored
            def records(*options: str) -> list[list[str]]:
                output = run(common + ['--with-colons', '--with-fingerprint', '--with-subkey-fingerprint', *options])
                return [line.split(':') for line in output.decode('utf-8').splitlines()]

            secret_rows = records('--list-secret-keys', primary)
            main = [x for x in secret_rows if x[0] == 'sec']
            subs = [x for x in secret_rows if x[0] == 'ssb']
            secret_fprs = [x[9].upper() for x in secret_rows if x[0] == 'fpr']
            if (len(main) != 1 or len(main[0]) <= 14 or main[0][14] != '#'
                    or len(subs) != 1 or secret_fprs != [primary, signing]
                    or 's' not in subs[0][11].lower()):
                raise ValueError('CI keyring must contain only the selected signing subkey, without the primary secret')
            public_rows = records('--list-keys', primary)
            if [x[9].upper() for x in public_rows if x[0] == 'fpr'] != [primary, signing]:
                raise ValueError('CI keyring contains an unexpected public key or subkey')
            # Only one primary key may be present; do not accept an unrelated
            # key hidden in the imported secret bundle.
            all_public = records('--list-keys')
            if len([x for x in all_public if x[0] == 'pub']) != 1:
                raise ValueError('CI keyring must contain exactly one primary key')
            builder = Path(__file__).with_name('build-apt-release-candidate.py')
            build_args = ['python3', str(builder), str(args.package), str(args.destination),
                          '--gnupg-home', str(home), '--fingerprint', signing,
                          '--valid-days', str(args.valid_days), '--passphrase-file', str(password_file)]
            if args.previous is not None:
                build_args += ['--previous', str(args.previous)]
            if (args.ansible_package is None) != (args.approved_ansible_sha256 is None):
                raise ValueError('the module and its approved SHA256 must be supplied together')
            if args.ansible_package is not None:
                build_args += ['--ansible-package', str(args.ansible_package),
                               '--approved-ansible-sha256', args.approved_ansible_sha256]
            provenance = (args.source_commit, args.build_run_id, args.approved_package_sha256)
            if any(value is not None for value in provenance):
                if not all(value is not None for value in provenance):
                    raise ValueError('complete build provenance is required')
                build_args += ['--source-commit', args.source_commit, '--build-run-id', args.build_run_id,
                               '--approved-package-sha256', args.approved_package_sha256]
            run(build_args)
            release = args.destination / 'dists/stable/InRelease'
            status = run(common + ['--status-fd', '1', '--verify', str(release)]).decode('utf-8')
            signatures = [line.split() for line in status.splitlines() if line.startswith('[GNUPG:] VALIDSIG ')]
            if len(signatures) != 1 or signatures[0][2] != signing or signatures[0][-1] != primary:
                raise RuntimeError('signed metadata does not match the expected primary and subkey')
            if args.public_key_output is not None:
                output = args.public_key_output
                if not output.is_absolute() or not output.parent.is_dir() or output.parent.is_symlink():
                    raise ValueError('public key output must be an absolute path in an existing directory')
                public = run(common + ['--export', primary])
                if not public:
                    raise ValueError('public key export is empty')
                descriptor = os.open(output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
                with os.fdopen(descriptor, 'wb') as key_file:
                    key_file.write(public)
        finally:
            subprocess.run(['gpgconf', '--homedir', str(home), '--kill', 'gpg-agent'],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
    print('Signed APT candidate verified locally with the selected CI subkey; no publication performed.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc:
        raise SystemExit(str(exc))
