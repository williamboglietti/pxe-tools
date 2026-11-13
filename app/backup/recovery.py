# SPDX-License-Identifier: LGPL-3.0-only
"""Experimental encrypted SQLite + master-key backup into a new offline directory.

A full database contains accounts, machine data, secrets and active job/token
state. Restore prepares an inactive copy and invalidates transient operations;
it does not migrate an existing installation or start any service.
Inputs and their parent directories must be trusted and the source stopped.
"""
from __future__ import annotations

from contextlib import closing
import io
import hmac
import json
import os
from pathlib import Path
import shutil
import sqlite3
import stat
import subprocess
import tarfile
import tempfile
import time
from urllib.parse import quote

from .openpgp import BackupError, MAX_ARCHIVE, MAX_PLAINTEXT, _decrypt_bounded, _gpg_home, _publish, encrypt_file
from .assets import (MAX_ASSET_BYTES, MAX_ASSET_ENTRIES, TREE_CATEGORIES, add_tree,
                     inspect_tree, netboot_alias_target, safe_asset_name)

MAX_DB = 512 * 1024 * 1024
MANIFEST = {'format': 'pxe-tools-offline-recovery', 'version': 1,
            'includes': ['accounts', 'inventory', 'secrets', 'master_key']}
OPTIONAL_FILES = {'db_config': ('db.conf',), 'mok': ('MOK.key', 'MOK.pem', 'MOK.der')}
MAX_OPTIONAL = 64 * 1024
REQUIRED_TABLES = {
    'machines': ('id', 'mac'), 'presets': ('id', 'name'),
    'users': ('id', 'username', 'password_hash'), 'settings': ('key', 'value'),
    'actions': ('id', 'status', 'token'), 'init_jobs': ('id', 'status'),
    'boot_sessions': ('id', 'machine_id', 'token'),
}


def _private_destination(path: Path) -> Path:
    if not path.is_absolute() or path.name in ('', '.', '..'):
        raise BackupError('an absolute new destination is required')
    parent = path.parent.resolve(strict=True)
    info = parent.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
        raise BackupError('destination parent must be owned by the caller and not writable by others')
    target = parent / path.name
    if os.path.lexists(target):
        raise BackupError('destination already exists')
    return target


def _regular_input(path: Path, max_size: int) -> os.stat_result:
    if not path.is_absolute() or path.is_symlink():
        raise BackupError('an absolute non-symlink source is required')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_size > max_size:
        raise BackupError('invalid source file')
    return info


def _sidecars(path: Path) -> bool:
    return any(os.path.lexists(str(path) + suffix) for suffix in ('-wal', '-shm', '-journal'))


def _validate_database(path: Path) -> None:
    uri = 'file:' + quote(str(path.resolve(strict=True)), safe='/') + '?mode=ro'
    with closing(sqlite3.connect(uri, uri=True, timeout=5)) as db:
        db.execute('PRAGMA query_only=ON')
        db.execute('PRAGMA trusted_schema=OFF')
        tables = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if not set(REQUIRED_TABLES).issubset(tables):
            raise BackupError('unsupported or incomplete PXE Tools database')
        for name, columns in REQUIRED_TABLES.items():
            found = {row[1] for row in db.execute(f'PRAGMA table_info("{name}")')}
            if not set(columns).issubset(found):
                raise BackupError('unsupported or incomplete PXE Tools database')
        if db.execute('PRAGMA integrity_check').fetchone() != ('ok',):
            raise BackupError('database integrity check failed')
        if db.execute('PRAGMA foreign_key_check').fetchone() is not None:
            raise BackupError('database references are inconsistent')


def _snapshot(source: Path, target: Path) -> None:
    before = _regular_input(source, MAX_DB)
    resolved = source.resolve(strict=True)
    if _sidecars(resolved):
        raise BackupError('SQLite journal present; stop the source cleanly')
    # SQLite may create WAL/SHM sidecars on a read-only connection to a
    # database configured in WAL mode. Immutable is safe here only because
    # the operator confirmed a stopped source and sidecars were refused.
    uri = 'file:' + quote(str(resolved), safe='/') + '?mode=ro&immutable=1'
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    os.close(fd)
    deadline = time.monotonic() + 60
    def progress(_status, _remaining, _total):
        if time.monotonic() > deadline:
            raise BackupError('database snapshot timed out')
    with closing(sqlite3.connect(uri, uri=True, timeout=5)) as original, closing(sqlite3.connect(target)) as copy:
        original.execute('PRAGMA query_only=ON')
        original.backup(copy, pages=256, progress=progress, sleep=0.05)
        if _sidecars(resolved):
            raise BackupError('SQLite journal appeared during snapshot')
    after = source.stat()
    if (_sidecars(resolved) or (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns)
            != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)):
        raise BackupError('source changed during snapshot')
    if target.stat().st_size > MAX_DB:
        raise BackupError('database exceeds the backup limit')
    _validate_database(target)


