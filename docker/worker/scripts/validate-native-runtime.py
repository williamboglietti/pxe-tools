#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fail closed before privileged native writes to unsafe TFTP or MOK trees."""
import importlib.util
import os
from pathlib import Path
import sys

path = Path(__file__).with_name('prepare-debian-shim.py')
spec = importlib.util.spec_from_file_location('pxe_trusted_directory', path)
if spec is None or spec.loader is None:
    raise SystemExit('Native path guard unavailable')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


def ensure_mok(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts or not path.name:
        raise ValueError('invalid MOK path')
    parent = guard.trusted_directory(path.parent)
    try:
        try:
            os.mkdir(path.name, 0o700, dir_fd=parent)
        except FileExistsError:
            pass
        fd = os.open(path.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
        try:
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o077:
                raise ValueError('MOK directory must be root-owned and private')
        finally:
            os.close(fd)
    finally:
        os.close(parent)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3 or os.geteuid() != 0:
            raise ValueError('expected root, guard mode and absolute path')
        if sys.argv[1] == 'tftp':
            os.close(guard.trusted_directory(sys.argv[2]))
        elif sys.argv[1] == 'mok':
            ensure_mok(sys.argv[2])
        else:
            raise ValueError('invalid guard mode')
    except (OSError, ValueError) as exc:
        raise SystemExit(f'Native runtime path refused: {exc}')
