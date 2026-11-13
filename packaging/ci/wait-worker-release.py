#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Wait a bounded time for a deployed InRelease to appear publicly."""
from pathlib import Path
import importlib.util
import sys
import time
import urllib.error
import urllib.request

spec_http = importlib.util.spec_from_file_location('pxe_public_http', Path(__file__).with_name('public-http.py'))
if spec_http is None or spec_http.loader is None:
    raise RuntimeError('Public HTTPS helper unavailable')
public_http = importlib.util.module_from_spec(spec_http)
spec_http.loader.exec_module(public_http)




def wait(expected: bytes, *, attempts: int = 12, delay: int = 10) -> None:
    for attempt in range(attempts):
        try:
            status, data = public_http.fetch()
            if status == 200 and data == expected:
                return
        except (OSError, ValueError):
            pass
        if attempt + 1 < attempts:
            time.sleep(delay)
    raise ValueError('expected signed metadata did not appear before the deadline')


if __name__ == '__main__':
    try:
        wait(Path(sys.argv[1]).read_bytes())
    except (OSError, ValueError) as exc:
        raise SystemExit(str(exc))
