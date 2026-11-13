#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build an unpublished pxe-tools package from the local source.

This command does not publish the result or establish its provenance.
"""
from __future__ import annotations

import argparse
import importlib.util
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path, help='new absolute output path')
    parser.add_argument('--version', required=True, help='Debian version for this candidate')
    parser.add_argument('--maintainer', required=True, help='one maintainer name and email')
    args = parser.parse_args()
    spec = importlib.util.spec_from_file_location('pxe_package_builder', Path(__file__).with_name('build-local-package.py'))
    if spec is None or spec.loader is None:
        raise RuntimeError('native package builder unavailable')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    builder.build(args.destination, args.version, package_name='pxe-tools', maintainer=args.maintainer)
    print('Built unpublished PXE Tools package:', args.destination)


if __name__ == '__main__':
    main()
