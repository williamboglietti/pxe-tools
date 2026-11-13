#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Check the optional runtime's trust boundaries without executing Ansible."""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pxe_ansible_runtime', ROOT / 'app/ansible_runtime.py')
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


def rejected(function, *args) -> None:
    try:
        function(*args)
    except (runtime.RuntimeErrorSafe, OSError, ValueError):
        return
    raise AssertionError('unsafe input was accepted')


def create_environment(root: Path, name: str) -> Path:
    environment = root / 'runtimes' / name
    (environment / 'bin').mkdir(parents=True, mode=0o700)
    (root / 'runtimes').chmod(0o700)
    environment.chmod(0o700)
    executable = environment / 'bin/ansible-playbook'
    executable.write_text('#!/bin/sh\nexit 0\n')
    executable.chmod(0o700)
    marker = environment / '.pxe-runtime.json'
    marker.write_text(json.dumps({'format': 1, 'ansible_core': '2.21.0', 'lock_sha256': 'a' * 64,
                                  'compatibility': runtime.compatibility()}))
    marker.chmod(0o600)
    return environment


def main() -> None:
    with tempfile.TemporaryDirectory(prefix='pxe-ansible-test-') as temporary:
        root = Path(temporary).resolve() / 'runtime'
        root.mkdir(mode=0o700)
        wheels = root.parent / 'wheelhouse'
        wheels.mkdir()
        lock = wheels / 'requirements.lock'
        good = 'ansible-core==2.21.0 --hash=sha256:' + 'a' * 64 + '\n'
        lock.write_text(good)
        (wheels / 'ansible_core-2.21.0-py3-none-any.whl').write_bytes(b'fixture')
        assert runtime.wheel_lock(wheels) == good.encode()
        for bad in ('ansible-core>=2.21\n', 'ansible-core==2.21.0\n',
                    '--index-url https://example.invalid\n', good + good,
                    'ansible-core==2.21.0 --hash=sha256:' + 'A' * 64 + '\n'):
            lock.write_text(bad)
            rejected(runtime.wheel_lock, wheels)
        lock.write_text(good)
        link = wheels / 'other.whl'
        link.symlink_to(lock)
        rejected(runtime.wheel_lock, wheels)
        link.unlink()
        assert runtime.status(root) == {'state': 'absent'}
        first = create_environment(root, 'env-aaaaaaaa')
        second = create_environment(root, 'env-bbbbbbbb')
        runtime.activate(root, first.name)
        assert runtime.status(root) == {'state': 'ready', 'runtime': first.name, 'ansible_core': '2.21.0'}
        runtime.activate(root, second.name)
        assert runtime.status(root)['runtime'] == second.name and first.is_dir()
        binary = second / 'bin/ansible-playbook'
        binary.chmod(0o777)
        rejected(runtime.status, root)
        binary.chmod(0o700)
        assert runtime.status(root)['runtime'] == second.name
        rejected(runtime.activate, root, '../elsewhere')
        marker = second / '.pxe-runtime.json'
        info = json.loads(marker.read_text())
        info['compatibility']['machine'] = 'different-architecture'
        marker.write_text(json.dumps(info))
        rejected(runtime.status, root)
        marker.write_text(json.dumps({**info, 'compatibility': runtime.compatibility()}))
        (root / 'current').unlink()
        (root / 'current').symlink_to(first / '.pxe-runtime.json')
        rejected(runtime.status, root)
        (root / 'current').unlink()
        runtime.activate(root, first.name)
        # A failed installation removes only its own newly-created directory.
        with patch.object(runtime.subprocess, 'run', side_effect=subprocess.CalledProcessError(1, ['venv'])):
            try:
                runtime.install(root, wheels)
            except subprocess.CalledProcessError:
                pass
            else:
                raise AssertionError('failed installation succeeded')
        assert {item.name for item in (root / 'runtimes').iterdir()} == {first.name, second.name}
        assert runtime.status(root)['runtime'] == first.name
    print('Ansible runtime: locked inputs, private state, compatibility, activation and cleanup passed')


if __name__ == '__main__':
    main()
