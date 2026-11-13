# SPDX-License-Identifier: LGPL-3.0-only
"""Queue and run the sole supported netboot acquisition job.

No arbitrary commands, network activation, or active-version changes are
accepted. A worker must have its own restricted database connection and must
not share a connection across threads.
"""
from __future__ import annotations

from pathlib import Path
import re
import sqlite3
from typing import Callable

from artifacts.boot_bundle import _validate_server_ip
from artifacts.download_bundle import download_and_build_bundle
from artifacts.verify import VerificationError


class JobError(Exception):
    """The request or job state is invalid."""


def enqueue(db: sqlite3.Connection) -> int:
    """Enqueue one fixed acquisition, rejecting a duplicate pending/running job."""
    if db.in_transaction:
        raise JobError("enqueue connection already has a transaction")
    try:
        with db:
            cursor = db.execute("INSERT INTO netboot_jobs(kind,status) VALUES('debian13-amd64','pending')")
        return int(cursor.lastrowid)
    except sqlite3.IntegrityError as error:
        raise JobError("a netboot acquisition is already pending or running") from error


def claim(db: sqlite3.Connection) -> int | None:
    """Claim one pending job atomically; only one worker can receive it."""
    if db.in_transaction:
        raise JobError("worker connection already has a transaction")
    try:
        db.execute("BEGIN IMMEDIATE")
        row = db.execute("SELECT id FROM netboot_jobs WHERE kind='debian13-amd64' AND status='pending' ORDER BY id LIMIT 1").fetchone()
        if row is None:
            db.commit()
            return None
        job = int(row[0])
        updated = db.execute("UPDATE netboot_jobs SET status='running', started_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'", (job,))
        if updated.rowcount != 1:
            raise JobError("job could not be claimed")
        db.commit()
        return job
    except BaseException:
        db.rollback()
        raise


def run_one(db: sqlite3.Connection, keyring: Path, staging: Path, served: Path, server_ip: str, *,
            _download: Callable = download_and_build_bundle) -> tuple[int, bool] | None:
    """Run one claimed job, recording a relative artifact set only on success.

    A process killed after publication but before recording may leave a valid,
    unreferenced set and a running job. Recovery must be explicit; never delete
    any artifact or silently retry a job whose outcome is unknown.
    """
    try:
        _validate_server_ip(server_ip)
    except VerificationError as error:
        raise JobError('a usable server IPv4 address is required') from error
    job = claim(db)
    if job is None:
        return None
    try:
        result = _download(keyring, staging, served, server_ip)
        relative = result.relative_to(served.resolve(strict=True)).as_posix()
        if (not re.fullmatch(r"bundle-[0-9a-f]{32}/content", relative) or result.is_symlink()
                or not result.is_dir() or result != result.resolve(strict=True)):
            raise JobError("invalid artifact set location")
        for name in ("http/netboot/debian/linux", "http/netboot/debian/initrd.gz",
                     "tftp/netboot/debian/linux", "tftp/netboot/debian/initrd.gz",
                     "tftp/lpxelinux.0", "tftp/bootx64.efi", "tftp/pxelinux.cfg/default", "tftp/grub/grub.cfg"):
            artifact = result / name
            if artifact.is_symlink() or not artifact.is_file() or artifact.stat().st_size == 0:
                raise JobError("incomplete artifact set")
    except Exception:
        with db:
            updated = db.execute("UPDATE netboot_jobs SET status='failed', ended_at=CURRENT_TIMESTAMP, "
                                 "message='Acquisition ou vérification impossible.' WHERE id=? AND status='running'", (job,))
            if updated.rowcount != 1:
                raise JobError("job state changed while running")
        return job, False
    with db:
        updated = db.execute("UPDATE netboot_jobs SET status='succeeded', ended_at=CURRENT_TIMESTAMP, artifact_path=?, server_ip=?, "
                             "message='Ensemble vérifié ; démarrage non activé.' WHERE id=? AND status='running'", (relative, server_ip, job))
        if updated.rowcount != 1:
            raise JobError("job state changed while running")
    return job, True
