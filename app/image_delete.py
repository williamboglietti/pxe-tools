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
