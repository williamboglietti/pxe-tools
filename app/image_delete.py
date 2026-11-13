# SPDX-License-Identifier: LGPL-3.0-only
"""Bounded native image deletion: protected paths and restart recovery."""
from __future__ import annotations
import json
import os
from pathlib import PurePosixPath
import re
import stat

FILES = ('linux', 'initrd.gz', 'firmware.cpio.gz')
COMPONENT = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z')


def components(log: str, image_id: int) -> tuple[str, ...]:
    meta = json.loads(log)
    if not isinstance(meta, dict) or set(meta) != {'id', 'distro', 'version', 'version_full', 'arch'}:
        raise ValueError('invalid image metadata')
    if type(meta['id']) is not int or meta['id'] != image_id or image_id <= 0:
        raise ValueError('invalid image identifier')
    version_full = meta['version_full']
    if version_full is not None and not isinstance(version_full, str):
        raise ValueError('invalid version')
    parts = [meta['distro'], version_full, meta['arch']] if version_full else [meta['version'], meta['arch']]
    if any(not isinstance(part, str) or not COMPONENT.fullmatch(part) or '..' in part for part in parts):
        raise ValueError('invalid image path component')
    return ('netboot', *parts)


def open_image_dir(http_root: str, parts: tuple[str, ...]) -> int | None:
    """Open only root-owned, non-writable directories without following links."""
    path = PurePosixPath(http_root)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('invalid HTTP root')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        root_info = os.fstat(fd)
        if root_info.st_uid != 0 or root_info.st_mode & 0o022:
            raise ValueError('unprotected filesystem root')
        for part in (*path.parts[1:], *parts):
            try:
                child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            except FileNotFoundError:
                os.close(fd)
                return None
            os.close(fd); fd = child
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('unprotected image directory')
        return fd
    except BaseException:
        os.close(fd)
        raise


def validate_files(fd: int | None, job_id: int) -> None:
    if fd is None:
        return
    if type(job_id) is not int or job_id <= 0:
        raise ValueError('invalid job identifier')
    for name in FILES:
        for candidate in (name, f'{name}.pxe-delete-{job_id}'):
            try:
                info = os.stat(candidate, dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                continue
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('unsafe image file')


def require_trusted_http_root(http_root: str, marker_dir: str = '/var/lib/pxe-tools-native') -> None:
    """Require the path to match a root-owned, no-follow native marker."""
    directory = open_image_dir(marker_dir, ())
    if directory is None:
        raise ValueError('protected native marker missing')
    try:
        fd = os.open('runtime.env', os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1
                    or info.st_mode & 0o022 or info.st_size > 4096):
                raise ValueError('untrusted native marker')
            content = os.read(fd, 4097)
            if len(content) != info.st_size:
                raise ValueError('native marker changed while reading')
        finally:
            os.close(fd)
    finally:
        os.close(directory)
    lines = [line[len(b'HTTP_ROOT='):] for line in content.splitlines() if line.startswith(b'HTTP_ROOT=')]
    if len(lines) != 1 or lines[0] != os.fsencode(http_root):
        raise ValueError('HTTP root differs from protected native marker')


def _exists(fd: int, name: str) -> bool:
    try:
        os.stat(name, dir_fd=fd, follow_symlinks=False)
        return True
    except FileNotFoundError:
        return False


def restore_staged(fd: int | None, job_id: int) -> None:
    """Revert a pre-commit interruption before revalidating dependencies."""
    if fd is None:
        return
    validate_files(fd, job_id)
    staged = [(name, f'{name}.pxe-delete-{job_id}') for name in FILES]
    if any(_exists(fd, name) and _exists(fd, backup) for name, backup in staged):
        raise ValueError('ambiguous image staging')
    for name, backup in staged:
        if _exists(fd, backup):
            os.rename(backup, name, src_dir_fd=fd, dst_dir_fd=fd)
    os.fsync(fd)


def stage_files(fd: int | None, job_id: int) -> None:
    """Move only the fixed image files inside the validated directory."""
    if fd is None:
        return
    validate_files(fd, job_id)
    staged = [(name, f'{name}.pxe-delete-{job_id}') for name in FILES]
    if any(_exists(fd, backup) for _name, backup in staged):
        raise ValueError('image staging already exists')
    for name, backup in staged:
        if _exists(fd, name):
            os.rename(name, backup, src_dir_fd=fd, dst_dir_fd=fd)
    os.fsync(fd)


def cleanup_committed(fd: int | None, job_id: int) -> None:
    """After database commit, remove only this job's deterministic staging."""
    if fd is None:
        return
    validate_files(fd, job_id)
    for name in FILES:
        try:
            os.unlink(f'{name}.pxe-delete-{job_id}', dir_fd=fd)
        except FileNotFoundError:
            pass
    os.fsync(fd)
