#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fail closed before privileged native writes to unsafe TFTP or MOK trees."""
import importlib.util
import os
from pathlib import Path
import re
import stat
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


def ensure_tls(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts or not path.name:
        raise ValueError('invalid TLS path')
    parent = guard.trusted_directory(path.parent)
    try:
        try:
            os.mkdir(path.name, 0o700, dir_fd=parent)
        except FileExistsError:
            pass
        directory = os.open(path.name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
        try:
            info = os.fstat(directory)
            if info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
                raise ValueError('TLS directory must be root-owned and private')
            present = []
            for name in ('cert.pem', 'key.pem'):
                try:
                    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
                except FileNotFoundError:
                    present.append(False)
                    continue
                try:
                    file_info = os.fstat(fd)
                    mode = stat.S_IMODE(file_info.st_mode)
                    if (not stat.S_ISREG(file_info.st_mode) or file_info.st_uid != 0
                            or file_info.st_nlink != 1 or mode & 0o022
                            or (name == 'key.pem' and mode & 0o077)):
                        raise ValueError('TLS certificate or key must be a protected root-owned file')
                    present.append(True)
                finally:
                    os.close(fd)
            if present[0] != present[1]:
                raise ValueError('TLS certificate and key must be present together')
        finally:
            os.close(directory)
    finally:
        os.close(parent)


def ensure_http_tree(root, relative):
    parts = relative.split('/')
    if (not parts or any(not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._+\-]{0,99}', p)
                         or '..' in p for p in parts)):
        raise ValueError('invalid HTTP tree component')
    fd = guard.trusted_directory(root)
    try:
        for part in parts:
            created = False
            try:
                os.mkdir(part, 0o755, dir_fd=fd)
                created = True
            except FileExistsError:
                pass
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('HTTP tree must be root-owned and not group/world writable')
            if created:
                os.fchmod(fd, 0o755)
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        if len(sys.argv) not in (3, 4) or os.geteuid() != 0:
            raise ValueError('expected root, guard mode and absolute path')
        if sys.argv[1] == 'tftp' and len(sys.argv) == 3:
            os.close(guard.trusted_directory(sys.argv[2]))
        elif sys.argv[1] == 'mok' and len(sys.argv) == 3:
            ensure_mok(sys.argv[2])
        elif sys.argv[1] == 'tls' and len(sys.argv) == 3:
            ensure_tls(sys.argv[2])
        elif sys.argv[1] == 'http-tree' and len(sys.argv) == 4:
            ensure_http_tree(sys.argv[2], sys.argv[3])
        else:
            raise ValueError('invalid guard mode')
    except (OSError, ValueError) as exc:
        raise SystemExit(f'Native runtime path refused: {exc}')
