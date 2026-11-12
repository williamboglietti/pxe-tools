#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Request or process a Debian netboot acquisition; never activate PXE services."""
from __future__ import annotations

import argparse
from contextlib import closing
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import signal
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
from urllib.parse import quote

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from artifacts.boot_bundle import _validate_server_ip  # noqa: E402
from artifacts.verify import VerificationError, _bounded_read  # noqa: E402
from worker.netboot import JobError, enqueue, run_one  # noqa: E402


def open_database(path: Path) -> sqlite3.Connection:
    """Open only an existing private database in an operator-owned directory."""
    if not path.is_absolute() or path.is_symlink():
        raise JobError("private existing database required")
    parent = path.parent.resolve(strict=True)
    info, parent_info = path.stat(), parent.stat()
    if (not stat.S_ISREG(info.st_mode) or not stat.S_ISDIR(parent_info.st_mode)
            or info.st_uid != os.geteuid() or parent_info.st_uid != os.geteuid()
            or info.st_mode & 0o077 or parent_info.st_mode & 0o022):
        raise JobError("private existing database required")
    uri = "file:" + quote(str(parent / path.name), safe="/") + "?mode=rw"
    db = sqlite3.connect(uri, uri=True, timeout=5)
    db.execute("PRAGMA foreign_keys=ON")
    return db


def pending_request(database: Path) -> str | None:
    """Return the saved server IPv4 when a request is pending and that address is usable."""
    with closing(open_database(database)) as db:
        db.execute("PRAGMA query_only=ON")
        if db.execute("SELECT 1 FROM netboot_jobs WHERE kind='debian13-amd64' AND status='pending'").fetchone() is None:
            return None
        row = db.execute("SELECT settings_json FROM pxe_network_settings WHERE id=1").fetchone()
    try:
        address = json.loads(row[0]).get("pxe_ip") if row is not None and len(row[0]) <= 8192 else None
        _validate_server_ip(address)
    except (VerificationError, ValueError, AttributeError, TypeError):
        return None
    return address


def optional_ansible_status() -> str | None:
    """Check the optional volume without running a playbook or changing jobs."""
    configured = os.environ.get('PXE_ANSIBLE_RUNTIME')
    if configured is None:
        return None
    if configured == 'system':
        from worker.ansible_native import NativeRuntimeError, status
        try:
            return status()[1]
        except NativeRuntimeError:
            return 'unavailable'
    if configured != '/var/lib/pxe-ansible':
        return 'unavailable'
    helper = Path('/usr/local/lib/pxe-ansible/runtime.py')
    try:
        result = subprocess.run([sys.executable, str(helper), '--runtime', configured, 'status'],
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                text=True, timeout=5, check=False)
        if result.returncode != 0 or len(result.stdout) > 4096:
            return 'unavailable'
        value = json.loads(result.stdout)
        if value == {'state': 'absent'}:
            return 'absent'
        if (not isinstance(value, dict) or set(value) != {'state', 'runtime', 'ansible_core'}
                or value['state'] != 'ready' or not isinstance(value['runtime'], str)
                or re.fullmatch(r'env-[a-z0-9_]{8}', value['runtime']) is None
                or not isinstance(value['ansible_core'], str)
                or re.fullmatch(r'[0-9][A-Za-z0-9.+!_-]{0,99}', value['ansible_core']) is None):
            return 'unavailable'
        return value['ansible_core']
    except (OSError, ValueError, subprocess.SubprocessError):
        return 'unavailable'


def optional_ansible_cycle(database: Path) -> None:
    """Publish trusted profiles and run at most one explicitly requested job."""
    configured = os.environ.get('PXE_ANSIBLE_PROFILES')
    if configured is None:
        return
    if configured != '/etc/pxe-ansible/profiles':
        return
    from worker.ansible_queue import (QueueError, execute, open_queue, publish, read_profiles, runtime_status)
    try:
        with closing(open_queue(database.parent)) as db:
            try:
                profiles = read_profiles(Path(configured))
                runtime_id, version = runtime_status()
            except (QueueError, OSError, ValueError, subprocess.SubprocessError):
                with db:
                    db.execute('DELETE FROM state')
                    db.execute('DELETE FROM profiles')
                return
            publish(db, profiles, runtime_id, version)
            execute(db, Path(configured), profiles, runtime_id)
    except (QueueError, OSError, ValueError, sqlite3.Error, subprocess.SubprocessError):
        # A faulty optional module must not stop the PXE acquisition worker.
        print('Optional Ansible queue is unavailable; acquisition remains available.', file=sys.stderr, flush=True)


