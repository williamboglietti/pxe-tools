# SPDX-License-Identifier: LGPL-3.0-only
"""A private Unix-socket Debian mirror for disposable installer tests.

Only paths authenticated by the supplied Release and installer indices may be
fetched. The guest cannot choose a destination host or reach the host LAN.
"""
from __future__ import annotations

import hashlib
import json
from http.server import BaseHTTPRequestHandler
from pathlib import Path
import re
import socketserver
import subprocess
import sys
import threading
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

from artifacts.debian_netboot import _clearsigned_payload


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('unexpected Debian mirror redirect')


class FixtureMirror:
    def __init__(self, root: Path, release: Path, keyring: Path, preseed: bytes):
        self.root, self.release, self.keyring, self.preseed = root, release, keyring, preseed
        self.base = 'https://deb.debian.org/debian/'
        self.opener = build_opener(ProxyHandler({}), NoRedirect())
        self.release_entries: dict[str, tuple[str, int]] = {}
        self.package_entries: dict[str, tuple[str, int]] = {}
        self.requests: list[str] = []
        self.errors: list[str] = []
        self.lock = threading.Lock()
        in_sha = False
        for line in _clearsigned_payload(release.read_bytes()):
            if line == 'SHA256:':
                in_sha = True
                continue
            if in_sha and not line.startswith(' '):
                in_sha = False
            if in_sha:
                match = re.fullmatch(r'\s+([a-fA-F0-9]{64})\s+(\d+)\s+(\S+)', line)
                if match:
                    path, metadata = match.group(3), (match.group(1).lower(), int(match.group(2)))
                    if path in self.release_entries:
                        raise ValueError('duplicate release path')
                    self.release_entries[path] = metadata
        for arch in ('amd64', 'all'):
            path = 'main/debian-installer/binary-' + arch + '/Packages.xz'
            compressed = self._fetch_signed('dists/trixie/' + path, self.release_entries[path])
            # The authenticated index is parsed in a short-lived process. Its
            # decompressed text and allocator arenas are gone before QEMU runs.
            result = subprocess.run([sys.executable, str(Path(__file__).with_name('debian_installer_index.py')), str(compressed)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False)
            if result.returncode != 0 or len(result.stdout) > 32 * 1024 * 1024:
                raise ValueError('invalid installer package index')
            parsed = json.loads(result.stdout)
            for name, values in parsed.items():
                metadata = values[0], values[1]
                if name in self.package_entries and self.package_entries[name] != metadata:
                    raise ValueError('ambiguous installer package path')
                self.package_entries[name] = metadata
        self.release_plain = self._fetch_unsigned('dists/trixie/Release', 4 * 1024 * 1024)
        signature = self._fetch_unsigned('dists/trixie/Release.gpg', 65536)
        subprocess.run(['gpgv', '--keyring', str(keyring), str(signature), str(self.release_plain)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)

    def _fetch_unsigned(self, path: str, limit: int) -> Path:
        dest = self.root / hashlib.sha256(path.encode()).hexdigest()
        if dest.exists():
            return dest
        url = self.base + path
        with self.opener.open(Request(url, headers={'User-Agent': 'PXE-Tools-isolated-test/0.1'}), timeout=60) as response:
            if response.geturl() != url:
                raise ValueError('unexpected Debian download location')
            data = response.read(limit + 1)
            if len(data) > limit:
                raise ValueError('Debian download exceeds limit')
        dest.write_bytes(data)
        return dest

    def _fetch_signed(self, path: str, metadata: tuple[str, int]) -> Path:
        digest, size = metadata
        if not 0 < size <= 32 * 1024 * 1024:
            raise ValueError('Debian file exceeds fixture limit')
        dest = self._fetch_unsigned(path, size)
        with dest.open('rb') as stream:
            if dest.stat().st_size != size or hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                raise ValueError('Debian file checksum mismatch')
        return dest

    def file_for(self, request: str) -> tuple[bytes | None, Path | None]:
        if request == '/preseed.cfg':
            return self.preseed, None
        if not request.startswith('/debian/') or '?' in request or '%' in request or '..' in request.split('/'):
            raise ValueError('unexpected fixture path')
        path = request[len('/debian/'):]
        if path == 'dists/trixie/InRelease':
            return None, self.release
        if path == 'dists/trixie/Release':
            return None, self.release_plain
        if path == 'dists/trixie/Release.gpg':
            return None, self._fetch_unsigned(path, 65536)
        if path.startswith('dists/trixie/'):
            relative = path[len('dists/trixie/'):]
            if relative in self.release_entries:
                return None, self._fetch_signed(path, self.release_entries[relative])
        if path in self.package_entries:
            return None, self._fetch_signed(path, self.package_entries[path])
        raise ValueError('file is not in the authenticated installer indices')

    def start(self, socket_path: Path) -> None:
        fixture = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, fmt, *args):
                pass
            def do_GET(self):
                with fixture.lock:
                    fixture.requests.append(self.path)
                    with (fixture.root / 'requests.log').open('a', encoding='utf-8') as events:
                        events.write(self.path + '\n')
                    try:
                        data, file = fixture.file_for(self.path)
                    except Exception as error:
                        fixture.errors.append(self.path + ': ' + type(error).__name__)
                        self.send_error(404)
                        return
                length = len(data) if data is not None else file.stat().st_size
                self.send_response(200)
                self.send_header('Content-Length', str(length))
                self.end_headers()
                try:
                    if data is not None:
                        self.wfile.write(data)
                    else:
                        with file.open('rb') as source:
                            while chunk := source.read(256 * 1024):
                                self.wfile.write(chunk)
                except (BrokenPipeError, ConnectionResetError):
                    pass
        class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
            daemon_threads = True
        self.server = Server(str(socket_path), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def close(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=5)
