#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Start the worker with a bounded workaround for the old Ubuntu keyring."""
import os
from pathlib import Path
import subprocess

FALLBACK = '/usr/lib/pxe-tools/native/keyrings/debian-archive-2025.1.gpg'
WORKER = '/usr/lib/pxe-tools/app/worker.py'


def worker_environment(environment, os_release, keyring_version):
    result = dict(environment)
    # An administrator-supplied keyring is always authoritative, including an
    # invalid/empty path: the verifier then fails closed instead of overriding it.
    if 'DEBIAN_ARCHIVE_KEYRING' in result:
        return result
    if ((os_release.get('ID'), os_release.get('VERSION_ID')) in
            (('ubuntu', '24.04'), ('zorin', '18'))
            and keyring_version == '2023.4ubuntu1'):
        result['DEBIAN_ARCHIVE_KEYRING'] = FALLBACK
    return result


def main():
    # os-release and the package database are controlled by the host admin.
    release = {}
    for line in Path('/etc/os-release').read_text().splitlines():
        key, sep, value = line.partition('=')
        if sep and key in ('ID', 'VERSION_ID'):
            release[key] = value.strip().strip('"').strip("'")
    query = subprocess.run(['dpkg-query', '-W', '-f=${Version}', 'debian-archive-keyring'],
                           stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                           text=True, check=False)
    version = query.stdout.strip() if query.returncode == 0 else ''
    os.execve('/usr/bin/python3', ['/usr/bin/python3', WORKER],
              worker_environment(os.environ, release, version))


if __name__ == '__main__':
    main()