def run_loop(args: argparse.Namespace) -> int:
    """Process requests one at a time in a child process, so the idle loop stays small."""
    stopping = False

    def stop(signum, frame):
        nonlocal stopping
        stopping = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, stop)
    print("Acquisition worker started; PXE activation is never changed by this process.", flush=True)
    ansible = optional_ansible_status()
    if ansible in ('absent', 'unavailable'):
        print('Optional Ansible runtime is absent or unavailable; acquisition remains available.', flush=True)
    elif ansible is not None:
        print(f'Optional Ansible runtime detected: {ansible}.', flush=True)
    while not stopping:
        try:
            address = pending_request(args.db)
        except (JobError, sqlite3.Error, OSError):
            address = None
        if address is not None:
            # A started acquisition is left to finish: its outcome must be recorded.
            subprocess.run([sys.executable, str(Path(__file__).resolve()), "--db", str(args.db), "run-once",
                            "--keyring", str(args.keyring), "--keyring-sha256", args.keyring_sha256,
                            "--staging", str(args.staging), "--served", str(args.served), "--server-ip", address],
                           stdin=subprocess.DEVNULL, start_new_session=True, check=False)
        if not stopping:
            optional_ansible_cycle(args.db)
        deadline = time.monotonic() + args.interval
        while not stopping and time.monotonic() < deadline:
            time.sleep(0.2)
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--db", type=Path, required=True)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("enqueue", help="request one Debian 13 amd64 acquisition")
    worker = commands.add_parser("run-once", help="process at most one pending acquisition")
    loop = commands.add_parser("run-loop", help="process requests as they arrive, for the saved server address")
    for command in (worker, loop):
        for option in ("keyring", "staging", "served"):
            command.add_argument("--" + option, type=Path, required=True)
        command.add_argument("--keyring-sha256", required=True, help="SHA-256 of an independently authenticated keyring")
    worker.add_argument("--server-ip", required=True, help="IPv4 address to render into the boot menus")
    loop.add_argument("--interval", type=float, default=15.0)
    args = parser.parse_args()
    if args.command != "enqueue" and not re.fullmatch(r"[a-fA-F0-9]{64}", args.keyring_sha256):
        parser.error("keyring SHA-256 must be 64 hexadecimal characters")
    if args.command == "run-loop":
        if not 1 <= args.interval <= 3600:
            parser.error("interval must be between 1 and 3600 seconds")
        return run_loop(args)
    try:
        with closing(open_database(args.db)) as db:
            if args.command == "enqueue":
                print(f"Acquisition requested: job {enqueue(db)}")
                return 0
            # Authenticate a snapshot before claiming work, so a bad local
            # configuration does not consume a pending job.
            keyring = _bounded_read(args.keyring, 8 * 1024 * 1024)
            if not hmac.compare_digest(hashlib.sha256(keyring).hexdigest(), args.keyring_sha256.lower()):
                raise VerificationError("keyring digest does not match")
            with tempfile.TemporaryDirectory(prefix="pxe-trust-") as directory:
                trusted = Path(directory) / "archive-keyring.gpg"
                trusted.write_bytes(keyring)
                result = run_one(db, trusted, args.staging, args.served, args.server_ip)
            if result is None:
                print("No acquisition pending.")
                return 0
            job, success = result
            print(f"Job {job}: {'verified files prepared' if success else 'acquisition failed'}; PXE activation unchanged.")
            return 0 if success else 1
    except (JobError, VerificationError, sqlite3.Error, OSError, ValueError):
        print("Netboot job could not be processed; check local configuration and job state.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
