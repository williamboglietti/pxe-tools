#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Recover the exact deployed snapshot from persistent GitHub release assets."""
from __future__ import annotations
import argparse
import io
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import urllib.error
import urllib.request

TAG = 'apt-snapshots'
RELEASE_URL = 'https://packages.pxetools.com/apt/dists/stable/InRelease'
MAX_SNAPSHOT = 512 * 1024 * 1024


def gh(*args: str) -> bytes:
    return subprocess.run(['gh', *args], check=True, stdout=subprocess.PIPE).stdout


def live_release() -> bytes | None:
    request = urllib.request.Request(RELEASE_URL, headers={'User-Agent': 'curl/8.14.1', 'Cache-Control': 'no-cache'})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            content = response.read(1024 * 1024 + 1)
            if len(content) > 1024 * 1024:
                raise ValueError('live InRelease too large')
            return content
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return None
        raise


def recover(destination: Path, bootstrap: bool) -> None:
    repository = os.environ['GITHUB_REPOSITORY']
    release = subprocess.run(['gh', 'api', f'repos/{repository}/releases/tags/{TAG}'],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if release.returncode:
        # Fail closed on API errors. A missing snapshot release is created explicitly,
        # outside this script, before enabling the publication workflow.
        raise ValueError('persistent snapshot release is unavailable')
    metadata = json.loads(release.stdout)
    if metadata.get('draft') or metadata.get('tag_name') != TAG:
        raise ValueError('snapshot release must be published under the fixed tag')
    assets = [a for a in metadata.get('assets', [])
              if re.fullmatch(r'snapshot-[0-9]+-[0-9]+\.tar\.gz', a.get('name', ''))]
    # A DNS, TLS or network failure is never evidence that no repository
    # exists: bootstrap requires an explicit HTTP 404 from the HTTPS origin.
    live = live_release()
    if live is None:
        if bootstrap and not assets:
            return
        raise ValueError('live InRelease is missing while prior snapshots exist')
    for asset in reversed(assets):
        size = asset.get('size', 0)
        if not isinstance(size, int) or size <= 0 or size > MAX_SNAPSHOT:
            raise ValueError('snapshot asset size is invalid')
        data = gh('api', '-H', 'Accept: application/octet-stream',
                  f'repos/{repository}/releases/assets/{asset["id"]}')
        if len(data) != size:
            raise ValueError('snapshot download size mismatch')
        import hashlib
        if asset.get('digest') != 'sha256:' + hashlib.sha256(data).hexdigest():
            raise ValueError('snapshot release digest mismatch')
        with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as archive:
            members = archive.getmembers()
            if len(members) > 20000 or sum(x.size for x in members) > MAX_SNAPSHOT:
                raise ValueError('snapshot archive is too large')
            for member in members:
                path = Path(member.name)
                if (path.is_absolute() or '..' in path.parts or not path.parts
                        or path.parts[0] != 'snapshot' or not (member.isfile() or member.isdir())):
                    raise ValueError('unsafe snapshot archive')
            try:
                signed = archive.extractfile('snapshot/dists/stable/InRelease')
            except KeyError:
                continue
            if signed is None or signed.read(1024 * 1024 + 1) != live:
                continue
            if destination.exists():
                raise ValueError('snapshot destination already exists')
            destination.mkdir(parents=True)
            archive.extractall(destination, filter='data')
            print('Recovered the snapshot matching the live signed metadata.')
            return
    raise ValueError('no persistent snapshot matches the live InRelease')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    parser.add_argument('--bootstrap', action='store_true')
    args = parser.parse_args()
    recover(args.destination, args.bootstrap)
