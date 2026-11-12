"""Mot de passe Vault Ansible, saisi pour une seule exécution.

PHP dépose le mot de passe saisi dans `unlock`, un fichier du tmpfs. Le worker
le réclame au moment d'un ansible-playbook : renommer `unlock` le consomme.
Le worker transmet ensuite le contenu par un descripteur anonyme en mémoire,
fermé à la fin de l'exécution. L'ancien helper de fichier reste disponible
pour les appels historiques, mais le worker ne l'utilise plus.
"""

import contextlib
import os
import tempfile
import uuid
from collections.abc import Iterator

UNLOCK_DIR = os.environ.get("PXE_VAULT_UNLOCK_DIR", "/dev/shm/pxe-vault")
UNLOCK_FILE = os.path.join(UNLOCK_DIR, "unlock")
LEGACY_VAULT_FILE = os.environ.get("PXE_ANSIBLE_VAULT_PATH", "/home/ansible/.vault_pass")


class VaultLocked(RuntimeError):
    """Aucun déverrouillage n'a été saisi pour l'exécution en cours."""


def unlock_pending() -> bool:
    return os.path.isfile(UNLOCK_FILE)


def purge_unlock_dir() -> None:
    """Efface les fichiers qu'un arrêt brutal du worker aurait laissés."""
    if not os.path.isdir(UNLOCK_DIR):
        return
    for entry in os.scandir(UNLOCK_DIR):
        if entry.is_file():
            os.unlink(entry.path)


def remove_legacy_vault_file() -> None:
    """Une version précédente écrivait le mot de passe Vault en clair à cet endroit."""
    with contextlib.suppress(FileNotFoundError):
        os.unlink(LEGACY_VAULT_FILE)


def _claim_password() -> bytes:
    claimed = os.path.join(UNLOCK_DIR, f"claim-{uuid.uuid4().hex}")
    try:
        os.rename(UNLOCK_FILE, claimed)
    except FileNotFoundError:
        raise VaultLocked() from None
    try:
        with open(claimed, "rb") as source:
            password = source.read()
    finally:
        os.unlink(claimed)
    return password


@contextlib.contextmanager
def ansible_vault_password_file() -> Iterator[str]:
    password = _claim_password()

    fd, path = tempfile.mkstemp(prefix="run-", dir=UNLOCK_DIR)
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(password)
        yield path
    finally:
        os.unlink(path)


@contextlib.contextmanager
def ansible_vault_password_fd(uid: int, gid: int) -> Iterator[tuple[str, int]]:
    """Pass a one-use unlock to the unprivileged child through anonymous RAM."""
    if not hasattr(os, 'memfd_create') or not os.path.isdir('/proc/self/fd'):
        raise RuntimeError('Transmission temporaire du mot de passe Vault indisponible')
    fd = os.memfd_create('pxe-ansible-vault', flags=os.MFD_CLOEXEC)
    try:
        os.fchmod(fd, 0o600)
        if os.geteuid() == 0:
            os.fchown(fd, uid, gid)
        elif os.geteuid() != uid or os.getegid() != gid:
            raise RuntimeError('Identité Ansible invalide')
        password = _claim_password()
        with os.fdopen(os.dup(fd), 'wb') as output:
            output.write(password)
        os.lseek(fd, 0, os.SEEK_SET)
        yield f'/proc/self/fd/{fd}', fd
    finally:
        os.close(fd)
