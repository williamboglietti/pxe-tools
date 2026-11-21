#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Prepare an explicitly authorized APT client certificate transition.

This candidate requires independently verified fingerprints and signed
metadata. No automatic download, publication or key rotation is performed.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import stat
import tempfile

ROOT = Path(__file__).resolve().parents[2]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    if spec is None or spec.loader is None:
        raise RuntimeError('required transition helper unavailable')
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result

uninstall = load('pxe_uninstall_for_transition', 'packaging/native/uninstall-apt.py')
inspector = load('pxe_key_inspector_for_transition', 'packaging/native/check-apt-key-transition.py')
KEY_RELATIVE = Path('etc/apt/keyrings/pxe-tools-archive-keyring.gpg')
CONFIG = {'SOURCE': '/etc/apt/sources.list', 'PARTS': '/etc/apt/sources.list.d',
          'TRUSTED': '/etc/apt/trusted.gpg', 'TRUSTEDPARTS': '/etc/apt/trusted.gpg.d', 'ROOT': ''}


def require_fresh_release(signed: bytes, *, now: datetime | None = None) -> None:
    """Check dates only after the caller has verified the cleartext signature."""
    text = signed.decode('utf-8').replace('\r\n', '\n')
    if not text.startswith('-----BEGIN PGP SIGNED MESSAGE-----\n'):
        raise ValueError('new signed index is not a clear-signed InRelease')
    header, separator, remainder = text.partition('\n\n')
    content, marker, _ = remainder.partition('\n-----BEGIN PGP SIGNATURE-----')
    if not separator or not marker or '\r' in header or '\r' in content:
        raise ValueError('new signed index has an unsupported format')
    fields = {}
    for line in content.splitlines():
        if line.startswith(('Date:', 'Valid-Until:')):
            name, value = line.split(':', 1)
            if name in fields:
                raise ValueError('new signed index has duplicate date fields')
            fields[name] = value.strip()
    if set(fields) != {'Date', 'Valid-Until'}:
        raise ValueError('new signed index must have Date and Valid-Until')
    try:
        issued, expires = (parsedate_to_datetime(fields[name]) for name in ('Date', 'Valid-Until'))
    except (TypeError, ValueError, OverflowError) as exc:
        raise ValueError('invalid new signed index dates') from exc
    if issued.tzinfo is None or expires.tzinfo is None:
        raise ValueError('new signed index dates must include a timezone')
    current = now or datetime.now(timezone.utc)
    if issued > current + timedelta(minutes=10) or expires <= current or expires <= issued:
        raise ValueError('new signed index is expired or not yet valid')


def inspect_candidate(root: Path, new_certificate: Path, old_release: Path, new_release: Path,
                      primary: str, old_signer: str, new_signer: str, *, owner, apt_config):
    key = root / KEY_RELATIVE
    # The existing uninstall guard proves the source and original key witness,
    # and rejects shared, redirected, symlinked or ambiguous configurations.
    if uninstall.apt_cleanup_plan(root, owner=owner, apt_config=apt_config) != (True, True):
        raise ValueError('source or key ownership and exclusive use are not established')
    report = inspector.inspect(key, new_certificate, primary, old_signer, new_signer)
    old_bytes = inspector.read_regular(key)
    new_bytes = inspector.read_regular(new_certificate)
    if hashlib.sha256(old_bytes).hexdigest() != report['old_certificate_sha256'] or \
       hashlib.sha256(new_bytes).hexdigest() != report['new_certificate_sha256']:
        raise ValueError('certificate changed after inspection')
    # Test both signers against the exact candidate bytes. This prevents a
    # certificate which cannot still authenticate the old index from being
    # installed during the overlap. The new proof must also be timely below.
    old_release_bytes = inspector.read_regular(old_release)
    new_release_bytes = inspector.read_regular(new_release)
    with tempfile.TemporaryDirectory(prefix='pxe-client-proof-', dir='/tmp') as temporary:
        proof_root = Path(temporary)
        old_cert_copy, new_cert_copy = proof_root / 'old.gpg', proof_root / 'new.gpg'
        old_index_copy, new_index_copy = proof_root / 'old.InRelease', proof_root / 'new.InRelease'
        for path, data in ((old_cert_copy, old_bytes), (new_cert_copy, new_bytes),
                           (old_index_copy, old_release_bytes), (new_index_copy, new_release_bytes)):
            path.write_bytes(data)
        inspector.proof.verify_signature(old_cert_copy, old_index_copy, old_signer.upper(), primary.upper())
        inspector.proof.verify_signature(new_cert_copy, old_index_copy, old_signer.upper(), primary.upper())
        inspector.proof.verify_signature(new_cert_copy, new_index_copy, new_signer.upper(), primary.upper())
        # The historical old proof may have expired while a client was offline;
        # require a fresh new-signer proof before changing client trust.
        require_fresh_release(new_release_bytes)
    report['applied'] = False
    report['inspection_only'] = True
    return report, old_bytes, new_bytes


