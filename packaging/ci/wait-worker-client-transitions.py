#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Check staged client transition assets after deployment, from one vantage point."""
import importlib.util
from pathlib import Path
import re
import sys
import time

spec = importlib.util.spec_from_file_location('pxe_transition_public_http', Path(__file__).with_name('public-http.py'))
if spec is None or spec.loader is None:
    raise RuntimeError('public HTTPS helper unavailable')
http = importlib.util.module_from_spec(spec)
spec.loader.exec_module(http)
NAMES = {'old-public.gpg', 'new-public.gpg', 'old.InRelease', 'new.InRelease'}


def wait(assets: Path, *, seconds=120, delay=10, clock=time.monotonic, sleep=time.sleep):
    root = assets / 'apt-transition'
    if not root.exists() and not root.is_symlink():
        return
    paths = root / 'sha256'
    if root.is_symlink() or paths.is_symlink() or not paths.is_dir():
        raise ValueError('invalid staged transition assets')
    directories = list(paths.iterdir())
    if not 1 <= len(directories) <= 8:
        raise ValueError('invalid staged transition count')
    pending = {}
    for directory in directories:
        if directory.is_symlink() or not directory.is_dir() or not re.fullmatch(r'[0-9a-f]{64}', directory.name):
            raise ValueError('invalid staged transition identifier')
        if {path.name for path in directory.iterdir()} != NAMES:
            raise ValueError('invalid staged transition files')
        for name in NAMES:
            path = directory / name
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 131072:
                raise ValueError('invalid staged transition object')
            pending[(directory.name, name)] = path.read_bytes()
    deadline = clock() + seconds
    while pending:
        for ident, name in list(pending):
            if clock() >= deadline:
                raise ValueError('public transition objects did not converge before deadline')
            try:
                status, data = http.fetch_transition(ident, name)
                if status == 200 and data == pending[(ident, name)]:
                    del pending[(ident, name)]
            except (OSError, ValueError):
                pass
        if pending:
            remaining = deadline - clock()
            if remaining <= 0:
                raise ValueError('public transition objects did not converge before deadline')
            sleep(min(delay, remaining))


if __name__ == '__main__':
    try:
        wait(Path(sys.argv[1]))
    except (OSError, ValueError) as exc:
        raise SystemExit(str(exc))
