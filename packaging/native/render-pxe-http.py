#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Render the native PXE HTTP listener only for the configured IPv4 interface."""
from contextlib import closing
import importlib.util
import ipaddress
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import tempfile
import time


def render(database, template, output, ip_command='ip', *, environ=None, allow_missing_db=False,
           wait_seconds=30, poll_interval=1):
    environ = os.environ if environ is None else environ
    db_path = Path(database)
    if not db_path.exists() and allow_missing_db:
        rows = []
    else:
        # Open read-only and never create an empty database as root.
        uri = db_path.resolve(strict=True).as_uri() + '?mode=ro'
        with closing(sqlite3.connect(uri, uri=True, timeout=5)) as connection:
            rows = connection.execute("SELECT key, value FROM settings WHERE key IN ('pxe_ip','pxe_interface')").fetchall()
    if len(rows) != len({row[0] for row in rows}):
        raise ValueError('duplicate PXE settings')
    settings = dict(rows)
    # Match the existing dnsmasq renderer: environment fallback is used only
    # for missing keys, never for explicitly empty database values.
    interface = settings.get('pxe_interface', environ.get('PXE_INTERFACE', ''))
    address = settings.get('pxe_ip', environ.get('PXE_IP') or environ.get('ORCHESTRATOR_IP', ''))
    if not isinstance(interface, str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,64}', interface):
        raise ValueError('invalid PXE interface')
    if not isinstance(address, str):
        raise ValueError('invalid PXE address')
    parsed = ipaddress.IPv4Address(address)
    if parsed.is_unspecified or parsed.is_multicast or parsed.is_loopback or parsed.is_reserved:
        # Documentation networks (used by isolated labs) are marked private, not reserved.
        raise ValueError('PXE address must be a unicast interface address')
    deadline = time.monotonic() + wait_seconds
    while True:
        try:
            raw = subprocess.check_output([ip_command, '-j', '-4', 'addr', 'show', 'dev', interface],
                                          timeout=10, stderr=subprocess.DEVNULL)
            links = json.loads(raw)
            matched = len(links) == 1 and links[0].get('ifname') == interface and any(
                entry.get('family') == 'inet' and entry.get('local') == str(parsed)
                for entry in links[0].get('addr_info', [])
            )
        except subprocess.CalledProcessError:
            # Interfaces may be created after the service starts (e.g. bridges).
            matched = False
        if matched:
            break
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise ValueError('PXE address is not assigned to the selected interface')
        time.sleep(min(poll_interval, remaining))
    text = Path(template).read_text(encoding='utf-8')
    marker = '@@PXE_IPV4@@'
    if text.count(marker) != 1:
        raise ValueError('invalid PXE HTTP template')
    rendered = text.replace(marker, str(parsed))
    target = Path(output)
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=target.parent, delete=False) as stream:
            temporary = stream.name
            stream.write(rendered)
        os.chmod(temporary, 0o644)
        os.replace(temporary, target)
        temporary = None
    finally:
        if temporary is not None:
            os.unlink(temporary)


def main():
    spec = importlib.util.spec_from_file_location('database_selection', '/usr/lib/pxe-tools/app/database_selection.py')
    selector = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(selector)
    database, source = selector.select_database()
    render(database, '/usr/lib/pxe-tools/native/pxe.conf', '/run/pxe-tools-pxe-http/pxe.conf', allow_missing_db=(source != 'config'))


if __name__ == '__main__':
    try:
        main()
    except (OSError, RuntimeError, ValueError, sqlite3.Error, subprocess.SubprocessError, json.JSONDecodeError) as error:
        print(f'PXE HTTP: {error}', file=sys.stderr)
        sys.exit(78)
