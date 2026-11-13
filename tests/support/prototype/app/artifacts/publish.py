# SPDX-License-Identifier: LGPL-3.0-only
"""Publish independently verified artifacts without exposing unverified staging.

Both roots must be on the same filesystem and writable only by the worker.
The caller is responsible for a separately trusted keyring and for configuring
HTTP to serve only the supplied served root, not the staging root.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import tempfile
from typing import Sequence

from .verify import VerificationError, _bounded_read, _open_regular, verify_signed_sha256
from .debian_netboot import verify_debian_netboot


def _safe_root(path: Path) -> Path:
    resolved = Path(path).resolve(strict=True)
    info = resolved.stat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid()
            or info.st_mode & 0o022):
        raise VerificationError("artifact root must be owned by worker and not writable by others")
    return resolved


def _roots(staging_root: Path, served_root: Path) -> tuple[Path, Path]:
    stage_root = _safe_root(staging_root)
    public_root = _safe_root(served_root)
    if stage_root.stat().st_mode & 0o077:
        raise VerificationError("staging root must be private to the worker")
    if stage_root == public_root or stage_root in public_root.parents or public_root in stage_root.parents:
        raise VerificationError("staging and served roots must be separate")
    if stage_root.stat().st_dev != public_root.stat().st_dev:
        raise VerificationError("staging and served roots must share a filesystem")
    return stage_root, public_root


def _promote(stage: Path, public_root: Path) -> Path:
    for child in stage.iterdir():
        child.chmod(0o644)
    stage.chmod(0o755)
    # mkdir is exclusive: a name collision cannot replace a previous version.
    target = public_root / ("set-" + secrets.token_hex(16))
    target.mkdir(mode=0o700)
    try:
        content = target / "content"
        os.rename(stage, content)
        target.chmod(0o755)
        return content
    except OSError:
        # Remove only an empty directory created by this call. Preserve content
        # for inspection if it was already moved.
        try:
            target.rmdir()
        except OSError:
            pass
        raise


def publish_signed_set(artifacts: Sequence[Path], manifest: Path, signature: Path,
                       keyring: Path, staging_root: Path, served_root: Path) -> Path:
    """Verify and atomically publish a new immutable-by-convention set.

    A new unique directory is returned. Existing published directories and
    pointers are not modified; an integrating worker must update its pointer
    only after this function succeeds. The caller must prevent any other writer
    from modifying the staged or published files.
    """
    try:
        stage_root, public_root = _roots(staging_root, served_root)
        paths = [Path(item) for item in artifacts]
        names = [path.name for path in paths]
        if (not paths or len(paths) > 16 or len(set(names)) != len(names)
                or any(not re.fullmatch(r"[A-Za-z0-9_+.-]{1,200}", name)
                       or name in (".", "..", "SHA256SUMS", "SHA256SUMS.sig") for name in names)):
            raise VerificationError("invalid artifact set")
        stage = Path(tempfile.mkdtemp(prefix="pxe-stage-", dir=stage_root))
        try:
            (stage / "SHA256SUMS").write_bytes(_bounded_read(Path(manifest), 8 * 1024 * 1024))
            (stage / "SHA256SUMS.sig").write_bytes(_bounded_read(Path(signature), 1024 * 1024))
            for source in paths:
                with _open_regular(source) as src, (stage / source.name).open("xb") as dst:
                    shutil.copyfileobj(src, dst, length=1024 * 1024)
            for source in paths:
                verify_signed_sha256(stage / source.name, stage / "SHA256SUMS", stage / "SHA256SUMS.sig", keyring)
            return _promote(stage, public_root)
        finally:
            # Only the unique directory created by this call can be removed.
            if stage.exists():
                shutil.rmtree(stage)
    except VerificationError:
        raise
    except (OSError, ValueError) as error:
        raise VerificationError("artifact publication failed") from error


def publish_debian_netboot(kernel: Path, initrd: Path, sums: Path, release: Path, keyring: Path,
                           staging_root: Path, served_root: Path) -> Path:
    """Stage, authenticate and atomically publish a Debian amd64 boot pair.

    No firmware or active-version pointer is provided. Release freshness uses
    the verifier's default bound and depends on a trustworthy system clock.
    """
    try:
        stage_root, public_root = _roots(staging_root, served_root)
        if Path(kernel).name != "linux" or Path(initrd).name != "initrd.gz":
            raise VerificationError("unexpected netboot filenames")
        stage = Path(tempfile.mkdtemp(prefix="pxe-stage-", dir=stage_root))
        try:
            (stage / "SHA256SUMS").write_bytes(_bounded_read(Path(sums), 8 * 1024 * 1024))
            (stage / "InRelease").write_bytes(_bounded_read(Path(release), 8 * 1024 * 1024))
            for source, name in ((Path(kernel), "linux"), (Path(initrd), "initrd.gz")):
                with _open_regular(source) as src, (stage / name).open("xb") as dst:
                    shutil.copyfileobj(src, dst, length=1024 * 1024)
            verify_debian_netboot(stage / "linux", stage / "initrd.gz", stage / "SHA256SUMS", stage / "InRelease", keyring)
            return _promote(stage, public_root)
        finally:
            if stage.exists():
                shutil.rmtree(stage)
    except VerificationError:
        raise
    except (OSError, ValueError) as error:
        raise VerificationError("netboot publication failed") from error
