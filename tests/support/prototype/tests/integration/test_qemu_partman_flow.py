#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the identity hook through the real Debian Installer partitioner.

Disks are created in a temporary directory and exposed read-only by default.
The explicit disposable-write fixture attaches one newly created qcow2 writable.
The guest has restricted QEMU user networking with one Unix-socket forward to an
authenticated Debian fixture mirror. No host interface or disk is bridged.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import selectors
import shlex
import shutil
import subprocess
import sys
import tempfile
import time
import re

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'app'))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from debian_disk_modules import verify_disk_module  # noqa: E402
from install.partman_overlay import build_identity_overlay  # noqa: E402
from qemu_debian_mirror import FixtureMirror  # noqa: E402
from test_qemu_dnsmasq_tftp import verify_packages  # noqa: E402
from test_qemu_proxy_boot import archive_tree  # noqa: E402
from test_qemu_project_menu import package_checks  # noqa: E402


def scope_refusal_in(log: bytes | bytearray) -> bool:
    """Do not mistake shell tracing of the trap definition for a real refusal."""
    return re.search(rb'(?m)^(?:[^\r\n]*: )?Installer partition plan scope refused\.\r?$', log) is not None


def fixture_mounts_in(log: bytes | bytearray) -> list[bytes]:
    # The installer redraws its progress bar with carriage returns, including
    # immediately before a hook's first line on the shared serial console. CSI
    # styling and cursor movements may also precede the first marker.
    clean = re.sub(rb'\x1b\[[0-?]*[ -/]*[@-~]', b'', log)
    return re.findall(rb'(?:^|[\r\n])PXE_PARTMAN_RECIPE_MOUNT=([^\r\n]*)(?=\r?\n)', clean)


def fixture_partitions_in(log: bytes | bytearray) -> list[tuple[bytes, bytes, bytes]]:
    clean = re.sub(rb'\x1b\[[0-?]*[ -/]*[@-~]', b'', log)
    return re.findall(rb'(?:^|[\r\n])PXE_PARTMAN_WRITE_PART=(vda[123]),([0-9]+),([^\r\n]*)(?=\r?\n)', clean)


def preseed(serial: str, *, commit_gate: bool = False, mismatch: bool = False, other_disk: bool = False,
            recipe_segments: str | None = None, fixture_lvm: bool = False) -> bytes:
    # These are synthetic test-only values. The guest never receives an actual
    # password; the caller selects the test-only stop point explicitly.
    early = ("mkdir -p /run/pxe-tools; chmod 700 /run/pxe-tools; "
             "printf '%s\\n' '/dev/vda' '" + serial + "' > /run/pxe-tools/disk-identity; "
             "chmod 600 /run/pxe-tools/disk-identity; touch /var/log/syslog; "
             "tail -f /var/log/syslog </dev/null >/dev/ttyS0 2>/dev/null &")
    if commit_gate and mismatch:
        early += ' touch /run/pxe-fixture-mismatch-at-commit'
    if other_disk:
        early += ' touch /run/pxe-fixture-other-disk'
    if fixture_lvm:
        early += ' touch /run/pxe-fixture-lvm'
    choice = 'pxe-fixture' if recipe_segments is not None else 'atomic'
    # The standard fixture intentionally contains no swap partition. Answer the
    # installer's warning explicitly, only for this disposable test recipe.
    expert = (f'd-i partman-auto/expert_recipe string pxe-fixture :: {recipe_segments}\n'
              'd-i partman-basicfilesystems/no_swap boolean false\n') if recipe_segments is not None else ''
    automatic = f'''{expert}d-i partman-auto/choose_recipe select {choice}
d-i partman/confirm_write_new_label boolean true
d-i partman/choose_partition select finish
d-i partman/confirm boolean true
d-i partman/confirm_nooverwrite boolean true
''' if commit_gate else ''
    lvm = '''d-i partman-auto-lvm/new_vg_name string vg0
d-i partman-auto-lvm/guided_size string max
d-i partman-lvm/device_remove_lvm boolean true
d-i partman-lvm/confirm boolean true
d-i partman-lvm/confirm_nooverwrite boolean true
d-i partman-auto/purge_lvm_from_device boolean true
''' if fixture_lvm else ''
    return f'''d-i debian-installer/locale string en_US.UTF-8
d-i keyboard-configuration/xkb-keymap select us
d-i netcfg/choose_interface select auto
d-i netcfg/get_hostname string pxe-fixture
d-i netcfg/get_domain string example.invalid
d-i mirror/country string manual
d-i mirror/http/hostname string 10.0.2.100
d-i mirror/http/directory string /debian
d-i mirror/http/proxy string
d-i mirror/suite string trixie
d-i mirror/udeb/suite string trixie
d-i passwd/root-login boolean false
d-i passwd/user-fullname string Fixture User
d-i passwd/username string fixture
d-i passwd/user-password-crypted password !
d-i clock-setup/utc boolean true
d-i clock-setup/ntp boolean false
d-i time/zone string UTC
d-i hw-detect/load_firmware boolean false
d-i partman-auto/method string {'lvm' if fixture_lvm else 'regular'}
d-i partman-auto/disk string {'/dev/vdb' if other_disk else '/dev/vda'}
{lvm}
{automatic}
d-i preseed/early_command string {early}
'''.encode('ascii')


