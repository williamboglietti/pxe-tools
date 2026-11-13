#!/usr/bin/env python3
"""Prepare an initramfs with one additional public root CA; does not enable TLS.

The input initrd is preserved. The output consists of the validated input and
an independent gzip/newc archive, as allowed by the Linux initramfs format.
"""
import argparse
import ctypes
import errno
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import sys
import tempfile


class BuildError(Exception):
    pass


MAX_LINK_TARGET = 4096
MAX_LINK_BYTES = 16 * 1024 * 1024
MAX_CERT_SIZE = 1024 * 1024
MAX_CERT_BYTES = 16 * 1024 * 1024
MAX_HASH_SLOTS = 256


class ArchiveEntry:
    """A virtual inode; hardlinked paths share the same instance."""
    def __init__(self, mode, offset=0, size=0, target=None):
        self.mode = mode
        self.offset = offset
        self.size = size
        self.target = target


def archive_resolve(entries, path, follow_last=True):
    """Resolve a path in the virtual initrd, never against the host filesystem."""
    remaining = path.split('/')
    resolved = []
    hops = 0
    while remaining:
        part = remaining.pop(0)
        if part in ('', '.'):
            continue
        if part == '..':
            if resolved:
                resolved.pop()
            continue
        candidate = '/'.join(resolved + [part])
        entry = entries.get(candidate)
        if entry is None:
            return None
        if stat.S_ISLNK(entry.mode) and (remaining or follow_last):
            hops += 1
            if hops > 40 or entry.target is None or not entry.target or b'\0' in entry.target:
                return None
            target = os.fsdecode(entry.target)
            if target.startswith('/'):
                resolved = []
            remaining = target.split('/') + remaining
            continue
        if remaining and not stat.S_ISDIR(entry.mode):
            return None
        resolved.append(part)
    return '/'.join(resolved)


def archive_destination(entries, path):
    parent, _, leaf = path.rpartition('/')
    resolved = archive_resolve(entries, parent)
    if resolved is None:
        return None
    parent_node = entries.get(resolved)
    if parent_node is None or not stat.S_ISDIR(parent_node.mode):
        return None
    return (resolved + '/' if resolved else '') + leaf


def archive_remove(entries, path):
    node = entries.get(path)
    if node is None:
        return True
    if stat.S_ISDIR(node.mode) and any(name.startswith(path + '/') for name in entries):
        return False
    del entries[path]
    return True


def archive_apply(entries, links, name, mode, data_offset, size, target, hardlink):
    """Mirror Linux newc link/replacement semantics without extracting files."""
    path = archive_destination(entries, name)
    if path is None:
        return
    existing = entries.get(path)
    if stat.S_ISLNK(mode):
        if target is None:  # Linux skips oversized symlinks.
            return
        if archive_remove(entries, path):
            entries[path] = ArchiveEntry(mode, target=target)
        return

    if existing is not None and stat.S_IFMT(existing.mode) != stat.S_IFMT(mode):
        if not archive_remove(entries, path):
            return
        existing = None
    if stat.S_ISREG(mode):
        linked = False
        if hardlink is not None:
            anchor = links.get(hardlink)
            if anchor is None:
                links[hardlink] = name
            else:
                old_path = archive_resolve(entries, anchor, follow_last=False)
                old_node = entries.get(old_path) if old_path is not None else None
                # A regular-file hardlink whose original name changed type is
                # ambiguous; Linux could follow that link when opening it.
                if old_node is None or not stat.S_ISREG(old_node.mode):
                    raise BuildError('Groupe de liens physiques non fiable dans l’initrd.')
                if not archive_remove(entries, path):
                    return
                if old_path == path:
                    # clean_path removed the anchor before Linux tries link(2).
                    return
                entries[path] = old_node
                existing = old_node
                linked = True
        if existing is None:
            existing = ArchiveEntry(mode)
            entries[path] = existing
        # First entry opens with O_TRUNC; later links preserve prior content
        # unless they carry a payload, in which case Linux truncates to it.
        if not linked or size:
            existing.offset, existing.size = data_offset, size
        existing.mode = mode
    elif stat.S_ISDIR(mode):
        if existing is None:
            entries[path] = ArchiveEntry(mode)
        else:
            existing.mode = mode
    elif existing is None:
        entries[path] = ArchiveEntry(mode)


