# SPDX-License-Identifier: LGPL-3.0-only
"""Verify a staged Debian 13 (trixie) amd64 netboot set against a trusted InRelease.

This checks local files only. The archive keyring must come from a separately
trusted source, and the staging directory must not be writable by other actors.
gpgv does not itself check key expiry or revocation. This module does not
download, publish, or verify optional firmware.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
import hashlib
import os
from pathlib import Path
import re
import subprocess
import tempfile

from .verify import VerificationError, _bounded_read, _open_regular


def _check_file(path: Path, expected: str) -> None:
    with _open_regular(path) as stream:
        before = os.fstat(stream.fileno())
        digest = hashlib.file_digest(stream, "sha256").hexdigest()
        after = os.fstat(stream.fileno())
    if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
        raise VerificationError("artifact changed during verification")
    if digest != expected:
        raise VerificationError("artifact checksum mismatch")


def _clearsigned_payload(raw: bytes) -> list[str]:
    lines = raw.decode("utf-8", errors="strict").splitlines()
    if not lines or lines[0] != "-----BEGIN PGP SIGNED MESSAGE-----":
        raise VerificationError("invalid signed release format")
    try:
        start = lines.index("") + 1
        end = lines.index("-----BEGIN PGP SIGNATURE-----", start)
    except ValueError as error:
        raise VerificationError("invalid signed release format") from error
    return [line[2:] if line.startswith("- ") else line for line in lines[start:end]]


def _timestamp(value: str) -> datetime:
    try:
        result = parsedate_to_datetime(value)
        if result is None or result.tzinfo is None:
            raise ValueError("missing timezone")
        return result.astimezone(timezone.utc)
    except (TypeError, ValueError, OverflowError) as error:
        raise VerificationError("invalid release date") from error


def verify_debian_netboot(kernel: Path, initrd: Path, sums: Path, release: Path, keyring: Path,
                          *, now: datetime | None = None, max_age: timedelta = timedelta(days=180)) -> None:
    """Verify the Debian 13 release, its freshness, signed sums and amd64 boot files.

    Freshness is bounded even when the release lacks Valid-Until. No rollback or
    replay protection beyond the time bound is provided; callers need a trusted
    clock and should retain a previously accepted release version separately.
    """
    kernel, initrd, sums, release, keyring = map(Path, (kernel, initrd, sums, release, keyring))
    if kernel.name != "linux" or initrd.name != "initrd.gz":
        raise VerificationError("unexpected netboot filenames")
    if max_age <= timedelta(0):
        raise VerificationError("invalid release maximum age")
    if now is None:
        now = datetime.now(timezone.utc)
    if now.tzinfo is None:
        raise VerificationError("trusted UTC time required")
    now = now.astimezone(timezone.utc)
    try:
        release_bytes = _bounded_read(release, 8 * 1024 * 1024)
        sums_bytes = _bounded_read(sums, 8 * 1024 * 1024)
        keyring = keyring.resolve(strict=True)
        with tempfile.TemporaryDirectory(prefix="pxe-release-") as directory:
            signed = Path(directory) / "InRelease"
            signed.write_bytes(release_bytes)
            checked = subprocess.run(["gpgv", "--weak-digest", "SHA1", "--homedir", directory, "--keyring", str(keyring), str(signed)],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30, check=False)
        if checked.returncode != 0:
            raise VerificationError("release signature invalid")
        lines = _clearsigned_payload(release_bytes)
        origins = [line[7:].strip() for line in lines if line.startswith("Origin:")]
        codenames = [line[9:].strip() for line in lines if line.startswith("Codename:")]
        architectures = [line[14:].strip().split() for line in lines if line.startswith("Architectures:")]
        if origins != ["Debian"] or codenames != ["trixie"] or len(architectures) != 1 or "amd64" not in architectures[0]:
            raise VerificationError("unexpected Debian release or architecture")
        dates = [line[5:].strip() for line in lines if line.startswith("Date:")]
        expires = [line[12:].strip() for line in lines if line.startswith("Valid-Until:")]
        if len(dates) != 1 or len(expires) > 1:
            raise VerificationError("missing or ambiguous release date")
        issued = _timestamp(dates[0])
        if issued > now + timedelta(minutes=5) or now - issued > max_age:
            raise VerificationError("release date outside allowed period")
        if expires:
            expiry = _timestamp(expires[0])
            if expiry < now or expiry < issued:
                raise VerificationError("release expired")
        target = "main/installer-amd64/current/images/SHA256SUMS"
        entries = []
        in_sha = False
        sections = 0
        for line in lines:
            if line == "SHA256:":
                sections += 1
                in_sha = True
                continue
            if in_sha and not line.startswith(" "):
                in_sha = False
            if in_sha:
                match = re.fullmatch(r"\s+([a-fA-F0-9]{64})\s+\d+\s+(\S+)", line)
                if match and match.group(2) == target:
                    entries.append(match.group(1).lower())
        if sections != 1 or len(entries) != 1 or hashlib.sha256(sums_bytes).hexdigest() != entries[0]:
            raise VerificationError("signed installer checksums missing, ambiguous or modified")
        expected = {"./netboot/debian-installer/amd64/linux": [],
                    "./netboot/debian-installer/amd64/initrd.gz": []}
        for line in sums_bytes.decode("utf-8", errors="strict").splitlines():
            match = re.fullmatch(r"([a-fA-F0-9]{64}) [ *](\S+)", line)
            if match and match.group(2) in expected:
                expected[match.group(2)].append(match.group(1).lower())
        if any(len(items) != 1 for items in expected.values()):
            raise VerificationError("netboot files missing or ambiguous in checksums")
        _check_file(kernel, expected["./netboot/debian-installer/amd64/linux"][0])
        _check_file(initrd, expected["./netboot/debian-installer/amd64/initrd.gz"][0])
    except VerificationError:
        raise
    except (OSError, UnicodeError, subprocess.SubprocessError) as error:
        raise VerificationError("netboot verification could not be completed") from error
