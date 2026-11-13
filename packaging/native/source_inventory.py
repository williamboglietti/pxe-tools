#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fail closed unless package inputs are tracked and publicly readable."""
from pathlib import Path
import stat
import subprocess


def tracked_files(root: Path) -> set[str]:
    try:
        top = subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--show-toplevel'],
                                      stderr=subprocess.DEVNULL, text=True).strip()
        if Path(top).resolve() != root.resolve():
            raise ValueError('source checkout root does not match')
        output = subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z', '--cached'],
                                         stderr=subprocess.DEVNULL)
        return {item.decode('utf-8', 'strict') for item in output.split(b'\0') if item}
    except (OSError, subprocess.SubprocessError, UnicodeError) as error:
        raise ValueError('package builds require a Git checkout with tracked source files') from error


def checked_file(root: Path, path: Path, tracked: set[str]) -> Path:
    try:
        relative = path.relative_to(root).as_posix()
        if (relative not in tracked or path.is_symlink()
                or any(parent.is_symlink() for parent in path.parents if parent != root and root in parent.parents)):
            raise ValueError('untracked or linked package input: ' + relative)
        info = path.stat()
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o644 != 0o644:
            raise ValueError('private or invalid package input: ' + relative)
        return path
    except OSError as error:
        raise ValueError('package input unavailable') from error
