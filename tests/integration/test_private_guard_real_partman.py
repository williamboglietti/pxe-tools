#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Run the application's generated disk guard in an isolated Debian Installer.

The prototype supplies authenticated Debian fixture helpers, but its own disk
guard is not loaded. Only the actual generated private-application command is
used. Both guests have a single newly created disposable qcow2 disk and QEMU
restricted user networking with a Unix-socket fixture mirror.
"""
import argparse
import base64
import os
from pathlib import Path
import re
import selectors
import shlex
import subprocess
import sys
import tempfile
import time
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[2]
# Keep the actual application renderer in this checkout as the default while
# preserving an explicitly selected fixture path.
os.environ.setdefault('PXE_APP_DIR', str(ROOT / 'app'))
# Verification and QEMU bench helpers kept with the tests (LGPL-3.0, reviewed).
PROTOTYPE = ROOT / 'tests/support/prototype'
sys.path.insert(0, str(ROOT / 'tests/unit'))
sys.path.insert(0, str(PROTOTYPE / 'tests/integration'))
sys.path.insert(0, str(PROTOTYPE / 'app'))
from test_preseed_install_invariants import PreseedInvariants
from run_qemu_dnsmasq_from_debian import prepare_inputs
from artifacts.debian_netboot import verify_debian_netboot
from debian_disk_modules import verify_disk_module
from test_qemu_dnsmasq_tftp import verify_packages
from test_qemu_proxy_boot import archive_tree
from test_qemu_partman_flow import preseed
from qemu_debian_mirror import FixtureMirror


def generated_command(serial: str) -> str:
    config = {'distro':'debian', 'partition_mode':'default', 'admin_user_login':'fixture',
              'admin_user_pwhash':'!', 'system_disk_path':'/dev/vda',
              'system_disk_identity':{'kind':'serial', 'value':serial, 'status':'valid'}}
    result = PreseedInvariants().render(config, template='{{ACCOUNTS_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
    found = re.findall(r'(?m)^d-i partman/early_command string \\\n    ([^\n]+)', result)
    if len(found) != 1 or '/tmp/pxe-disk-guard.sh remap ' not in found[0]:
        raise RuntimeError('application did not generate exactly one expected guard')
    expected = base64.b64encode(serial.encode()).decode()
    if "'/dev/vda:" + expected + "'" not in found[0]:
        raise RuntimeError('generated guard is not bound to the fixture identity')
    return found[0]


def run_guest(root: Path, args, mirror: FixtureMirror, initrd: Path, *, valid: bool) -> None:
    label = 'valid' if valid else 'mismatch'
    disk = root / (label + '.qcow2')
    subprocess.run(['qemu-img','create','-f','qcow2',str(disk),'8G'], check=True, stdout=subprocess.DEVNULL)
    initial = root / (label + '-blank.qcow2')
    subprocess.run(['qemu-img','create','-f','qcow2',str(initial),'8G'], check=True, stdout=subprocess.DEVNULL)
    actual = generated_command('PXE-TEST-ONE' if valid else 'PXE-TEST-OTHER')
    # Preserve the command and exit status; markers are test-only observation.
    probe = ("for f in /sys/block/vda/serial /sys/block/vda/device/serial "
             "/sys/block/vda/device/device/serial; do "
             "if [ -r \"$f\" ] && [ \"$(cat \"$f\")\" = PXE-TEST-ONE ]; then "
             "printf 'PRIVATE_SERIAL_SOURCE=%s\\n' \"$f\" >/dev/ttyS0; fi; done; ")
    instrumentation = ("; rc=$?; printf 'PRIVATE_GUARD_STATUS=%s\\n' \"$rc\" >/dev/ttyS0; exit \"$rc\"")
    mirror.preseed = (preseed('PXE-TEST-ONE', commit_gate=True)
                      + ('d-i partman/early_command string ' + probe + actual + instrumentation + '\n').encode())
    forward = ' '.join(shlex.quote(x) for x in (sys.executable, '-u',
                       str(PROTOTYPE / 'tests/integration/qemu_unix_http_forward.py'), str(root / 'mirror.sock')))
    command = ['qemu-system-x86_64','-machine','pc,accel=tcg','-smp','1','-display','none',
               '-serial','stdio','-monitor','none','-no-reboot','-m',str(args.memory),
               '-kernel',str(root / 'linux'),'-initrd',str(initrd),
               '-append','console=ttyS0,115200n8 net.ifnames=0 auto=true priority=critical netcfg/get_nameservers=10.0.2.3 url=http://10.0.2.100/preseed.cfg',
               '-netdev','user,id=lan,restrict=on,guestfwd=tcp:10.0.2.100:80-cmd:'+forward.replace(',',',,'),
               '-device','e1000,netdev=lan','-drive','file='+str(disk)+',if=none,id=disk,format=qcow2,readonly=' + ('on' if valid else 'off'),
               '-device','virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE']
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    output = bytearray()
    refusal_seen = None
    try:
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + args.timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                data = os.read(key.fileobj.fileno(), 65536)
                if data:
                    output.extend(data)
                    if len(output) > 16 * 1024 * 1024:
                        raise RuntimeError('installer log exceeded limit')
            if valid:
                if b'PRIVATE_GUARD_STATUS=1' in output:
                    raise RuntimeError('real guard refused the matching fixture')
                if b'PRIVATE_PARTMAN_BEFORE_COMMIT' in output:
                    if b'PRIVATE_GUARD_STATUS=0' not in output:
                        raise RuntimeError('partitioner reached commit without a successful real guard')
                    break
            else:
                if b'PRIVATE_PARTMAN_BEFORE_COMMIT' in output:
                    raise RuntimeError('partitioner reached commit after identity mismatch')
                if b'PRIVATE_GUARD_STATUS=1' in output and refusal_seen is None:
                    refusal_seen = time.monotonic()
                if refusal_seen is not None and time.monotonic() - refusal_seen >= 10:
                    break
            if process.poll() is not None:
                raise RuntimeError('installer exited before the observation')
        else:
            raise RuntimeError('installer observation timed out')
    except Exception:
        tail = output[-8000:].decode('utf-8','replace')
        print('Fixture serial tail:\n' + re.sub(r'[A-Za-z0-9+/=]{200,}', '[encoded fixture]', tail), file=sys.stderr)
        raise
    finally:
        selector.close()
        if process.poll() is None: process.terminate()
        try: process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        if process.stdout: process.stdout.close()
    comparison = subprocess.run(['qemu-img','compare','-q',str(disk),str(initial)], check=False)
    if comparison.returncode != 0:
        raise RuntimeError('fixture disk changed before the test stop')
    sources = re.findall(rb'PRIVATE_SERIAL_SOURCE=(/sys/block/vda/(?:serial|device/serial|device/device/serial))(?=\r?\n)', output)
    print('Real application guard:', label, 'observed; disposable disk unchanged; sysfs sources:',
          ', '.join(x.decode('ascii', 'replace') for x in sources) or 'none', flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keyring', type=Path, required=True)
    parser.add_argument('--memory', type=int, default=1024)
    parser.add_argument('--timeout', type=int, default=600)
    args = parser.parse_args()
    if not 768 <= args.memory <= 1536 or not 60 <= args.timeout <= 1200:
        parser.error('invalid disposable guest limits')
    with tempfile.TemporaryDirectory(prefix='pxe-private-partman-') as directory:
        root = Path(directory)
        (root / 'debs').mkdir()
        parameters = SimpleNamespace(keyring=args.keyring, runtime_manager=False, disk_guard=True,
                                     proxy_boot=False, uefi=False, partman_uefi=False,
                                     backup_guest=False, discovery_guest=False, discovery_pxe=False)
        prepare_inputs(parameters, root)
        verify_debian_netboot(root/'linux', root/'initrd.gz', root/'SHA256SUMS', root/'InRelease', args.keyring)
        verify_packages((root/'InRelease').read_bytes(), (root/'Packages.xz').read_bytes(), root/'debs')
        verify_disk_module((root/'InRelease').read_bytes(), (root/'installer-Packages.xz').read_bytes(),
                           root/'initrd.gz', root/'disk-modules.udeb')
        hook = root/'fixture/usr/lib/partman/commit.d/00-private-fixture-stop'
        hook.parent.mkdir(parents=True)
        hook.write_text('#!/bin/sh\nprintf "PRIVATE_PARTMAN_BEFORE_COMMIT\\n" >/dev/ttyS0\nexit 1\n')
        hook.chmod(0o755)
        overlay = root/'fixture.gz'
        archive_tree(root/'fixture', overlay)
        combined = root/'installer.gz'
        with combined.open('wb') as out:
            for file in (root/'initrd.gz', overlay):
                with file.open('rb') as source:
                    while chunk := source.read(1024*1024): out.write(chunk)
        cache = root/'mirror-cache'
        cache.mkdir(mode=0o700)
        mirror = FixtureMirror(cache, root/'InRelease', args.keyring, b'')
        mirror.start(root/'mirror.sock')
        try:
            run_guest(root, args, mirror, combined, valid=False)
            run_guest(root, args, mirror, combined, valid=True)
        finally:
            mirror.close()


if __name__ == '__main__':
    main()