def _read_key(path: Path) -> bytes:
    _regular_input(path, 32)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size != 32:
            raise BackupError('master key must contain exactly 32 bytes')
        key = stream.read(33)
    if len(key) != 32:
        raise BackupError('master key must contain exactly 32 bytes')
    return key


def _read_optional(path: Path) -> bytes:
    _regular_input(path, MAX_OPTIONAL)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_OPTIONAL:
            raise BackupError('invalid optional recovery file')
        data = stream.read(MAX_OPTIONAL + 1)
    if len(data) != info.st_size:
        raise BackupError('optional recovery file changed during reading')
    return data


def _unique_object(pairs):
    result = {}
    for name, value in pairs:
        if name in result:
            raise BackupError('duplicate recovery metadata')
        result[name] = value
    return result


def _validate_db_config(data: bytes) -> None:
    def invalid_constant(_value):
        raise BackupError('invalid SQLite configuration')
    try:
        config = json.loads(data.decode('utf-8'), object_pairs_hook=_unique_object, parse_constant=invalid_constant)
    except (UnicodeError, ValueError, RecursionError) as error:
        raise BackupError('invalid SQLite configuration') from error
    if not isinstance(config, dict) or not isinstance(config.get('driver'), str) or config['driver'].lower() != 'sqlite':
        raise BackupError('only an explicit SQLite configuration can be included')
    path = config.get('path', '/var/lib/pxe-tools/pxe-tools.sqlite')
    if (not isinstance(path, str) or not path.startswith('/')
            or any(ord(char) < 32 or ord(char) == 127 for char in path)):
        raise BackupError('invalid SQLite configuration path')


def _validate_mok(files: dict[str, bytes]) -> None:
    def openssl(args: list[str], data: bytes) -> bytes:
        result = subprocess.run(['openssl', *args], input=data, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=20, check=False)
        if result.returncode or not result.stdout:
            raise BackupError('invalid MOK key or certificate')
        return result.stdout
    private_public = openssl(['pkey', '-passin', 'pass:', '-pubout', '-outform', 'DER'], files['MOK.key'])
    certificate_public = openssl(['x509', '-pubkey', '-noout'], files['MOK.pem'])
    certificate_public = openssl(['pkey', '-pubin', '-pubout', '-outform', 'DER'], certificate_public)
    certificate_der = openssl(['x509', '-outform', 'DER'], files['MOK.pem'])
    if (not hmac.compare_digest(private_public, certificate_public)
            or not hmac.compare_digest(certificate_der, files['MOK.der'])):
        raise BackupError('MOK key and certificates do not match')


def _read_options(db_config: Path | None, mok_dir: Path | None) -> dict[str, bytes]:
    files: dict[str, bytes] = {}
    if db_config is not None:
        files['db.conf'] = _read_optional(db_config)
        _validate_db_config(files['db.conf'])
    if mok_dir is not None:
        if not mok_dir.is_absolute() or mok_dir.is_symlink() or not mok_dir.is_dir():
            raise BackupError('an absolute non-symlink MOK directory is required')
        for name in OPTIONAL_FILES['mok']:
            files[name] = _read_optional(mok_dir/name)
        _validate_mok(files)
    return files


def _manifest(options: dict[str, bytes], trees: tuple[str, ...] = (), *, aliases: bool = False) -> dict:
    selected = [category for category, files in OPTIONAL_FILES.items() if all(name in options for name in files)]
    selected_trees = [name for name in TREE_CATEGORIES if name in trees]
    if selected_trees:
        return {**MANIFEST, 'version': 4 if aliases else 3, 'includes': [*MANIFEST['includes'], *selected, *selected_trees]}
    return ({**MANIFEST, 'version': 2, 'includes': [*MANIFEST['includes'], *selected]} if selected else MANIFEST)


