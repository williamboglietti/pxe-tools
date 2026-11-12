# SPDX-License-Identifier: LGPL-3.0-only
"""Download and build a verified Debian BIOS/UEFI bundle without activation."""
from __future__ import annotations

from pathlib import Path
import posixpath
import re
import tempfile
import time
from urllib.error import URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

from .boot_bundle import (MAX_INDEX, _package_records, _validate_server_ip, build_debian_bundle)
from .debian_netboot import verify_debian_netboot
from .download import BASE, FILES, _allowed_url
from .publish import _roots
from .verify import VerificationError

INDEX_URL = BASE + 'main/binary-amd64/Packages.xz'
MIRROR_ROOT = 'https://deb.debian.org/debian/'


def _allowed_package_url(url: str) -> bool:
    try:
        parts = urlsplit(url)
        return (parts.scheme == 'https' and parts.hostname == 'deb.debian.org'
                and parts.port in (None, 443) and parts.username is None and parts.password is None
                and not parts.query and not parts.fragment and '%' not in parts.path
                and posixpath.normpath(parts.path) == parts.path
                and re.fullmatch(r'/debian/pool/[A-Za-z0-9_+./~-]+\.deb', parts.path) is not None)
    except ValueError:
        return False


class _Redirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not (_allowed_url(newurl) or _allowed_package_url(newurl)):
            raise VerificationError('download redirect is outside the Debian archive')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download_and_build_bundle(keyring: Path, staging_root: Path, served_root: Path, server_ip: str, *,
                              _opener=None) -> Path:
    """Fetch signed Debian artifacts and create a new bundle; never activate it."""
    stage_root, _ = _roots(staging_root, served_root)
    _validate_server_ip(server_ip)
    opener = _opener if _opener is not None else build_opener(ProxyHandler({}), _Redirect())
    try:
        with tempfile.TemporaryDirectory(prefix='pxe-bundle-download-', dir=stage_root) as directory:
            temp = Path(directory)
            deadline = time.monotonic() + 1200

            def fetch(name: str, url: str, maximum: int) -> Path:
                if not (_allowed_url(url) or _allowed_package_url(url)):
                    raise VerificationError('invalid download URL')
                target = temp / name
                request = Request(url, headers={'User-Agent': 'PXE-Tools-netboot/0.1'})
                with opener.open(request, timeout=30) as response, target.open('xb') as output:
                    final = response.geturl()
                    if not (_allowed_url(final) or _allowed_package_url(final)):
                        raise VerificationError('download response is outside the Debian archive')
                    size = 0
                    while True:
                        if time.monotonic() > deadline:
                            raise VerificationError('download timed out')
                        chunk = response.read(1024 * 1024)
                        if not chunk:
                            break
                        size += len(chunk)
                        if size > maximum:
                            raise VerificationError('download exceeds size limit')
                        output.write(chunk)
                    if size == 0:
                        raise VerificationError('download is empty')
                return target

            for name, url, limit in FILES:
                fetch(name, url, limit)
            fetch('Packages.xz', INDEX_URL, MAX_INDEX)
            # Do not trust package names or URLs before authenticating Release.
            verify_debian_netboot(temp / 'linux', temp / 'initrd.gz', temp / 'SHA256SUMS', temp / 'InRelease', keyring)
            records = _package_records((temp / 'InRelease').read_bytes(), (temp / 'Packages.xz').read_bytes())
            packages: dict[str, Path] = {}
            for name, record in records.items():
                filename = record.get('Filename', '')
                if not filename.startswith('pool/') or '..' in filename.split('/'):
                    raise VerificationError('invalid signed package path')
                url = MIRROR_ROOT + filename
                if not _allowed_package_url(url):
                    raise VerificationError('invalid signed package path')
                packages[name] = fetch(name + '.deb', url, int(record['Size']))
            return build_debian_bundle(temp / 'linux', temp / 'initrd.gz', temp / 'SHA256SUMS',
                                       temp / 'InRelease', temp / 'Packages.xz', packages, keyring,
                                       stage_root, served_root, server_ip)
    except VerificationError:
        raise
    except (OSError, URLError, ValueError) as error:
        raise VerificationError('boot bundle download could not be completed') from error
