# SPDX-License-Identifier: LGPL-3.0-only
"""Explicit, profile-constrained Ansible requests for the existing worker.

Profiles and their playbooks are trusted administrator-provided code. The web
interface may request only a published identifier and revision; it never
provides commands, paths, inventory, options or secrets to this executor.
"""
from __future__ import annotations

from contextlib import closing
import hashlib
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
from typing import Any

PROFILE_ID = re.compile(r'[a-z][a-z0-9_-]{0,47}')
RUNTIME_ID = re.compile(r'(?:env-[a-z0-9_]{8}|sys-[a-f0-9]{64})')
SAFE_PART = re.compile(r'[A-Za-z0-9_.-]{1,100}')
MAX_BYTES = 32 * 1024 * 1024
MAX_FILES = 512
MAX_PENDING_SECONDS = 120
SCHEMA = (
    "CREATE TABLE state (id INTEGER PRIMARY KEY CHECK(id=1), updated INTEGER NOT NULL, runtime_id TEXT NOT NULL, runtime_version TEXT NOT NULL)",
    "CREATE TABLE profiles (id TEXT PRIMARY KEY, title TEXT NOT NULL, targets TEXT NOT NULL, description TEXT NOT NULL, revision TEXT NOT NULL)",
    "CREATE TABLE jobs (id INTEGER PRIMARY KEY, profile_id TEXT NOT NULL, title TEXT NOT NULL, targets TEXT NOT NULL, revision TEXT NOT NULL, runtime_id TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('pending','running','succeeded','failed','rejected')), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, started_at TEXT, ended_at TEXT, message TEXT)",
    "CREATE UNIQUE INDEX one_active_ansible_job ON jobs((1)) WHERE status IN ('pending','running')",
)


class QueueError(Exception):
    """Queue, profile, runtime or execution configuration is not trustworthy."""


def _private_dir(path: Path, *, create: bool = False) -> None:
    if not path.is_absolute() or path.is_symlink():
        raise QueueError('private directory required')
    if create:
        path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if (not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077
            or path.resolve(strict=True) != path):
        raise QueueError('private directory required')


def open_queue(state: Path) -> sqlite3.Connection:
    """Open a private extension database without changing the core schema."""
    _private_dir(state)
    directory = state / 'ansible'
    _private_dir(directory, create=True)
    path = directory / 'queue.sqlite'
    descriptor = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077
                or info.st_nlink != 1):
            raise QueueError('private queue database required')
    finally:
        os.close(descriptor)
    db = sqlite3.connect(str(path), timeout=5)
    try:
        db.execute('BEGIN IMMEDIATE')
        version = db.execute('PRAGMA user_version').fetchone()[0]
        if version == 0:
            if db.execute("SELECT 1 FROM sqlite_master WHERE type IN ('table','index','trigger','view') AND name NOT LIKE 'sqlite_%' LIMIT 1").fetchone():
                raise QueueError('unrecognized queue schema')
            for statement in SCHEMA:
                db.execute(statement)
            db.execute('PRAGMA user_version=1')
        elif version != 1:
            raise QueueError('unsupported queue schema')
        with closing(sqlite3.connect(':memory:')) as reference:
            for statement in SCHEMA:
                reference.execute(statement)
            query = ("SELECT type,name,tbl_name,sql FROM sqlite_master "
                     "WHERE substr(name,1,7) != 'sqlite_' ORDER BY type,name")
            if (db.execute(query).fetchall() != reference.execute(query).fetchall()
                    or db.execute('PRAGMA quick_check').fetchone() != ('ok',)):
                raise QueueError('unrecognized queue schema')
        db.commit()
        return db
    except BaseException:
        db.rollback()
        db.close()
        raise


def _pairs(items: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in items:
        if key in result:
            raise QueueError('duplicate JSON key')
        result[key] = value
    return result


def _read_regular(path: Path, limit: int) -> bytes:
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, 'rb') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > limit:
            raise QueueError('invalid profile file')
        data = source.read(limit + 1)
        if len(data) > limit or len(data) != info.st_size:
            raise QueueError('profile file changed or is too large')
        return data


def _text(value: Any, maximum: int) -> str:
    if (not isinstance(value, str) or not 1 <= len(value) <= maximum or value != value.strip()
            or any(ord(c) < 32 or ord(c) == 127 for c in value)):
        raise QueueError('invalid profile text')
    return value


