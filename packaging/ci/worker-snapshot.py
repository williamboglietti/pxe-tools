#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Recover the exact deployed snapshot from persistent GitHub release assets."""
from __future__ import annotations
import argparse
import io
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile
import urllib.error
import urllib.request

spec_http = importlib.util.spec_from_file_location('pxe_public_http', Path(__file__).with_name('public-http.py'))
if spec_http is None or spec_http.loader is None:
    raise RuntimeError('Public HTTPS helper unavailable')
public_http = importlib.util.module_from_spec(spec_http)
spec_http.loader.exec_module(public_http)

TAG = Path('packaging/ci/snapshot-release-tag.txt').read_text().strip()
if not re.fullmatch(r'apt-snapshots(?:-[a-z0-9]+)*', TAG):
    raise ValueError('invalid persistent snapshot release tag')
RELEASE_URL = 'https://packages.pxetools.com/apt/dists/stable/InRelease'
MAX_SNAPSHOT = 512 * 1024 * 1024


def gh(*args: str) -> bytes:
    return subprocess.run(['gh', *args], check=True, stdout=subprocess.PIPE).stdout


def live_release() -> bytes | None:
    status, content = public_http.fetch()
    if status == 404:
        return None
    if status != 200:
        raise ValueError(f'Public domain returned HTTP {status}')
    return content



def validate_recovered(root: Path, keyring: Path, primary: str, signing: str) -> None:
    spec = importlib.util.spec_from_file_location('static_apt', 'packaging/native/prepare-static-apt.py')
    if spec is None or spec.loader is None:
        raise ValueError('snapshot verifier unavailable')
    verifier = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(verifier)
    verifier.validate(root, keyring, primary, signing, allow_expired=True)


def recover(destination: Path, bootstrap: bool, *, keyring: Path | None = None,
            primary: str | None = None, signing: str | None = None) -> None:
    # An alternate trust anchor is accepted only as a complete, explicit set.
    # The production workflow passes none of these and retains its fixed pins.
    alternate = (keyring, primary, signing)
    if any(value is not None for value in alternate):
        if (not all(value is not None for value in alternate)
                or not all(re.fullmatch(r'(?:[0-9A-F]{40}|[0-9A-F]{64})', value or '')
                           for value in (primary, signing))
                or keyring.is_symlink() or not keyring.is_file()):
            raise ValueError('complete explicit snapshot verification identities are required')
    else:
        keyring = Path('packaging/ci/pxe-tools-public.gpg')
        primary = 'CD8B4493876B4FBD1009FE2DB195E5DE026807EC'
        signing = '76CA2883C9DACEB52D9E806A1F2D96C9062271AB'
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
    if not isinstance(metadata.get('id'), int):
        raise ValueError('snapshot release ID missing')
    pages = json.loads(gh('api', '--paginate', '--slurp',
                          f'repos/{repository}/releases/{metadata["id"]}/assets?per_page=100'))
    if not isinstance(pages, list) or any(not isinstance(page, list) for page in pages):
        raise ValueError('invalid snapshot asset pagination')
    all_assets = [asset for page in pages for asset in page]
    if len(all_assets) >= 950:
        raise ValueError('snapshot release is near its asset limit; rotate before publication')
    assets = [a for a in all_assets
              if re.fullmatch(r'snapshot-[0-9]+-[0-9]+\.tar\.gz', a.get('name', ''))]
    # A DNS, TLS or network failure is never evidence that no repository
    # exists: bootstrap requires an explicit HTTP 404 from the HTTPS origin.
    live = live_release()
    if live is None:
        if bootstrap and not assets:
            return
        raise ValueError('live InRelease is missing while prior snapshots exist')
    # Never fall back to an older matching public response: an edge cache or
    # propagation delay could otherwise silently discard a later deployment.
    # A failed deploy after snapshot upload requires operator investigation.
    assets.sort(key=lambda item: tuple(map(int, re.fullmatch(
        r'snapshot-([0-9]+)-([0-9]+)\.tar\.gz', item['name']).groups())), reverse=True)
    current_run = os.environ.get('GITHUB_RUN_ID')
    if current_run and assets:
        latest_run = int(re.fullmatch(r'snapshot-([0-9]+)-([0-9]+)\.tar\.gz', assets[0]['name']).group(1))
        if not current_run.isdecimal() or latest_run >= int(current_run):
            raise ValueError('publication run is older than the latest stored snapshot')
    for asset in assets[:1]:
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
            if destination.exists() or destination.is_symlink():
                raise ValueError('snapshot destination already exists')
            # Never expose an extracted snapshot until every signature and object
            # has passed validation. A failed check leaves no recovery directory.
            destination.parent.mkdir(parents=True, exist_ok=True)
            with tempfile.TemporaryDirectory(prefix='.pxe-recover-', dir=destination.parent) as temporary:
                staging = Path(temporary)
                archive.extractall(staging, filter='data')
                validate_recovered(staging / 'snapshot', keyring, primary, signing)
                destination.mkdir(mode=0o700)
                try:
                    os.rename(staging / 'snapshot', destination / 'snapshot')
                except BaseException:
                    destination.rmdir()
                    raise
            print('Recovered the snapshot matching the live signed metadata.')
            return
    raise ValueError('no persistent snapshot matches the live InRelease')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    parser.add_argument('--bootstrap', action='store_true')
    parser.add_argument('--verification-keyring', type=Path, help='explicit public keyring for a rehearsed transition')
    parser.add_argument('--verification-primary', help='complete expected primary fingerprint')
    parser.add_argument('--verification-signing', help='complete expected signing subkey fingerprint')
    args = parser.parse_args()
    recover(args.destination, args.bootstrap, keyring=args.verification_keyring,
            primary=args.verification_primary, signing=args.verification_signing)
