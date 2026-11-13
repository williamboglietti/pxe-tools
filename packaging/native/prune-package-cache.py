#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Remove only recognised interpreter caches left after a package purge."""
import os
from pathlib import Path
import re
import stat
import sys

# Package-owned Python modules only. No user data or unrecognised entry may be
# removed. The parent and all directories must be private to the caller (root
# when run by dpkg), so an unprivileged user cannot change entries during scan.
DIRECTORIES = {'', 'app', 'app/__pycache__', 'app/backup',
               'app/backup/__pycache__', 'app/cli', 'app/cli/__pycache__'}
MODULES = {
    'app/__pycache__': {'worker', 'ansible_vault', 'database_selection',
                         'repository_scope', 'crypto', 'ansible_runtime'},
    'app/backup/__pycache__': {'__init__', 'recovery', 'assets', 'openpgp'},
    'app/cli/__pycache__': {'recovery-backup'},
}


def prune(root: Path) -> None:
    if not root.is_absolute() or root.is_symlink():
        raise ValueError('invalid package directory')
    directories, files = [], []
    def scan(path: Path, rel: str) -> None:
        info = path.lstat()
        if (rel not in DIRECTORIES or not stat.S_ISDIR(info.st_mode)
                or info.st_uid != os.geteuid() or info.st_mode & 0o022):
            raise ValueError('unrecognised or writable package directory')
        directories.append(path)
        with os.scandir(path) as entries:
            for entry in entries:
                child = path / entry.name
                name = f'{rel}/{entry.name}' if rel else entry.name
                item = entry.stat(follow_symlinks=False)
                if stat.S_ISDIR(item.st_mode):
                    scan(child, name)
                elif stat.S_ISREG(item.st_mode) and item.st_uid == os.geteuid() and not item.st_mode & 0o022:
                    match = re.fullmatch(r'([A-Za-z0-9_-]+)\.cpython-[0-9]+(?:\.opt-[012])?\.pyc', entry.name)
                    if not match or match.group(1) not in MODULES.get(rel, set()):
                        raise ValueError('unrecognised package file')
                    files.append(child)
                else:
                    raise ValueError('unrecognised package entry')
    scan(root, '')
    for path in files:
        path.unlink()
    for path in reversed(directories):
        path.rmdir()


if __name__ == '__main__':
    try:
        if len(sys.argv) != 2:
            raise ValueError('expected package directory')
        prune(Path(sys.argv[1]))
    except (OSError, ValueError):
        raise SystemExit('PXE Tools: unrecognised files preserved; installation refused')