def _tar_member(name: str, data: bytes) -> tuple[tarfile.TarInfo, io.BytesIO]:
    entry = tarfile.TarInfo(name)
    entry.size, entry.mode, entry.mtime = len(data), 0o600, 0
    entry.uid = entry.gid = 0
    return entry, io.BytesIO(data)


def export_recovery(database: Path, key: Path, destination: Path, passphrase: bytes, *,
                    confirm_stopped: bool, include_accounts: bool, include_inventory: bool,
                    include_secrets: bool, include_master_key: bool,
                    db_config: Path | None = None, include_db_config: bool = False,
                    confirm_db_config_matches: bool = False,
                    mok_dir: Path | None = None, include_mok: bool = False,
                    http_root: Path | None = None, include_http: bool = False,
                    tftp_root: Path | None = None, include_tftp: bool = False,
                    tls_root: Path | None = None, include_tls: bool = False,
                    ansible_root: Path | None = None, include_ansible: bool = False) -> None:
    """Export all four sensitive categories; no selective filtering is claimed."""
    if any(value is not True for value in (confirm_stopped, include_accounts, include_inventory,
                                           include_secrets, include_master_key)):
        raise BackupError('stopped source and all sensitive categories require explicit confirmation')
    if (type(include_db_config) is not bool or type(confirm_db_config_matches) is not bool or type(include_mok) is not bool
            or type(include_http) is not bool or type(include_tftp) is not bool or type(include_tls) is not bool
            or type(include_ansible) is not bool
            or include_db_config != (db_config is not None) or include_mok != (mok_dir is not None)
            or include_http != (http_root is not None) or include_tftp != (tftp_root is not None)
            or include_tls != (tls_root is not None)
            or include_ansible != (ansible_root is not None)
            or confirm_db_config_matches != include_db_config):
        raise BackupError('optional files and their explicit confirmations must be selected together')
    try:
        database, key = Path(database), Path(key)
        destination = _private_destination(Path(destination))
        master_key = _read_key(key)
        config_path = Path(db_config) if db_config is not None else None
        mok_path = Path(mok_dir) if mok_dir is not None else None
        options = _read_options(config_path, mok_path)
        roots = {name: Path(path) for name, path in (('http_files', http_root), ('tftp_files', tftp_root),
                 ('tls_files', tls_root), ('ansible_files', ansible_root))
                 if path is not None}
        records = {}
        for name, path in roots.items():
            tree = inspect_tree(path, TREE_CATEGORIES[name])
            if destination.parent.is_relative_to(path.resolve(strict=True)):
                raise BackupError('backup destination cannot be inside a selected asset tree')
            records[name] = tree
        if (sum(len(tree) for tree in records.values()) > MAX_ASSET_ENTRIES
                or sum(value[1][3] for tree in records.values() for value in tree.values() if value[0] == 'file') > MAX_ASSET_BYTES):
            raise BackupError('selected assets exceed the combined limits')
        database_before = _regular_input(database, MAX_DB)
        with tempfile.TemporaryDirectory(prefix='.pxe-recovery-export-', dir=destination.parent) as directory:
            root = Path(directory); snapshot = root/'database.sqlite'; plain = root/'recovery.tar'
            _snapshot(database, snapshot)
            if _read_key(key) != master_key:
                raise BackupError('master key changed during snapshot')
            if _read_options(config_path, mok_path) != options:
                raise BackupError('optional recovery files changed during snapshot')
            with plain.open('xb') as stream:
                os.chmod(plain, 0o600)
                with tarfile.open(fileobj=stream, mode='w', format=tarfile.USTAR_FORMAT) as tar:
                    info, data = _tar_member('manifest.json', json.dumps(_manifest(options, tuple(roots), aliases=any(value[0] == 'link' for tree in records.values() for value in tree.values())), separators=(',', ':')).encode())
                    tar.addfile(info, data)
                    info, data = _tar_member('master.key', master_key)
                    tar.addfile(info, data)
                    info = tarfile.TarInfo('database.sqlite')
                    info.size, info.mode, info.mtime = snapshot.stat().st_size, 0o600, 0
                    info.uid = info.gid = 0
                    with snapshot.open('rb') as content:
                        tar.addfile(info, content)
                    for name, value in options.items():
                        info, data = _tar_member(name, value)
                        tar.addfile(info, data)
                    for name, path in roots.items():
                        add_tree(tar, path, TREE_CATEGORIES[name], records[name])
            for name, path in roots.items():
                if inspect_tree(path, TREE_CATEGORIES[name]) != records[name]:
                    raise BackupError('asset tree changed during capture')
            after = _regular_input(database, MAX_DB)
            if ((database_before.st_dev, database_before.st_ino, database_before.st_size, database_before.st_mtime_ns)
                    != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns)
                    or _sidecars(database.resolve(strict=True)) or _read_key(key) != master_key
                    or _read_options(config_path, mok_path) != options):
                raise BackupError('source changed during capture')
            if plain.stat().st_size > MAX_PLAINTEXT:
                raise BackupError('plaintext archive exceeds limit')
            encrypted = root/'recovery.gpg'
            with _gpg_home() as home:
                encrypt_file(plain, encrypted, passphrase, home)
            _publish(encrypted, destination)
    except BackupError:
        raise
    except (OSError, sqlite3.Error, subprocess.SubprocessError, tarfile.TarError, ValueError) as error:
        raise BackupError('backup failed; no existing destination was replaced') from error