def apply_locked(root: Path, new_certificate: Path, old_release: Path, new_release: Path,
                 primary: str, old_signer: str, new_signer: str, *, owner, config_provider,
                 mounts_provider) -> dict:
    """Caller must hold the installer and dpkg frontend locks in that order."""
    key = root / KEY_RELATIVE
    def check_mounts():
        # A mounted file is not replaced by renaming its parent directory entry.
        # Also reject mounts directly on the proof/source files.
        paths = [key, root / 'etc/apt/sources.list.d/pxe-tools.sources']
        paths.extend(key.parent.glob('.pxe-tools-archive-keyring.*.created'))
        paths.extend((root / 'etc/apt/sources.list.d').glob('.pxe-tools-sources.*.created'))
        mounts = set(mounts_provider())
        if any(str(path) in mounts for path in paths):
            raise ValueError('APT source, certificate or witness is a mountpoint')

    check_mounts()
    report, old_bytes, new_bytes = inspect_candidate(root, new_certificate, old_release, new_release,
                                                     primary, old_signer, new_signer,
                                                     owner=owner, apt_config=config_provider())
    # Keep the old hard-link witness untouched. An interrupted atomic rename
    # leaves either the old or new key at the same path; the new key is never
    # silently claimed as installer-owned by the old witness.
    directory = os.open(key.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    temporary = '.pxe-key-transition-' + secrets.token_hex(16)
    created = False
    try:
        check_mounts()
        if uninstall.apt_cleanup_plan(root, owner=owner, apt_config=config_provider()) != (True, True):
            raise ValueError('source or key ownership changed before replacement')
        current = os.stat(key.name, dir_fd=directory, follow_symlinks=False)
        if not stat.S_ISREG(current.st_mode) or inspector.read_regular(key) != old_bytes:
            raise ValueError('installed key changed before replacement')
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600,
                             dir_fd=directory)
        created = True
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(new_bytes)
            stream.flush()
            os.fchmod(stream.fileno(), 0o644)
            os.fsync(stream.fileno())
        check_mounts()
        if uninstall.apt_cleanup_plan(root, owner=owner, apt_config=config_provider()) != (True, True):
            raise ValueError('source or key ownership changed during staging')
        if not os.path.samestat(os.fstat(directory), os.stat(key.parent, follow_symlinks=False)):
            raise ValueError('key directory changed during staging')
        again = os.stat(key.name, dir_fd=directory, follow_symlinks=False)
        if (again.st_dev, again.st_ino) != (current.st_dev, current.st_ino) or inspector.read_regular(key) != old_bytes:
            raise ValueError('installed key changed during staging')
        os.replace(temporary, key.name, src_dir_fd=directory, dst_dir_fd=directory)
        created = False
        try:
            os.fsync(directory)
        except OSError as exc:
            raise RuntimeError('certificate was replaced but directory durability is uncertain; inspect before retrying') from exc
        report['applied'] = True
        report['inspection_only'] = False
        report['client_keyring_modified'] = True
        return report
    finally:
        if created:
            try:
                os.unlink(temporary, dir_fd=directory)
            except FileNotFoundError:
                pass
        os.close(directory)


