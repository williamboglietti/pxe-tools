# SPDX-License-Identifier: LGPL-3.0-only
"""Verify a local artifact against a signed SHA256SUMS file.

The caller supplies an independently trusted public keyring. This module does
not download, publish, or choose trusted keys. gpgv assumes its keyring is
trusted and does not itself check key expiry or revocation; maintaining the
keyring is the caller's responsibility. Staging must be private to the worker
so artifacts cannot change concurrently during verification.
"""
from __future__ import annotations

import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile


class VerificationError(Exception):
    """The artifact or its signed manifest could not be verified."""


def _open_regular(path: Path):
    if not hasattr(os, "O_NOFOLLOW"):
        raise VerificationError("safe file opening unavailable")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    stream = os.fdopen(fd, "rb")
    if not stat.S_ISREG(os.fstat(stream.fileno()).st_mode):
        stream.close()
        raise VerificationError("verification input is not a regular file")
    return stream


def _bounded_read(path: Path, limit: int) -> bytes:
    with _open_regular(path) as stream:
        data = stream.read(limit + 1)
    if len(data) > limit:
        raise VerificationError("verification input too large")
    return data


def verify_signed_sha256(artifact: Path, manifest: Path, signature: Path, keyring: Path) -> None:
    """Raise VerificationError unless a detached signature and artifact hash match.

    The caller must trust and protect the keyring independently; a valid
    signature from an arbitrary user-supplied key does not establish origin.
    """
    artifact, manifest, signature, keyring = map(Path, (artifact, manifest, signature, keyring))
    if not re.fullmatch(r"[A-Za-z0-9_+.-]{1,200}", artifact.name) or artifact.name in (".", ".."):
        raise VerificationError("invalid artifact filename")
    try:
        sums = _bounded_read(manifest, 8 * 1024 * 1024)
        sig = _bounded_read(signature, 1024 * 1024)
        keyring = keyring.resolve(strict=True)
        with tempfile.TemporaryDirectory(prefix="pxe-verify-") as temporary:
            temp = Path(temporary)
            (temp / "SHA256SUMS").write_bytes(sums)
            (temp / "SHA256SUMS.sig").write_bytes(sig)
            # Isolated GnuPG home avoids touching the user's keyring or configuration.
            result = subprocess.run(
                ["gpgv", "--weak-digest", "SHA1", "--homedir", str(temp), "--keyring", str(keyring),
                 str(temp / "SHA256SUMS.sig"), str(temp / "SHA256SUMS")],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30, check=False,
            )
        if result.returncode != 0:
            raise VerificationError("manifest signature invalid")
        text = sums.decode("utf-8", errors="strict")
        matches = []
        for line in text.splitlines():
            match = re.fullmatch(r"([a-fA-F0-9]{64}) [ *](.+)", line)
            if not match:
                continue
            name = match.group(2)
            if name.startswith("./"):
                name = name[2:]
            if name == artifact.name:
                matches.append(match.group(1).lower())
        if len(matches) != 1:
            raise VerificationError("artifact missing or ambiguous in signed manifest")
        with _open_regular(artifact) as stream:
            before = os.fstat(stream.fileno())
            digest = hashlib.file_digest(stream, "sha256").hexdigest()
            after = os.fstat(stream.fileno())
            if (before.st_size, before.st_mtime_ns, before.st_ctime_ns) != (after.st_size, after.st_mtime_ns, after.st_ctime_ns):
                raise VerificationError("artifact changed during verification")
        if digest != matches[0]:
            raise VerificationError("artifact checksum mismatch")
    except VerificationError:
        raise
    except (OSError, subprocess.SubprocessError, UnicodeError) as error:
        raise VerificationError("verification could not be completed") from error
