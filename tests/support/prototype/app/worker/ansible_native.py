# SPDX-License-Identifier: LGPL-3.0-only
"""Identify the explicitly selected distribution Ansible runtime.

This is a freshness identifier for the installed package and entry point, not
an attestation of all dependencies or an isolation boundary.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

PROGRAM = Path('/usr/bin/ansible-playbook')


class NativeRuntimeError(Exception):
    """The distribution runtime is missing or cannot be identified safely."""


def status() -> tuple[str, str]:
    """Return an identifier and Ansible Core version for the system package."""
    try:
        target = PROGRAM.resolve(strict=True)
        if not (target.is_relative_to('/usr/bin') or target.is_relative_to('/usr/lib')):
            raise NativeRuntimeError('unexpected Ansible entry point')
        info = target.stat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022
                or not info.st_mode & 0o111 or info.st_size > 4 * 1024 * 1024):
            raise NativeRuntimeError('untrusted Ansible entry point')
        package = subprocess.run(['/usr/bin/dpkg-query', '-W', '-f=${Status}\n${Version}\n${Architecture}\n', 'ansible-core'],
                                 stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                 check=True, text=True, timeout=5, cwd='/',
                                 env={'PATH':'/usr/bin:/bin', 'LANG':'C', 'HOME':'/nonexistent'}).stdout
        fields = package.splitlines()
        if (len(fields) != 3 or fields[0] != 'install ok installed'
                or not re.fullmatch(r'[0-9A-Za-z.+:~_-]{1,128}', fields[1])
                or not re.fullmatch(r'[a-z0-9-]{1,32}', fields[2])):
            raise NativeRuntimeError('invalid package status')
        with tempfile.TemporaryDirectory(prefix='pxe-ansible-check-') as private:
            config = Path(private) / 'ansible.cfg'
            config.write_text('[defaults]\n', encoding='ascii')
            config.chmod(0o600)
            environment = {'PATH':'/usr/bin:/bin', 'LANG':'C.UTF-8', 'HOME':private, 'ANSIBLE_LOCAL_TEMP':private,
                           'ANSIBLE_CONFIG':str(config), 'PYTHONNOUSERSITE':'1', 'PYTHONDONTWRITEBYTECODE':'1'}
            output = subprocess.run([str(PROGRAM), '--version'], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                    stderr=subprocess.DEVNULL, check=True, text=True, timeout=10, cwd='/', env=environment).stdout
        first = output.splitlines()[0] if output else ''
        match = re.fullmatch(r'ansible-playbook \[core ([0-9][A-Za-z0-9.+!_-]{0,99})\]', first)
        if match is None:
            raise NativeRuntimeError('unrecognized Ansible version')
        with target.open('rb') as stream:
            executable_hash = hashlib.file_digest(stream, 'sha256').hexdigest()
        descriptor = {'package_version':fields[1], 'architecture':fields[2], 'entrypoint_sha256':executable_hash,
                      'worker_python':sys.version.split()[0]}
        runtime = 'sys-' + hashlib.sha256(json.dumps(descriptor, sort_keys=True, separators=(',', ':')).encode('ascii')).hexdigest()
        return runtime, match.group(1)
    except NativeRuntimeError:
        raise
    except (OSError, ValueError, UnicodeError, IndexError, subprocess.SubprocessError) as error:
        raise NativeRuntimeError('distribution Ansible runtime unavailable') from error
