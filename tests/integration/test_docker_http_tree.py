#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise Docker HTTP ownership, legacy refusal and root image deletion."""
import importlib.util
import json
import os
from pathlib import Path
import pwd
import shutil
import sqlite3
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[2]
HTTP = Path('/srv/http')
if (os.environ.get('PXE_DOCKER_HTTP_TEST') != '1' or os.geteuid() != 0
        or not Path('/.dockerenv').is_file()
        or Path('/etc/pxe-tools/db.conf').exists()
        or {path.name for path in Path('/sys/class/net').iterdir()} != {'lo'}):
    raise SystemExit('A disposable root container with no network or appliance DB is required')
HTTP.mkdir(parents=True, exist_ok=True)
if any(HTTP.iterdir()):
    raise SystemExit('Refusing to change a nonempty HTTP tree')
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / 'app'))
from image_delete import (DOCKER_MARKER, DOCKER_MARKER_CONTENT,
                          require_trusted_docker_http_root)


def refused() -> None:
    try:
        require_trusted_docker_http_root(str(HTTP), check_contents=True)
    except (OSError, ValueError):
        return
    raise AssertionError('an unsafe HTTP tree was accepted')


def php(code: str, database: Path, *, uid: int, gid: int) -> str:
    bootstrap = ("spl_autoload_register(function($c){if(str_starts_with($c,'Admin\\\\')) "
                 "require getenv('PXE_APP_DIR').'/src/'.str_replace('\\\\','/',substr($c,6)).'.php';});")
    env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite',
               PXE_DB_PATH=str(database), HTTP_ROOT=str(HTTP), PXE_HTTP_ROOT=str(HTTP))
    result = subprocess.run(['php', '-r', bootstrap + code], env=env, user=uid, group=gid,
                            extra_groups=(), capture_output=True, text=True, timeout=10)
    if result.returncode:
        raise AssertionError(f'PHP operation failed: {result.stderr[-300:]}')
    return result.stdout.strip()


