#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Atomically publish a public MOK certificate without following destination links."""
import os
from pathlib import Path
import secrets
import stat
import sys


def open_directory(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('absolute directory required')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    fd = os.open('/', flags)
    try:
        for part in path.parts[1:]:
            child = os.open(part, flags, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd
    except BaseException:
        os.close(fd)
        raise


def publish(mok_dir, http_dir):
    source = open_directory(mok_dir)
    try:
        destination = open_directory(http_dir)
        try:
            cert = os.open('MOK.der', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=source)
            with os.fdopen(cert, 'rb') as stream:
                info = os.fstat(stream.fileno())
                if not stat.S_ISREG(info.st_mode) or info.st_size < 1 or info.st_size > 1024 * 1024:
                    raise ValueError('invalid public MOK certificate')
                data = stream.read(1024 * 1024 + 1)
            name = '.pxe-mok-' + secrets.token_hex(16)
            tmp = os.open(name, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW,
                          0o644, dir_fd=source)
            try:
                with os.fdopen(tmp, 'wb') as stream:
                    os.fchmod(stream.fileno(), 0o644)
                    stream.write(data)
                    stream.flush()
                    os.fsync(stream.fileno())
                os.replace(name, 'MOK.der', src_dir_fd=source, dst_dir_fd=destination)
            finally:
                try:
                    os.unlink(name, dir_fd=source)
                except FileNotFoundError:
                    pass
        finally:
            os.close(destination)
    finally:
        os.close(source)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 3:
            raise ValueError('expected MOK and HTTP directories')
        publish(sys.argv[1], sys.argv[2])
    except (OSError, ValueError) as exc:
        raise SystemExit(f'Cannot publish MOK certificate safely: {exc}')
