#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Manage a separately installed Ansible runtime in an operator-owned volume.

The wheelhouse and its hashes must be authenticated independently. This tool
never downloads packages and does not execute playbooks or manage SSH access.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shutil
import stat
import subprocess
import sys
import tempfile

ENV_NAME = re.compile(r'env-[a-z0-9_]{8}')
LOCK_LINE = re.compile(r'([A-Za-z0-9][A-Za-z0-9_.-]{0,99})==([A-Za-z0-9][A-Za-z0-9.!+_-]{0,99}) --hash=sha256:([a-f0-9]{64})')


class RuntimeErrorSafe(Exception):
    """An operation cannot be performed without weakening the runtime boundary."""


def private_directory(path: Path, *, create: bool = False) -> Path:
    if not path.is_absolute() or path.is_symlink():
        raise RuntimeErrorSafe('an absolute, non-symlink directory is required')
    if create:
        path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise RuntimeErrorSafe('runtime directory must be private and owned by the current user')
    if path.resolve(strict=True) != path:
        raise RuntimeErrorSafe('runtime directory must not contain symbolic-link components')
    return path


def protected_file(path: Path, limit: int) -> bytes:
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_nlink != 1:
            raise RuntimeErrorSafe('runtime metadata must be a private regular file')
        data = source.read(limit + 1)
    if len(data) > limit:
        raise RuntimeErrorSafe('runtime metadata exceeds its size limit')
    return data


def wheel_lock(wheelhouse: Path) -> bytes:
    if not wheelhouse.is_absolute() or wheelhouse.is_symlink() or not wheelhouse.is_dir():
        raise RuntimeErrorSafe('wheelhouse must be an absolute directory')
    entries = list(wheelhouse.iterdir())
    if len(entries) > 129:
        raise RuntimeErrorSafe('too many wheelhouse files')
    for item in entries:
        info = item.lstat()
        if not stat.S_ISREG(info.st_mode) or item.is_symlink():
            raise RuntimeErrorSafe('wheelhouse may contain only regular files')
        if item.name != 'requirements.lock' and not item.name.endswith('.whl'):
            raise RuntimeErrorSafe('unexpected file in wheelhouse')
    lock = wheelhouse / 'requirements.lock'
    descriptor = os.open(lock, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as source:
        data = source.read(32769)
    if not data or len(data) > 32768:
        raise RuntimeErrorSafe('invalid requirements lock size')
    lines = data.decode('ascii').splitlines()
    if not 1 <= len(lines) <= 128:
        raise RuntimeErrorSafe('invalid requirements count')
    names: set[str] = set()
    for line in lines:
        match = LOCK_LINE.fullmatch(line)
        if match is None:
            raise RuntimeErrorSafe('each locked requirement needs one exact version and SHA-256')
        name = re.sub(r'[-_.]+', '-', match[1]).lower()
        if name in names:
            raise RuntimeErrorSafe('duplicate locked distribution')
        names.add(name)
    if 'ansible-core' not in names:
        raise RuntimeErrorSafe('ansible-core must be explicitly locked')
    return data


def compatibility() -> dict[str, object]:
    return {'python': [sys.version_info.major, sys.version_info.minor],
            'implementation': sys.implementation.name, 'machine': platform.machine(),
            'system': platform.system(), 'base_executable': str(Path(sys.executable).resolve(strict=True))}


@contextmanager
def locked(root: Path):
    path = root / '.runtime.lock'
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_nlink != 1:
            raise RuntimeErrorSafe('runtime lock is not private')
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield
    finally:
        os.close(descriptor)


def inspect(root: Path, name: str) -> dict[str, object]:
    if not ENV_NAME.fullmatch(name):
        raise RuntimeErrorSafe('invalid runtime identifier')
    parent = private_directory(root / 'runtimes')
    runtime = private_directory(parent / name)
    metadata = json.loads(protected_file(runtime / '.pxe-runtime.json', 4096))
    if not isinstance(metadata, dict) or set(metadata) != {'format', 'ansible_core', 'lock_sha256', 'compatibility'}:
        raise RuntimeErrorSafe('unsupported runtime metadata')
    if metadata['format'] != 1 or metadata['compatibility'] != compatibility():
        raise RuntimeErrorSafe('runtime is incompatible with this Python or platform')
    if (not isinstance(metadata['ansible_core'], str) or len(metadata['ansible_core']) > 100
            or not isinstance(metadata['lock_sha256'], str)
            or not re.fullmatch(r'[a-f0-9]{64}', metadata['lock_sha256'])):
        raise RuntimeErrorSafe('invalid runtime metadata')
    bin_dir = runtime / 'bin'
    directory_info = bin_dir.lstat()
    if (not stat.S_ISDIR(directory_info.st_mode) or directory_info.st_uid != os.geteuid()
            or directory_info.st_mode & 0o022 or bin_dir.resolve(strict=True) != bin_dir):
        raise RuntimeErrorSafe('runtime executable directory is unsafe')
    executable = bin_dir / 'ansible-playbook'
    info = executable.lstat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022
            or info.st_nlink != 1 or not os.access(executable, os.X_OK)):
        raise RuntimeErrorSafe('ansible-playbook is missing or unsafe')
    return metadata