def openssl(args, data=None):
    try:
        result = subprocess.run(['openssl', *args], input=data, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, check=False, timeout=15)
    except (OSError, subprocess.TimeoutExpired):
        raise BuildError('OpenSSL indisponible ou délai dépassé.') from None
    if result.returncode:
        raise BuildError('Certificat de CA invalide ou non vérifiable.')
    return result.stdout


def validate_ca(path):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > 1024 * 1024:
                raise BuildError('Le certificat de CA doit être un fichier de taille limitée.')
            data = source.read(1024 * 1024 + 1)
    except OSError:
        raise BuildError('Certificat de CA illisible.') from None
    if (not data or len(data) > 1024 * 1024 or data.count(b'-----BEGIN CERTIFICATE-----') != 1
            or data.count(b'-----END CERTIFICATE-----') != 1 or b'PRIVATE KEY' in data):
        raise BuildError('Fournir un seul certificat public, sans clé privée.')
    pem = openssl(['x509', '-inform', 'PEM', '-outform', 'PEM'], data)
    constraints = openssl(['x509', '-noout', '-ext', 'basicConstraints'], pem)
    if not re.search(rb'\bCA\s*:\s*TRUE\b', constraints):
        raise BuildError("Le certificat fourni n'est pas une CA.")
    openssl(['x509', '-noout', '-checkend', '0'], pem)
    names = openssl(['x509', '-noout', '-subject', '-issuer', '-nameopt', 'RFC2253'], pem).decode('utf-8')
    parts = names.strip().splitlines()
    if len(parts) != 2 or not parts[0].startswith('subject=') or not parts[1].startswith('issuer='):
        raise BuildError('Identité de CA illisible.')
    if parts[0][len('subject='):] != parts[1][len('issuer='):]:
        raise BuildError('Le certificat doit être une autorité racine autosignée.')
    with tempfile.TemporaryDirectory(prefix='pxe-ca-verify-') as directory:
        cert = Path(directory) / 'root.pem'
        cert.write_bytes(pem)
        openssl(['verify', '-check_ss_sig', '-CAfile', str(cert), str(cert)])
    subject_hash = openssl(['x509', '-noout', '-subject_hash'], pem).decode('ascii').strip()
    if not re.fullmatch('[0-9a-f]{8}', subject_hash):
        raise BuildError('Empreinte de sujet OpenSSL invalide.')
    der = openssl(['x509', '-outform', 'DER'], pem)
    return pem, subject_hash, hashlib.sha256(der).hexdigest()


def read_exact(stream, size):
    data = stream.read(size)
    if len(data) != size:
        raise BuildError('Archive initrd tronquée.')
    return data


