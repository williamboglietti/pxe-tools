#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare a disposable appliance HTTP bind mount with production guard rules."""
from pathlib import Path
import shutil
import stat
import sys

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'app'))
from image_delete import DOCKER_MARKER, DOCKER_MARKER_CONTENT


def prepare_http_fixture(http: Path) -> None:
    info = http.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('fixture HTTP root must be root-owned and protected')
    marker = http/DOCKER_MARKER
    if marker.exists() or marker.is_symlink():
        raise FileExistsError('fixture HTTP marker already exists')
    marker.write_bytes(DOCKER_MARKER_CONTENT)
    marker.chmod(0o644)
    static_bin = http/'static-bin'
    shutil.copytree(ROOT/'docker/pxe-tools/static-bin', static_bin, symlinks=True)
    for item in (static_bin, *static_bin.rglob('*')):
        if item.is_symlink() or not (item.is_dir() or item.is_file()):
            raise ValueError('unsupported static binary fixture')
        item.chmod(0o755 if item.is_dir() or item.stat().st_mode & 0o111 else 0o644)
