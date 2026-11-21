#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Inspect two public APT certificates; never install a key or change APT state."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import tempfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pxe_apt_proof', ROOT / 'packaging/ci/apt-proof.py')
if spec is None or spec.loader is None:
    raise RuntimeError('APT public certificate verifier unavailable')
proof = importlib.util.module_from_spec(spec)
spec.loader.exec_module(proof)
LIMIT = 131072


def read_regular(path: Path) -> bytes:
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > LIMIT:
            raise ValueError('each certificate must be a small regular file')
        data = stream.read(LIMIT + 1)
        if not data or len(data) > LIMIT:
            raise ValueError('certificate is empty or too large')
        return data


def inspect(old_path: Path, new_path: Path, primary: str, old: str, new: str) -> dict:
    fingerprints = tuple(value.upper() for value in (primary, old, new))
    if (not all(re.fullmatch(r'(?:[0-9A-F]{40}|[0-9A-F]{64})', value) for value in fingerprints)
            or len(set(fingerprints)) != 3):
        raise ValueError('three distinct complete fingerprints are required')
    primary, old, new = fingerprints
    old_bytes, new_bytes = read_regular(old_path), read_regular(new_path)
    # Validate copies of the exact bytes that will be hashed; neither input is
    # imported into the user's keyring or replaced by this read-only tool.
    with tempfile.TemporaryDirectory(prefix='pxe-apt-key-inspection-', dir='/tmp') as tmp:
        root = Path(tmp)
        home = root / 'gnupg'
        home.mkdir(mode=0o700)
        old_copy, new_copy = root / 'old.gpg', root / 'new.gpg'
        old_copy.write_bytes(old_bytes)
        new_copy.write_bytes(new_bytes)
        previous_home = os.environ.get('GNUPGHOME')
        os.environ['GNUPGHOME'] = str(home)
        try:
            proof.validate_keyring(old_copy, primary, old)
            proof.validate_keyring(new_copy, primary, new, old)
        finally:
            if previous_home is None:
                os.environ.pop('GNUPGHOME', None)
            else:
                os.environ['GNUPGHOME'] = previous_home
    return {'primary_fingerprint': primary, 'old_signing_fingerprint': old,
            'new_signing_fingerprint': new,
            'old_certificate_sha256': hashlib.sha256(old_bytes).hexdigest(),
            'new_certificate_sha256': hashlib.sha256(new_bytes).hexdigest(),
            'independent_fingerprint_verification_required': True,
            'client_keyring_modified': False}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--old-certificate', required=True, type=Path)
    parser.add_argument('--new-certificate', required=True, type=Path)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--old-signing-fingerprint', required=True)
    parser.add_argument('--new-signing-fingerprint', required=True)
    args = parser.parse_args()
    print(json.dumps(inspect(args.old_certificate, args.new_certificate, args.primary_fingerprint,
                             args.old_signing_fingerprint, args.new_signing_fingerprint), indent=2))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc:
        raise SystemExit(str(exc))
