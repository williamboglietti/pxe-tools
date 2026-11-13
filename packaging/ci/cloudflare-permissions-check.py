#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Read only: confirm scoped Worker access without printing token or response."""
import json
import os
import re
import urllib.error
import urllib.request


def status(account: str, token: str, worker: str) -> int:
    url = f'https://api.cloudflare.com/client/v4/accounts/{account}/workers/scripts/{worker}/script-settings'
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token, 'User-Agent': 'PXE-Tools-CI'})
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            payload = json.loads(response.read(1024 * 1024))
            return response.status if payload.get('success') is True else 0
    except urllib.error.HTTPError as exc:
        return exc.code
    except (OSError, ValueError, TypeError):
        return 0


def main() -> None:
    account = os.environ.get('CLOUDFLARE_ACCOUNT_ID', '')
    token = os.environ.get('CLOUDFLARE_API_TOKEN', '')
    if not re.fullmatch(r'[0-9a-f]{32}', account) or not token:
        raise SystemExit('Cloudflare credentials are missing or invalid')
    if status(account, token, 'packages-pxetools-com') != 200:
        raise SystemExit('Dedicated Worker access was not confirmed')
    if status(account, token, 'www-pxetools-com') not in (403, 404):
        raise SystemExit('Access to the other Worker was not denied as expected')
    print('Dedicated Worker read access and other Worker denial confirmed; no writes performed.')


if __name__ == '__main__': main()
