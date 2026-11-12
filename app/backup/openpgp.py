# SPDX-License-Identifier: LGPL-3.0-only
"""OpenPGP helpers adapted from the previous experimental backup prototype."""
from __future__ import annotations
from contextlib import contextmanager
import os
from pathlib import Path
import selectors
import subprocess
import tempfile
import time
from typing import Iterator

# Bounded authenticated plaintext, including optional PXE assets.
MAX_PLAINTEXT = 8 * 1024 * 1024 * 1024
MAX_ARCHIVE = MAX_PLAINTEXT + 64 * 1024 * 1024

class BackupError(Exception):
    """The backup or restore operation was refused or failed."""

def _passphrase_fd(passphrase: bytes) -> int:
    if (not isinstance(passphrase, bytes) or not 12 <= len(passphrase) <= 1024
            or any(c in passphrase for c in (b'\n', b'\r', b'\0'))):
        raise BackupError('invalid passphrase')
    reader, writer = os.pipe()
    try:
        remaining = memoryview(passphrase + b'\n')
        while remaining:
            written = os.write(writer, remaining)
            if written <= 0:
                raise BackupError('could not pass the passphrase to GnuPG')
            remaining = remaining[written:]
    except BaseException:
        os.close(reader)
        raise
    finally:
        os.close(writer)
    return reader


@contextmanager
def _gpg_home() -> Iterator[Path]:
    with tempfile.TemporaryDirectory(prefix='pxe-gpg-') as directory:
        home = Path(directory)
        try:
            yield home
        finally:
            # Limit agent cleanup to this disposable home, never the user's keyring.
            try:
                subprocess.run(['gpgconf', '--homedir', str(home), '--kill', 'gpg-agent'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10, check=False)
            except (OSError, subprocess.SubprocessError):
                pass


def _gpg_args(home: Path, passfd: int, statusfd: int) -> list[str]:
    return ['gpg', '--no-options', '--homedir', str(home), '--batch', '--no-tty',
            '--pinentry-mode', 'loopback', '--no-symkey-cache', '--passphrase-fd', str(passfd),
            '--status-fd', str(statusfd)]


def _statuses(status) -> list[list[str]]:
    status.seek(0)
    raw = status.read(65537)
    if len(raw) > 65536:
        raise BackupError('unexpected GnuPG status')
    return [line[len('[GNUPG:] '):].split() for line in raw.decode('ascii', errors='replace').splitlines()
            if line.startswith('[GNUPG:] ')]


def _publish(staged: Path, destination: Path) -> None:
    # A hard link is an atomic no-replace publication on the same filesystem.
    with staged.open('rb') as stream:
        os.fsync(stream.fileno())
    os.link(staged, destination, follow_symlinks=False)
    parentfd = os.open(destination.parent, os.O_RDONLY)
    try:
        try:
            os.fsync(parentfd)
        except OSError as error:
            raise BackupError('output was created but its directory could not be synced; inspect it before retrying') from error
    finally:
        os.close(parentfd)


def encrypt_file(source: Path, destination: Path, passphrase: bytes, home: Path) -> None:
    """Encrypt to a new private file and validate GnuPG's machine-readable status."""
    passfd = _passphrase_fd(passphrase)
    try:
        with source.open('rb') as plain, destination.open('xb') as output, tempfile.TemporaryFile() as status:
            os.chmod(destination, 0o600)
            command = _gpg_args(home, passfd, status.fileno()) + [
                '--cipher-algo', 'AES256', '--s2k-mode', '3', '--s2k-digest-algo', 'SHA256',
                '--s2k-count', '65011712', '--compress-algo', 'none', '--output', '-', '--symmetric']
            result = subprocess.run(command, stdin=plain, stdout=output, stderr=subprocess.DEVNULL,
                                    pass_fds=(passfd, status.fileno()), timeout=3600, check=False)
            records = _statuses(status)
            starts = [line for line in records if line[:1] == ['BEGIN_ENCRYPTION']]
            if (result.returncode != 0 or len(starts) != 1 or starts[0][1:3] != ['2', '9']
                    or not any(line[:1] == ['END_ENCRYPTION'] for line in records)):
                raise BackupError('encryption failed')
        if destination.stat().st_size > MAX_ARCHIVE:
            raise BackupError('archive exceeds export limit')
    finally:
        os.close(passfd)


def _decrypt_bounded(source, output, passphrase: bytes, home: Path) -> None:
    passfd = _passphrase_fd(passphrase)
    try:
        with tempfile.TemporaryFile() as status:
            command = _gpg_args(home, passfd, status.fileno()) + ['--output', '-', '--decrypt']
            process = subprocess.Popen(command, stdin=source, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                       pass_fds=(passfd, status.fileno()))
            try:
                assert process.stdout is not None
                selector = selectors.DefaultSelector()
                selector.register(process.stdout, selectors.EVENT_READ)
                total, deadline = 0, time.monotonic() + 3600
                try:
                    while True:
                        if time.monotonic() >= deadline:
                            raise BackupError('decryption timed out')
                        if not selector.select(timeout=1):
                            continue
                        chunk = os.read(process.stdout.fileno(), 256 * 1024)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > MAX_PLAINTEXT:
                            raise BackupError('decrypted archive exceeds limit')
                        output.write(chunk)
                finally:
                    selector.close()
                result = process.wait(timeout=10)
                records = _statuses(status)
                infos = [line for line in records if line[:1] == ['DECRYPTION_INFO']]
                # Refuse legacy OpenPGP without an integrity check, even if GPG
                # emits plaintext. Require successful authenticated decryption.
                protected = len(infos) == 1 and infos[0][1:3] == ['2', '9']
                if result != 0 or not protected or not any(line[:1] == ['DECRYPTION_OKAY'] for line in records):
                    raise BackupError('archive authentication failed')
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                if process.stdout is not None:
                    process.stdout.close()
    finally:
        os.close(passfd)
