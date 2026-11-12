# SPDX-License-Identifier: LGPL-3.0-only
"""Fetch Debian 13 amd64 netboot files privately, then verify and publish them.

This does not activate a boot configuration or start a service. Trust in the
archive keyring and maintenance of its revocations are the caller's job.
"""
from __future__ import annotations

from pathlib import Path
import posixpath
import tempfile
import time
from urllib.error import URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

from .publish import _roots, publish_debian_netboot
from .verify import VerificationError

BASE = "https://deb.debian.org/debian/dists/trixie/"
FILES = (
    ("InRelease", BASE + "InRelease", 8 * 1024 * 1024),
    ("SHA256SUMS", BASE + "main/installer-amd64/current/images/SHA256SUMS", 8 * 1024 * 1024),
    ("linux", BASE + "main/installer-amd64/current/images/netboot/debian-installer/amd64/linux", 128 * 1024 * 1024),
    ("initrd.gz", BASE + "main/installer-amd64/current/images/netboot/debian-installer/amd64/initrd.gz", 256 * 1024 * 1024),
)


def _allowed_url(url: str) -> bool:
    try:
        parts = urlsplit(url)
        return (parts.scheme == "https" and parts.hostname == "deb.debian.org"
                and parts.port in (None, 443) and parts.username is None and parts.password is None
                and not parts.query and not parts.fragment and "%" not in parts.path
                and posixpath.normpath(parts.path) == parts.path
                and parts.path.startswith("/debian/dists/trixie/"))
    except ValueError:
        return False


class _DebianRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not _allowed_url(newurl):
            raise VerificationError("download redirect is outside the Debian archive")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download_and_publish(keyring: Path, staging_root: Path, served_root: Path, *, _opener=None) -> Path:
    """Fetch a fixed Debian netboot set and publish it only after verification.

    Existing published versions and the active boot configuration are untouched.
    The caller must protect the supplied keyring and its signing-key lifecycle.
    """
    stage_root, _ = _roots(staging_root, served_root)
    opener = _opener if _opener is not None else build_opener(ProxyHandler({}), _DebianRedirect())
    try:
        with tempfile.TemporaryDirectory(prefix="pxe-download-", dir=stage_root) as directory:
            temp = Path(directory)
            deadline = time.monotonic() + 900
            for name, url, maximum in FILES:
                if not _allowed_url(url):
                    raise VerificationError("invalid download URL")
                request = Request(url, headers={"User-Agent": "PXE-Tools-netboot/0.1"})
                with opener.open(request, timeout=30) as response, (temp / name).open("xb") as output:
                    if not _allowed_url(response.geturl()):
                        raise VerificationError("download response is outside the Debian archive")
                    size = 0
                    while True:
                        if time.monotonic() > deadline:
                            raise VerificationError("download timed out")
                        chunk = response.read(1024 * 1024)
                        if not chunk:
                            break
                        size += len(chunk)
                        if size > maximum:
                            raise VerificationError("download exceeds size limit")
                        output.write(chunk)
                    if size == 0:
                        raise VerificationError("download is empty")
            return publish_debian_netboot(temp / "linux", temp / "initrd.gz", temp / "SHA256SUMS",
                                          temp / "InRelease", keyring, stage_root, served_root)
    except VerificationError:
        raise
    except (OSError, URLError, ValueError) as error:
        raise VerificationError("download could not be completed") from error
