#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Explicit foreground launcher for the experimental discovery HTTPS endpoint.

No system service is installed. The caller supplies an existing TLS identity,
private database and runtime parent. Stopping this command stops its children.
"""
from __future__ import annotations

import argparse
from contextlib import closing, contextmanager
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import signal
import socket
import sqlite3
import stat
import subprocess
import sys
import tempfile
import time
from typing import Iterator

sys.dont_write_bytecode = True
APP = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(APP))
from cli.netboot_job import JobError, open_database  # noqa: E402


class RuntimeErrorSafe(Exception):
    """The discovery service could not be prepared or safely launched."""


def _path(path: Path) -> str:
    """Restrict configuration values so no nginx/FPM syntax can be injected."""
    value = str(path)
    if not path.is_absolute() or re.fullmatch(r'/[A-Za-z0-9_./ -]+', value) is None:
        raise RuntimeErrorSafe('unsupported path')
    return value


def _private_dir(path: Path) -> Path:
    if not path.is_absolute() or path.is_symlink():
        raise RuntimeErrorSafe('private runtime parent required')
    actual = path.resolve(strict=True)
    info = actual.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o022:
        raise RuntimeErrorSafe('private runtime parent required')
    _path(actual)
    return actual


def _tls_file(path: Path, *, private: bool) -> Path:
    if not path.is_absolute() or path.is_symlink():
        raise RuntimeErrorSafe('trusted TLS file required')
    actual = path.resolve(strict=True)
    info, parent = actual.stat(), actual.parent.stat()
    allowed_owners = (os.geteuid(),) if private else (0, os.geteuid())
    if (not stat.S_ISREG(info.st_mode) or info.st_uid not in allowed_owners or info.st_mode & (0o077 if private else 0o022)
            or parent.st_uid not in (0, os.geteuid()) or parent.st_mode & 0o022):
        raise RuntimeErrorSafe('trusted TLS file required')
    _path(actual)
    return actual


def address(value: str, port: int) -> str:
    try:
        ip = ipaddress.IPv4Address(value)
    except ipaddress.AddressValueError as error:
        raise RuntimeErrorSafe('specific IPv4 address required') from error
    if ip.is_unspecified or ip.is_multicast or ip.is_reserved or not 1 <= port <= 65535:
        raise RuntimeErrorSafe('specific IPv4 address and valid port required')
    return f'{ip}:{port}'


def _interface_name(interface: str) -> None:
    if (not re.fullmatch(r'[A-Za-z0-9_.-]{1,15}', interface) or interface.startswith('-')
            or interface in ('.', '..')):
        raise RuntimeErrorSafe('invalid interface')


def verify_interface(interface: str, ip: str) -> None:
    """Read Linux interface state immediately before explicitly activating HTTPS."""
    _interface_name(interface)
    result = subprocess.run(['ip', '-j', 'address', 'show', 'dev', interface],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=10, check=False)
    if result.returncode != 0 or len(result.stdout) > 1024 * 1024:
        raise RuntimeErrorSafe('interface could not be verified')
    try:
        values = json.loads(result.stdout)
        if not isinstance(values, list) or len(values) != 1 or values[0].get('ifname') != interface:
            raise RuntimeErrorSafe('interface could not be verified')
        addresses = values[0].get('addr_info')
        if not isinstance(addresses, list) or not any(isinstance(item, dict) and item.get('family') == 'inet'
                                                        and item.get('local') == ip for item in addresses):
            raise RuntimeErrorSafe('address is not present on selected interface')
    except (AttributeError, TypeError, ValueError) as error:
        raise RuntimeErrorSafe('interface could not be verified') from error


def render_configs(root: Path, db: Path, cert: Path, key: Path, user: str, listen: str) -> tuple[str, str]:
    """Render fixed configuration; listen is built internally, never raw CLI input."""
    root_s, db_s, cert_s, key_s, app_s = map(_path, (root, db, cert, key, APP))
    if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_.-]{0,63}\$?', user):
        raise RuntimeErrorSafe('unsupported service user')
    if listen.startswith('unix:'):
        # Used by the disposable socket-only test harness; the CLI never offers it.
        _path(Path(listen[5:]))
    elif re.fullmatch(r'[0-9.]+:[0-9]{1,5}', listen) is None:
        raise RuntimeErrorSafe('invalid listener')
    fpm = f'''[global]
daemonize = no
error_log = "{root_s}/fpm-error.log"
pid = "{root_s}/fpm.pid"
[discovery]
user = {user}
listen = "{root_s}/fpm.sock"
listen.mode = 0600
pm = static
pm.max_children = 2
clear_env = yes
env[PXE_DISCOVERY_ENABLED] = 1
env[PXE_DB_PATH] = "{db_s}"
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off
php_admin_value[cgi.fix_pathinfo] = 0
request_terminate_timeout = 30s
security.limit_extensions = .php
'''
    nginx = f'''worker_processes 1;
error_log "{root_s}/nginx-error.log" warn;
pid "{root_s}/nginx.pid";
events {{ worker_connections 64; }}
http {{
  access_log off;
  server_tokens off;
  client_max_body_size 1m;
  client_body_buffer_size 1m;
  client_body_timeout 10s;
  client_header_timeout 10s;
  keepalive_timeout 5s;
  client_body_temp_path "{root_s}/client_tmp";
  proxy_temp_path "{root_s}/proxy_tmp";
  fastcgi_temp_path "{root_s}/fastcgi_tmp";
  uwsgi_temp_path "{root_s}/uwsgi_tmp";
  scgi_temp_path "{root_s}/scgi_tmp";
  limit_req_zone $binary_remote_addr zone=discovery_requests:1m rate=20r/m;
  limit_conn_zone $binary_remote_addr zone=discovery_connections:1m;
  limit_req_status 429;
  limit_conn_status 429;
  server {{
    listen "{listen}" ssl;
    ssl_certificate "{cert_s}";
    ssl_certificate_key "{key_s}";
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache off;
    location = /discovery/v1/reports {{
      limit_req zone=discovery_requests burst=5 nodelay;
      limit_conn discovery_connections 4;
      if ($http_transfer_encoding != "") {{ return 400; }}
      fastcgi_pass_request_headers off;
      fastcgi_request_buffering off;
      fastcgi_param SCRIPT_FILENAME "{app_s}/discovery_api/receive.php";
      fastcgi_param REQUEST_METHOD $request_method;
      fastcgi_param REQUEST_URI $request_uri;
      fastcgi_param CONTENT_TYPE $content_type;
      fastcgi_param CONTENT_LENGTH $content_length;
      fastcgi_param HTTPS on;
      fastcgi_param HTTP_AUTHORIZATION $http_authorization;
      fastcgi_param SERVER_PROTOCOL $server_protocol;
      fastcgi_pass "unix:{root_s}/fpm.sock";
    }}
    location / {{ return 404; }}
  }}
}}
'''
    return fpm, nginx


def _binary(names: tuple[str, ...]) -> str:
    for name in names:
        found = shutil.which(name)
        if found:
            return found
    raise RuntimeErrorSafe('required executable unavailable')


@contextmanager
def prepared(db: Path, cert: Path, key: Path, runtime_parent: Path, listen: str) -> Iterator[dict[str, Path | str]]:
    if os.geteuid() == 0:
        raise RuntimeErrorSafe('run as a dedicated unprivileged service user')
    parent = _private_dir(runtime_parent)
    certificate, private_key = _tls_file(cert, private=False), _tls_file(key, private=True)
    with closing(open_database(db)) as connection:
        connection.execute('SELECT token_hash, expires_at FROM discovery_enrollment_tokens LIMIT 1').fetchone()
    db = db.parent.resolve(strict=True) / db.name
    _path(db)
    user = pwd.getpwuid(os.geteuid()).pw_name
    nginx = _binary(('nginx',))
    fpm = _binary(('php-fpm', 'php-fpm8.5', 'php-fpm8.4', 'php-fpm8.3'))
    with tempfile.TemporaryDirectory(prefix='.pxe-discovery-', dir=parent) as directory:
        root = Path(directory)
        # AF_UNIX limits vary by platform; fail before touching any listener.
        if len(os.fsencode(str(root / 'nginx-check.sock'))) >= 100:
            raise RuntimeErrorSafe('runtime path is too long for the private socket')
        fpm_text, nginx_text = render_configs(root, db, certificate, private_key, user, listen)
        # nginx -t actually binds its configured listener. Check an equivalent
        # configuration on a disposable Unix socket, never the requested IP.
        _, check_text = render_configs(root, db, certificate, private_key, user, 'unix:' + str(root / 'nginx-check.sock'))
        fpm_conf, nginx_conf, check_conf = root / 'fpm.conf', root / 'nginx.conf', root / 'nginx-check.conf'
        fpm_conf.write_text(fpm_text, encoding='ascii')
        nginx_conf.write_text(nginx_text, encoding='ascii')
        check_conf.write_text(check_text, encoding='ascii')
        fpm_conf.chmod(0o600)
        nginx_conf.chmod(0o600)
        check_conf.chmod(0o600)
        fpm_check = subprocess.run([fpm, '--test', '--fpm-config', str(fpm_conf)],
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=False)
        if fpm_check.returncode != 0:
            raise RuntimeErrorSafe('PHP-FPM rejected the configuration')
        check = subprocess.run([nginx, '-t', '-p', str(root) + '/', '-c', str(check_conf),
                                '-g', 'daemon off; master_process off;'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=False)
        if check.returncode != 0:
            raise RuntimeErrorSafe('nginx rejected the configuration or TLS identity')
        yield {'root': root, 'nginx': nginx, 'fpm': fpm, 'fpm_conf': fpm_conf, 'nginx_conf': nginx_conf, 'listen': listen}


def _stop(process: subprocess.Popen | None) -> None:
    if process is None:
        return
    # The parent may have failed while children still exist in its session.
    # Stop the process group even when the leader has already exited.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=8)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()


def _listener_ready(process: subprocess.Popen, listen: str) -> bool:
    """Observe the launched nginx listener, without contacting another IP service."""
    if listen.startswith('unix:'):
        # Socket-only test harness: the CLI cannot select this mode.
        try:
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as probe:
                probe.settimeout(0.2)
                probe.connect(listen[5:])
            return True
        except OSError:
            return False
    try:
        host, raw_port = listen.rsplit(':', 1)
        ip = ipaddress.IPv4Address(host)
        local = f'{int.from_bytes(ip.packed, sys.byteorder):08X}:{int(raw_port):04X}'
        descriptors = Path(f'/proc/{process.pid}/fd')
        inodes = set()
        for descriptor in descriptors.iterdir():
            try:
                match = re.fullmatch(r'socket:\[(\d+)\]', os.readlink(descriptor))
                if match:
                    inodes.add(match.group(1))
            except OSError:
                continue
        if not inodes:
            return False
        with Path('/proc/net/tcp').open(encoding='ascii') as table:
            next(table, None)
            for line in table:
                fields = line.split()
                if len(fields) > 9 and fields[1] == local and fields[3] == '0A' and fields[9] in inodes:
                    return True
    except (OSError, ValueError):
        return False
    return False


def serve(plan: dict[str, Path | str]) -> None:
    root = Path(plan['root'])
    fpm = nginx = None
    interrupted = False
    def stop(signum, frame):
        nonlocal interrupted
        interrupted = True
    handlers = {sig: signal.signal(sig, stop) for sig in (signal.SIGINT, signal.SIGTERM)}
    try:
        # Unlike dry-run, activation may test-bind the explicitly requested
        # listener. This catches a conflict without stopping its existing owner.
        check = subprocess.run([str(plan['nginx']), '-t', '-p', str(root) + '/', '-c', str(plan['nginx_conf']),
                                '-g', 'daemon off; master_process off;'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=False)
        if interrupted:
            return
        if check.returncode != 0:
            raise RuntimeErrorSafe('requested HTTPS listener is unavailable')
        fpm = subprocess.Popen([str(plan['fpm']), '--nodaemonize', '--fpm-config', str(plan['fpm_conf'])],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        deadline = time.monotonic() + 15
        while not (root / 'fpm.sock').is_socket() and fpm.poll() is None and not interrupted and time.monotonic() < deadline:
            time.sleep(0.05)
        if interrupted:
            return
        if not (root / 'fpm.sock').is_socket() or fpm.poll() is not None:
            raise RuntimeErrorSafe('private FastCGI process did not start')
        nginx = subprocess.Popen([str(plan['nginx']), '-p', str(root) + '/', '-c', str(plan['nginx_conf']),
                                  '-g', 'daemon off; master_process off;'],
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        # nginx can remain alive while retrying a failed bind. Observe its own
        # listening socket, not just the lifetime of its process.
        deadline = time.monotonic() + 15
        while (not interrupted and nginx.poll() is None and fpm.poll() is None
               and time.monotonic() < deadline and not _listener_ready(nginx, str(plan['listen']))):
            time.sleep(0.05)
        if interrupted:
            return
        if nginx.poll() is not None or fpm.poll() is not None or not _listener_ready(nginx, str(plan['listen'])):
            raise RuntimeErrorSafe('HTTPS service did not start')
        print('Discovery HTTPS started explicitly; press Ctrl-C to stop.', flush=True)
        while not interrupted and nginx.poll() is None and fpm.poll() is None:
            time.sleep(0.2)
        if not interrupted:
            raise RuntimeErrorSafe('discovery service stopped unexpectedly')
    finally:
        _stop(nginx)
        _stop(fpm)
        for sig, handler in handlers.items():
            signal.signal(sig, handler)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('db', 'cert', 'key', 'runtime-parent'):
        parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--listen-ip', required=True, help='specific local IPv4 address')
    parser.add_argument('--interface', required=True, help='Linux interface owning the selected IPv4')
    parser.add_argument('--port', type=int, required=True)
    parser.add_argument('--dry-run', action='store_true', help='validate inputs without binding a port')
    parser.add_argument('--confirm-activation', action='store_true', help='start HTTPS explicitly in the foreground')
    args = parser.parse_args()
    if args.dry_run == args.confirm_activation:
        parser.error('choose exactly one of --dry-run or --confirm-activation')
    try:
        listen = address(args.listen_ip, args.port)
        _interface_name(args.interface)
        with prepared(args.db, args.cert, args.key, args.runtime_parent, listen) as plan:
            if args.dry_run:
                print('Discovery HTTPS configuration validated; interface not checked and no network service started.')
            else:
                verify_interface(args.interface, args.listen_ip)
                serve(plan)
        return 0
    except RuntimeErrorSafe as error:
        print('Discovery HTTPS: ' + str(error) + '.', file=sys.stderr)
        return 1
    except (JobError, OSError, ValueError, sqlite3.Error, subprocess.SubprocessError):
        print('Discovery HTTPS was not started or stopped unexpectedly; check configuration and permissions.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
