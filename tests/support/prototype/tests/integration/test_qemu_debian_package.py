#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Test the development .deb on an isolated, disposable Debian cloud VM.

The supplied cloud image is checksum-checked, but its provenance depends on
how the caller obtained that checksum. Debian's cloud image checksum file is
not necessarily signed. A separate signed Debian Release authenticates all
packages provided by the narrowly scoped fixture mirror. Only a temporary
copy-on-write overlay is writable; no host disk or network is bridged.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import lzma
import os
from pathlib import Path
import re
import selectors
import shlex
import shutil
import subprocess
import struct
import sys
import tempfile
import time
from urllib.parse import unquote_to_bytes

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from qemu_debian_mirror import FixtureMirror  # noqa: E402
from test_qemu_dnsmasq_tftp import package_records  # noqa: E402


class PackageMirror(FixtureMirror):
    """Serve only .deb files listed in the authenticated amd64 index."""
    def __init__(self, root: Path, release: Path, keyring: Path, index: Path):
        release_bytes, compressed = release.read_bytes(), index.read_bytes()
        package_records(release_bytes, compressed)
        text = lzma.decompress(compressed, memlimit=256 * 1024 * 1024).decode('utf-8')
        if len(text) > 128 * 1024 * 1024:
            raise ValueError('package index too large')
        self.deb_entries: dict[str, tuple[str, int]] = {}
        for paragraph in text.split('\n\n'):
            fields = dict(line.split(': ', 1) for line in paragraph.splitlines()
                          if ': ' in line and not line.startswith(' '))
            name, digest, size = fields.get('Filename', ''), fields.get('SHA256', ''), fields.get('Size', '')
            if (not re.fullmatch(r'pool/[A-Za-z0-9_+./~%-]+\.deb', name) or '..' in name.split('/')
                    or not re.fullmatch(r'[a-fA-F0-9]{64}', digest) or not size.isdigit()
                    or not 1 <= int(size) <= 32 * 1024 * 1024):
                continue
            metadata = digest.lower(), int(size)
            if name in self.deb_entries and self.deb_entries[name] != metadata:
                raise ValueError('ambiguous package path')
            self.deb_entries[name] = metadata
        super().__init__(root, release, keyring, b'')

    def file_for(self, request: str) -> tuple[bytes | None, Path | None]:
        if request.startswith('/debian/pool/') and request.endswith('.deb'):
            if '?' in request or '#' in request:
                raise ValueError('unexpected package request')
            path = unquote_to_bytes(request[len('/debian/'):]).decode('ascii', errors='strict')
            if path not in self.deb_entries:
                raise ValueError('package is not in the authenticated index')
            return None, self._fetch_signed(path, self.deb_entries[path])
        return super().file_for(request)


# This script runs only in the disposable guest. The password and account are
# fixtures; the VM has no host disk and no connection to the user's network.
USER_DATA = r'''#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
exec </dev/null >/dev/ttyS1 2>&1
fail() {
  printf 'Fixture failed near line %s with exit code %s.\n' "$1" "$2"
  if [ -f /var/log/pxe-fixture.log ]; then tail -n 100 /var/log/pxe-fixture.log; fi
  printf '%s\n' 'PXE_DEBIAN_PACKAGE_FAILED'
  /sbin/poweroff -f || true
  exit 1
}
trap 'fail "$LINENO" "$?"' ERR
. /etc/os-release
[ "${ID:-}" = debian ]
[ "${VERSION_ID:-}" = 13 ]
[ "$(uname -m)" = x86_64 ]
printf '%s\n' 'PXE_DEBIAN_PLATFORM_OK'
mkdir -p /mnt/pxe-fixture /tmp/pxe-apt-original
mount -o ro /dev/sr0 /mnt/pxe-fixture
for path in /etc/apt/sources.list.d/*; do
  [ -e "$path" ] || continue
  mv "$path" /tmp/pxe-apt-original/
done
printf '%s\n' 'deb [arch=amd64] http://10.0.2.100/debian trixie main' > /etc/apt/sources.list
printf '%s\n' 'Acquire::By-Hash "false";' 'Acquire::Languages "none";' 'Acquire::http::Proxy "false";' > /etc/apt/apt.conf.d/99-pxe-fixture
apt-get -qq update >/var/log/pxe-fixture.log 2>&1
apt-get -y --no-install-recommends install /mnt/pxe-fixture/pxe-tools-dev.deb >>/var/log/pxe-fixture.log 2>&1
dpkg-query -W -f='${Status}\n' pxe-tools-dev | grep -qx 'install ok installed'
printf '%s\n' 'PXE_DEBIAN_APT_OK'
useradd --system --create-home --home-dir /var/lib/pxe-fixture --shell /usr/sbin/nologin pxe-fixture
chmod 700 /var/lib/pxe-fixture
runuser -u pxe-fixture -- /bin/bash -c 'exec 3< <(printf "%s\n" "test-password-only"); exec /usr/bin/pxe-tools-dev init --state /var/lib/pxe-fixture/state --username fixture-admin --password-fd 3'
cp /var/lib/pxe-fixture/state/database.sqlite /tmp/pxe-fixture-database-before
runuser -u pxe-fixture -- /usr/bin/pxe-tools-dev serve --state /var/lib/pxe-fixture/state --port 18080 >/tmp/pxe-fixture-web.log 2>&1 &
webpid=$!
trap 'kill "$webpid" 2>/dev/null || true' EXIT
python3 /mnt/pxe-fixture/console-test.py
kill "$webpid"
wait "$webpid" || true
trap - EXIT
dpkg --install /mnt/pxe-fixture/pxe-tools-dev-upgrade.deb >>/var/log/pxe-fixture.log 2>&1
[ "$(dpkg-query -W -f='${Version}' pxe-tools-dev)" = '0.0.1~dev2' ]
cmp /tmp/pxe-fixture-database-before /var/lib/pxe-fixture/state/database.sqlite
dpkg --remove pxe-tools-dev >>/var/log/pxe-fixture.log 2>&1
cmp /tmp/pxe-fixture-database-before /var/lib/pxe-fixture/state/database.sqlite
dpkg --purge pxe-tools-dev >>/var/log/pxe-fixture.log 2>&1
cmp /tmp/pxe-fixture-database-before /var/lib/pxe-fixture/state/database.sqlite
[ ! -e /usr/lib/pxe-tools-dev/app/cli/init.php ]
[ ! -e /usr/bin/pxe-tools-dev ]
printf '%s\n' 'PXE_DEBIAN_PACKAGE_OK'
sync
/sbin/poweroff -f
'''

