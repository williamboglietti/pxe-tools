#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Keep the PXE services aligned with the desired state saved by the console.

The supervisor starts network services only when it was itself started with
--confirm-activation, a non-disabled network mode is saved and a verified
bundle is selected. It never assigns addresses and never changes interfaces.
"""
from __future__ import annotations

import argparse
from contextlib import closing
import json
import os
from pathlib import Path
import re
import signal
import sqlite3
import subprocess
import sys
import time
from typing import Callable, Sequence

sys.dont_write_bytecode = True
APP = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(APP))
sys.path.insert(0, str(APP / 'cli'))
from artifacts.verify import VerificationError  # noqa: E402
from netboot_job import JobError, open_database  # noqa: E402
from run_selected_pxe import saved_network  # noqa: E402
from verify_selected_bundle import selected  # noqa: E402

READY_MARKER = b'Starting the explicitly requested PXE services'
STATUS_VERSION = 1
MAX_BACKOFF = 300


def desired_state(database: Path) -> tuple[tuple | None, str]:
    """Return the desired configuration, or None with the reason for staying idle."""
    try:
        with closing(open_database(database)) as db:
            db.execute('PRAGMA query_only=ON')
            mode = db.execute('SELECT mode FROM pxe_network_settings WHERE id=1').fetchone()
            if mode is None or mode[0] == 'disabled':
                return None, 'disabled'
            try:
                network = saved_network(db)
            except (VerificationError, ValueError):
                return None, 'invalid-network'
            try:
                bundle = selected(db)
            except VerificationError:
                return None, 'no-bundle'
            if bundle[2] != network[2]:
                return None, 'address-mismatch'
            return (network, bundle), 'configured'
    except (JobError, sqlite3.Error, OSError):
        return None, 'database-unavailable'


class Supervisor:
    """Reconcile one child launcher with the desired state; one tick per call."""

    def __init__(self, database: Path, status: Path, command: Callable[[], Sequence[str]], *,
                 clock: Callable[[], float] = time.time, stop_timeout: float = 15.0) -> None:
        self.database, self.status, self.command = database, status, command
        self.clock, self.stop_timeout = clock, stop_timeout
        self.child: subprocess.Popen | None = None
        self.applied: tuple | None = None
        self.ready = False
        self.failures = 0
        self.retry_at = 0.0
        self.since = clock()
        self.state = 'idle'

    def _publish(self, state: str, reason: str, desired: tuple | None) -> None:
        if state != self.state:
            self.state, self.since = state, self.clock()
        data = {'version': STATUS_VERSION, 'state': state, 'reason': reason, 'since': int(self.since),
                'heartbeat': int(self.clock()), 'failures': self.failures}
        if desired is not None:
            (mode, interface, address, proxy), (job, _path, _ip) = desired
            data.update(mode=mode, interface=interface, address=address, job_id=job)
            if proxy is not None:
                data['proxy_cidr'] = proxy
        temporary = self.status.with_name(self.status.name + '.tmp')
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w', encoding='ascii') as output:
            json.dump(data, output)
        os.replace(temporary, self.status)

    def _drain(self) -> None:
        if self.child is None or self.child.stdout is None:
            return
        try:
            data = os.read(self.child.stdout.fileno(), 65536)
        except BlockingIOError:
            return
        if data:
            sys.stdout.buffer.write(data)
            sys.stdout.buffer.flush()
            if READY_MARKER in data:
                self.ready = True

    def stop_child(self) -> None:
        """Stop the launcher gracefully, then remove anything left in its process group."""
        child, self.child, self.ready = self.child, None, False
        if child is None:
            return
        if child.poll() is None:
            child.terminate()
            try:
                child.wait(timeout=self.stop_timeout)
            except subprocess.TimeoutExpired:
                pass
        try:
            # The launcher leads its own group; daemons it left behind are in it.
            os.killpg(child.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError):
            pass
        child.wait()
        if child.stdout is not None:
            child.stdout.close()

    def tick(self) -> str:
        desired, reason = desired_state(self.database)
        if reason == 'database-unavailable' and self.applied is not None:
            # A database that cannot be read right now says nothing about the
            # administrator's intent: keep what was last applied.
            desired = self.applied
        self._drain()
        if self.child is not None and self.child.poll() is not None:
            self.stop_child()
            self.failures += 1
            self.retry_at = self.clock() + min(MAX_BACKOFF, 5 * 2 ** (self.failures - 1))
        if desired != self.applied:
            self.stop_child()
            self.applied, self.failures, self.retry_at = desired, 0, 0.0
        if desired is None:
            self._publish('idle', reason, None)
        elif self.child is None and self.clock() < self.retry_at:
            self._publish('backoff', 'launcher-exited', desired)
        else:
            if self.child is None:
                self.child = subprocess.Popen(list(self.command()), stdout=subprocess.PIPE,
                                              stdin=subprocess.DEVNULL, start_new_session=True)
                os.set_blocking(self.child.stdout.fileno(), False)
            self._publish('running' if self.ready else 'starting', reason, desired)
        return self.state

    def shutdown(self) -> None:
        self.stop_child()
        self.applied = None
        self._publish('stopped', 'supervisor-stopped', None)


def private_directory(path: Path) -> Path:
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if path.is_symlink() or not path.is_dir() or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise OSError('private directory required')
    return path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state', type=Path, required=True, help='instance state directory')
    parser.add_argument('--keyring', type=Path, required=True)
    parser.add_argument('--keyring-sha256', required=True, help='SHA-256 of an independently authenticated keyring')
    parser.add_argument('--interval', type=float, default=5.0)
    parser.add_argument('--confirm-activation', action='store_true',
                        help='allow this supervisor to start network services from the saved state')
    args = parser.parse_args()
    if not args.confirm_activation:
        parser.error('--confirm-activation is required: this command starts network services')
    if not re.fullmatch(r'[a-fA-F0-9]{64}', args.keyring_sha256):
        parser.error('keyring SHA-256 must be 64 hexadecimal characters')
    if not args.state.is_absolute() or not args.keyring.is_absolute() or not 1 <= args.interval <= 60:
        parser.error('absolute paths and an interval between 1 and 60 seconds are required')
    database, served = args.state / 'database.sqlite', args.state / 'artifacts'
    try:
        status = private_directory(args.state / 'run') / 'pxe-service.json'
    except OSError:
        print('The state directory is unavailable or not private.', file=sys.stderr)
        return 1

    def command() -> list[str]:
        return [sys.executable, str(APP / 'cli/run_selected_pxe.py'), '--db', str(database), '--served', str(served),
                '--keyring', str(args.keyring), '--keyring-sha256', args.keyring_sha256,
                '--use-saved-network', '--confirm-activation']

    supervisor = Supervisor(database, status, command)
    stopping = False

    def stop(signum, frame):
        nonlocal stopping
        stopping = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, stop)
    print('PXE supervisor started; services follow the saved configuration.', flush=True)
    try:
        while not stopping:
            supervisor.tick()
            deadline = time.monotonic() + args.interval
            while not stopping and time.monotonic() < deadline:
                time.sleep(0.2)
    finally:
        supervisor.shutdown()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