def apply_with_locks(root: Path, new_certificate: Path, old_release: Path, new_release: Path,
                     primary: str, old_signer: str, new_signer: str, *, owner, config_provider,
                     mounts_provider) -> dict:
    # Match the installer's outer flock and the uninstaller's dpkg ordering.
    with uninstall.installer_lock(root / 'run/lock/pxe-tools-apt-install.lock', owner=owner):
        with uninstall.dpkg_frontend_lock(root / 'var/lib/dpkg/lock-frontend', owner=owner):
            return apply_locked(root, new_certificate, old_release, new_release, primary, old_signer,
                                new_signer, owner=owner, config_provider=config_provider,
                                mounts_provider=mounts_provider)


def rehearse(root: Path, new_certificate: Path, old_release: Path, new_release: Path,
             primary: str, old_signer: str, new_signer: str, *, apply: bool = False) -> dict:
    if os.environ.get('PXE_APT_ROTATION_FIXTURE') != '1':
        raise ValueError('this preparation requires a disposable fixture')
    marker = root / '.pxe-rotation-fixture'
    if (not root.is_absolute() or root.is_symlink() or not root.is_dir()
            or root.resolve().parent != Path('/tmp').resolve()
            or not root.name.startswith('pxe-rotation-client-')
            or root.stat().st_uid != os.getuid()
            or marker.is_symlink() or not marker.is_file()
            or marker.read_bytes() != b'PXE_TOOLS_ROTATION_DISPOSABLE\n'):
        raise ValueError('an explicitly marked disposable fixture root is required')
    if apply:
        return apply_with_locks(root, new_certificate, old_release, new_release, primary, old_signer,
                                new_signer, owner=None, config_provider=lambda: CONFIG,
                                mounts_provider=lambda: ())
    report, _, _ = inspect_candidate(root, new_certificate, old_release, new_release,
                                     primary, old_signer, new_signer, owner=None, apt_config=CONFIG)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument('--fixture-root', type=Path)
    target.add_argument('--inspect-system', action='store_true', help='read-only inspection; never apply')
    target.add_argument('--apply-system', action='store_true', help='apply a separately authorized transition as root')
    parser.add_argument('--new-certificate', required=True, type=Path)
    parser.add_argument('--old-inrelease', required=True, type=Path)
    parser.add_argument('--new-inrelease', required=True, type=Path)
    parser.add_argument('--primary-fingerprint', required=True)
    parser.add_argument('--old-signing-fingerprint', required=True)
    parser.add_argument('--new-signing-fingerprint', required=True)
    parser.add_argument('--apply-to-fixture', action='store_true')
    parser.add_argument('--acknowledge-independent-fingerprints', action='store_true')
    args = parser.parse_args()
    if args.apply_system:
        if args.apply_to_fixture or not args.acknowledge_independent_fingerprints:
            raise ValueError('system application requires independent fingerprint confirmation and no fixture flag')
        if os.geteuid() != 0:
            raise ValueError('system application requires root')
        os.environ['PATH'] = '/usr/sbin:/usr/bin:/sbin:/bin'
        result = apply_with_locks(Path('/'), args.new_certificate, args.old_inrelease, args.new_inrelease,
                                  args.primary_fingerprint, args.old_signing_fingerprint, args.new_signing_fingerprint,
                                  owner=0, config_provider=uninstall._apt_effective_config,
                                  mounts_provider=lambda: uninstall.parse_mountinfo(
                                      Path('/proc/self/mountinfo').read_text(encoding='utf-8')))
    elif args.inspect_system:
        if args.apply_to_fixture:
            raise ValueError('system inspection cannot apply a replacement')
        # Read-only: no installer lock, APT configuration or keyring is modified.
        # A later migration would need to lock and revalidate the whole state.
        result, _, _ = inspect_candidate(Path('/'), args.new_certificate, args.old_inrelease,
                                         args.new_inrelease, args.primary_fingerprint,
                                         args.old_signing_fingerprint, args.new_signing_fingerprint,
                                         owner=0, apt_config=uninstall._apt_effective_config())
    else:
        result = rehearse(args.fixture_root, args.new_certificate, args.old_inrelease, args.new_inrelease,
                          args.primary_fingerprint, args.old_signing_fingerprint, args.new_signing_fingerprint,
                          apply=args.apply_to_fixture)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, UnicodeError) as exc:
        raise SystemExit(str(exc))