CONSOLE_TEST = r'''import http.cookiejar,re,time,urllib.parse,urllib.request
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
url='http://127.0.0.1:18080'
for attempt in range(60):
 try:
  login=opener.open(url+'/login',timeout=2).read().decode('utf-8')
  break
 except OSError:
  time.sleep(1)
else:
 raise RuntimeError('console did not start')
token=re.search(r'name="csrf_token" value="([^"]+)"',login)
if token is None: raise RuntimeError('login form missing')
data=urllib.parse.urlencode({'csrf_token':token.group(1),'username':'fixture-admin','password':'test-password-only'}).encode()
page=opener.open(urllib.request.Request(url+'/login',data=data),timeout=10).read().decode('utf-8')
if 'Machines' not in page or 'Déconnexion' not in page: raise RuntimeError('login or inventory page failed')
print('PXE_DEBIAN_CONSOLE_OK',flush=True)
'''


def run(args: list[str]) -> None:
    subprocess.run(args, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def validate_cloud_header(header: bytes) -> None:
    """Reject qcow2 features that could make QEMU open another host file."""
    if len(header) != 104 or header[:4] != b'QFI\xfb':
        raise ValueError('not a qcow2 image')
    version = struct.unpack_from('>I', header, 4)[0]
    backing_offset, backing_size = struct.unpack_from('>QI', header, 8)
    if version not in (2, 3) or backing_offset or backing_size:
        raise ValueError('cloud image must not use a backing file')
    if version == 3 and struct.unpack_from('>Q', header, 72)[0] & 4:
        raise ValueError('cloud image must not use an external data file')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb-dir', 'cloud-image'):
        parser.add_argument('--' + name, type=Path, required=True)
    parser.add_argument('--cloud-sha512', required=True)
    parser.add_argument('--timeout', type=int, default=900)
    args = parser.parse_args()
    if not 120 <= args.timeout <= 1800 or re.fullmatch(r'[a-fA-F0-9]{128}', args.cloud_sha512) is None:
        parser.error('invalid timeout or cloud image checksum')
    for executable in ('qemu-system-x86_64', 'qemu-img', 'xorriso', 'dpkg-deb', 'gpgv'):
        if not shutil.which(executable):
            parser.error(executable + ' is required')
    if not args.cloud_image.is_absolute() or args.cloud_image.is_symlink() or not args.cloud_image.is_file():
        parser.error('an absolute regular cloud image is required')
    verify_debian_netboot(args.kernel, args.initrd, args.sums, args.release, args.keyring)
    with tempfile.TemporaryDirectory(prefix='pxe-native-vm-', dir='/tmp') as directory:
        root = Path(directory)
        # QEMU must never follow a backing path or an external data file from
        # the supplied image. Verify a private snapshot before opening it.
        image = root / 'cloud.qcow2'
        digest = hashlib.sha512()
        with args.cloud_image.open('rb') as source, image.open('xb') as output:
            while chunk := source.read(1024 * 1024):
                digest.update(chunk)
                output.write(chunk)
                if output.tell() > 1024 * 1024 * 1024:
                    raise ValueError('cloud image exceeds size limit')
        if digest.hexdigest() != args.cloud_sha512.lower():
            raise ValueError('cloud image checksum mismatch')
        with image.open('rb') as stream:
            header = stream.read(104)
        validate_cloud_header(header)
        info = json.loads(subprocess.check_output(['qemu-img', 'info', '-f', 'qcow2', '--output=json', str(image)]))
        if (info.get('format') != 'qcow2' or 'backing-filename' in info
                or not 1024 * 1024 * 1024 <= info.get('virtual-size', 0) <= 16 * 1024 * 1024 * 1024):
            raise ValueError('unexpected cloud image layout')
        seed = root / 'seed'
        seed.mkdir()
        (seed / 'user-data').write_text(USER_DATA, encoding='utf-8')
        (seed / 'meta-data').write_text('instance-id: pxe-fixture\nlocal-hostname: pxe-fixture\n', encoding='ascii')
        (seed / 'console-test.py').write_text(CONSOLE_TEST, encoding='utf-8')
        package = seed / 'pxe-tools-dev.deb'
        run([sys.executable, str(ROOT / 'packaging/debian/build-dev-package.py'), str(package)])
        spec = importlib.util.spec_from_file_location('pxe_dev_builder', ROOT / 'packaging/debian/build-dev-package.py')
        if spec is None or spec.loader is None:
            raise RuntimeError('package builder unavailable')
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        builder.CONTROL = builder.CONTROL.replace('Version: 0.0.1~dev1', 'Version: 0.0.1~dev2')
        builder.build(seed / 'pxe-tools-dev-upgrade.deb')
        iso, overlay = root / 'seed.iso', root / 'guest.qcow2'
        run(['xorriso', '-as', 'mkisofs', '-quiet', '-volid', 'cidata', '-joliet', '-rock', '-o', str(iso), str(seed)])
        run(['qemu-img', 'create', '-q', '-f', 'qcow2', '-F', 'qcow2', '-b', str(image), str(overlay)])
        cache = root / 'mirror-cache'
        cache.mkdir(mode=0o700)
        mirror = PackageMirror(cache, args.release, args.keyring, args.index)
        socket_path = root / 'mirror.sock'
        mirror.start(socket_path)
        forward = ' '.join(shlex.quote(item) for item in (sys.executable, '-u',
                           str(ROOT / 'tests/integration/qemu_unix_http_forward.py'), str(socket_path)))
        guest_output = root / 'fixture-console.log'
        guest_output.touch(mode=0o600)
        command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-m', '1024',
                   '-display', 'none', '-serial', 'stdio', '-serial', 'file:' + str(guest_output),
                   '-monitor', 'none', '-no-reboot',
                   '-drive', 'file=' + str(overlay) + ',if=virtio,format=qcow2',
                   '-drive', 'file=' + str(iso) + ',media=cdrom,readonly=on',
                   '-netdev', 'user,id=lan,restrict=on,guestfwd=tcp:10.0.2.100:80-cmd:' + forward.replace(',', ',,'),
                   '-device', 'virtio-net-pci,netdev=lan,mac=52:54:00:00:00:10']
        process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        selector = selectors.DefaultSelector()
        log = bytearray()
        fixture = bytearray()
        reported: set[bytes] = set()
        live_log = (root / 'serial.log').open('wb')
        fixture_log = guest_output.open('rb')
        try:
            assert process.stdout is not None
            selector.register(process.stdout, selectors.EVENT_READ)
            deadline = time.monotonic() + args.timeout
            print('Starting disposable Debian VM with a restricted, authenticated package mirror', flush=True)
            while time.monotonic() < deadline:
                for key, _ in selector.select(timeout=1):
                    block = os.read(key.fileobj.fileno(), 65536)
                    if block:
                        log.extend(block)
                        live_log.write(block)
                        live_log.flush()
                fixture.extend(fixture_log.read())
                for marker, message in ((b'PXE_DEBIAN_PLATFORM_OK', 'Guest reports Debian 13 amd64'),
                                        (b'PXE_DEBIAN_APT_OK', 'APT installed the development package'),
                                        (b'PXE_DEBIAN_CONSOLE_OK', 'Local console login succeeded')):
                    if marker in fixture and marker not in reported:
                        reported.add(marker)
                        print(message, flush=True)
                if b'PXE_DEBIAN_PACKAGE_FAILED' in fixture:
                    raise RuntimeError('guest package test failed')
                if b'PXE_DEBIAN_PACKAGE_OK' in fixture:
                    if any(marker not in fixture for marker in (b'PXE_DEBIAN_PLATFORM_OK', b'PXE_DEBIAN_APT_OK',
                                                           b'PXE_DEBIAN_CONSOLE_OK')):
                        raise RuntimeError('platform, APT or console test marker missing')
                    print('Debian VM: package install, local login, upgrade, removal and preserved database passed', flush=True)
                    return 0
                if process.poll() is not None:
                    raise RuntimeError('guest exited before finishing')
            raise RuntimeError('guest test timed out')
        except Exception:
            print('Guest fixture output (tail):\n' + fixture[-12000:].decode('utf-8', errors='replace'), file=sys.stderr)
            print('Cloud-init final stage observed:', b"running 'modules:final'" in log, file=sys.stderr)
            print('Fixture rejected requests:', mirror.errors[-20:], file=sys.stderr)
            raise
        finally:
            selector.close()
            live_log.close()
            fixture_log.close()
            if process.poll() is None:
                process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            mirror.close()


if __name__ == '__main__':
    raise SystemExit(main())