def inspect_initrd(source, subject_hash):
    """Read gzip/newc members and model the final archive without extraction."""
    entries = {'': ArchiveEntry(stat.S_IFDIR | 0o755)}
    hardlinks = {}
    observed = set()
    hash_prefix = 'etc/ssl/certs/' + subject_hash + '.'
    link_bytes = 0
    offset = 0
    trailers = 0
    try:
        with gzip.GzipFile(fileobj=source, mode='rb') as stream:
            while True:
                first = stream.read(1)
                if not first:
                    break
                if first == b'\0':
                    offset += 1
                    continue
                if offset % 4:
                    raise BuildError('Alignement initrd invalide.')
                header = first + read_exact(stream, 109)
                offset += 110
                # Refuser le format CRC plutôt que d'ignorer son contrôle d'intégrité.
                if header[:6] != b'070701':
                    raise BuildError('Initrd non pris en charge : cpio newc sans CRC requis.')
                try:
                    fields = [int(header[6 + 8 * i:14 + 8 * i], 16) for i in range(13)]
                except ValueError:
                    raise BuildError('En-tête cpio invalide.') from None
                inode, mode, nlink, size, dev_major, dev_minor, name_size = (
                    fields[0], fields[1], fields[4], fields[6], fields[7], fields[8], fields[11])
                if not 1 <= name_size <= 4096 or size > 1024 * 1024 * 1024:
                    raise BuildError('Entrée cpio hors limites.')
                raw_name = read_exact(stream, name_size)
                offset += name_size
                if raw_name[-1:] != b'\0' or b'\0' in raw_name[:-1]:
                    raise BuildError('Nom cpio invalide.')
                name = os.fsdecode(raw_name[:-1])
                if name.startswith('/') or any(p == '..' for p in name.split('/')):
                    raise BuildError('Chemin cpio non sûr.')
                name = '/'.join(p for p in name.split('/') if p and p != '.')
                padding = (-offset) % 4
                read_exact(stream, padding)
                offset += padding
                data_offset = offset
                remaining = size
                target_parts = [] if stat.S_ISLNK(mode) and size <= MAX_LINK_TARGET else None
                while remaining:
                    chunk = stream.read(min(1024 * 1024, remaining))
                    if not chunk:
                        raise BuildError('Données cpio tronquées.')
                    if target_parts is not None:
                        target_parts.append(chunk)
                    remaining -= len(chunk)
                    offset += len(chunk)
                padding = (-offset) % 4
                read_exact(stream, padding)
                offset += padding
                if name == 'TRAILER!!!':
                    trailers += 1
                    hardlinks.clear()
                elif name:
                    target = b''.join(target_parts) if target_parts is not None else None
                    if target is not None:
                        link_bytes += len(target)
                        if link_bytes > MAX_LINK_BYTES:
                            raise BuildError('Trop de données de liens dans l’initrd.')
                    # Remember both the archive name and its actual destination:
                    # an existing directory alias may legitimately write a slot
                    # into the trust store, while a transient alias must not hide
                    # a slot that appeared under the original trust-store name.
                    destination = archive_destination(entries, name)
                    for candidate in (name, destination):
                        if candidate is not None and candidate.startswith(hash_prefix):
                            suffix = candidate[len(hash_prefix):]
                            if re.fullmatch(r'0|[1-9][0-9]*', suffix):
                                observed.add(candidate)
                    hardlink = (dev_major, dev_minor, inode) if nlink > 1 and stat.S_ISREG(mode) else None
                    archive_apply(entries, hardlinks, name, mode, data_offset, size, target, hardlink)
    except (OSError, EOFError, gzip.BadGzipFile):
        raise BuildError('Initrd gzip invalide ou illisible.') from None
    if not trailers:
        raise BuildError('Fin de l’archive cpio absente.')
    for directory in ('etc', 'etc/ssl', 'etc/ssl/certs'):
        entry = entries.get(directory)
        if entry is None or not stat.S_ISDIR(entry.mode):
            raise BuildError('Magasin de CA absent ou non fiable dans l’initrd.')
    return entries, observed


def resolve_archive_file(entries, path):
    """Resolve links as paths inside the initrd, without reading the host FS."""
    resolved = archive_resolve(entries, path)
    entry = entries.get(resolved) if resolved is not None else None
    if entry is None or not stat.S_ISREG(entry.mode):
        raise BuildError('Lien de CA existant brisé, cyclique ou non régulier.')
    return entry