def run_installer(args, root: Path, combined: Path, mirror: FixtureMirror, *, valid: bool) -> None:
    name = 'cross' if args.cross_target else ('valid' if valid else 'mismatch')
    disk = root / (name + '.qcow2')
    disk_size = '16G' if args.fixture_lvm else '8G'
    subprocess.run(['qemu-img', 'create', '-f', 'qcow2', str(disk), disk_size],
                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
    with disk.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    mirror.preseed = preseed('PXE-TEST-ONE' if valid or args.commit_gate else 'PXE-TEST-WRONG',
                             commit_gate=args.commit_gate, mismatch=not valid, other_disk=args.cross_target,
                             recipe_segments=args.recipe_segments, fixture_lvm=args.fixture_lvm)
    socket_path = root / 'mirror.sock'
    forward = ' '.join(shlex.quote(item) for item in (sys.executable, '-u',
                       str(ROOT / 'tests/integration/qemu_unix_http_forward.py'), str(socket_path)))
    command = ['qemu-system-x86_64', '-machine', 'pc,accel=tcg', '-smp', '1', '-display', 'none',
               '-serial', 'stdio', '-monitor', 'none', '-no-reboot', '-m', str(args.guest_memory_mib),
               '-kernel', str(args.kernel), '-initrd', str(combined),
               # The restricted DHCP fixture advertises no DNS server. Supply
               # a synthetic address before the remote preseed is fetched so
               # netcfg does not wait for an interactive response.
               '-append', 'console=ttyS0,115200n8 net.ifnames=0 auto=true priority=critical netcfg/get_nameservers=10.0.2.3 url=http://10.0.2.100/preseed.cfg',
               '-netdev', 'user,id=lan,restrict=on,guestfwd=tcp:10.0.2.100:80-cmd:' + forward.replace(',', ',,'),
               '-device', 'e1000,netdev=lan',
               '-drive', 'file=' + str(disk) + ',if=none,id=disk,format=qcow2,readonly=' + ('off' if args.disposable_write else 'on'),
               '-device', 'virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE']
    if args.uefi:
        variables = root / (name + '-ovmf-vars.fd')
        shutil.copyfile(args.firmware_vars, variables)
        command += ['-device', 'virtio-rng-pci',
                    '-drive', 'if=pflash,format=raw,readonly=on,file=' + str(args.firmware_code),
                    '-drive', 'if=pflash,format=raw,file=' + str(variables)]
    other_disk = root / (name + '-other.qcow2')
    if args.two_disks or args.cross_target:
        subprocess.run(['qemu-img', 'create', '-f', 'qcow2', str(other_disk), '8G'],
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        with other_disk.open('rb') as stream:
            other_digest = hashlib.file_digest(stream, 'sha256').hexdigest()
        command += ['-drive', 'file=' + str(other_disk) + ',if=none,id=other,format=qcow2,readonly=on',
                    '-device', 'virtio-blk-pci,drive=other,serial=PXE-TEST-TWO']
    print('Starting real Debian Installer with', name, 'identity', flush=True)
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    log = bytearray()
    live_log = (root / (name + '-serial.log')).open('wb')
    try:
        assert process.stdout is not None
        selector.register(process.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + args.timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                data = os.read(key.fileobj.fileno(), 65536)
                if data:
                    log.extend(data)
                    live_log.write(data)
                    live_log.flush()
            success_marker = (b'PXE_PARTMAN_DISPOSABLE_WRITE_COMPLETE' if args.disposable_write else
                              b'PXE_PARTMAN_COMMIT_AFTER_IDENTITY_GATE' if args.commit_gate else b'PXE_PARTMAN_AFTER_IDENTITY_GATE')
            scope_refused = scope_refusal_in(log)
            if args.cross_target and scope_refused:
                observed_reason = re.search(rb'(?m)^(?:[^\r\n]*: )?Partman reported changes on another disk\.\r?$', log)
                if b'PXE_PARTMAN_OTHER_DISK_CHANGED=yes' not in log or success_marker in log or observed_reason is None:
                    raise RuntimeError('scope refusal did not follow the observed other-disk change')
                if args.debug_scope:
                    print('Scope trace:\n' + log[-8000:].decode('utf-8', errors='replace'), flush=True)
                print('Real partman refused pending changes on the other disk', flush=True)
                break
            if valid and success_marker in log:
                if args.cross_target:
                    raise RuntimeError('scope gate accepted pending changes on the other disk')
                if args.fixed_size_experiment and b'PXE_FIXED_SIZE_EXPERIMENT_APPLIED' not in log:
                    raise RuntimeError('disposable fixed-size experiment did not run')
                match = re.search(rb'PXE_DISCOVERY_FIXTURE: (\{[^\r\n]*\})', log)
                if match is None:
                    raise RuntimeError('installer collector did not report an inventory')
                report = json.loads(match.group(1))
                disks = [disk for disk in report['disks'] if disk['name'] == '/dev/vda']
                if report['interface'] != 'eth0' or len(disks) != 1 or disks[0]['serial'] != 'PXE-TEST-ONE':
                    raise RuntimeError('installer collector reported unexpected fixture hardware')
                if report.get('inventory', {}).get('boot_mode') != ('UEFI' if args.uefi else 'BIOS'):
                    raise RuntimeError('installer collector reported an unexpected boot mode')
                if args.recipe_segments is not None:
                    mounts = fixture_mounts_in(log)
                    if sorted(mounts) != ([b'/', b'/boot', b'/boot/efi'] if args.uefi else [b'/', b'/boot']):
                        raise RuntimeError('partman did not produce the expected fixture mount points')
                    print('Real partman planned the fixture root and boot mount points', flush=True)
                if args.disposable_write:
                    parts = fixture_partitions_in(log)
                    actual = {name: (int(sectors) * 512, fs) for name, sectors, fs in parts}
                    expected_root = 8 * 1024**3 - 512_000_000 if args.fixture_fill else 4_096_000_000
                    expected_parts = ((b'vda1', 512_000_000, b'vfat'), (b'vda2', 512_000_000, b'ext4'),
                                      (b'vda3', expected_root, b'ext4')) if args.uefi else (
                                          (b'vda1', 512_000_000, b'ext4'), (b'vda2', expected_root, b'ext4'))
                    if (len(parts) != len(expected_parts) or set(actual) != {name for name, _, _ in expected_parts}
                            or any(actual[name][1] != fs or abs(actual[name][0] - expected) > 8_000_000
                                   for name, expected, fs in expected_parts)):
                        raise RuntimeError('formatted disposable partitions differ from the fixture')
                    print('Real partman wrote and formatted the disposable fixture partitions',
                          '(remaining space)' if args.fixture_fill else '(fixed sizes)', flush=True)
                print('Real Debian Installer collected the disposable guest inventory', flush=True)
                print('Real partman reached the test stop after accepting the identity',
                      'after commit.d' if args.disposable_write else 'in commit.d' if args.commit_gate else 'during initialization', flush=True)
                break
            if valid and b'Installer disk identity gate refused.' in log:
                raise RuntimeError('partitioner refused the valid test identity')
            if valid and not args.cross_target and scope_refused:
                raise RuntimeError('partitioner refused the expected disk scope')
            if valid and b'PXE_DISCOVERY_FIXTURE_FAILED' in log:
                raise RuntimeError('installer collector failed in the guest')
            if args.fixed_size_experiment and b'PXE_FIXED_SIZE_EXPERIMENT_FAILED' in log:
                raise RuntimeError('disposable fixed-size experiment refused the installer source')
            if args.fixture_lvm and scope_refused and b'PXE_PARTMAN_FIXTURE_CORRUPTED_IDENTITY' not in log:
                raise RuntimeError('scope guard refused during LVM preparation before the deliberate identity change')
            if args.fixture_lvm and b'Autopartitioning using LVM failed' in log:
                raise RuntimeError('installer reported an LVM setup failure')
            if not valid and b'Installer disk identity gate refused.' in log:
                if args.commit_gate and b'PXE_PARTMAN_FIXTURE_CORRUPTED_IDENTITY' not in log:
                    raise RuntimeError('identity was refused before the commit fixture changed it')
                if args.fixed_size_experiment and b'PXE_FIXED_SIZE_EXPERIMENT_APPLIED' not in log:
                    raise RuntimeError('disposable fixed-size experiment did not run')
                if success_marker in log:
                    raise RuntimeError('partitioner continued after an identity mismatch')
                print('Real partman invoked and refused the mismatched identity', flush=True)
                break
            if success_marker in log and not valid:
                raise RuntimeError('partitioner passed the identity gate unexpectedly')
            if process.poll() is not None:
                raise RuntimeError('installer exited unexpectedly')
        else:
            raise RuntimeError('installer test timed out')
    except Exception:
        print('Installer log (tail):\n' + log[-12000:].decode('utf-8', errors='replace'), file=sys.stderr)
        print('Fixture requests:', mirror.requests[-50:], file=sys.stderr)
        print('Fixture rejected requests:', mirror.errors[-20:], file=sys.stderr)
        raise
    finally:
        selector.close()
        live_log.close()
        if process.poll() is None:
            process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
    if args.disposable_write and not valid:
        # Compare logical contents with a fresh blank image: opening a qcow2 for
        # writing may alter its metadata without changing any guest sectors.
        reference = root / 'blank-reference.qcow2'
        subprocess.run(['qemu-img', 'create', '-f', 'qcow2', str(reference), disk_size],
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        comparison = subprocess.run(['qemu-img', 'compare', '-q', str(disk), str(reference)],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=False)
        if args.fixture_lvm:
            observed = re.search(rb'PXE_LVM_BEFORE_COMMIT_FIRST_MIB_SHA256=([a-f0-9]{64})', log)
            if observed is None:
                raise RuntimeError('LVM pre-commit disk observation is missing')
            blank = hashlib.sha256(bytes(1024 * 1024)).hexdigest().encode('ascii')
            if observed.group(1) != blank:
                print('LVM fixture had already changed the first MiB before the final identity change', flush=True)
                raise RuntimeError('LVM changed the first MiB before the commit identity check')
        if comparison.returncode != 0:
            raise RuntimeError('refused writable fixture disk differs from a blank disk')
        print('Refused writable fixture disk has unchanged logical contents', flush=True)
    else:
        with disk.open('rb') as stream:
            changed = hashlib.file_digest(stream, 'sha256').hexdigest() != digest
            if changed != args.disposable_write:
                if args.disposable_write:
                    raise AssertionError('writable disposable disk did not change')
                raise AssertionError('read-only virtual disk changed')
    if args.two_disks or args.cross_target:
        with other_disk.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != other_digest:
                raise AssertionError('other read-only virtual disk changed')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('kernel', 'initrd', 'sums', 'release', 'keyring', 'index', 'deb_dir', 'disk_index', 'disk_modules'):
        parser.add_argument('--' + name.replace('_', '-'), type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=600)
    parser.add_argument('--guest-memory-mib', type=int, default=1024, help='RAM assigned to the disposable installer guest')
    parser.add_argument('--commit-gate', action='store_true', help='exercise the later partman commit hook')
    parser.add_argument('--cross-target', action='store_true', help='test refusal of a change on the other disk')
    parser.add_argument('--two-disks', action='store_true', help='attach an unchanged second read-only disk')
    parser.add_argument('--positive-only', action='store_true', help='run only the matching-identity case')
    parser.add_argument('--recipe-fixture', action='store_true', help='test a generated standard recipe before writing')
    parser.add_argument('--disposable-write', action='store_true', help='allow writing only the newly created fixture disk')
    parser.add_argument('--fixed-size-experiment', action='store_true', help='try a test-only partman change in the disposable installer')
    parser.add_argument('--fixture-fill', action='store_true', help='use the remaining-space variant of the synthetic recipe')
    parser.add_argument('--fixture-mismatch-write', action='store_true', help='verify a writable fixture stays blank after identity refusal')
    parser.add_argument('--fixture-lvm', action='store_true', help='observe pre-commit writes for a synthetic LVM identity refusal')
    parser.add_argument('--uefi', action='store_true', help='use the UEFI test firmware without Secure Boot')
    parser.add_argument('--ovmf-deb', type=Path, help='signed-index-verified Debian OVMF package')
    parser.add_argument('--debug-scope', action='store_true', help='trace the scope hook to the disposable serial console')
    args = parser.parse_args()
    if not 60 <= args.timeout <= 1800:
        parser.error('timeout must be between 60 and 1800 seconds')
    if not 256 <= args.guest_memory_mib <= 1024:
        parser.error('guest memory must be between 256 and 1024 MiB')
    if args.cross_target and not args.commit_gate:
        parser.error('--cross-target requires --commit-gate')
    if args.two_disks and not args.commit_gate:
        parser.error('--two-disks requires --commit-gate')
    if args.positive_only and (not args.commit_gate or args.cross_target):
        parser.error('--positive-only requires --commit-gate without --cross-target')
    if args.recipe_fixture and (not args.commit_gate or not args.positive_only or args.two_disks or args.cross_target):
        parser.error('--recipe-fixture requires --commit-gate --positive-only and one disk')
    if args.disposable_write and not args.recipe_fixture:
        parser.error('--disposable-write requires --recipe-fixture')
    if args.fixed_size_experiment and not args.disposable_write:
        parser.error('--fixed-size-experiment requires --disposable-write')
    if args.fixture_fill and not args.fixed_size_experiment:
        parser.error('--fixture-fill requires --fixed-size-experiment')
    if args.fixture_mismatch_write and (not args.fixed_size_experiment or args.fixture_fill):
        parser.error('--fixture-mismatch-write requires --fixed-size-experiment without --fixture-fill')
    if args.fixture_lvm and (not args.fixture_mismatch_write or args.uefi):
        parser.error('--fixture-lvm requires the BIOS writable identity-refusal fixture')
    if args.uefi != (args.ovmf_deb is not None):
        parser.error('--uefi and --ovmf-deb must be supplied together')
    if args.uefi and (not args.fixed_size_experiment or args.fixture_mismatch_write or args.fixture_fill):
        parser.error('--uefi requires the fixed-size experiment and the standard positive fixture')
    args.recipe_segments = None
    if args.recipe_fixture:
        if not shutil.which('php'):
            parser.error('php is required for --recipe-fixture')
        fixture_command = ['php', str(ROOT / 'tests/integration/render_partman_fixture.php')]
        if args.fixture_fill:
            fixture_command.append('--fill')
        if args.uefi:
            fixture_command.append('--uefi')
        if args.fixture_lvm:
            fixture_command.append('--lvm')
        generated = subprocess.run(fixture_command,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
        if not generated or len(generated) > 8192 or b'\n' in generated or b'\r' in generated:
            raise ValueError('invalid generated fixture recipe')
        args.recipe_segments = generated.decode('ascii')
    for tool in ('qemu-system-x86_64', 'qemu-img', 'gpgv', 'dpkg-deb', 'cpio'):
        if not shutil.which(tool):
            parser.error(tool + ' is required')
    with tempfile.TemporaryDirectory(prefix='pxe-vm-', dir='/tmp') as directory:
        root = Path(directory)
        verify_debian_netboot(args.kernel, args.initrd, args.sums, args.release, args.keyring)
        verify_packages(args.release.read_bytes(), args.index.read_bytes(), args.deb_dir)
        verify_disk_module(args.release.read_bytes(), args.disk_index.read_bytes(), args.initrd, args.disk_modules)
        if args.uefi:
            if args.ovmf_deb.stat().st_size > 32 * 1024 * 1024:
                raise ValueError('firmware package is too large')
            package_checks(args.release.read_bytes(), args.index.read_bytes(), {'ovmf': args.ovmf_deb})
            firmware = root / 'firmware'
            firmware.mkdir()
            subprocess.run(['dpkg-deb', '-x', str(args.ovmf_deb), str(firmware)],
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
            args.firmware_code = firmware / 'usr/share/OVMF/OVMF_CODE_4M.fd'
            args.firmware_vars = firmware / 'usr/share/OVMF/OVMF_VARS_4M.fd'
            if not args.firmware_code.is_file() or not args.firmware_vars.is_file():
                raise ValueError('required UEFI firmware files missing')
        busybox_root = root / 'busybox'
        busybox_root.mkdir()
        subprocess.run(['dpkg-deb', '-x', str(args.deb_dir / 'busybox-static.deb'), str(busybox_root)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        # Test-only stop hook proves that the genuine partitioner got past our
        # initialization gate without reaching its partitioning code.
        tree = root / 'fixture'
        collector = tree / 'usr/lib/pxe-tools/collect.sh'
        collector.parent.mkdir(parents=True)
        shutil.copyfile(ROOT / 'app/discovery/collect.sh', collector)
        collector.chmod(0o755)
        hook = tree / 'usr/lib/partman/init.d/00z-pxe-test-stop'
        hook.parent.mkdir(parents=True)
        hook.write_text('#!/bin/sh\n'
                        '/bin/sh /usr/lib/pxe-tools/collect.sh --interface eth0 > /run/pxe-fixture-inventory.json || '
                        '{ printf "PXE_DISCOVERY_FIXTURE_FAILED\\n" >/dev/ttyS0; exit 1; }\n'
                        'printf "PXE_DISCOVERY_FIXTURE: " >/dev/ttyS0\n'
                        'cat /run/pxe-fixture-inventory.json >/dev/ttyS0\n'
                        'printf "PXE_PARTMAN_AFTER_IDENTITY_GATE\\n" >/dev/ttyS0\nexit '
                        + ('0\n' if args.commit_gate else '1\n'), encoding='ascii')
        hook.chmod(0o755)
        if args.fixed_size_experiment:
            experiment = tree / 'usr/lib/pxe-tools/test/partman-fixed-size.sh'
            experiment.parent.mkdir(parents=True)
            shutil.copyfile(ROOT / 'tests/integration/partman_fixed_size_experiment.sh', experiment)
            experiment.chmod(0o555)
            patch_hook = tree / 'usr/lib/partman/init.d/00y-pxe-fixed-size-experiment'
            patch_hook.write_text('#!/bin/sh\n'
                '/bin/sh /usr/lib/pxe-tools/test/partman-fixed-size.sh /lib/partman/lib/auto-shared.sh /run/pxe-fixed-size-experiment.sha256 >/dev/ttyS0 2>&1 || '
                '{ printf "PXE_FIXED_SIZE_EXPERIMENT_FAILED\\n" >/dev/ttyS0; exit 1; }\n', encoding='ascii')
            patch_hook.chmod(0o755)
        if args.commit_gate:
            commit_dir = tree / 'usr/lib/partman/commit.d'
            commit_dir.mkdir(parents=True)
            # Partman's frontend can swallow child stderr. Route only the test
            # copy's diagnostics to serial, preserving the gate's logic.
            trace = commit_dir / '01-pxe-scope'
            content = (ROOT / 'app/install/partman_scope_gate.sh').read_text(encoding='ascii')
            flags = 'set -ex\n' if args.debug_scope else 'set -e\n'
            trace.write_text(content.replace('set -e\n', 'exec 2>/dev/ttyS0\n' + flags, 1), encoding='ascii')
            trace.chmod(0o500)
            if args.fixture_lvm:
                identity = commit_dir / '00-pxe-identity'
                content = (ROOT / 'app/install/partman_identity_gate.sh').read_text(encoding='ascii')
                identity.write_text(content.replace('set -eu\n', 'exec 2>/dev/ttyS0\nset -eu\n', 1), encoding='ascii')
                identity.chmod(0o500)
            before = commit_dir / '00-aaa-pxe-fixture'
            before.write_text('#!/bin/sh\n'
                              'if [ -f /run/pxe-fixture-mismatch-at-commit ]; then\n'
                              '  if [ -f /run/pxe-fixture-lvm ]; then\n'
                              '    if ! /sbin/lvm vgs --noheadings vg0 >/dev/null 2>&1; then\n'
                              '      printf "PXE_LVM_EARLY_COMMIT_BEFORE_VG\\n" >/dev/ttyS0\n'
                              '      exit 0\n'
                              '    fi\n'
                              '    bb=/usr/lib/pxe-tools/busybox\n'
                              '    "$bb" dd if=/dev/vda bs=1048576 count=1 2>/dev/null | "$bb" sha256sum > /run/pxe-lvm-first-mib\n'
                              '    read -r digest ignored < /run/pxe-lvm-first-mib\n'
                              '    printf "PXE_LVM_BEFORE_COMMIT_FIRST_MIB_SHA256=%s\\n" "$digest" >/dev/ttyS0\n'
                              '  fi\n'
                              '  printf "%s\\n" /dev/vda PXE-TEST-WRONG > /run/pxe-tools/disk-identity\n'
                              '  chmod 600 /run/pxe-tools/disk-identity\n'
                              '  printf "PXE_PARTMAN_FIXTURE_CORRUPTED_IDENTITY\\n" >/dev/ttyS0\n'
                              'fi\n'
                              'if [ -f /run/pxe-fixture-other-disk ]; then\n'
                              '  . /lib/partman/lib/base.sh\n'
                              '  for dev in "$DEVICES"/*; do\n'
                              '    [ -d "$dev" ] && [ -f "$dev/device" ] || continue\n'
                              '    [ "$(cat "$dev/device")" = /dev/vdb ] || continue\n'
                              '    cd "$dev" || exit 1\n'
                              '    open_dialog IS_CHANGED\n'
                              '    read_line changed\n'
                              '    close_dialog\n'
                              '    printf "PXE_PARTMAN_OTHER_DISK_CHANGED=%s\\n" "$changed" >/dev/ttyS0\n'
                              '  done\n'
                              'fi\nexit 0\n', encoding='ascii')
            before.chmod(0o755)
            # Sort after 01-pxe-scope and before the regular numbered hooks.
            after = commit_dir / '01z-pxe-test-stop'
            inspect = ('for mount in /var/lib/partman/devices/*/*/mountpoint; do\n'
                       '  [ -f "$mount" ] || continue\n'
                       '  printf "PXE_PARTMAN_RECIPE_MOUNT=%s\\n" "$(cat "$mount")" >/dev/ttyS0\n'
                       'done\n') if args.recipe_fixture else ''
            after.write_text('#!/bin/sh\n' + inspect + 'printf "PXE_PARTMAN_COMMIT_AFTER_IDENTITY_GATE\\n" >/dev/ttyS0\nexit '
                             + ('0\n' if args.disposable_write else '1\n'),
                             encoding='ascii')
            after.chmod(0o755)
            if args.disposable_write:
                finish_dir = tree / 'usr/lib/partman/finish.d'
                finish_dir.mkdir(parents=True)
                finish = finish_dir / '00-pxe-disposable-write-stop'
                finish.write_text('#!/bin/sh\n'
                    'for disk in ' + ('vda1 vda2 vda3' if args.uefi else 'vda1 vda2') + '; do\n'
                    '  size=$(cat "/sys/class/block/$disk/size") || exit 1\n'
                    '  fs=$(/sbin/blkid -p -s TYPE -o value "/dev/$disk") || exit 1\n'
                    '  printf "PXE_PARTMAN_WRITE_PART=%s,%s,%s\\n" "$disk" "$size" "$fs" >/dev/ttyS0\n'
                    'done\n'
                    'printf "PXE_PARTMAN_DISPOSABLE_WRITE_COMPLETE\\n" >/dev/ttyS0\nexit 1\n', encoding='ascii')
                finish.chmod(0o755)
        fixture_overlay = root / 'fixture.gz'
        archive_tree(tree, fixture_overlay)
        combined_image = root / 'installer-initrd.gz'
        with combined_image.open('wb') as output:
            with args.initrd.open('rb') as original:
                shutil.copyfileobj(original, output)
            output.write(build_identity_overlay(busybox_root / 'usr/bin/busybox'))
            with fixture_overlay.open('rb') as fixture:
                shutil.copyfileobj(fixture, output)
        mirror_root = root / 'mirror-cache'
        mirror_root.mkdir(mode=0o700)
        mirror = FixtureMirror(mirror_root, args.release, args.keyring, preseed('PXE-TEST-WRONG'))
        mirror.start(root / 'mirror.sock')
        try:
            combined = args.cross_target and args.two_disks
            if args.fixture_mismatch_write:
                run_installer(args, root, combined_image, mirror, valid=False)
            elif combined:
                run_installer(args, root, combined_image, mirror, valid=True)
                args.cross_target = False
            elif not args.cross_target and not args.positive_only:
                run_installer(args, root, combined_image, mirror, valid=False)
            if not args.fixture_mismatch_write:
                run_installer(args, root, combined_image, mirror, valid=True)
        finally:
            mirror.close()
    print('Real Debian Installer: identity refusal preserved the blank writable disk' if args.fixture_mismatch_write else
          'Real Debian Installer: other-disk refusal and unchanged second disk verified' if combined else
          'Real Debian Installer: other-disk scope refusal verified' if args.cross_target else
          'Real Debian Installer: matching identity and unchanged second disk verified' if args.positive_only and args.two_disks else
          'Real Debian Installer: matching identity reached the test stop' if args.positive_only else
          'Real Debian Installer: identity refusal and accepted identity before a test-only stop verified',
          '(commit hooks)' if args.commit_gate else '(initialization hook)')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