def _unpack_authenticated(plain: Path, root: Path) -> tuple[list[str], dict, dict, dict]:
    mandatory = {'manifest.json', 'master.key', 'database.sqlite'}
    allowed = mandatory | {name for files in OPTIONAL_FILES.values() for name in files}
    seen: set[str] = set()
    directories: dict[str, int] = {}
    files: dict[str, int] = {}
    links: dict[str, str] = {}
    asset_bytes = 0
    with tarfile.open(plain, mode='r|') as tar:
        for member in tar:
            if member.name in seen or member.pax_headers or member.size < 0:
                raise BackupError('invalid recovery archive contents')
            if member.name not in allowed:
                parts = safe_asset_name(member.name)
                if len(directories) + len(files) + len(links) >= MAX_ASSET_ENTRIES:
                    raise BackupError('too many asset entries')
                if member.mode < 0 or member.mode & ~0o777:
                    raise BackupError('invalid asset permissions')
                if len(parts) > 1 and '/'.join(parts[:-1]) not in directories:
                    raise BackupError('asset parent directory missing')
                target = root.joinpath(*parts)
                if member.isdir():
                    if member.size != 0:
                        raise BackupError('invalid asset directory')
                    target.mkdir(mode=0o700)
                    directories[member.name] = member.mode
                elif member.type in (tarfile.REGTYPE, tarfile.AREGTYPE):
                    if len(parts) == 1:
                        raise BackupError('asset root must be a directory')
                    asset_bytes += member.size
                    if asset_bytes > MAX_ASSET_BYTES:
                        raise BackupError('asset data exceeds restore limit')
                    source = tar.extractfile(member)
                    if source is None:
                        raise BackupError('invalid asset file')
                    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
                    with source, os.fdopen(fd, 'wb') as output:
                        shutil.copyfileobj(source, output, length=256 * 1024)
                        output.flush()
                        os.fsync(output.fileno())
                    if target.stat().st_size != member.size:
                        raise BackupError('truncated asset file')
                    files[member.name] = member.mode
                elif member.issym():
                    if member.size != 0:
                        raise BackupError('invalid asset link')
                    links[member.name] = member.linkname
                else:
                    raise BackupError('unsupported asset file type')
                seen.add(member.name)
                continue
            if member.type not in (tarfile.REGTYPE, tarfile.AREGTYPE):
                raise BackupError('invalid recovery archive contents')
            if member.name == 'manifest.json' and member.size > 4096:
                raise BackupError('invalid recovery manifest')
            if member.name == 'master.key' and member.size != 32:
                raise BackupError('invalid master key')
            if member.name == 'database.sqlite' and member.size > MAX_DB:
                raise BackupError('database exceeds restore limit')
            if member.name not in mandatory and not 0 < member.size <= MAX_OPTIONAL:
                raise BackupError('invalid optional recovery file')
            seen.add(member.name)
            source = tar.extractfile(member)
            if source is None:
                raise BackupError('invalid recovery archive member')
            target = root/member.name
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with source, os.fdopen(fd, 'wb') as output:
                shutil.copyfileobj(source, output, length=256 * 1024)
                output.flush()
                os.fsync(output.fileno())
            if target.stat().st_size != member.size:
                raise BackupError('truncated recovery archive')
    if not mandatory.issubset(seen):
        raise BackupError('incomplete recovery archive')
    optional_names = seen & (allowed - mandatory)
    optional = {name: (root/name).read_bytes() for name in optional_names}
    trees = tuple(name for name, prefix in TREE_CATEGORIES.items() if prefix in directories)
    kinds = {**{name: 'dir' for name in directories}, **{name: 'file' for name in files}}
    for name, target in links.items():
        netboot_alias_target(name, target, kinds)
    expected_manifest = _manifest(optional, trees, aliases=bool(links))
    expected_files = mandatory | {name for category, files in OPTIONAL_FILES.items()
                                  if category in expected_manifest['includes'] for name in files}
    expected_files |= set(directories) | set(files) | set(links)
    manifest = json.loads((root/'manifest.json').read_text(encoding='utf-8'), object_pairs_hook=_unique_object)
    if (not isinstance(manifest, dict) or type(manifest.get('version')) is not int
            or manifest != expected_manifest or seen != expected_files):
        raise BackupError('unsupported recovery format')
    if 'db.conf' in optional:
        _validate_db_config(optional['db.conf'])
    if 'mok' in expected_manifest['includes']:
        _validate_mok(optional)
    _validate_database(root/'database.sqlite')
    return (['database.sqlite', 'master.key', 'manifest.json', *sorted(optional_names)],
            directories, files, links)


