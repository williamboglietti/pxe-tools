# SPDX-License-Identifier: LGPL-3.0-only
"""Extend the disposable installer mirror to signed Debian binary packages.

The caller must authenticate the Release first. Only paths in its validated
indexes can be fetched from the fixed Debian origin; no redirects are accepted.
"""
import hashlib
import lzma
from pathlib import Path
import re
from urllib.request import Request
from urllib.parse import unquote

from qemu_debian_mirror import FixtureMirror

MAX_INDEX = 256 * 1024 * 1024
MAX_PACKAGE = 256 * 1024 * 1024
PATH = re.compile(r'pool/[A-Za-z0-9_+./~%-]+\.deb')


def binary_records(compressed: bytes) -> dict[str, tuple[str, int]]:
    if len(compressed) > 32 * 1024 * 1024:
        raise ValueError('compressed binary index too large')
    decoder = lzma.LZMADecompressor(memlimit=MAX_INDEX)
    raw = decoder.decompress(compressed, max_length=MAX_INDEX + 1)
    if len(raw) > MAX_INDEX or not decoder.eof or decoder.unused_data:
        raise ValueError('binary index too large')
    entries = {}
    for paragraph in raw.decode('utf-8').split('\n\n'):
        fields = dict(line.split(': ', 1) for line in paragraph.splitlines()
                      if ': ' in line and not line.startswith(' '))
        name, digest, size = fields.get('Filename', ''), fields.get('SHA256', ''), fields.get('Size', '')
        if not name:
            continue
        if not PATH.fullmatch(name) or '..' in name.split('/'):
            raise ValueError('invalid binary package path')
        if not re.fullmatch(r'[a-fA-F0-9]{64}', digest):
            raise ValueError('invalid binary package digest')
        if not size.isdigit() or not 0 < int(size) <= 32 * 1024 * 1024 * 1024:
            raise ValueError('invalid binary package size')
        value = (digest.lower(), int(size))
        if name in entries and entries[name] != value:
            raise ValueError('ambiguous package path')
        entries[name] = value
    return entries


class FullInstallMirror(FixtureMirror):
    def __init__(self, root: Path, release: Path, keyring: Path, preseed: bytes, binary_index: Path):
        super().__init__(root, release, keyring, preseed)
        data = binary_index.read_bytes()
        expected = self.release_entries.get('main/binary-amd64/Packages.xz')
        actual = (hashlib.sha256(data).hexdigest(), len(data))
        if expected != actual:
            raise ValueError('binary index does not match the authenticated Release')
        for path, value in binary_records(data).items():
            if path in self.package_entries and self.package_entries[path] != value:
                raise ValueError('package path conflicts with installer index')
            self.package_entries[path] = value

    def _fetch_unsigned(self, path: str, limit: int) -> Path:
        if path.startswith('/') or '..' in path.split('/') or limit > MAX_PACKAGE:
            raise ValueError('invalid fixture download')
        dest = self.root / hashlib.sha256(path.encode()).hexdigest()
        if dest.exists():
            return dest
        url = self.base + path
        with self.opener.open(Request(url, headers={'User-Agent':'PXE-Tools-isolated-test/0.1'}), timeout=60) as response:
            if response.geturl() != url:
                raise ValueError('unexpected Debian download location')
            with dest.open('xb') as output:
                size = 0
                while chunk := response.read(256 * 1024):
                    size += len(chunk)
                    if size > limit:
                        raise ValueError('Debian file exceeds fixture limit')
                    output.write(chunk)
        return dest

    def _fetch_signed(self, path: str, metadata: tuple[str, int]) -> Path:
        digest, size = metadata
        if not 0 < size <= MAX_PACKAGE:
            raise ValueError('invalid signed file size')
        dest = self._fetch_unsigned(path, size)
        with dest.open('rb') as source:
            if dest.stat().st_size != size or hashlib.file_digest(source, 'sha256').hexdigest() != digest:
                raise ValueError('signed file checksum mismatch')
        return dest

    def file_for(self, request: str):
        match = re.fullmatch(r'/debian/dists/trixie/(main/binary-(?:amd64|all))/by-hash/SHA256/([a-f0-9]{64})', request)
        if match:
            directory, wanted = match.groups()
            matches = [(path, value) for path, value in self.release_entries.items()
                       if path.startswith(directory + '/') and value[0] == wanted]
            if not matches or any(value != matches[0][1] for _, value in matches):
                raise ValueError('unrecognized signed index hash')
            return None, self._fetch_signed('dists/trixie/' + matches[0][0], matches[0][1])
        if request.startswith('/debian/pool/') and '?' not in request:
            path = request[len('/debian/'):]
            # APT percent-encodes characters such as '+' and '~', while the
            # authenticated index may list either literal or encoded names.
            # Keep exact matches first, then accept a decoded path only when
            # the resulting exact path occurs in the authenticated index.
            if path not in self.package_entries:
                if re.search(r'%(?![0-9a-fA-F]{2})|%2[fF]|%5[cC]', path):
                    raise ValueError('invalid encoded package path')
                path = unquote(path, encoding='ascii', errors='strict')
            if path in self.package_entries:
                return None, self._fetch_signed(path, self.package_entries[path])
        return super().file_for(request)
