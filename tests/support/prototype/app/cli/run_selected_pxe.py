#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare and explicitly run selected PXE services in the foreground.

Experimental: no system service is installed and the web UI cannot invoke it.
The operator must ensure exclusive, trusted ownership of its database and files.
"""
from __future__ import annotations

import argparse
from contextlib import closing, contextmanager
import hashlib
import hmac
import ipaddress
import json
from pathlib import Path
import re
import signal
import sqlite3
import subprocess
import sys
import tempfile
from typing import Iterator

sys.dont_write_bytecode = True
APP = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(APP))
from artifacts.boot_bundle import build_debian_bundle  # noqa: E402
from artifacts.verify import VerificationError, _bounded_read  # noqa: E402
from artifacts.verify_bundle import verify_bundle  # noqa: E402
from netboot_job import JobError, open_database  # noqa: E402
from verify_selected_bundle import selected  # noqa: E402


def saved_network(db: sqlite3.Connection) -> tuple[str, str, str, str | None]:
    """Read a desired configuration, without applying it or trusting old file paths."""
    row = db.execute('SELECT mode, settings_json FROM pxe_network_settings WHERE id=1').fetchone()
    if row is None or row[0] not in ('tftp', 'proxy') or not isinstance(row[1], str) or len(row[1]) > 8192:
        raise VerificationError('saved network mode is disabled or invalid')
    def unique_object(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise VerificationError('duplicate saved network setting')
            result[key] = value
        return result
    settings = json.loads(row[1], object_pairs_hook=unique_object)
    if not isinstance(settings, dict):
        raise VerificationError('invalid saved network settings')
    interface, address = settings.get('pxe_interface'), settings.get('pxe_ip')
    if (not isinstance(interface, str) or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,64}', interface)
            or interface.startswith('-') or not isinstance(address, str)):
        raise VerificationError('invalid saved network interface or address')
    try:
        ipaddress.IPv4Address(address)
    except ipaddress.AddressValueError as error:
        raise VerificationError('invalid saved network address') from error
    if (settings.get('bios_bootfile', 'lpxelinux.0') != 'lpxelinux.0'
            or settings.get('uefi_bootfile', 'bootx64.efi') != 'bootx64.efi'):
        raise VerificationError('saved boot filenames do not match the managed bundle')
    proxy = settings.get('dhcp_proxy_cidr') if row[0] == 'proxy' else None
    if row[0] == 'proxy' and (not isinstance(proxy, str) or not proxy):
        raise VerificationError('saved proxy network is missing')
    return row[0], interface, address, proxy


@contextmanager
def prepared_runtime(db: sqlite3.Connection, served: Path, keyring: Path, *, mode: str,
                     interface: str, proxy_cidr: str | None) -> Iterator[dict[str, str]]:
    """Rebuild a private, verified copy and render config; never start a service."""
    if (mode not in ('tftp', 'proxy') or not isinstance(interface, str)
            or not re.fullmatch(r'[A-Za-z0-9_.:-]{1,64}', interface) or interface.startswith('-')):
        raise VerificationError('invalid service mode or interface')
    if (mode == 'proxy' and not proxy_cidr) or (mode == 'tftp' and proxy_cidr is not None):
        raise VerificationError('proxy network must match the chosen mode')
    original = selected(db)
    verify_bundle(served, original[1], keyring, original[2])
    source = served.resolve(strict=True) / original[1]
    with tempfile.TemporaryDirectory(prefix='pxe-runtime-') as directory:
        root = Path(directory).resolve(strict=True)
        # Child daemons run as nobody; only the served copy is world-readable.
        root.chmod(0o711)
        staging, public = root / 'staging', root / 'served'
        staging.mkdir(mode=0o700)
        public.mkdir(mode=0o755)
        public.chmod(0o755)
        metadata = source / 'metadata'
        rebuilt = build_debian_bundle(source / 'http/netboot/debian/linux', source / 'http/netboot/debian/initrd.gz',
                                      metadata / 'SHA256SUMS', metadata / 'InRelease', metadata / 'Packages.xz',
                                      {'pxelinux': metadata / 'pxelinux.deb', 'syslinux-common': metadata / 'syslinux-common.deb',
                                       'grub-efi-amd64-signed': metadata / 'grub-efi-amd64-signed.deb'}, keyring,
                                      staging, public, original[2])
        data = {'mode': mode, 'pxe_interface': interface, 'pxe_ip': original[2], 'tftp_root': str(rebuilt / 'tftp')}
        if mode == 'proxy':
            data['dhcp_proxy_cidr'] = proxy_cidr
        rendered = subprocess.run(['php', '-d', 'display_errors=stderr', str(APP / 'cli/render_runtime_config.php')], input=json.dumps(data).encode(),
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30, check=True).stdout
        if not rendered or len(rendered) > 16384:
            raise VerificationError('invalid generated network configuration')
        config = root / 'dnsmasq.conf'
        config.write_bytes(rendered)
        config.chmod(0o600)
        if selected(db) != original:
            raise VerificationError('selection changed during preparation')
        yield {'mode': mode, 'interface': interface, 'address': original[2], 'tftp': str(rebuilt / 'tftp'),
               'http': str(rebuilt / 'http'), 'config': str(config), 'selected_id': str(original[0]),
               'selected_path': original[1]}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('db', 'served', 'keyring'):
        parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--keyring-sha256', required=True)
    parser.add_argument('--mode', choices=('tftp', 'proxy'))
    parser.add_argument('--interface')
    parser.add_argument('--proxy-cidr')
    parser.add_argument('--use-saved-network', action='store_true', help='use the saved desired network settings')
    parser.add_argument('--confirm-activation', action='store_true', help='explicitly start network services')
    parser.add_argument('--dry-run', action='store_true', help='verify and prepare without opening network ports')
    args = parser.parse_args()
    if args.dry_run == args.confirm_activation:
        parser.error('choose exactly one of --dry-run or --confirm-activation')
    if not re.fullmatch(r'[a-fA-F0-9]{64}', args.keyring_sha256):
        parser.error('keyring SHA-256 must be 64 hexadecimal characters')
    if args.use_saved_network and any(value is not None for value in (args.mode, args.interface, args.proxy_cidr)):
        parser.error('--use-saved-network cannot be combined with manual network options')
    if not args.use_saved_network and (args.mode is None or args.interface is None):
        parser.error('provide --mode and --interface, or --use-saved-network')
    if (args.mode == 'proxy' and not args.proxy_cidr) or (args.mode == 'tftp' and args.proxy_cidr is not None):
        parser.error('--proxy-cidr is required only for proxy mode')
    try:
        keyring = _bounded_read(args.keyring, 8 * 1024 * 1024)
        if not hmac.compare_digest(hashlib.sha256(keyring).hexdigest(), args.keyring_sha256.lower()):
            raise VerificationError('keyring digest does not match')
        with closing(open_database(args.db)) as db, tempfile.TemporaryDirectory(prefix='pxe-trust-') as directory:
            trusted = Path(directory) / 'archive-keyring.gpg'
            trusted.write_bytes(keyring)
            desired = saved_network(db) if args.use_saved_network else None
            mode, interface, proxy_cidr = (desired[0], desired[1], desired[3]) if desired else (args.mode, args.interface, args.proxy_cidr)
            with prepared_runtime(db, args.served, trusted, mode=mode, interface=interface, proxy_cidr=proxy_cidr) as plan:
                if desired is not None and (desired[2] != plan['address'] or saved_network(db) != desired):
                    raise VerificationError('saved network does not match the selected bundle or changed during preparation')
                if args.dry_run:
                    print('Selected bundle and configuration prepared; no network service started.')
                    return 0
                process = None
                interrupted = False
                def stop(signum, frame):
                    nonlocal interrupted
                    interrupted = True
                    if process is not None and process.poll() is None:
                        try:
                            process.terminate()
                        except ProcessLookupError:
                            pass
                previous = {sig: signal.signal(sig, stop) for sig in (signal.SIGINT, signal.SIGTERM)}
                try:
                    # Freeze the selection while handing the verified copy to the supervisor.
                    db.execute('BEGIN IMMEDIATE')
                    try:
                        if selected(db) != (int(plan['selected_id']), plan['selected_path'], plan['address']):
                            raise VerificationError('selection changed before activation')
                        if desired is not None and saved_network(db) != desired:
                            raise VerificationError('network settings changed before activation')
                        if interrupted:
                            raise VerificationError('startup interrupted')
                        command = ['/bin/sh', str(APP / 'service/serve_pxe.sh'), '--enable', plan['mode'],
                                   plan['interface'], plan['address'], plan['tftp'], plan['http'], plan['config']]
                        print('Starting the explicitly requested PXE services in the foreground.', flush=True)
                        process = subprocess.Popen(command)
                        if interrupted and process.poll() is None:
                            process.terminate()
                        db.commit()
                    except BaseException:
                        db.rollback()
                        raise
                    result = process.wait()
                    return 0 if interrupted else result
                finally:
                    for sig, handler in previous.items():
                        signal.signal(sig, handler)
                    if process is not None and process.poll() is None:
                        process.terminate()
                        process.wait()
    except (VerificationError, JobError, sqlite3.Error, OSError, ValueError, subprocess.SubprocessError):
        print('PXE preparation or service startup failed; previous artifacts were preserved.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
