#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Start the worker with a bounded workaround for the old Ubuntu keyring."""
import os
from pathlib import Path
import subprocess

FALLBACK = '/usr/lib/pxe-tools/native/keyrings/debian-archive-2025.1.gpg'
WORKER = '/usr/lib/pxe-tools/app/worker.py'
BASE_HELPER = Path('/opt/pxe-tools/scripts/host-base.sh')
if not BASE_HELPER.exists():
    BASE_HELPER = Path(__file__).resolve().parents[2] / 'docker/worker/scripts/host-base.sh'


def worker_environment(environment, os_release, keyring_version, *, detected=None):
    result = dict(environment)
    # An administrator-supplied keyring is always authoritative, including an
    # invalid/empty path: the verifier then fails closed instead of overriding it.
    if 'DEBIAN_ARCHIVE_KEYRING' in result:
        return result
    if detected is None:
        detected = subprocess.run(['/bin/sh', '-c', '. "$1"; pxe_tools_detect_base', 'pxe', str(BASE_HELPER)],
                                  env=os_release, stdout=subprocess.PIPE, text=True, check=True).stdout.strip()
    if (detected == 'ubuntu:noble'
            and keyring_version == '2023.4ubuntu1'):
        result['DEBIAN_ARCHIVE_KEYRING'] = FALLBACK
    return result


def main():
    # os-release and the package database are controlled by the host admin.
    detected = subprocess.run(['/bin/sh', str(BASE_HELPER), '--detect'],
                              stdout=subprocess.PIPE, text=True, check=True).stdout.strip()
    query = subprocess.run(['dpkg-query', '-W', '-f=${Version}', 'debian-archive-keyring'],
                           stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                           text=True, check=False)
    version = query.stdout.strip() if query.returncode == 0 else ''
    os.execve('/usr/bin/python3', ['/usr/bin/python3', WORKER],
              worker_environment(os.environ, {}, version, detected=detected))


if __name__ == '__main__':
    main()
