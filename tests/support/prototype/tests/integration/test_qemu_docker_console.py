#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build and exercise the development Compose console inside a disposable VM.

The guest cannot reach the host network: its sole HTTP forward reaches a
fixture serving packages from Debian-signed indices. Docker runs only inside
the guest. Cloud-image and Docker-base provenance depend on the supplied
independently obtained checksums; neither image is published by this test.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
from http.server import BaseHTTPRequestHandler
import json
import lzma
import os
from pathlib import Path
import re
import selectors
import shlex
import shutil
import socketserver
import subprocess
import ssl
import sys
import tempfile
import threading
import time
from urllib.parse import unquote_to_bytes
from urllib.request import Request

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.debian_netboot import _clearsigned_payload, _timestamp, verify_debian_netboot  # noqa: E402
from test_qemu_debian_package import CONSOLE_TEST, PackageMirror, run, validate_cloud_header  # noqa: E402


class DockerPackageMirror(PackageMirror):
    """Proxy only authenticated trixie, updates and security archive files."""
    def __init__(self, root: Path, release: Path, keyring: Path, index: Path,
                 boot_files: dict[str, Path] | None = None):
        super().__init__(root, release, keyring, index)
        self.repositories: dict[str, tuple[str, dict[str, tuple[str, int]], Path]] = {
            'trixie': ('https://deb.debian.org/debian/', self.release_entries, release),
        }
        self.security_debs: dict[str, tuple[str, int]] = {}
        self.boot_files = boot_files or {}
        for suite, base in (('trixie-updates', 'https://deb.debian.org/debian/'),
                            ('trixie-security', 'https://security.debian.org/debian-security/')):
            url = base + 'dists/' + suite + '/InRelease'
            with self.opener.open(Request(url, headers={'User-Agent': 'PXE-Tools-isolated-test/0.1'}), timeout=60) as response:
                raw = response.read(8 * 1024 * 1024 + 1)
                if response.geturl() != url or len(raw) > 8 * 1024 * 1024:
                    raise ValueError('unexpected repository release')
            signed = root / (suite + '-InRelease')
            signed.write_bytes(raw)
            checked = subprocess.run(['gpgv', '--weak-digest', 'SHA1', '--homedir', str(root), '--keyring',
                                      str(keyring.resolve(strict=True)), str(signed)],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
            if checked.returncode:
                raise ValueError('repository signature invalid: ' + suite)
            lines = _clearsigned_payload(raw)
            if [line[9:].strip() for line in lines if line.startswith('Codename:')] != [suite]:
                raise ValueError('unexpected repository codename')
            dates = [line[5:].strip() for line in lines if line.startswith('Date:')]
            expiries = [line[12:].strip() for line in lines if line.startswith('Valid-Until:')]
            now = datetime.now(timezone.utc)
            if (len(dates) != 1 or len(expiries) > 1 or _timestamp(dates[0]) > now + timedelta(minutes=5)
                    or now - _timestamp(dates[0]) > timedelta(days=90)
                    or (expiries and _timestamp(expiries[0]) < now)):
                raise ValueError('repository freshness invalid')
            entries: dict[str, tuple[str, int]] = {}
            in_sha = False
            sections = 0
            for line in lines:
                if line == 'SHA256:':
                    sections += 1
                    in_sha = True
                    continue
                if in_sha and not line.startswith(' '):
                    in_sha = False
                if in_sha:
                    match = re.fullmatch(r'\s+([a-fA-F0-9]{64})\s+(\d+)\s+(\S+)', line)
                    if match:
                        name = match.group(3)
                        if name in entries:
                            raise ValueError('ambiguous repository entry')
                        entries[name] = (match.group(1).lower(), int(match.group(2)))
            if sections != 1:
                raise ValueError('missing repository checksums')
            self.repositories[suite] = (base, entries, signed)
            index_path = 'main/binary-amd64/Packages.xz'
            if index_path not in entries:
                raise ValueError('repository index unavailable')
            compressed = self._repository_file(base, 'dists/' + suite + '/' + index_path, entries[index_path])
            text = lzma.decompress(compressed.read_bytes(), memlimit=256 * 1024 * 1024).decode('utf-8')
            if len(text) > 128 * 1024 * 1024:
                raise ValueError('repository index too large')
            destination = self.security_debs if suite == 'trixie-security' else self.deb_entries
            for paragraph in text.split('\n\n'):
                fields = dict(line.split(': ', 1) for line in paragraph.splitlines()
                              if ': ' in line and not line.startswith(' '))
                name, digest, size = fields.get('Filename', ''), fields.get('SHA256', ''), fields.get('Size', '')
                if (not re.fullmatch(r'pool/[A-Za-z0-9_+./~%-]+\.deb', name) or '..' in name.split('/')
                        or not re.fullmatch(r'[a-fA-F0-9]{64}', digest) or not size.isdigit()
                        or not 1 <= int(size) <= 64 * 1024 * 1024):
                    continue
                metadata = (digest.lower(), int(size))
                if name in destination and destination[name] != metadata:
                    raise ValueError('ambiguous package path')
                destination[name] = metadata

    def _repository_file(self, base: str, path: str, metadata: tuple[str, int]) -> Path:
        digest, size = metadata
        if not 0 < size <= 64 * 1024 * 1024:
            raise ValueError('repository file too large')
        key = hashlib.sha256((base + path).encode()).hexdigest()
        destination = self.root / key
        if not destination.exists():
            url = base + path
            with self.opener.open(Request(url, headers={'User-Agent': 'PXE-Tools-isolated-test/0.1'}), timeout=60) as source:
                if source.geturl() != url:
                    raise ValueError('unexpected repository redirect')
                h = hashlib.sha256()
                count = 0
                with destination.open('xb') as output:
                    while chunk := source.read(1024 * 1024):
                        count += len(chunk)
                        if count > size:
                            raise ValueError('repository file exceeds signed size')
                        h.update(chunk)
                        output.write(chunk)
                if count != size or h.hexdigest() != digest:
                    raise ValueError('repository checksum mismatch')
        with destination.open('rb') as source:
            if destination.stat().st_size != size or hashlib.file_digest(source, 'sha256').hexdigest() != digest:
                raise ValueError('cached repository checksum mismatch')
        return destination

    def file_for(self, request: str) -> tuple[bytes | None, Path | None]:
        if '?' in request or '#' in request:
            raise ValueError('unexpected repository request')
        for prefix, suite in (('/debian-security/dists/trixie-security/', 'trixie-security'),
                              ('/debian/dists/trixie-updates/', 'trixie-updates'),
                              ('/debian/dists/trixie/', 'trixie')):
            if not request.startswith(prefix):
                continue
            relative = unquote_to_bytes(request[len(prefix):]).decode('ascii', errors='strict')
            base, entries, signed = self.repositories[suite]
            if relative == 'InRelease':
                return None, signed
            if suite == 'trixie' and relative in self.boot_files:
                return None, self.boot_files[relative]
            if '/by-hash/SHA256/' in relative:
                directory, digest = relative.split('/by-hash/SHA256/', 1)
                if re.fullmatch(r'[0-9a-f]{64}', digest) is None:
                    raise ValueError('invalid by-hash request')
                candidates = [name for name, record in entries.items()
                              if name.startswith(directory + '/') and '/' not in name[len(directory) + 1:]
                              and record[0] == digest]
                if len(candidates) != 1:
                    raise ValueError('by-hash entry missing or ambiguous')
                relative = candidates[0]
            if relative not in entries:
                raise ValueError('file absent from signed release')
            return None, self._repository_file(base, 'dists/' + suite + '/' + relative, entries[relative])
        if request.startswith('/debian-security/pool/') and request.endswith('.deb'):
            path = unquote_to_bytes(request[len('/debian-security/'):]).decode('ascii', errors='strict')
            if path not in self.security_debs:
                raise ValueError('package absent from security index')
            return None, self._repository_file('https://security.debian.org/debian-security/', path, self.security_debs[path])
        if request.startswith('/debian/pool/') and request.endswith('.deb'):
            path = unquote_to_bytes(request[len('/debian/'):]).decode('ascii', errors='strict')
            if path not in self.deb_entries:
                raise ValueError('package absent from Debian index')
            return None, self._repository_file('https://deb.debian.org/debian/', path, self.deb_entries[path])
        raise ValueError('unexpected repository path')


def start_tls_mirror(mirror: DockerPackageMirror, socket_path: Path, cert: Path, key: Path):
    """Expose the same restricted fixture over TLS on a private Unix socket."""
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):
            pass

        def do_GET(self):
            try:
                with mirror.lock:
                    mirror.requests.append(self.path)
                    data, file = mirror.file_for(self.path)
                size = len(data) if data is not None else file.stat().st_size
                self.send_response(200)
                self.send_header('Content-Length', str(size))
                self.end_headers()
                if data is not None:
                    self.wfile.write(data)
                else:
                    with file.open('rb') as source:
                        shutil.copyfileobj(source, self.wfile, length=256 * 1024)
            except (BrokenPipeError, ConnectionResetError):
                pass
            except Exception as error:
                with mirror.lock:
                    mirror.errors.append(self.path + ': ' + type(error).__name__)
                self.send_error(404)

    class Server(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
        daemon_threads = True

    server = Server(str(socket_path), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert, key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server, thread


USER_DATA = r'''#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
exec </dev/null >/dev/ttyS1 2>&1
fail() {
  printf 'Fixture failed near line %s with exit code %s.\n' "$1" "$2"
  for log in /var/log/pxe-apt.log /var/log/pxe-docker.log; do
    if [ -f "$log" ]; then tail -n 80 "$log"; fi
  done
  if systemctl status docker.service >/dev/null 2>&1; then :; else
    journalctl -u docker.service --no-pager -n 40 2>/dev/null || true
  fi
  printf '%s\n' 'PXE_DOCKER_FIXTURE_FAILED'
  /sbin/poweroff -f || true
  exit 1
}
trap 'fail "$LINENO" "$?"' ERR
. /etc/os-release
[ "${ID:-}" = debian ]
[ "${VERSION_ID:-}" = 13 ]
[ "$(uname -m)" = x86_64 ]
printf '%s\n' 'PXE_DOCKER_PLATFORM_OK'
mkdir -p /mnt/pxe-fixture /tmp/pxe-apt-original
mount -o ro /dev/sr0 /mnt/pxe-fixture
for path in /etc/apt/sources.list.d/*; do
  [ -e "$path" ] || continue
  mv "$path" /tmp/pxe-apt-original/
done
printf '%s\n' 'deb [arch=amd64] http://10.0.2.100/debian trixie main' > /etc/apt/sources.list
printf '%s\n' 'Acquire::By-Hash "false";' 'Acquire::Languages "none";' 'Acquire::http::Proxy "false";' > /etc/apt/apt.conf.d/99-pxe-fixture
apt-get -qq update >/var/log/pxe-apt.log 2>&1
apt-get -y --no-install-recommends install docker.io docker-cli docker-compose >>/var/log/pxe-apt.log 2>&1
systemctl start docker
docker info >/dev/null 2>>/var/log/pxe-docker.log
printf '%s\n' 'PXE_DOCKER_ENGINE_OK'
docker load -i /mnt/pxe-fixture/debian-base.tar >>/var/log/pxe-docker.log 2>&1
DOCKER_BUILDKIT=0 docker build --network host --add-host deb.debian.org:10.0.2.100 \
  -f /mnt/pxe-fixture/packaging/docker/Dockerfile.dev -t pxe-tools-public-dev:local /mnt/pxe-fixture >>/var/log/pxe-docker.log 2>&1
printf '%s\n' 'PXE_DOCKER_BUILD_OK'
compose=(docker compose -p pxefixtest -f /mnt/pxe-fixture/compose.dev.yaml -f /mnt/pxe-fixture/compose.test.yaml)
python3 /mnt/pxe-fixture/init-test.py
"${compose[@]}" up -d --no-build >>/var/log/pxe-docker.log 2>&1
cid=$("${compose[@]}" ps -q console)
[ -n "$cid" ]
docker inspect "$cid" > /tmp/pxe-container-inspect.json
python3 /mnt/pxe-fixture/inspect-test.py
python3 /mnt/pxe-fixture/console-test.py
"${compose[@]}" exec -T console python3 app/cli/netboot_job.py --db /var/lib/pxe-tools/state/database.sqlite enqueue >>/var/log/pxe-docker.log 2>&1
key_digest=$(sha256sum /mnt/pxe-fixture/archive.gpg | cut -d ' ' -f 1)
common=(run -T --rm --no-deps
        -v /mnt/pxe-fixture/archive.gpg:/tmp/archive.gpg:ro
        -v /mnt/pxe-fixture/test-ca.pem:/tmp/test-ca.pem:ro
        -v /mnt/pxe-fixture/verify-worker.py:/tmp/verify-worker.py:ro
        -e SSL_CERT_FILE=/tmp/test-ca.pem console)
if "${compose[@]}" "${common[@]}" python3 app/cli/console.py acquire --state /var/lib/pxe-tools/state \
   --keyring /tmp/archive.gpg --keyring-sha256 0000000000000000000000000000000000000000000000000000000000000000 \
   --server-ip 192.0.2.1 >>/var/log/pxe-docker.log 2>&1; then
  echo 'Worker accepted a wrong keyring digest.' >&2
  exit 1
fi
"${compose[@]}" "${common[@]}" python3 /tmp/verify-worker.py pending "$key_digest"
"${compose[@]}" "${common[@]}" /bin/sh -c \
 'python3 app/cli/console.py acquire --state /var/lib/pxe-tools/state --keyring /tmp/archive.gpg --keyring-sha256 "$1" --server-ip 192.0.2.1 && exec python3 /tmp/verify-worker.py succeeded "$1"' \
 fixture "$key_digest" >>/var/log/pxe-docker.log 2>&1
printf '%s\n' 'PXE_DOCKER_WORKER_OK'
before=$("${compose[@]}" exec -T console sha256sum /var/lib/pxe-tools/state/database.sqlite)
if "${compose[@]}" run -T --rm --no-deps console python3 app/cli/console.py init \
  --state /var/lib/pxe-tools/state --username fixture-admin >>/var/log/pxe-docker.log 2>&1; then
  echo 'Existing database was unexpectedly reinitialized.' >&2
  exit 1
fi
after_rejected=$("${compose[@]}" exec -T console sha256sum /var/lib/pxe-tools/state/database.sqlite)
[ "$before" = "$after_rejected" ]
"${compose[@]}" down >>/var/log/pxe-docker.log 2>&1
docker volume inspect pxefixtest_development-data >/dev/null
"${compose[@]}" up -d --no-build >>/var/log/pxe-docker.log 2>&1
python3 /mnt/pxe-fixture/console-test.py
after=$("${compose[@]}" exec -T console sha256sum /var/lib/pxe-tools/state/database.sqlite)
[ "$before" = "$after" ]
"${compose[@]}" down >>/var/log/pxe-docker.log 2>&1
docker volume inspect pxefixtest_development-data >/dev/null
printf '%s\n' 'PXE_DOCKER_FIXTURE_OK'
sync
/sbin/poweroff -f
'''

INSPECT_TEST = r'''import json
with open('/tmp/pxe-container-inspect.json',encoding='utf-8') as stream:
 obj=json.load(stream)[0]
host=obj['HostConfig']
if obj['Config']['User']!='65532:65532' or not host['ReadonlyRootfs'] or host['NetworkMode']!='host':
 raise RuntimeError('unexpected container user, filesystem or network mode')
if 'ALL' not in (host.get('CapDrop') or []): raise RuntimeError('capabilities not dropped')
if not any(item.startswith('no-new-privileges') for item in (host.get('SecurityOpt') or [])):
 raise RuntimeError('no-new-privileges missing')
mounts=obj.get('Mounts',[])
if any(item.get('Destination')=='/var/run/docker.sock' for item in mounts): raise RuntimeError('Docker socket mounted')
if not any(item.get('Type')=='volume' and item.get('Destination')=='/var/lib/pxe-tools' for item in mounts):
 raise RuntimeError('persistent volume missing')
print('PXE_DOCKER_INSPECT_OK',flush=True)
'''

VERIFY_WORKER = r'''import sqlite3,sys
from pathlib import Path
sys.path.insert(0,'/opt/pxe-tools/app')
from artifacts.verify_bundle import verify_bundle
state=Path('/var/lib/pxe-tools/state')
with sqlite3.connect(state/'database.sqlite') as db:
 row=db.execute('SELECT status,artifact_path,server_ip FROM netboot_jobs').fetchone()
 selection=db.execute('SELECT job_id FROM pxe_boot_selection WHERE id=1').fetchone()
 mode=db.execute('SELECT mode FROM pxe_network_settings WHERE id=1').fetchone()
if not row or row[0]!=sys.argv[1] or selection!=(None,) or mode!=('disabled',):
 raise RuntimeError('unexpected acquisition, selection or network state')
if sys.argv[1]=='pending':
 if row[1] is not None: raise RuntimeError('pending job has artifacts')
elif row[2]!='192.0.2.1': raise RuntimeError('incorrect artifact address')
else: verify_bundle(state/'artifacts',row[1],Path('/tmp/archive.gpg'),'192.0.2.1')
'''

INIT_TEST = r'''import os,pty,selectors,subprocess,time
command=['docker','compose','-p','pxefixtest','-f','/mnt/pxe-fixture/compose.dev.yaml',
         '-f','/mnt/pxe-fixture/compose.test.yaml',
         'run','--rm','--no-deps','console','python3','app/cli/console.py','init',
         '--state','/var/lib/pxe-tools/state','--username','fixture-admin']
master,slave=pty.openpty()
process=subprocess.Popen(command,stdin=slave,stdout=slave,stderr=slave,start_new_session=True)
os.close(slave)
selector=selectors.DefaultSelector()
selector.register(master,selectors.EVENT_READ)
log=bytearray()
prompts=[b'Mot de passe administrateur',b'Confirmer le mot de passe']
cursor=0
secret=b'test-password-only'
try:
 deadline=time.monotonic()+90
 while time.monotonic()<deadline:
  for key,_ in selector.select(timeout=1):
   try: data=os.read(key.fd,4096)
   except OSError: data=b''
   if data: log.extend(data)
  if prompts:
   position=log.find(prompts[0],cursor)
   if position>=0:
    cursor=position+len(prompts.pop(0))
    os.write(master,secret+b'\n')
  if process.poll() is not None: break
 while selector.select(timeout=0):
  try: data=os.read(master,4096)
  except OSError: break
  if not data: break
  log.extend(data)
 if process.poll()!=0 or prompts or secret in log:
  raise RuntimeError('interactive container initialization failed or echoed the password')
 print('PXE_DOCKER_INIT_OK',flush=True)
finally:
 selector.close()
 if process.poll() is None:
  process.terminate()
  try: process.wait(timeout=5)
  except subprocess.TimeoutExpired: process.kill();process.wait()
 os.close(master)
'''


def copy_with_digest(source: Path, target: Path, expected: str, algorithm: str, limit: int) -> None:
    if not source.is_absolute() or source.is_symlink() or not source.is_file():
        raise ValueError('absolute regular input file required')
    h = hashlib.new(algorithm)
    count = 0
    with source.open('rb') as inp, target.open('xb') as out:
        while chunk := inp.read(1024 * 1024):
            count += len(chunk)
            if count > limit:
                raise ValueError('input file exceeds limit')
            h.update(chunk)
            out.write(chunk)
    if h.hexdigest() != expected.lower():
        raise ValueError('input checksum mismatch')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb-dir', 'cloud-image', 'docker-base'):
        parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--cloud-sha512', required=True)
    parser.add_argument('--docker-base-sha256', required=True)
    parser.add_argument('--timeout', type=int, default=1200)
    args = parser.parse_args()
    if (not 120 <= args.timeout <= 2400 or re.fullmatch(r'[a-fA-F0-9]{128}', args.cloud_sha512) is None
            or re.fullmatch(r'[a-fA-F0-9]{64}', args.docker_base_sha256) is None):
        parser.error('invalid timeout or input checksum')
    for executable in ('qemu-system-x86_64', 'qemu-img', 'xorriso', 'dpkg-deb', 'gpgv', 'openssl'):
        if not shutil.which(executable):
            parser.error(executable + ' is required')
    verify_debian_netboot(args.kernel, args.initrd, args.sums, args.release, args.keyring)
    with tempfile.TemporaryDirectory(prefix='pxe-docker-vm-', dir='/tmp') as directory:
        root = Path(directory)
        image = root / 'cloud.qcow2'
        copy_with_digest(args.cloud_image, image, args.cloud_sha512, 'sha512', 1024 * 1024 * 1024)
        with image.open('rb') as stream:
            validate_cloud_header(stream.read(104))
        info = json.loads(subprocess.check_output(['qemu-img', 'info', '-f', 'qcow2', '--output=json', str(image)]))
        if (info.get('format') != 'qcow2' or 'backing-filename' in info
                or not 1024 * 1024 * 1024 <= info.get('virtual-size', 0) <= 8 * 1024 * 1024 * 1024):
            raise ValueError('unexpected cloud image layout')
        seed = root / 'seed'
        seed.mkdir()
        copy_with_digest(args.docker_base, seed / 'debian-base.tar', args.docker_base_sha256,
                         'sha256', 1024 * 1024 * 1024)
        for item in [ROOT / 'app', *(ROOT / 'app').rglob('*')]:
            if item.is_symlink() or (not item.is_dir() and not item.is_file()):
                raise ValueError('unsupported build context path')
        shutil.copytree(ROOT / 'app', seed / 'app', ignore=shutil.ignore_patterns('__pycache__', '*.pyc'))
        (seed / 'packaging/docker').mkdir(parents=True)
        shutil.copyfile(ROOT / 'packaging/docker/Dockerfile.dev', seed / 'packaging/docker/Dockerfile.dev')
        shutil.copyfile(ROOT / 'compose.dev.yaml', seed / 'compose.dev.yaml')
        (seed / 'compose.test.yaml').write_text('services:\n  console:\n    extra_hosts:\n      - "deb.debian.org:10.0.2.100"\n', encoding='ascii')
        shutil.copyfile(ROOT / '.dockerignore', seed / '.dockerignore')
        (seed / 'user-data').write_text(USER_DATA, encoding='utf-8')
        (seed / 'meta-data').write_text('instance-id: pxe-docker-fixture\nlocal-hostname: pxe-fixture\n', encoding='ascii')
        (seed / 'console-test.py').write_text(CONSOLE_TEST.replace('18080', '8080'), encoding='utf-8')
        (seed / 'inspect-test.py').write_text(INSPECT_TEST, encoding='utf-8')
        (seed / 'init-test.py').write_text(INIT_TEST, encoding='utf-8')
        (seed / 'verify-worker.py').write_text(VERIFY_WORKER, encoding='utf-8')
        shutil.copyfile(args.keyring, seed / 'archive.gpg')
        cert, key = seed / 'test-ca.pem', root / 'test-ca-key.pem'
        run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
             '-subj', '/CN=deb.debian.org', '-addext', 'subjectAltName=DNS:deb.debian.org',
             '-keyout', str(key), '-out', str(cert)])
        iso, overlay = root / 'seed.iso', root / 'guest.qcow2'
        run(['xorriso', '-as', 'mkisofs', '-quiet', '-volid', 'cidata', '-joliet', '-rock', '-o', str(iso), str(seed)])
        run(['qemu-img', 'create', '-q', '-f', 'qcow2', '-F', 'qcow2', '-b', str(image), str(overlay)])
        run(['qemu-img', 'resize', '-q', str(overlay), '8G'])
        cache = root / 'mirror-cache'
        cache.mkdir(mode=0o700)
        boot_prefix = 'main/installer-amd64/current/images/netboot/debian-installer/amd64/'
        mirror = DockerPackageMirror(cache, args.release, args.keyring, args.index,
                                     {boot_prefix + 'linux': args.kernel, boot_prefix + 'initrd.gz': args.initrd})
        socket_path = root / 'mirror.sock'
        mirror.start(socket_path)
        tls_socket = root / 'mirror-tls.sock'
        tls_server, tls_thread = start_tls_mirror(mirror, tls_socket, cert, key)
        forward = ' '.join(shlex.quote(item) for item in (sys.executable, '-u',
                           str(ROOT / 'tests/integration/qemu_unix_http_forward.py'), str(socket_path)))
        guest_output = root / 'fixture-console.log'
        guest_output.touch(mode=0o600)
        command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-m', '1024',
                   '-display', 'none', '-serial', 'stdio', '-serial', 'file:' + str(guest_output),
                   '-monitor', 'none', '-no-reboot', '-drive', 'file=' + str(overlay) + ',if=virtio,format=qcow2',
                   '-drive', 'file=' + str(iso) + ',media=cdrom,readonly=on',
                   '-netdev', 'user,id=lan,restrict=on,guestfwd=tcp:10.0.2.100:80-cmd:' + forward.replace(',', ',,')
                   + ',guestfwd=tcp:10.0.2.100:443-cmd:' + ' '.join(shlex.quote(item) for item in
                      (sys.executable, '-u', str(ROOT / 'tests/integration/qemu_unix_http_forward.py'), str(tls_socket))).replace(',', ',,'),
                   '-device', 'virtio-net-pci,netdev=lan,mac=52:54:00:00:00:10']
        process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        selector = selectors.DefaultSelector()
        boot = bytearray()
        fixture = bytearray()
        reported: set[bytes] = set()
        boot_log = (root / 'serial.log').open('wb')
        fixture_log = guest_output.open('rb')
        try:
            assert process.stdout is not None
            selector.register(process.stdout, selectors.EVENT_READ)
            deadline = time.monotonic() + args.timeout
            print('Starting disposable Debian VM; Docker runs only inside that VM', flush=True)
            while time.monotonic() < deadline:
                for key, _ in selector.select(timeout=1):
                    block = os.read(key.fileobj.fileno(), 65536)
                    if block:
                        boot.extend(block)
                        boot_log.write(block)
                        boot_log.flush()
                fixture.extend(fixture_log.read())
                for marker, message in ((b'PXE_DOCKER_PLATFORM_OK', 'Guest reports Debian 13 amd64'),
                                        (b'PXE_DOCKER_ENGINE_OK', 'Docker started inside the guest'),
                                        (b'PXE_DOCKER_BUILD_OK', 'Development Dockerfile built inside the guest'),
                                        (b'PXE_DOCKER_INIT_OK', 'Interactive container initialization succeeded without password echo'),
                                        (b'PXE_DOCKER_INSPECT_OK', 'Compose container restrictions verified'),
                                        (b'PXE_DOCKER_WORKER_OK', 'Container worker prepared and reverified the signed Debian bundle'),
                                        (b'PXE_DEBIAN_CONSOLE_OK', 'Local console login succeeded')):
                    if marker in fixture and marker not in reported:
                        reported.add(marker)
                        print(message, flush=True)
                if b'PXE_DOCKER_FIXTURE_FAILED' in fixture:
                    raise RuntimeError('guest Docker test failed')
                if b'PXE_DOCKER_FIXTURE_OK' in fixture:
                    required = (b'PXE_DOCKER_PLATFORM_OK', b'PXE_DOCKER_ENGINE_OK', b'PXE_DOCKER_BUILD_OK', b'PXE_DOCKER_INIT_OK', b'PXE_DOCKER_WORKER_OK',
                                b'PXE_DOCKER_INSPECT_OK', b'PXE_DEBIAN_CONSOLE_OK')
                    if any(marker not in fixture for marker in required) or fixture.count(b'PXE_DEBIAN_CONSOLE_OK') < 2:
                        raise RuntimeError('required Docker test marker missing')
                    print('Docker VM: Compose console, recreation and persistent database passed', flush=True)
                    return 0
                if process.poll() is not None:
                    raise RuntimeError('guest exited before finishing')
            raise RuntimeError('guest test timed out')
        except Exception:
            print('Guest fixture output (tail):\n' + fixture[-14000:].decode('utf-8', errors='replace'), file=sys.stderr)
            print('Cloud-init final stage observed:', b"running 'modules:final'" in boot, file=sys.stderr)
            print('Fixture rejected requests:', mirror.errors[-20:], file=sys.stderr)
            raise
        finally:
            selector.close()
            boot_log.close()
            fixture_log.close()
            if process.poll() is None:
                process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            tls_server.shutdown()
            tls_server.server_close()
            tls_thread.join(timeout=5)
            mirror.close()


if __name__ == '__main__':
    raise SystemExit(main())