def _prepare_standby(database: Path, *, has_http: bool) -> None:
    """Make the authenticated copy inert before publishing it to the operator.

    The original archive stays unchanged. Unknown schemas or missing columns
    fail closed instead of publishing a partially neutralised database.
    """
    updates = (
        "UPDATE settings SET value='0' WHERE key='wizard_completed'",
        "INSERT OR IGNORE INTO settings(key,value) VALUES('wizard_completed','0')",
        "UPDATE settings SET value='off' WHERE key='dhcp_proxy_enabled'",
        "INSERT OR IGNORE INTO settings(key,value) VALUES('dhcp_proxy_enabled','off')",
        "UPDATE settings SET value='off' WHERE key='ansible_enabled'",
        "INSERT OR IGNORE INTO settings(key,value) VALUES('ansible_enabled','off')",
        "UPDATE settings SET value='off' WHERE key='ansible_repo_webhook_enabled'",
        "INSERT OR IGNORE INTO settings(key,value) VALUES('ansible_repo_webhook_enabled','off')",
        "UPDATE actions SET token=lower(hex(randomblob(32)))",
        "UPDATE boot_sessions SET token=lower(hex(randomblob(32)))",
        "UPDATE actions SET status='aborted', ended_at=CURRENT_TIMESTAMP WHERE status NOT IN ('success','failed','cancelled','aborted') OR status IS NULL",
        "UPDATE init_jobs SET status='failed', message='Interrupted by standby restore' WHERE status NOT IN ('success','failed','skipped','cancelled','aborted') OR status IS NULL",
        # The worker can automatically retry the latest failed GitHub push or
        # delete after Ansible is enabled again. Add a terminal sentinel for
        # each such job type, retaining the original failure and its log.
        "INSERT INTO init_jobs(job_type,status,message,ended_at) SELECT j.job_type,'cancelled','Retry blocked by standby restore',CURRENT_TIMESTAMP FROM init_jobs AS j WHERE j.status='failed' AND (j.job_type LIKE 'preset:push_github:%' OR j.job_type LIKE 'preset:delete_github:%') AND j.id=(SELECT MAX(id) FROM init_jobs WHERE job_type=j.job_type)",
        "UPDATE installations SET install_status='aborted', ended_at=CURRENT_TIMESTAMP WHERE install_status NOT IN ('success','failed','aborted','cancelled') OR install_status IS NULL",
        "UPDATE installations SET ansible_status='aborted' WHERE ansible_status IS NOT NULL AND ansible_status NOT IN ('success','failed','aborted','cancelled','no_role','skipped')",
        "UPDATE wipes SET wipe_status='aborted', ended_at=CURRENT_TIMESTAMP WHERE wipe_status NOT IN ('success','failed','aborted','cancelled') OR wipe_status IS NULL",
        "UPDATE disk_tests SET job_status='aborted', ended_at=CURRENT_TIMESTAMP WHERE job_status NOT IN ('success','failed','aborted','cancelled') OR job_status IS NULL",
        "UPDATE discoveries SET discovery_status='aborted', ended_at=CURRENT_TIMESTAMP WHERE discovery_status NOT IN ('success','failed','aborted','cancelled') OR discovery_status IS NULL",
        "UPDATE task_phases SET status='aborted', ended_at=CURRENT_TIMESTAMP WHERE status NOT IN ('success','failed','skipped','cancelled','aborted') OR status IS NULL",
        "UPDATE os_images SET status='failed', error_msg='Interrupted by standby restore' WHERE status NOT IN ('downloaded','failed') OR status IS NULL",
        "UPDATE machines SET next_action=NULL, awaiting_action_until=NULL, rescue_mode=0",
    )
    with closing(sqlite3.connect(database, timeout=5)) as db:
        db.execute('PRAGMA trusted_schema=OFF')
        db.execute('PRAGMA foreign_keys=ON')
        with db:
            for statement in updates:
                db.execute(statement)
            if not has_http:
                # Metadata alone must not advertise images whose HTTP files
                # were not selected for this restore.
                db.execute("UPDATE os_images SET enabled=0, status='failed', error_msg='HTTP files were not included in the restore'")
        if db.execute('PRAGMA integrity_check').fetchone() != ('ok',) or db.execute('PRAGMA foreign_key_check').fetchone() is not None:
            raise BackupError('standby database integrity check failed')


