#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fetch the fixed public APT endpoint with bounded curl and no secret headers."""
from pathlib import Path
import re
import subprocess
import tempfile

URL = 'https://packages.pxetools.com/apt/dists/stable/InRelease'
LIMIT = 1024 * 1024


def fetch() -> tuple[int, bytes]:
    with tempfile.TemporaryDirectory(prefix='pxe-public-http-') as temporary:
        target = Path(temporary) / 'body'
        result = subprocess.run(['curl', '--proto', '=https', '--silent', '--show-error',
                                 '--max-time', '20', '--max-filesize', str(LIMIT),
                                 '--user-agent', 'curl/8.14.1', '--header', 'Cache-Control: no-cache',
                                 '--output', str(target), '--write-out', '%{http_code}', URL],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=False)
        if result.returncode:
            raise ValueError(f'Public HTTPS request failed (curl exit {result.returncode})')
        status = result.stdout.decode('ascii', 'strict')
        if not re.fullmatch(r'[1-5][0-9]{2}', status):
            raise ValueError('Public HTTPS request returned an invalid status')
        if not target.is_file() or target.stat().st_size > LIMIT:
            raise ValueError('Public HTTPS response body is missing or too large')
        return int(status), target.read_bytes()