def _profile_files(directory: Path, target: Path | None = None, *, _require_readonly: bool = True) -> tuple[str, set[str]]:
    """Hash a complete profile tree, optionally copying it to a private snapshot."""
    if directory.is_symlink() or not directory.is_dir() or directory.resolve(strict=True) != directory:
        raise QueueError('invalid profile directory')
    digest = hashlib.sha256()
    count, total = 0, 0
    files: set[str] = set()
    def walk_error(error: OSError) -> None:
        raise QueueError('profile directory cannot be read') from error

    for parent, directories, names in os.walk(directory, topdown=True, followlinks=False, onerror=walk_error):
        directories.sort()
        names.sort()
        source_parent = Path(parent)
        relative_parent = source_parent.relative_to(directory)
        for name in directories:
            item = source_parent / name
            if (not SAFE_PART.fullmatch(name) or name in ('.', '..') or item.is_symlink() or not item.is_dir()
                    or (_require_readonly and not os.statvfs(item).f_flag & os.ST_RDONLY)):
                raise QueueError('unsafe profile directory entry')
            relative = (relative_parent / name).as_posix()
            if len(relative) > 240:
                raise QueueError('profile path too long')
            count += 1
            if count > MAX_FILES:
                raise QueueError('profile exceeds limits')
            encoded = relative.encode('utf-8')
            digest.update(b'D' + len(encoded).to_bytes(4, 'big') + encoded)
            if target is not None:
                (target / relative_parent / name).mkdir(mode=0o700)
        for name in names:
            if not SAFE_PART.fullmatch(name) or name in ('.', '..'):
                raise QueueError('unsafe profile filename')
            path = source_parent / name
            relative = (relative_parent / name).as_posix()
            if len(relative) > 240:
                raise QueueError('profile path too long')
            info = path.lstat()
            if (not stat.S_ISREG(info.st_mode) or path.is_symlink()
                    or (_require_readonly and not os.statvfs(path).f_flag & os.ST_RDONLY)):
                raise QueueError('unsafe profile file')
            data = _read_regular(path, MAX_BYTES - total)
            count += 1
            total += len(data)
            if count > MAX_FILES or total > MAX_BYTES:
                raise QueueError('profile exceeds limits')
            execute = bool(info.st_mode & 0o111)
            encoded = relative.encode('utf-8')
            digest.update(b'F' + len(encoded).to_bytes(4, 'big') + encoded + bytes([execute]))
            digest.update(len(data).to_bytes(8, 'big') + data)
            if target is not None:
                output = target / relative
                with output.open('xb') as dest:
                    dest.write(data)
                output.chmod(0o500 if execute else 0o400)
            files.add(relative)
    if not {'playbook.yml', 'inventory.ini', 'known_hosts'}.issubset(files):
        raise QueueError('playbook, inventory and known_hosts are required')
    return digest.hexdigest(), files


def read_profiles(root: Path, *, _require_readonly: bool = True) -> list[dict[str, str]]:
    if not root.is_absolute() or root.is_symlink() or not root.is_dir() or root.resolve(strict=True) != root:
        raise QueueError('invalid profile root')
    if _require_readonly and not os.statvfs(root).f_flag & os.ST_RDONLY:
        raise QueueError('profile root must be mounted read-only')
    manifest = json.loads(_read_regular(root / 'profiles.json', 65536), object_pairs_hook=_pairs)
    if not isinstance(manifest, dict) or set(manifest) != {'version', 'profiles'} or type(manifest['version']) is not int or manifest['version'] != 1:
        raise QueueError('unsupported profile manifest')
    if not isinstance(manifest['profiles'], list) or len(manifest['profiles']) > 50:
        raise QueueError('invalid profile list')
    result, identifiers = [], set()
    for item in manifest['profiles']:
        if not isinstance(item, dict) or set(item) != {'id', 'title', 'targets', 'description'}:
            raise QueueError('invalid profile')
        identifier = item['id']
        if not isinstance(identifier, str) or not PROFILE_ID.fullmatch(identifier) or identifier in identifiers:
            raise QueueError('invalid or duplicate profile identifier')
        identifiers.add(identifier)
        info = {'id': identifier, 'title': _text(item['title'], 100), 'targets': _text(item['targets'], 200),
                'description': _text(item['description'], 500)}
        digest, _ = _profile_files(root / identifier, _require_readonly=_require_readonly)
        revision = hashlib.sha256(json.dumps(info, sort_keys=True, ensure_ascii=True, separators=(',', ':')).encode()
                                  + bytes.fromhex(digest)).hexdigest()
        result.append({**info, 'revision': revision})
    return result


