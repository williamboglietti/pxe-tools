#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare a new protected native TFTP root; never alter existing paths."""
import os
from pathlib import Path
import sys


BASE = Path('/var/lib')
RUNTIME = 'pxe-tools-native'
TFTP = 'pxe-tools-tftp'
HTTP = 'pxe-tools-http'
MARKER = (b'TFTP_ROOT=/var/lib/pxe-tools-tftp\n'
          b'HTTP_ROOT=/var/lib/pxe-tools-http\n'
          b'PXE_TOOLS_DATA_DIR=/var/lib/pxe-tools-native/rendered\n'
          b'MOK_DIR=/var/lib/pxe-tools-native/mok\n')


def prepare(base=BASE):
    # /var and /var/lib are expected to be root-owned and not writable by the
    # web account. Open the entire path without following symlinks.
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    created = []
    try:
        root = os.fstat(fd)
        if root.st_uid != 0 or root.st_mode & 0o022:
            raise ValueError('unsafe root directory')
        for part in Path(base).parts[1:]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('unsafe parent of native TFTP paths')
        for name in (RUNTIME, TFTP, HTTP):
            try:
                os.stat(name, dir_fd=fd, follow_symlinks=False)
            except FileNotFoundError:
                continue
            raise FileExistsError('native TFTP destination or marker already exists: ' + name)
        for name in (RUNTIME, TFTP, HTTP):
            os.mkdir(name, 0o755, dir_fd=fd)
            created.append(name)
            opened = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            try:
                os.fchmod(opened, 0o755)
            finally:
                os.close(opened)
        runtime = os.open(RUNTIME, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
        try:
            os.mkdir('rendered', 0o755, dir_fd=runtime)
            rendered = os.open('rendered', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=runtime)
            try:
                os.fchmod(rendered, 0o755)
            finally:
                os.close(rendered)
            marker = os.open('runtime.env', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                             0o644, dir_fd=runtime)
            with os.fdopen(marker, 'wb') as stream:
                os.fchmod(stream.fileno(), 0o644)
                stream.write(MARKER)
                stream.flush()
                os.fsync(stream.fileno())
            os.fsync(runtime)
        finally:
            os.close(runtime)
        os.fsync(fd)
    except BaseException:
        # Only remove empty directories created by this invocation. A marker
        # must never be deleted after an ambiguous failure.
        for name in reversed(created):
            try:
                os.rmdir(name, dir_fd=fd)
            except OSError:
                pass
        raise
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        if len(sys.argv) != 1 or os.geteuid() != 0:
            raise ValueError('this command must be run as root without arguments')
        prepare()
        print('Protected native TFTP root prepared; existing paths were not changed.')
    except (OSError, ValueError) as exc:
        raise SystemExit(f'Cannot prepare native TFTP root: {exc}')
