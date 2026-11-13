#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Use the existing protected TFTP lock for worker menu maintenance."""
from contextlib import contextmanager
import fcntl
import os
from pathlib import Path
import stat
import time


@contextmanager
def menu_lock(root: str, timeout: float = 10):
    path = Path(root)
    if not path.is_absolute() or path == Path('/') or '..' in path.parts:
        raise ValueError('invalid TFTP root')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    directory = os.open('/', flags)
    lock = None
    try:
        parts = path.parts[1:]
        for index, part in enumerate(parts):
            child = os.open(part, flags, dir_fd=directory)
            os.close(directory)
            directory = child
            info = os.fstat(directory)
            sticky_ancestor = index < len(parts)-1 and bool(info.st_mode & stat.S_ISVTX)
            if info.st_uid != 0 or (info.st_mode & 0o022 and not sticky_ancestor):
                raise ValueError('untrusted TFTP root')
        lock = os.open('.pxe-menu.lock', os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        info = os.fstat(lock)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o002:
            raise ValueError('untrusted menu lock')
        deadline = time.monotonic() + timeout
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise TimeoutError('PXE menu lock is busy')
                time.sleep(0.05)
        yield
    finally:
        if lock is not None:
            os.close(lock)
        os.close(directory)
