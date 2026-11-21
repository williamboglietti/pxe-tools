#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Add explicitly configured, content-addressed public transition data to staged assets."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import tempfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pxe_transition_data', ROOT / 'packaging/native/stage-client-transition-data.py')
if spec is None or spec.loader is None:
    raise RuntimeError('transition data verifier unavailable')
transition = importlib.util.module_from_spec(spec)
spec.loader.exec_module(transition)
NAMES = ('old-public.gpg', 'new-public.gpg', 'old.InRelease', 'new.InRelease')
FINGERPRINT = r'(?:[0-9A-F]{40}|[0-9A-F]{64})'


def identity(primary: str, old: str, new: str, files: dict[str, str]) -> str:
    canonical = json.dumps({'primary': primary, 'old': old, 'new': new, 'files': files},
                           sort_keys=True, separators=(',', ':')).encode('ascii')
    return hashlib.sha256(canonical).hexdigest()


def prepare(assets: Path, config: Path, data_root: Path, primary: str) -> int:
    if assets.is_symlink() or not assets.is_dir() or config.is_symlink() or not config.is_file():
        raise ValueError('assets and transition configuration must be regular, non-symlink paths')
    if not re.fullmatch(FINGERPRINT, primary):
        raise ValueError('a pinned uppercase primary fingerprint is required')
    raw = transition.inspector.read_regular(config)
    def no_duplicates(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('duplicate transition configuration field')
            result[key] = value
        return result
    settings = json.loads(raw, object_pairs_hook=no_duplicates)
    if (not isinstance(settings, dict) or set(settings) != {'version', 'transitions'}
            or type(settings['version']) is not int or settings['version'] != 1
            or not isinstance(settings['transitions'], list)
            or len(settings['transitions']) > 8):
        raise ValueError('invalid transition configuration')
    if not settings['transitions']:
        return 0
    if data_root.is_symlink() or not data_root.is_dir():
        raise ValueError('transition data directory is missing or symlinked')
    target = assets / 'apt-transition'
    headers = assets / '_headers'
    if target.exists() or target.is_symlink() or headers.is_symlink() or not headers.is_file():
        raise ValueError('unsafe or pre-existing transition assets')
    with tempfile.TemporaryDirectory(prefix='.pxe-published-transition-', dir=assets.parent) as temporary:
        staging = Path(temporary) / 'apt-transition'
        staging.mkdir()
        seen = set()
        for entry in settings['transitions']:
            if not isinstance(entry, dict) or set(entry) != {'id', 'primary', 'old', 'new'}:
                raise ValueError('invalid transition entry')
            ident, claimed_primary, old, new = (entry[k] for k in ('id', 'primary', 'old', 'new'))
            if (not all(isinstance(v, str) for v in (ident, claimed_primary, old, new))
                    or not re.fullmatch(r'[0-9a-f]{64}', ident)
                    or claimed_primary != primary or not re.fullmatch(FINGERPRINT, old)
                    or not re.fullmatch(FINGERPRINT, new) or len({primary, old, new}) != 3 or ident in seen):
                raise ValueError('invalid or duplicated pinned transition identity')
            seen.add(ident)
            source = data_root / ident
            if source.is_symlink() or not source.is_dir():
                raise ValueError('transition data missing or symlinked')
            # Reject unlisted files so no private material can be published.
            if {p.name for p in source.iterdir()} != set(NAMES):
                raise ValueError('unexpected transition data files')
            candidate = staging / 'sha256' / ident
            candidate.parent.mkdir(parents=True, exist_ok=True)
            transition.stage(candidate, *(source / name for name in NAMES), primary, old, new)
            files = {name: hashlib.sha256((candidate / name).read_bytes()).hexdigest() for name in NAMES}
            if identity(primary, old, new, files) != ident:
                raise ValueError('transition content does not match its immutable identifier')
            # The generated report is not part of the identifier and may
            # change with tooling, so it must not be published at this path.
            (candidate / 'manifest.json').unlink()
        os.rename(staging, target)
    with headers.open('a', encoding='utf-8') as output:
        output.write('/apt-transition/sha256/*\n  Cache-Control: public, max-age=31536000, immutable\n')
    return len(seen)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--assets', required=True, type=Path)
    parser.add_argument('--config', required=True, type=Path)
    parser.add_argument('--data-root', required=True, type=Path)
    parser.add_argument('--primary-fingerprint', required=True)
    args = parser.parse_args()
    print('Prepared public client transitions:', prepare(args.assets, args.config, args.data_root,
                                                        args.primary_fingerprint))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc:
        raise SystemExit(str(exc))