def restore_recovery(archive: Path, destination: Path, passphrase: bytes) -> None:
    """Authenticate and restore into a new private, inactive directory only."""
    try:
        destination = _private_destination(Path(destination))
        archive = Path(archive)
        _regular_input(archive, MAX_ARCHIVE)
        fd = os.open(archive, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(fd, 'rb') as source:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_ARCHIVE:
                raise BackupError('invalid encrypted archive')
            with tempfile.TemporaryDirectory(prefix='.pxe-recovery-restore-', dir=destination.parent) as directory:
                root = Path(directory); plain = root/'recovery.tar'
                with _gpg_home() as home, plain.open('xb') as output:
                    os.chmod(plain, 0o600)
                    _decrypt_bounded(source, output, passphrase, home)
                names, directories, files, links = _unpack_authenticated(plain, root)
                _prepare_standby(root/'database.sqlite', has_http='http' in directories)
                # Exclusive directory creation cannot replace an existing path.
                # A crash after this point can leave a partial private directory.
                destination.mkdir(mode=0o700)
                try:
                    for name in names:
                        with (root/name).open('rb') as stream:
                            os.fsync(stream.fileno())
                        os.link(root/name, destination/name, follow_symlinks=False)
                    for name in sorted(directories, key=lambda item: (item.count('/'), item)):
                        (destination/name).mkdir(mode=0o700)
                    for name in sorted(files):
                        os.link(root/name, destination/name, follow_symlinks=False)
                    for name, target in sorted(links.items()):
                        os.symlink(target, destination/name)
                    for name, mode in files.items():
                        file_fd = os.open(destination/name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
                        try:
                            os.fchmod(file_fd, mode)
                            os.fsync(file_fd)
                        finally: os.close(file_fd)
                    for name in sorted(directories, key=lambda item: (-item.count('/'), item)):
                        directory_fd = os.open(destination/name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
                        try:
                            os.fchmod(directory_fd, directories[name])
                            os.fsync(directory_fd)
                        finally: os.close(directory_fd)
                    directory_fd = os.open(destination, os.O_RDONLY)
                    try: os.fsync(directory_fd)
                    finally: os.close(directory_fd)
                    parent_fd = os.open(destination.parent, os.O_RDONLY)
                    try: os.fsync(parent_fd)
                    finally: os.close(parent_fd)
                except BaseException as error:
                    raise BackupError('restore directory may be incomplete; inspect it before retrying') from error
    except BackupError:
        raise
    except (OSError, sqlite3.Error, subprocess.SubprocessError, tarfile.TarError, UnicodeError, ValueError, RecursionError) as error:
        raise BackupError('restore failed; no existing destination was replaced') from error
