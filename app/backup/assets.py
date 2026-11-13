# SPDX-License-Identifier: LGPL-3.0-only
"""Bounded, no-follow handling of explicitly selected offline PXE asset trees."""
from __future__ import annotations

import os
from pathlib import Path
import stat
import tarfile

from .openpgp import BackupError

MAX_ASSET_BYTES = 6 * 1024 * 1024 * 1024
MAX_ASSET_ENTRIES = 20000
MAX_PATH_BYTES = 200
MAX_LINK_BYTES = 100
TREE_CATEGORIES = {'http_files': 'http', 'tftp_files': 'tftp', 'tls_files': 'tls',
                   'ansible_files': 'ansible'}


def _safe_text(value: str, limit: int) -> bool:
    try:
        encoded = value.encode('utf-8', 'strict')
    except UnicodeError:
        return False
    return bool(encoded) and len(encoded) <= limit and not any(ord(ch) < 32 or ord(ch) == 127 for ch in value)


def safe_asset_name(name: str) -> tuple[str, ...]:
    if not _safe_text(name, MAX_PATH_BYTES) or name.startswith('/') or '\\' in name:
        raise BackupError('invalid asset path')
    parts = tuple(name.split('/'))
    if any(part in ('', '.', '..') or len(part.encode('utf-8')) > 100 for part in parts):
        raise BackupError('invalid asset path')
    if parts[0] not in TREE_CATEGORIES.values():
        raise BackupError('unknown asset category')
    return parts


def _info(info: os.stat_result) -> tuple:
    return (info.st_dev, info.st_ino, info.st_mode, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _open_root(path: Path) -> int:
    if not path.is_absolute() or path.is_symlink():
        raise BackupError('an absolute non-symlink asset directory is required')
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    if not stat.S_ISDIR(os.fstat(fd).st_mode):
        os.close(fd)
        raise BackupError('asset source is not a directory')
    return fd


def _open_parent(root_fd: int, parts: tuple[str, ...]) -> int:
    fd = os.dup(root_fd)
    try:
        for part in parts[:-1]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise


def inspect_tree(path: Path, prefix: str) -> dict[str, tuple]:
    """Record names and stable metadata without following symlinks."""
    if prefix not in TREE_CATEGORIES.values():
        raise BackupError('unknown asset category')
    records: dict[str, tuple] = {}
    total = 0
    root_fd = _open_root(path)
    try:
        def walk(fd: int, relative: str) -> None:
            nonlocal total
            root_info = os.fstat(fd)
            archive_name = prefix + ('/' + relative if relative else '')
            safe_asset_name(archive_name)
            if root_info.st_mode & 0o7000:
                raise BackupError('special file permission bits are not supported')
            records[archive_name] = ('dir', _info(root_info), '')
            if len(records) > MAX_ASSET_ENTRIES:
                raise BackupError('too many asset entries')
            with os.scandir(fd) as entries:
                names = sorted(entry.name for entry in entries)
            for name in names:
                child = relative + '/' + name if relative else name
                asset_name = prefix + '/' + child
                safe_asset_name(asset_name)
                info = os.stat(name, dir_fd=fd, follow_symlinks=False)
                if info.st_mode & 0o7000:
                    raise BackupError('special file permission bits are not supported')
                if stat.S_ISDIR(info.st_mode):
                    child_fd = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
                    try:
                        if _info(os.fstat(child_fd)) != _info(info):
                            raise BackupError('asset tree changed during inspection')
                        walk(child_fd, child)
                    finally:
                        os.close(child_fd)
                elif stat.S_ISREG(info.st_mode):
                    total += info.st_size
                    if total > MAX_ASSET_BYTES:
                        raise BackupError('asset data exceeds limit')
                    records[asset_name] = ('file', _info(info), '')
                elif stat.S_ISLNK(info.st_mode):
                    target = os.readlink(name, dir_fd=fd)
                    # The native package recreates this fixed link at startup.
                    # It points outside the selected tree and must never be
                    # followed or restored as a potentially unsafe link.
                    if prefix == 'http' and not relative and name == 'static-bin' and target == '/usr/lib/pxe-tools/static-bin':
                        continue
                    raise BackupError('symbolic links are not supported in asset trees')
                else:
                    raise BackupError('special files are not supported in asset trees')
                if len(records) > MAX_ASSET_ENTRIES:
                    raise BackupError('too many asset entries')
            if _info(os.fstat(fd)) != _info(root_info):
                raise BackupError('asset directory changed during inspection')
        walk(root_fd, '')
    finally:
        os.close(root_fd)
    return records


def add_tree(tar: tarfile.TarFile, path: Path, prefix: str, records: dict[str, tuple]) -> None:
    """Write an inspected tree without following filesystem links."""
    root_fd = _open_root(path)
    try:
        if _info(os.fstat(root_fd)) != records[prefix][1]:
            raise BackupError('asset root changed during capture')
        for name in sorted(records):
            kind, saved, target = records[name]
            parts = safe_asset_name(name)[1:]
            info = tarfile.TarInfo(name)
            info.mode = saved[2] & 0o777
            info.mtime = max(0, saved[4] // 1_000_000_000)
            info.uid = info.gid = 0
            if not parts:
                info.type = tarfile.DIRTYPE
                tar.addfile(info)
                continue
            parent_fd = _open_parent(root_fd, parts)
            try:
                actual = os.stat(parts[-1], dir_fd=parent_fd, follow_symlinks=False)
                if _info(actual) != saved:
                    raise BackupError('asset changed during capture')
                if kind == 'dir':
                    info.type = tarfile.DIRTYPE
                    tar.addfile(info)
                elif kind == 'file':
                    fd = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent_fd)
                    with os.fdopen(fd, 'rb') as stream:
                        if _info(os.fstat(stream.fileno())) != saved:
                            raise BackupError('asset changed during capture')
                        info.type = tarfile.REGTYPE
                        info.size = actual.st_size
                        tar.addfile(info, stream)
                        if _info(os.fstat(stream.fileno())) != saved:
                            raise BackupError('asset changed during capture')
                else:
                    raise BackupError('invalid asset entry')
            finally:
                os.close(parent_fd)
    finally:
        os.close(root_fd)
    if inspect_tree(path, prefix) != records:
        raise BackupError('asset tree changed during capture')