def validate_previous_hash_slots(source, entries, observed, subject_hash):
    """Ensure OpenSSL can traverse every preceding collision slot."""
    prefix = 'etc/ssl/certs/' + subject_hash + '.'
    slots = []
    for name in entries:
        if name.startswith(prefix):
            suffix = name[len(prefix):]
            if re.fullmatch(r'0|[1-9][0-9]*', suffix):
                if len(suffix) > 3 or int(suffix) >= MAX_HASH_SLOTS:
                    raise BuildError('Trop de collisions de hachage dans l’initrd.')
                slots.append(int(suffix))
    slots.sort()
    if observed != {prefix + str(slot) for slot in slots}:
        raise BuildError('Des emplacements de CA ont changé de chemin durant l’extraction.')
    if slots != list(range(len(slots))):
        raise BuildError('Séquence de hachage de CA existante discontinue.')

    positions = []
    for slot in slots:
        entry = resolve_archive_file(entries, prefix + str(slot))
        position = (entry.offset, entry.size)
        if not 0 < position[1] <= MAX_CERT_SIZE:
            raise BuildError('Certificat de CA existant absent ou trop volumineux.')
        positions.append(position)
    if sum(size for _, size in set(positions)) > MAX_CERT_BYTES:
        raise BuildError('Certificats de CA existants trop volumineux.')

    data_by_position = {}
    try:
        source.seek(0)
        with gzip.GzipFile(fileobj=source, mode='rb') as stream:
            for position, size in sorted(set(positions)):
                stream.seek(position)
                data_by_position[(position, size)] = read_exact(stream, size)
    except (OSError, EOFError, gzip.BadGzipFile):
        raise BuildError('Lecture des CA existantes impossible.') from None
    for position in positions:
        data = data_by_position[position]
        if data.count(b'-----BEGIN CERTIFICATE-----') != 1 or data.count(b'-----END CERTIFICATE-----') != 1:
            raise BuildError('Certificat PEM existant invalide dans la séquence de hachage.')
        try:
            actual_hash = openssl(['x509', '-inform', 'PEM', '-noout', '-subject_hash'], data).decode('ascii').strip()
        except (BuildError, UnicodeError):
            raise BuildError('Certificat PEM existant invalide dans la séquence de hachage.') from None
        if actual_hash != subject_hash:
            raise BuildError('Certificat existant associé à un mauvais hachage de sujet.')
    return len(slots)


def newc_entry(name, mode, data, inode):
    encoded_name = name.encode('ascii') + b'\0'
    values = (inode, mode, 0, 0, 1, 0, len(data), 0, 0, 0, 0, len(encoded_name), 0)
    record = b'070701' + b''.join(('%08x' % value).encode('ascii') for value in values) + encoded_name
    record += b'\0' * (-len(record) % 4)
    record += data
    record += b'\0' * (-len(record) % 4)
    return record


def digest_file(source):
    source.seek(0)
    digest = hashlib.sha256()
    for block in iter(lambda: source.read(1024 * 1024), b''):
        digest.update(block)
    source.seek(0)
    return digest.hexdigest()


def reject_extended_acl(fd):
    """A mode-bit check alone cannot rule out additional ACL writers."""
    if sys.platform == 'darwin':
        try:
            libc = ctypes.CDLL(None, use_errno=True)
            get_acl = libc.acl_get_fd_np
            free_acl = libc.acl_free
        except (OSError, AttributeError):
            raise BuildError('Contrôle des ACL du répertoire de sortie impossible.') from None
        get_acl.argtypes = [ctypes.c_int, ctypes.c_int]
        get_acl.restype = ctypes.c_void_p
        free_acl.argtypes = [ctypes.c_void_p]
        free_acl.restype = ctypes.c_int
        ctypes.set_errno(0)
        acl = get_acl(fd, 0x100)  # ACL_TYPE_EXTENDED
        if acl:
            free_acl(acl)
            raise BuildError('Le répertoire de sortie ou un parent possède une ACL non contrôlée.')
        if ctypes.get_errno() != errno.ENOENT:
            raise BuildError('Contrôle des ACL du répertoire de sortie impossible.')
    elif sys.platform.startswith('linux'):
        try:
            attributes = os.listxattr(fd)
        except (OSError, AttributeError):
            raise BuildError('Contrôle des ACL du répertoire de sortie impossible.') from None
        if any(name in attributes for name in ('system.posix_acl_access', 'system.posix_acl_default', 'system.nfs4_acl')):
            raise BuildError('Le répertoire de sortie ou un parent possède une ACL non contrôlée.')
    else:
        raise BuildError('Contrôle sécurisé du répertoire non disponible sur ce système.')


