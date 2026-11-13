#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Verify the public, pinned corresponding-source release and downloaded files."""
import hashlib
import json
from pathlib import Path
import sys

root, metadata = Path(sys.argv[1]), json.loads(Path(sys.argv[2]).read_text())
expected = json.loads(Path('packaging/ci/static-helpers-sources.json').read_text())
if metadata.get('draft') or metadata.get('tag_name') != 'sources-2026-11-12':
    raise SystemExit('source release is not publicly published')
assets = {item['name']: item for item in metadata.get('assets', [])}
if set(assets) != set(expected):
    raise SystemExit('source release does not have exactly the approved assets')
for name, checksum in expected.items():
    file = root / name
    if file.is_symlink() or not file.is_file() or assets[name].get('digest') != 'sha256:' + checksum:
        raise SystemExit('source release asset missing or changed')
    with file.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != checksum:
            raise SystemExit('downloaded source offer digest mismatch')
print('The five public corresponding-source assets match the pinned digests.')
