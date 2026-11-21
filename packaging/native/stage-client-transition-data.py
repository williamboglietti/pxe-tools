#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare locally verified public transition data without deploying anything."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tempfile

helper_path = Path(__file__).resolve().parent / 'check-apt-key-transition.py'
spec = importlib.util.spec_from_file_location('transition_data_inspector', helper_path)
if spec is None or spec.loader is None:
    raise RuntimeError('transition inspector unavailable')
inspector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inspector)


def stage(output: Path, old_cert: Path, new_cert: Path, old_index: Path, new_index: Path,
          primary: str, old_signer: str, new_signer: str) -> None:
    output = output.absolute()
    if (output.exists() or output.is_symlink() or not output.parent.is_dir()
            or output.parent.is_symlink()):
        raise ValueError('output must be a new directory in an existing non-symlink parent')
    inputs = {'old-public.gpg': old_cert, 'new-public.gpg': new_cert,
              'old.InRelease': old_index, 'new.InRelease': new_index}
    # Copy bounded regular files first, then validate and distribute those exact
    # bytes rather than trusting paths which can be replaced during inspection.
    with tempfile.TemporaryDirectory(prefix='.pxe-transition-data-', dir=output.parent) as temporary:
        candidate = Path(temporary) / 'data'
        candidate.mkdir(mode=0o700)
        for name, path in inputs.items():
            (candidate / name).write_bytes(inspector.read_regular(path))
        report = inspector.inspect(candidate / 'old-public.gpg', candidate / 'new-public.gpg',
                                   primary, old_signer, new_signer)
        primary, old_signer, new_signer = (value.upper() for value in (primary, old_signer, new_signer))
        proof = inspector.proof
        proof.verify_signature(candidate / 'old-public.gpg', candidate / 'old.InRelease', old_signer, primary)
        proof.verify_signature(candidate / 'new-public.gpg', candidate / 'old.InRelease', old_signer, primary)
        proof.verify_signature(candidate / 'new-public.gpg', candidate / 'new.InRelease', new_signer, primary)
        report['files'] = {name: hashlib.sha256((candidate / name).read_bytes()).hexdigest()
                           for name in sorted(inputs)}
        report['warning'] = 'Fingerprints and source must be verified independently; no freshness guarantee.'
        (candidate / 'manifest.json').write_text(json.dumps(report, indent=2, sort_keys=True) + '\n', encoding='utf-8')
        os.rename(candidate, output)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    parser.add_argument('--old-certificate', type=Path, required=True)
    parser.add_argument('--new-certificate', type=Path, required=True)
    parser.add_argument('--old-inrelease', type=Path, required=True)
    parser.add_argument('--new-inrelease', type=Path, required=True)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--old-signing-fingerprint', required=True)
    parser.add_argument('--new-signing-fingerprint', required=True)
    args = parser.parse_args()
    stage(args.output, args.old_certificate, args.new_certificate, args.old_inrelease, args.new_inrelease,
          args.primary_fingerprint, args.old_signing_fingerprint, args.new_signing_fingerprint)
    print('Public transition data prepared locally; no distribution or deployment performed.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc:
        raise SystemExit(str(exc))
