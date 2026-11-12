#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Stage a signed APT release candidate locally; does not publish or create keys.

The package must be named pxe-tools, reviewed and suitable for release. This
utility does not establish package provenance, grant redistribution rights,
select a production signer or publish the generated snapshot.
"""
from __future__ import annotations

import argparse
import importlib.util
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path, help='absolute path to a reviewed pxe-tools .deb')
    parser.add_argument('destination', type=Path, help='new absolute local snapshot directory')
    parser.add_argument('--gnupg-home', type=Path, required=True, help='explicit GnuPG home')
    parser.add_argument('--fingerprint', required=True, help='full fingerprint of the chosen signing key')
    parser.add_argument('--passphrase-file', type=Path, help='private file containing the signing passphrase')
    parser.add_argument('--valid-days', type=int, default=7, help='snapshot validity, 1 to 31 days')
    args = parser.parse_args()
    path = Path(__file__).with_name('build-local-apt-repository.py')
    spec = importlib.util.spec_from_file_location('pxe_apt_builder', path)
    if spec is None or spec.loader is None:
        raise RuntimeError('APT snapshot builder unavailable')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    builder.build(args.package, args.destination, args.gnupg_home, args.fingerprint,
                  args.valid_days, release_candidate=True, passphrase_file=args.passphrase_file)
    print('Built locally signed APT release candidate:', args.destination)


if __name__ == '__main__':
    main()
