#!/usr/bin/env python3
"""Copy one verified image file from private staging into the served tree."""
import os
from pathlib import Path
import re
import secrets
import stat
import sys

from atomic_publish import _open_parent


def _confirm_destination(path: Path, parent: int) -> None:
    check_fd, _ = _open_parent(path)
    try:
        current, expected = os.fstat(check_fd), os.fstat(parent)
        if (current.st_dev, current.st_ino) != (expected.st_dev, expected.st_ino):
            raise ValueError('image destination changed during publication')
    finally:
        os.close(check_fd)


def publish(source: Path, destination: Path) -> None:
    # The downloader creates this directory as root under the sticky /var/tmp.
    # Refuse any other source rather than letting a caller publish a web-owned
    # file (or read an arbitrary root file) through this privileged helper.
    if (source.parent.parent != Path('/var/tmp')
            or not source.parent.name.startswith('pxe-image.')
            or source.name not in ('linux', 'initrd.gz', 'firmware.cpio.gz', 'source.iso')):
        raise ValueError('invalid image staging path')
    stage = os.open(source.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        stage_info = os.fstat(stage)
        if (stage_info.st_uid != 0 or stat.S_IMODE(stage_info.st_mode) != 0o700
                or os.geteuid() != 0):
            raise ValueError('image staging is not private to root')
        input_fd = os.open(source.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK,
                           dir_fd=stage)
        try:
            input_info = os.fstat(input_fd)
            if (not stat.S_ISREG(input_info.st_mode) or input_info.st_uid != 0
                    or input_info.st_nlink != 1 or input_info.st_size == 0):
                raise ValueError('invalid verified image file')
            parent, name = _open_parent(destination)
            temporary = '.pxe-image-' + secrets.token_hex(16)
            temporary_fd = None
            temp_info = None
            try:
                os.mkdir(temporary, 0o700, dir_fd=parent)
                temporary_fd = os.open(temporary, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                                       dir_fd=parent)
                temp_info = os.fstat(temporary_fd)
                if (temp_info.st_uid != 0 or stat.S_IMODE(temp_info.st_mode) != 0o700):
                    raise ValueError('untrusted publication staging')
                output_fd = os.open('data', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                                    0o644, dir_fd=temporary_fd)
                with os.fdopen(output_fd, 'wb') as output:
                    os.fchmod(output.fileno(), 0o644)
                    while chunk := os.read(input_fd, 1024 * 1024):
                        output.write(chunk)
                    output.flush()
                    os.fsync(output.fileno())
                if os.fstat(input_fd).st_size != input_info.st_size:
                    raise ValueError('verified image changed during publication')
                os.replace('data', name, src_dir_fd=temporary_fd, dst_dir_fd=parent)
                os.fsync(parent)
                _confirm_destination(destination, parent)
            finally:
                if temporary_fd is not None:
                    try:
                        os.unlink('data', dir_fd=temporary_fd)
                    except FileNotFoundError:
                        pass
                    os.close(temporary_fd)
                try:
                    current = os.stat(temporary, dir_fd=parent, follow_symlinks=False)
                    if temp_info is not None and (current.st_dev, current.st_ino) == (temp_info.st_dev, temp_info.st_ino):
                        os.rmdir(temporary, dir_fd=parent)
                except FileNotFoundError:
                    pass
                os.close(parent)
        finally:
            os.close(input_fd)
    finally:
        os.close(stage)


def publish_alias(target: str, destination: Path) -> None:
    component = r'[A-Za-z0-9][A-Za-z0-9._+-]{0,99}'
    if (not re.fullmatch(rf'\.\./{component}/{component}/{component}', target)
            or '..' in target[3:]):
        raise ValueError('invalid image alias target')
    parent, name = _open_parent(destination)
    temporary = '.pxe-alias-' + secrets.token_hex(16)
    try:
        os.symlink(target, temporary, dir_fd=parent)
        os.replace(temporary, name, src_dir_fd=parent, dst_dir_fd=parent)
        os.fsync(parent)
        _confirm_destination(destination, parent)
    finally:
        try:
            os.unlink(temporary, dir_fd=parent)
        except FileNotFoundError:
            pass
        os.close(parent)


def remove(destination: Path) -> None:
    parent, name = _open_parent(destination)
    try:
        try:
            os.unlink(name, dir_fd=parent)
        except FileNotFoundError:
            return
        os.fsync(parent)
        _confirm_destination(destination, parent)
    finally:
        os.close(parent)


if __name__ == '__main__':
    if len(sys.argv) == 4 and sys.argv[1] == '--alias':
        publish_alias(sys.argv[2], Path(sys.argv[3]))
    elif len(sys.argv) == 3 and sys.argv[1] == '--remove':
        remove(Path(sys.argv[2]))
    elif len(sys.argv) == 3:
        publish(Path(sys.argv[1]), Path(sys.argv[2]))
    else:
        raise SystemExit('expected verified source and destination, or alias target and destination')
