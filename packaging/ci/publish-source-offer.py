#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Publish only the pinned corresponding-source offer, never an APT index."""
import argparse
import importlib.util
import json
from pathlib import Path

here = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('pxe_r2', here/'publish-r2-snapshot.py')
if spec is None or spec.loader is None: raise RuntimeError('R2 publisher unavailable')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
SOURCE_RELEASE = 'sources-2026-11-12'


def verify_release(release: dict, source: Path, manifest: Path) -> dict[str, bytes]:
    files = publisher.validate_sources(source, manifest)
    if (release.get('tag_name') != SOURCE_RELEASE or release.get('draft') is not False
            or not release.get('published_at')):
        raise ValueError('Expected published source-only release is unavailable')
    items = release.get('assets', [])
    if not isinstance(items, list): raise ValueError('Unexpected release metadata')
    for name, data in files.items():
        matches = [item for item in items if item.get('name') == name]
        if (len(matches) != 1 or matches[0].get('state') != 'uploaded'
                or matches[0].get('size') != len(data)
                or matches[0].get('digest') != 'sha256:'+publisher.digest(data)):
            raise ValueError('Source release asset does not match its pinned digest')
    return files


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source_dir', type=Path)
    parser.add_argument('release_json', type=Path)
    parser.add_argument('--endpoint', required=True)
    parser.add_argument('--verify-only', action='store_true')
    args = parser.parse_args()
    files = verify_release(json.loads(args.release_json.read_text()), args.source_dir,
                           here/'static-helpers-sources.json')
    if args.verify_only:
        print('Pinned source offer and release asset digests verified; nothing published.')
        return
    publisher.publish_sources(files, publisher.AwsR2(args.endpoint))
    print('Pinned corresponding-source offer published and reread; no APT metadata modified.')


if __name__ == '__main__':
    try: main()
    except (OSError, ValueError, TypeError, KeyError, RuntimeError, UnicodeError) as exc: raise SystemExit(str(exc))
