#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Publish bytes without following destination links, including parent links."""
from __future__ import annotations

import os
import grp
from pathlib import Path
import re
import secrets
import stat
import sys


def _open_parent(path: Path) -> tuple[int, str]:
    path = Path(path)
    if path.name in ('', '.', '..') or '..' in path.parts:
        raise ValueError('invalid output path')
    if not path.is_absolute():
        path = Path.cwd() / path
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    fd = os.open('/', flags)
    try:
        for part in path.parts[1:-1]:
            try:
                child = os.open(part, flags, dir_fd=fd)
            except FileNotFoundError:
                try:
                    os.mkdir(part, 0o755, dir_fd=fd)
                except FileExistsError:
                    pass
                child = os.open(part, flags, dir_fd=fd)
            os.close(fd)
            fd = child
        return fd, path.name
    except BaseException:
        os.close(fd)
        raise


def publish(path: Path, data: bytes, *, mode: int | None = None) -> None:
    """Replace a regular file without following links; preserve existing mode/owner."""
    parent, name = _open_parent(Path(path))
    stage = '.pxe-stage-' + secrets.token_hex(16)
    stage_fd = None
    stage_identity = None
    try:
        try:
            previous = os.stat(name, dir_fd=parent, follow_symlinks=False)
            if stat.S_ISLNK(previous.st_mode):
                # Replace the link itself, never the object it names.
                previous = None
            elif not stat.S_ISREG(previous.st_mode):
                raise ValueError('refusing non-regular output')
        except FileNotFoundError:
            previous = None
        os.mkdir(stage, 0o700, dir_fd=parent)
        stage_fd = os.open(stage, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
        stage_identity = os.fstat(stage_fd)
        if stage_identity.st_uid != os.geteuid() or stat.S_IMODE(stage_identity.st_mode) != 0o700:
            raise ValueError('untrusted staging directory')
        fd = os.open('data', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o666, dir_fd=stage_fd)
        with os.fdopen(fd, 'wb') as output:
            if previous is not None:
                if (previous.st_uid, previous.st_gid) != (os.geteuid(), os.getegid()):
                    os.fchown(output.fileno(), previous.st_uid, previous.st_gid)
                os.fchmod(output.fileno(), stat.S_IMODE(previous.st_mode) & 0o777)
            elif mode is None:
                # Generated files are served to nginx/PHP even if the worker
                # was started with a restrictive umask.
                os.fchmod(output.fileno(), 0o644)
            if mode is not None:
                os.fchmod(output.fileno(), mode)
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.replace('data', name, src_dir_fd=stage_fd, dst_dir_fd=parent)
        os.fsync(parent)
        # A writable ancestor may have been renamed while we held the fd.
        # Reopening must find the same directory without following links.
        check_fd, _ = _open_parent(Path(path))
        try:
            a, b = os.fstat(parent), os.fstat(check_fd)
            if (a.st_dev, a.st_ino) != (b.st_dev, b.st_ino):
                raise ValueError('output parent changed during publication')
        finally:
            os.close(check_fd)
    finally:
        if stage_fd is not None:
            try:
                os.unlink('data', dir_fd=stage_fd)
            except FileNotFoundError:
                pass
            os.close(stage_fd)
        try:
            current = os.stat(stage, dir_fd=parent, follow_symlinks=False)
            if stage_identity is not None and (current.st_dev, current.st_ino) == (stage_identity.st_dev, stage_identity.st_ino):
                os.rmdir(stage, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def prepare_menu(directory: Path) -> None:
    """Create a shared lock and writable menu directory below a trusted TFTP root."""
    path = Path(directory)
    if not path.is_absolute() or path == Path('/') or '..' in path.parts or os.geteuid() != 0:
        raise ValueError('TFTP root must be absolute and prepared by root')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    current = os.open('/', flags)
    try:
        components = path.parts[1:]
        for index, part in enumerate(components):
            child = os.open(part, flags, dir_fd=current)
            os.close(current)
            current = child
            info = os.fstat(current)
            # A root-owned sticky ancestor such as /tmp cannot have a
            # root-owned child renamed by an unprivileged user. The TFTP root
            # itself must always be non-writable by other users.
            sticky_ancestor = index < len(components)-1 and bool(info.st_mode & stat.S_ISVTX)
            if info.st_uid != 0 or (info.st_mode & 0o022 and not sticky_ancestor):
                raise ValueError('TFTP root and parents must not be writable by other users')
        group = grp.getgrnam('www-data').gr_gid
        try:
            os.mkdir('pxelinux.cfg', 0o755, dir_fd=current)
        except FileExistsError:
            pass
        menu = os.open('pxelinux.cfg', flags, dir_fd=current)
        try:
            info = os.fstat(menu)
            if info.st_uid != 0 or info.st_mode & 0o7002:
                raise ValueError('unsafe existing PXE menu directory')
            os.fchown(menu, 0, group)
            os.fchmod(menu, stat.S_IMODE(info.st_mode) | 0o030)
        finally:
            os.close(menu)
        fd = os.open('.pxe-menu.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o660, dir_fd=current)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1 or info.st_mode & 0o002:
                raise ValueError('unsafe PXE menu lock')
            os.fchown(fd, 0, group)
            os.fchmod(fd, 0o660)
        finally:
            os.close(fd)
    finally:
        os.close(current)


def clean_menus(directory: Path, batch: Path) -> None:
    """Remove only stale, well-formed per-MAC entries after a complete render."""
    destination, _ = _open_parent(Path(directory) / '.entry')
    staged, _ = _open_parent(Path(batch) / '.entry')
    try:
        info = os.fstat(staged)
        if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
            raise ValueError('untrusted menu batch')
        pattern = re.compile(r'01-(?:[0-9a-f]{2}-){5}[0-9a-f]{2}\Z')
        expected = set()
        for name in os.listdir(staged):
            if pattern.fullmatch(name):
                item = os.stat(name, dir_fd=staged, follow_symlinks=False)
                if not stat.S_ISREG(item.st_mode):
                    raise ValueError('invalid staged menu')
                expected.add(name)
        for name in os.listdir(destination):
            if pattern.fullmatch(name) and name not in expected:
                os.unlink(name, dir_fd=destination)
        os.fsync(destination)
    finally:
        os.close(staged)
        os.close(destination)


def main() -> None:
    if len(sys.argv) == 3 and sys.argv[1] == '--prepare-menu':
        prepare_menu(Path(sys.argv[2]))
        return
    if len(sys.argv) == 4 and sys.argv[1] == '--clean-menu':
        clean_menus(Path(sys.argv[2]), Path(sys.argv[3]))
        return
    if len(sys.argv) != 3:
        raise SystemExit('expected source and destination')
    source = Path(sys.argv[1])
    fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > 1024 * 1024:
            raise ValueError('invalid source file')
        data = stream.read(1024 * 1024 + 1)
    if len(data) != info.st_size:
        raise ValueError('source changed during reading')
    publish(Path(sys.argv[2]), data, mode=0o644)


if __name__ == '__main__':
    main()