try:
    os.environ['PXE_SERVICE_MANAGER'] = 's6'
    account = pwd.getpwnam('www-data')
    # A new image must carry a protected marker; legacy volumes are refused.
    refused()
    startup = subprocess.run(['/bin/sh', str(ROOT / 'docker/pxe-tools/init/php-init.sh')],
                             env=dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'), HTTP_ROOT=str(HTTP)),
                             capture_output=True, text=True, timeout=10)
    assert startup.returncode != 0 and 'Protected Docker HTTP tree refused' in startup.stderr
    assert not Path('/etc/pxe-tools/db.conf').exists(), 'startup changed DB configuration before refusal'
    marker = HTTP / DOCKER_MARKER
    marker.write_bytes(DOCKER_MARKER_CONTENT)
    netboot = HTTP / 'netboot'
    netboot.mkdir()
    static_bin = HTTP / 'static-bin'
    static_bin.mkdir()
    static_tool = static_bin / 'smartctl'
    static_tool.write_bytes(b'fixture-tool')
    require_trusted_docker_http_root(str(HTTP), check_contents=True)
    subprocess.run(['python3', str(ROOT / 'app/image_delete.py'), '--verify-docker-http-tree'],
                   check=True, capture_output=True, timeout=10)

    marker.unlink(); marker.symlink_to('/etc/passwd')
    refused()
    marker.unlink(); marker.write_bytes(DOCKER_MARKER_CONTENT)
    preseed = HTTP / 'preseed.php'
    preseed.write_bytes(b'fixture-rendered-preseed')
    os.chown(preseed, account.pw_uid, account.pw_gid)
    refused()
    os.chown(preseed, 0, 0)

    os.chown(HTTP, account.pw_uid, account.pw_gid)
    refused()
    os.chown(HTTP, 0, 0)
    os.chown(netboot, account.pw_uid, account.pw_gid)
    refused()
    os.chown(netboot, 0, 0)
    os.chown(static_tool, account.pw_uid, account.pw_gid)
    refused()
    os.chown(static_tool, 0, 0)
    static_tool.chmod(0o666)
    refused()
    static_tool.chmod(0o644)
    image = netboot / 'fixture/1/amd64'
    image.mkdir(parents=True)
    for name in ('linux', 'initrd.gz', 'firmware.cpio.gz'):
        (image / name).write_bytes(('fixture-' + name).encode())
    (image / 'linux').chmod(0o666)
    refused()
    (image / 'linux').chmod(0o644)
    escaped = netboot / 'escape'
    escaped.symlink_to('/etc', target_is_directory=True)
    refused()
    escaped.unlink()
    alias = netboot / 'trixie'; alias.mkdir()
    (alias / 'amd64').symlink_to('../fixture/1/amd64', target_is_directory=True)
    require_trusted_docker_http_root(str(HTTP), check_contents=True)

    # www-data cannot replace the parent entry or change a published artifact.
    for expression in ("os.rename('/srv/http/netboot','/srv/http/stolen')",
                       "open('/srv/http/netboot/fixture/1/amd64/linux','wb').write(b'bad')",
                       "open('/srv/http/static-bin/smartctl','wb').write(b'bad')",
                       "open('/srv/http/preseed.php','wb').write(b'bad')"):
        attempt = subprocess.run([sys.executable, '-c', 'import os; ' + expression],
                                 user=account.pw_uid, group=account.pw_gid, extra_groups=(),
                                 capture_output=True, timeout=5)
        assert attempt.returncode != 0, 'web account changed protected images'
    assert (image / 'linux').read_bytes() == b'fixture-linux'
    assert static_tool.read_bytes() == b'fixture-tool'
    assert preseed.read_bytes() == b'fixture-rendered-preseed'

    with tempfile.TemporaryDirectory(prefix='pxe-docker-delete-') as temporary:
        directory = Path(temporary); directory.chmod(0o777)
        database = directory / 'database.sqlite'
        with sqlite3.connect(database) as db:
            db.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            db.execute("INSERT INTO os_images(id,distro,version,version_full,arch,enabled,status) "
                       "VALUES(1,'fixture','1','1','amd64',1,'downloaded')")
        database.chmod(0o660)
        os.chown(database, account.pw_uid, account.pw_gid)
        assert php("$image=\\Admin\\Models\\OsImage::findById(1); "
                   "if (\\Admin\\Models\\OsImage::webCanDeleteDirect($image)) exit(1);",
                   database, uid=account.pw_uid, gid=account.pw_gid) == ''
        job_id = int(php("echo \\Admin\\Models\\OsImage::queueDelete(1);", database,
                         uid=account.pw_uid, gid=account.pw_gid))
        os.environ.update(PXE_APP_DIR=str(ROOT / 'app'), PXE_DB_DRIVER='sqlite',
                          PXE_DB_PATH=str(database), HTTP_ROOT=str(HTTP), PXE_HTTP_ROOT=str(HTTP))
        spec = importlib.util.spec_from_file_location('docker_worker_test', ROOT / 'app/worker.py')
        worker = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(worker)
        worker.DB_PATH = str(database)
        worker.HTTP_ROOT = str(HTTP)
        with sqlite3.connect(database) as db:
            db.row_factory = sqlite3.Row
            job = dict(db.execute('SELECT * FROM init_jobs WHERE id=?', (job_id,)).fetchone())
        worker.run_bootstrap_job(job)
        with sqlite3.connect(database) as db:
            assert db.execute('SELECT status FROM init_jobs WHERE id=?', (job_id,)).fetchone()[0] == 'success'
            assert db.execute('SELECT 1 FROM os_images WHERE id=1').fetchone() is None
        assert not any((image / name).exists() for name in ('linux', 'initrd.gz', 'firmware.cpio.gz'))
    print('Protected Docker HTTP tree and root image deletion verified.')
finally:
    # The test runner may mount /srv/http as a disposable tmpfs. Clear its
    # contents without trying to remove the mountpoint itself.
    for child in HTTP.iterdir():
        if child.is_dir() and not child.is_symlink():
            shutil.rmtree(child)
        else:
            child.unlink()