def runtime_status() -> tuple[str, str]:
    if os.environ.get('PXE_ANSIBLE_RUNTIME') == 'system':
        from worker.ansible_native import NativeRuntimeError, status
        try:
            return status()
        except NativeRuntimeError as error:
            raise QueueError('distribution runtime unavailable') from error
    if os.environ.get('PXE_ANSIBLE_RUNTIME') != '/var/lib/pxe-ansible':
        raise QueueError('optional runtime is not configured')
    result = subprocess.run([sys.executable, '/usr/local/lib/pxe-ansible/runtime.py', '--runtime',
                             '/var/lib/pxe-ansible', 'status'], check=True, stdin=subprocess.DEVNULL,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, timeout=5)
    if len(result.stdout) > 4096:
        raise QueueError('invalid runtime status')
    item = json.loads(result.stdout, object_pairs_hook=_pairs)
    if (not isinstance(item, dict) or set(item) != {'state', 'runtime', 'ansible_core'} or item['state'] != 'ready'
            or not isinstance(item['runtime'], str) or not RUNTIME_ID.fullmatch(item['runtime'])
            or not isinstance(item['ansible_core'], str)
            or not re.fullmatch(r'[0-9][A-Za-z0-9.+!_-]{0,99}', item['ansible_core'])):
        raise QueueError('optional runtime is unavailable')
    return item['runtime'], item['ansible_core']


def publish(db: sqlite3.Connection, profiles: list[dict[str, str]], runtime_id: str, version: str) -> None:
    if not RUNTIME_ID.fullmatch(runtime_id):
        raise QueueError('invalid runtime')
    with db:
        db.execute('DELETE FROM profiles')
        db.executemany('INSERT INTO profiles(id,title,targets,description,revision) VALUES(:id,:title,:targets,:description,:revision)', profiles)
        db.execute('INSERT INTO state(id,updated,runtime_id,runtime_version) VALUES(1,?,?,?) '
                   'ON CONFLICT(id) DO UPDATE SET updated=excluded.updated,runtime_id=excluded.runtime_id,runtime_version=excluded.runtime_version',
                   (int(time.time()), runtime_id, version))


def enqueue(db: sqlite3.Connection, identifier: str, revision: str, runtime_id: str, *, now: int | None = None) -> int:
    """Queue only the exact profile and runtime most recently published."""
    now = int(time.time()) if now is None else now
    if not PROFILE_ID.fullmatch(identifier) or not re.fullmatch(r'[a-f0-9]{64}', revision) or not RUNTIME_ID.fullmatch(runtime_id):
        raise QueueError('invalid request')
    try:
        db.execute('BEGIN IMMEDIATE')
        state = db.execute('SELECT updated,runtime_id FROM state WHERE id=1').fetchone()
        profile = db.execute('SELECT title,targets,revision FROM profiles WHERE id=?', (identifier,)).fetchone()
        if (state is None or not 0 <= now - state[0] <= 60 or state[1] != runtime_id
                or profile is None or profile[2] != revision):
            raise QueueError('profile or worker status changed')
        cursor = db.execute('INSERT INTO jobs(profile_id,title,targets,revision,runtime_id,status) VALUES(?,?,?,?,?,\'pending\')',
                            (identifier, profile[0], profile[1], revision, runtime_id))
        db.commit()
        return int(cursor.lastrowid)
    except BaseException:
        db.rollback()
        raise


def claim(db: sqlite3.Connection) -> tuple | None:
    db.execute('BEGIN IMMEDIATE')
    try:
        row = db.execute("SELECT id,profile_id,revision,runtime_id,CAST(strftime('%s',created_at) AS INTEGER) FROM jobs WHERE status='pending' ORDER BY id LIMIT 1").fetchone()
        if row is not None:
            changed = db.execute("UPDATE jobs SET status='running',started_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'", (row[0],))
            if changed.rowcount != 1:
                raise QueueError('job state changed')
        db.commit()
        return row
    except BaseException:
        db.rollback()
        raise


