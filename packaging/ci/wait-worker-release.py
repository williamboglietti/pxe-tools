#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Wait a bounded time for a deployed InRelease to appear publicly."""
from pathlib import Path
import sys
import time
import urllib.error
import urllib.request

URL = 'https://packages.pxetools.com/apt/dists/stable/InRelease'


def wait(expected: bytes, *, attempts: int = 12, delay: int = 10) -> None:
    for attempt in range(attempts):
        try:
            req = urllib.request.Request(URL, headers={'User-Agent': 'curl/8.14.1', 'Cache-Control': 'no-cache'})
            with urllib.request.urlopen(req, timeout=15) as response:
                data = response.read(1024 * 1024 + 1)
                if response.status == 200 and data == expected:
                    return
        except (urllib.error.URLError, TimeoutError):
            pass
        if attempt + 1 < attempts:
            time.sleep(delay)
    raise ValueError('expected signed metadata did not appear before the deadline')


if __name__ == '__main__':
    try:
        wait(Path(sys.argv[1]).read_bytes())
    except (OSError, ValueError) as exc:
        raise SystemExit(str(exc))