def install(root: Path, wheelhouse: Path) -> str:
    root = private_directory(root)
    lock = wheel_lock(wheelhouse)
    with locked(root):
        runtimes = private_directory(root / 'runtimes', create=True)
        path = Path(tempfile.mkdtemp(prefix='env-', dir=runtimes))
        if not ENV_NAME.fullmatch(path.name):
            raise RuntimeErrorSafe('unexpected runtime identifier')
        try:
            # The interpreter and venv remain at the same path for the life of
            # this runtime; moving a Python venv invalidates its script shebangs.
            environment = {'HOME': str(path), 'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8',
                           'PYTHONNOUSERSITE': '1', 'PIP_CONFIG_FILE': '/dev/null'}
            subprocess.run([sys.executable, '-m', 'venv', str(path)], env=environment,
                           check=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            # Snapshot the validated lock; pip must not read a changed file.
            requirements = path / '.requirements.lock'
            requirements.write_bytes(lock)
            requirements.chmod(0o600)
            python = path / 'bin/python'
            subprocess.run([str(python), '-m', 'pip', '--isolated', 'install', '--no-index', '--no-deps',
                            '--find-links', str(wheelhouse), '--only-binary=:all:', '--require-hashes',
                            '--no-cache-dir', '-r', str(requirements)], env=environment,
                           check=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            subprocess.run([str(python), '-m', 'pip', '--isolated', 'check'], env=environment,
                           check=True, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            version = subprocess.run([str(python), '-I', '-c',
                                      'import importlib.metadata; print(importlib.metadata.version("ansible-core"))'],
                                     env=environment, check=True, stdin=subprocess.DEVNULL,
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True).stdout.strip()
            metadata = {'format': 1, 'ansible_core': version, 'lock_sha256': hashlib.sha256(lock).hexdigest(),
                        'compatibility': compatibility()}
            marker = path / '.pxe-runtime.json'
            marker.write_text(json.dumps(metadata, sort_keys=True) + '\n', encoding='utf-8')
            marker.chmod(0o600)
            inspect(root, path.name)
            return path.name
        except BaseException:
            # Only the fresh, exclusively created environment is discarded.
            shutil.rmtree(path)
            raise


def activate(root: Path, name: str) -> None:
    root = private_directory(root)
    with locked(root):
        inspect(root, name)
        current = root / 'current'
        if current.exists() or current.is_symlink():
            protected_file(current, 128)
        descriptor, temporary = tempfile.mkstemp(prefix='.current-', dir=root)
        try:
            with os.fdopen(descriptor, 'wb') as output:
                output.write((name + '\n').encode('ascii'))
                output.flush()
                os.fsync(output.fileno())
            os.replace(temporary, current)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)


def status(root: Path) -> dict[str, object]:
    root = private_directory(root)
    current = root / 'current'
    if not current.exists() and not current.is_symlink():
        return {'state': 'absent'}
    name = protected_file(current, 128).decode('ascii').strip()
    metadata = inspect(root, name)
    return {'state': 'ready', 'runtime': name, 'ansible_core': metadata['ansible_core']}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runtime', type=Path, required=True, help='private persistent directory, mounted at a stable path')
    commands = parser.add_subparsers(dest='action', required=True)
    add = commands.add_parser('install', help='install a new environment from a locked offline wheelhouse')
    add.add_argument('--wheelhouse', type=Path, required=True)
    select = commands.add_parser('activate', help='select an installed environment without deleting others')
    select.add_argument('identifier')
    commands.add_parser('status', help='report the selected environment')
    args = parser.parse_args()
    try:
        if os.geteuid() == 0:
            raise RuntimeErrorSafe('run this tool as a non-privileged user')
        if args.action == 'install':
            print(json.dumps({'installed': install(args.runtime, args.wheelhouse)}))
        elif args.action == 'activate':
            activate(args.runtime, args.identifier)
            print(json.dumps(status(args.runtime)))
        else:
            print(json.dumps(status(args.runtime)))
        return 0
    except (RuntimeErrorSafe, OSError, ValueError, UnicodeError, subprocess.SubprocessError):
        print('Ansible runtime operation refused or failed; check the private volume, lock and compatibility.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