def finish(db: sqlite3.Connection, job: int, state: str, message: str) -> None:
    if state not in ('succeeded', 'failed', 'rejected'):
        raise QueueError('invalid final state')
    with db:
        result = db.execute("UPDATE jobs SET status=?,ended_at=CURRENT_TIMESTAMP,message=? WHERE id=? AND status='running'",
                            (state, message, job))
        if result.rowcount != 1:
            raise QueueError('job state changed')


def execute(db: sqlite3.Connection, root: Path, profiles: list[dict[str, str]], runtime_id: str,
            *, timeout: int = 300) -> bool:
    """Run at most one request; keep output private and never retry a started job."""
    job = claim(db)
    if job is None:
        return False
    job_id, identifier, expected_revision, expected_runtime, created = job
    profile = next((p for p in profiles if p['id'] == identifier), None)
    if (not isinstance(created, int) or not 0 <= int(time.time()) - created <= MAX_PENDING_SECONDS
            or profile is None or profile['revision'] != expected_revision or runtime_id != expected_runtime):
        finish(db, job_id, 'rejected', 'Demande expirée ou configuration modifiée ; aucune exécution.')
        return True
    try:
        with tempfile.TemporaryDirectory(prefix='pxe-ansible-job-') as directory:
            private = Path(directory)
            snapshot = private / 'profile'
            snapshot.mkdir(mode=0o700)
            digest, _ = _profile_files(root / identifier, snapshot)
            descriptor = {key: profile[key] for key in ('id', 'title', 'targets', 'description')}
            actual = hashlib.sha256(json.dumps(descriptor, sort_keys=True, ensure_ascii=True, separators=(',', ':')).encode()
                                    + bytes.fromhex(digest)).hexdigest()
            if actual != expected_revision:
                finish(db, job_id, 'rejected', 'Profil modifié ; aucune exécution.')
                return True
            config = private / 'ansible.cfg'
            config.write_text('[defaults]\nhost_key_checking = True\nretry_files_enabled = False\n'
                              '[ssh_connection]\nssh_args = -o StrictHostKeyChecking=yes -o UserKnownHostsFile='
                              + str(snapshot / 'known_hosts') + ' -o GlobalKnownHostsFile=/dev/null -o BatchMode=yes\n', encoding='ascii')
            config.chmod(0o600)
            environment = {'HOME': str(private), 'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8',
                           'ANSIBLE_CONFIG': str(config), 'ANSIBLE_HOST_KEY_CHECKING': 'True',
                           'ANSIBLE_NOCOLOR': '1', 'PYTHONNOUSERSITE': '1', 'PYTHONDONTWRITEBYTECODE': '1'}
            if runtime_id.startswith('sys-'):
                try:
                    current_runtime = runtime_status()[0] if os.environ.get('PXE_ANSIBLE_RUNTIME') == 'system' else None
                except QueueError:
                    current_runtime = None
                if current_runtime != runtime_id:
                    finish(db, job_id, 'rejected', 'Environnement modifié ; aucune exécution.')
                    return True
                program = Path('/usr/bin/ansible-playbook')
            else:
                program = Path('/var/lib/pxe-ansible/runtimes') / runtime_id / 'bin/ansible-playbook'
            process = subprocess.Popen([str(program), '-i', str(snapshot / 'inventory.ini'), str(snapshot / 'playbook.yml')],
                                       cwd=snapshot, env=environment, stdin=subprocess.DEVNULL,
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
            try:
                code = process.wait(timeout=timeout)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait()
                finish(db, job_id, 'failed', 'Délai dépassé ; vérifiez les cibles avant toute nouvelle demande.')
                return True
            finally:
                # A completed launcher must not leave local children in its process group.
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
        finish(db, job_id, 'succeeded' if code == 0 else 'failed',
               'Exécution terminée.' if code == 0 else 'Exécution en échec ; vérifiez les cibles avant toute nouvelle demande.')
    except (OSError, ValueError, QueueError, subprocess.SubprocessError):
        finish(db, job_id, 'failed', 'Exécution impossible ; vérifiez la configuration.')
    return True
