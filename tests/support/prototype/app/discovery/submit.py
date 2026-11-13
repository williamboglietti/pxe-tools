#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Submit one inventory with explicit TLS trust and a protected token fd.

This experimental client requires Python 3; it is not yet bundled in Debian
Installer or connected to the PXE menu. It never follows redirects or proxies.
"""
from __future__ import annotations

import argparse
import hashlib
import hmac
import http.client
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import ssl
import sys
from urllib.parse import urlsplit

MAX_REPORT = 1048576
ENDPOINT = '/discovery/v1/reports'


class DeliveryError(Exception):
    """The inventory could not be safely submitted."""


def _address(url: str) -> tuple[str, int]:
    if not isinstance(url, str) or len(url) > 2048 or any(ord(char) < 33 or ord(char) > 126 for char in url):
        raise DeliveryError('invalid endpoint')
    try:
        parsed = urlsplit(url)
        host, port = parsed.hostname, parsed.port
        if port is None:
            port = 443
        if (parsed.scheme != 'https' or parsed.username is not None or parsed.password is not None
                or parsed.path != ENDPOINT or parsed.query or parsed.fragment or not host or not 1 <= port <= 65535):
            raise DeliveryError('invalid endpoint')
        try:
            ipaddress.ip_address(host)
        except ValueError:
            if (len(host) > 253 or re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?', host) is None
                    or '..' in host):
                raise DeliveryError('invalid endpoint')
        return host, port
    except ValueError as error:
        raise DeliveryError('invalid endpoint') from error


def _pinned_context(pem: str) -> ssl.SSLContext:
    context = ssl.create_default_context(cadata=pem)
    if not hasattr(ssl, 'VERIFY_X509_PARTIAL_CHAIN'):
        raise DeliveryError('certificate pinning unavailable')
    context.verify_flags |= ssl.VERIFY_X509_PARTIAL_CHAIN
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context


def pin_server(url: str, expected: str, *, connection_factory=socket.create_connection) -> str:
    """Obtain and verify an exact server certificate before requesting a token.

    The fingerprint must arrive through an independently authenticated channel.
    The initial handshake sends no token or inventory and is used only to read
    a certificate. A second handshake validates its identity and hostname.
    """
    host, port = _address(url)
    if not isinstance(expected, str) or re.fullmatch(r'[A-Fa-f0-9]{64}', expected) is None:
        raise DeliveryError('invalid certificate fingerprint')
    expected = expected.lower()
    try:
        probe = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        probe.check_hostname = False
        probe.verify_mode = ssl.CERT_NONE
        probe.minimum_version = ssl.TLSVersion.TLSv1_2
        with connection_factory((host, port), timeout=15) as raw:
            with probe.wrap_socket(raw, server_hostname=host) as stream:
                certificate = stream.getpeercert(binary_form=True)
        if not certificate or not hmac.compare_digest(hashlib.sha256(certificate).hexdigest(), expected):
            raise DeliveryError('certificate fingerprint mismatch')
        pem = ssl.DER_cert_to_PEM_cert(certificate)
        verified = _pinned_context(pem)
        with connection_factory((host, port), timeout=15) as raw:
            with verified.wrap_socket(raw, server_hostname=host) as stream:
                actual = stream.getpeercert(binary_form=True)
                if not actual or not hmac.compare_digest(hashlib.sha256(actual).hexdigest(), expected):
                    raise DeliveryError('certificate changed during verification')
        return pem
    except DeliveryError:
        raise
    except (OSError, ValueError, ssl.SSLError) as error:
        raise DeliveryError('server certificate could not be authenticated') from error


def submit(url: str, ca_file: Path | None, token: bytes, report: bytes, *, ca_pem: str | None = None,
           leaf_pin: str | None = None, connection_factory=http.client.HTTPSConnection) -> None:
    """Submit once; connection_factory permits a private-socket integration fixture."""
    host, port = _address(url)
    if not isinstance(token, bytes) or re.fullmatch(rb'[A-Za-z0-9_-]{43}', token) is None:
        raise DeliveryError('invalid token')
    if not isinstance(report, bytes) or not 0 < len(report) <= MAX_REPORT:
        raise DeliveryError('invalid report')
    if (ca_file is None) == (ca_pem is None) or (leaf_pin is not None and re.fullmatch(r'[A-Fa-f0-9]{64}', leaf_pin) is None):
        raise DeliveryError('invalid TLS trust settings')
    try:
        parsed_report = json.loads(report)
        if not isinstance(parsed_report, dict):
            raise DeliveryError('invalid report')
        context = _pinned_context(ca_pem) if ca_pem is not None else ssl.create_default_context(cafile=str(ca_file))
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        # create_default_context verifies the certificate and host name.
        connection = connection_factory(host, port, context=context, timeout=15)
        try:
            if leaf_pin is not None:
                connection.connect()
                if connection.sock is None:
                    raise DeliveryError('TLS connection unavailable')
                certificate = connection.sock.getpeercert(binary_form=True)
                if not certificate or not hmac.compare_digest(hashlib.sha256(certificate).hexdigest(), leaf_pin.lower()):
                    raise DeliveryError('certificate changed before submission')
            connection.request('POST', ENDPOINT, body=report, headers={
                'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token.decode('ascii'),
                'Connection': 'close',
            })
            response = connection.getresponse()
            # Ignore the body: the server must never return sensitive data.
            extra = response.read(4097)
            if response.status != 204 or extra:
                raise DeliveryError('server refused or could not store the report')
        finally:
            connection.close()
    except DeliveryError:
        raise
    except (OSError, ValueError, UnicodeError, ssl.SSLError, http.client.HTTPException, json.JSONDecodeError) as error:
        raise DeliveryError('report could not be submitted securely') from error


def _read_token(fd: int) -> bytes:
    if fd < 3 or os.isatty(fd):
        raise DeliveryError('a protected token descriptor is required')
    data = bytearray()
    while len(data) <= 44:
        chunk = os.read(fd, 45 - len(data))
        if not chunk:
            break
        data.extend(chunk)
    if data.endswith(b'\n'):
        data.pop()
    return bytes(data)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True, help='HTTPS discovery report endpoint')
    parser.add_argument('--ca-file', required=True, type=Path, help='independently trusted server CA or certificate')
    parser.add_argument('--token-fd', required=True, type=int, help='protected nonterminal descriptor containing the token')
    args = parser.parse_args()
    try:
        if sys.stdin.isatty():
            raise DeliveryError('provide the report on protected standard input')
        token = _read_token(args.token_fd)
        report = sys.stdin.buffer.read(MAX_REPORT + 1)
        submit(args.url, args.ca_file, token, report)
        print('Inventory accepted.')
        return 0
    except (DeliveryError, OSError):
        print('Inventory was not confirmed; check TLS trust, token and server state.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
