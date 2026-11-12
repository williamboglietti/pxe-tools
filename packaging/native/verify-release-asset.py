#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Verify a selected .deb against the published GitHub release's asset digest."""
import argparse
import hashlib
import json
from pathlib import Path
import re


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--release-json', type=Path, required=True)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--asset', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,99}', args.tag):
        raise ValueError('invalid release tag')
    if not re.fullmatch(r'pxe-tools_[A-Za-z0-9.+~_-]+_amd64\.deb', args.asset.name):
        raise ValueError('unexpected package asset name')
    if args.asset.is_symlink() or not args.asset.is_file():
        raise ValueError('package must be a regular file')
    release = json.loads(args.release_json.read_text(encoding='utf-8'))
    if (release.get('tag_name') != args.tag or release.get('draft') is not False
            or release.get('prerelease') is not False or not release.get('published_at')):
        raise ValueError('a published, non-prerelease matching the requested tag is required')
    matches = [item for item in release.get('assets', []) if item.get('name') == args.asset.name]
    if len(matches) != 1 or matches[0].get('state') != 'uploaded':
        raise ValueError('exactly one uploaded package asset is required')
    expected = matches[0].get('digest')
    if not isinstance(expected, str) or not re.fullmatch(r'sha256:[0-9a-f]{64}', expected):
        raise ValueError('GitHub did not provide an SHA-256 digest')
    if args.asset.stat().st_size != matches[0].get('size') or args.asset.stat().st_size > 25 * 1024 * 1024:
        raise ValueError('package size differs from release metadata or exceeds the static asset limit')
    with args.asset.open('rb') as package:
        if hashlib.file_digest(package, 'sha256').hexdigest() != expected.removeprefix('sha256:'):
            raise ValueError('package digest differs from GitHub release metadata')
    print('Published release asset digest and size verified.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError) as exc:
        raise SystemExit(str(exc))