def open_safe_output_directory(output):
    """Pin each ancestor and refuse directories other accounts can replace."""
    absolute = os.path.abspath(os.fspath(output))
    parent, name = os.path.split(absolute)
    if not name or name in ('.', '..'):
        raise BuildError('Nom de fichier de sortie invalide.')
    flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
    parts = [part for part in parent.split(os.sep) if part]
    fd = os.open(os.sep, flags)
    try:
        for index in range(len(parts) + 1):
            info = os.fstat(fd)
            final = index == len(parts)
            if info.st_uid not in (0, os.geteuid()):
                raise BuildError('Le répertoire de sortie ou un parent appartient à un autre compte.')
            if info.st_mode & 0o022 and (final or not info.st_mode & stat.S_ISVTX):
                raise BuildError('Le répertoire de sortie ou un parent est modifiable par un autre compte.')
            reject_extended_acl(fd)
            if not final:
                next_fd = os.open(parts[index], flags, dir_fd=fd)
                os.close(fd)
                fd = next_fd
        return fd, name
    except BaseException:
        os.close(fd)
        raise


def build(initrd, ca, output):
    pem, subject_hash, fingerprint = validate_ca(ca)
    try:
        directory_fd, output_name = open_safe_output_directory(output)
    except OSError:
        raise BuildError('Répertoire de sortie inaccessible ou lien symbolique interdit.') from None
    try:
        fd = os.open(initrd, os.O_RDONLY | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            source_stat = os.fstat(source.fileno())
            if not stat.S_ISREG(source_stat.st_mode):
                raise BuildError('L’initrd source doit être un fichier régulier.')
            source_mode = stat.S_IMODE(source_stat.st_mode) & 0o644
            source_digest = digest_file(source)
            entries, observed = inspect_initrd(source, subject_hash)
            cert_name = 'PXE-Root-' + fingerprint[:24] + '.crt'
            cert_path = 'etc/ssl/certs/' + cert_name
            if cert_path in entries:
                raise BuildError('Cette CA semble déjà présente dans l’initrd fourni.')
            index = validate_previous_hash_slots(source, entries, observed, subject_hash)
            if index >= MAX_HASH_SLOTS:
                raise BuildError('Aucun emplacement de hachage de CA disponible.')
            link = 'etc/ssl/certs/%s.%d' % (subject_hash, index)
            archive = (newc_entry(cert_path, stat.S_IFREG | 0o644, pem, 1)
                       + newc_entry(link, stat.S_IFLNK | 0o777, cert_name.encode('ascii'), 2)
                       + newc_entry('TRAILER!!!', 0, b'', 3))
            overlay = gzip.compress(archive, mtime=0)
            source.seek(0)
            # Create and publish relative to the pinned, access-checked directory.
            # O_EXCL prevents a pre-existing staging file from being replaced.
            temporary = '.pxe-initrd-' + secrets.token_hex(16)
            temp_fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                              0o600, dir_fd=directory_fd)
            try:
                with os.fdopen(temp_fd, 'wb') as temp:
                    copy_digest = hashlib.sha256()
                    for block in iter(lambda: source.read(1024 * 1024), b''):
                        copy_digest.update(block)
                        temp.write(block)
                    if copy_digest.hexdigest() != source_digest:
                        raise BuildError('L’initrd source a changé pendant la préparation.')
                    temp.write(overlay)
                    temp.flush()
                    os.fchmod(temp.fileno(), source_mode)
                    os.fsync(temp.fileno())
                # The checked directory has no untrusted writers; linkat is atomic
                # and refuses to replace an existing destination.
                os.link(temporary, output_name, src_dir_fd=directory_fd,
                        dst_dir_fd=directory_fd, follow_symlinks=False)
            finally:
                os.unlink(temporary, dir_fd=directory_fd)
    except BuildError:
        raise
    except OSError:
        raise BuildError('Lecture de l’initrd ou création de la sortie impossible.') from None
    finally:
        os.close(directory_fd)
    return {'initrd_sha256': source_digest, 'ca_sha256': fingerprint, 'ca_link': link}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--initrd', required=True, help='initrd gzip/newc original')
    parser.add_argument('--ca', required=True, help='certificat public PEM de la CA racine')
    parser.add_argument('--output', required=True, help='nouveau fichier à créer')
    args = parser.parse_args()
    try:
        print(json.dumps(build(args.initrd, args.ca, args.output), sort_keys=True))
        return 0
    except BuildError as error:
        print('Erreur : ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
