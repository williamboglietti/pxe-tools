#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Log only HTTP statuses and transport error types, never response content."""
import urllib.request
import urllib.error
import importlib.util
from pathlib import Path
spec = importlib.util.spec_from_file_location('public_http', Path(__file__).with_name('public-http.py'))
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
try:
    request = urllib.request.Request(helper.URL, headers={'User-Agent': 'curl/8.14.1', 'Cache-Control': 'no-cache'})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            print('urllib HTTP', response.status)
    except urllib.error.HTTPError as exc:
        print('urllib HTTP', exc.code)
    except urllib.error.URLError as exc:
        print('urllib transport error:', type(exc.reason).__name__)
except (OSError, TimeoutError) as exc:
    print('urllib transport error:', type(exc).__name__)
try:
    status, _ = helper.fetch()
    print('curl HTTP', status)
except (OSError, ValueError) as exc:
    print('curl transport error:', type(exc).__name__, str(exc) if isinstance(exc, ValueError) else '')
