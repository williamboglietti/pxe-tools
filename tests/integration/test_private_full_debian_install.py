#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Install and boot Debian in a disposable, network-restricted QEMU guest.

This uses the application's rendered preseed and a fixture-only signed Debian
mirror. A single fresh qcow2 is the only guest disk. The test adds a serial
console to the installed guest solely to observe its first boot.
"""
import argparse
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
# The renderer normally runs in the appliance at /app; this bench executes on
# a disposable VM and must resolve the application from the checkout instead.
os.environ.setdefault('PXE_APP_DIR', str(ROOT / 'app'))
# Verification and QEMU bench helpers kept with the tests (LGPL-3.0, reviewed).
PROTOTYPE = ROOT / 'tests/support/prototype'
sys.path.insert(0, str(ROOT / 'tests/unit'))
sys.path.insert(0, str(PROTOTYPE / 'tests/integration'))
sys.path.insert(0, str(PROTOTYPE / 'app'))
import test_preseed_install_invariants as invariants
from full_install_mirror import FullInstallMirror
from run_qemu_dnsmasq_from_debian import prepare_inputs
from artifacts.debian_netboot import verify_debian_netboot
from debian_disk_modules import verify_disk_module
from test_qemu_dnsmasq_tftp import verify_packages


def rendered_preseed() -> bytes:
    template = (ROOT/'docker/worker/templates/preseed-base.cfg.j2').read_text()
    template = re.sub(r"\{\{ '(\{\{[A-Z_]+\}\})' \}\}", lambda match: match[1], template)
    password = subprocess.check_output(['openssl','passwd','-6','-salt','pxefixture','fixture-password'], text=True).strip()
    config = {'distro':'debian', 'codename':'trixie', 'hostname':'pxe-fixture', 'domain':'example.test',
              'partition_mode':'default', 'network_mode':'dhcp', 'root_login_mode':'disabled',
              'system_disk_path':'/dev/vda', 'system_disk_identity':{'kind':'serial','value':'PXE-TEST-ONE','status':'valid'},
              'admin_user_login':'fixture', 'admin_user_pwhash':password,
              'advanced': {'localization':{'locale':'en_US.UTF-8','keymap':'us'},
                           'mirror':{'country':'manual','hostname':'10.0.2.100','directory':'/debian'},
                           'timezone':{'utc':True,'zone':'UTC','ntp':False},
                           'packages':{'tasksel':['ssh-server'],'pkgsel_include':['sudo','openssh-server','curl'],
                                       'upgrade':'none','install_recommends':False},
                           'apt':{'non_free_firmware':False,'contrib':False,'non_free':False,
                                  'services_select':[],'hw_detect_load_firmware':False}}}
    result = invariants.PreseedInvariants().render(config, template=template)
    late = re.search(r'(?m)^d-i preseed/late_command string \\\n(?:[^\n]*\\\n)*[^\n]*', result)
    if late is None or result.count('d-i preseed/late_command string') != 1:
        raise RuntimeError('expected one application late command')
    serial_console = (" ; in-target /bin/sh -c 'printf \"\\nGRUB_CMDLINE_LINUX=\\\"console=tty0 console=ttyS0,115200n8\\\"\\n\" "
                      ">> /etc/default/grub; update-grub' ; printf 'PXE_FULL_LATE_DONE\\n' >/dev/ttyS0")
    result = result[:late.end()] + serial_console + result[late.end():]
    result += '\nd-i mirror/udeb/suite string trixie\n'
    return result.encode()


def observe(command: list[str], wanted: bytes, timeout: int, *, stop_on_match: bool) -> None:
    proc = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    selector = selectors.DefaultSelector()
    log = bytearray()
    seen = False
    try:
        selector.register(proc.stdout, selectors.EVENT_READ)
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            for key, _ in selector.select(timeout=1):
                data = os.read(key.fileobj.fileno(), 65536)
                if data:
                    log.extend(data)
                    if len(log) > 32 * 1024 * 1024:
                        raise RuntimeError('guest log exceeded limit')
            if re.search(wanted, log):
                seen = True
                if stop_on_match: break
            if proc.poll() is not None:
                if not seen or proc.returncode != 0:
                    raise RuntimeError('guest exited before expected milestone')
                break
        else:
            raise RuntimeError('guest milestone timed out')
    except Exception:
        tail = log[-5000:].decode('utf-8','replace')
        tail = re.sub(r'[A-Za-z0-9+/=]{200,}', '[encoded fixture]', tail)
        tail = re.sub(r'\$6\$[^\s]+', '[fixture password hash]', tail)
        print('Guest serial tail:\n' + tail, file=sys.stderr)
        raise
    finally:
        selector.close()
        if proc.poll() is None: proc.terminate()
        try: proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill(); proc.wait()
        if proc.stdout: proc.stdout.close()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--keyring', type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=2400)
    args = parser.parse_args()
    if not 600 <= args.timeout <= 3600:
        parser.error('invalid test timeout')
    with tempfile.TemporaryDirectory(prefix='pxe-private-install-') as directory:
        root = Path(directory)
        (root/'debs').mkdir()
        parameters = SimpleNamespace(keyring=args.keyring, runtime_manager=False, disk_guard=True,
                                     proxy_boot=False, uefi=False, partman_uefi=False,
                                     backup_guest=False, discovery_guest=False, discovery_pxe=False)
        prepare_inputs(parameters, root)
        verify_debian_netboot(root/'linux', root/'initrd.gz', root/'SHA256SUMS', root/'InRelease', args.keyring)
        verify_packages((root/'InRelease').read_bytes(), (root/'Packages.xz').read_bytes(), root/'debs')
        verify_disk_module((root/'InRelease').read_bytes(), (root/'installer-Packages.xz').read_bytes(),
                           root/'initrd.gz', root/'disk-modules.udeb')
        disk = root/'fixture.qcow2'
        subprocess.run(['qemu-img','create','-f','qcow2',str(disk),'12G'], check=True, stdout=subprocess.DEVNULL)
        cache = root/'mirror-cache'; cache.mkdir(mode=0o700)
        mirror = FullInstallMirror(cache, root/'InRelease', args.keyring, rendered_preseed(), root/'Packages.xz')
        mirror.start(root/'mirror.sock')
        try:
            forward = ' '.join(shlex.quote(x) for x in (sys.executable,'-u',
                               str(PROTOTYPE/'tests/integration/qemu_unix_http_forward.py'),str(root/'mirror.sock')))
            common = ['qemu-system-x86_64','-machine','pc,accel=tcg','-smp','1','-display','none',
                      '-serial','stdio','-monitor','none','-no-reboot','-m','1024',
                      '-drive','file='+str(disk)+',if=none,id=disk,format=qcow2',
                      '-device','virtio-blk-pci,drive=disk,serial=PXE-TEST-ONE']
            install = common + ['-kernel',str(root/'linux'),'-initrd',str(root/'initrd.gz'),
                       '-append','console=ttyS0,115200n8 net.ifnames=0 auto=true priority=critical netcfg/get_nameservers=10.0.2.3 url=http://10.0.2.100/preseed.cfg',
                       '-netdev','user,id=lan,restrict=on,guestfwd=tcp:10.0.2.100:80-cmd:'+forward.replace(',',',,'),
                       '-device','e1000,netdev=lan']
            print('Starting disposable installer using application preseed', flush=True)
            # Debian Installer's terminal may place varying cursor-control
            # sequences immediately before the marker on the same line.
            observe(install, rb'(?m)PXE_FULL_LATE_DONE\r?$', args.timeout, stop_on_match=False)
            print('Installer reached late-command marker and rebooted', flush=True)
            boot = common + ['-net','none']
            observe(boot, rb'(?m)^pxe-fixture login:', 600, stop_on_match=True)
            print('Installed disposable Debian reached its local login prompt', flush=True)
        except Exception:
            # An opt-in diagnostic copy is retained only in the test control
            # directory, allowing a failed first boot to be examined without
            # rerunning the entire installer. It must be removed after review.
            failure_disk = os.environ.get('PXE_FAILURE_DISK')
            if failure_disk:
                destination = Path(failure_disk)
                if destination.exists():
                    print('Failure disk already exists; not overwriting', file=sys.stderr)
                else:
                    copy = subprocess.run(['qemu-img', 'convert', '-f', 'qcow2', '-O', 'qcow2',
                                           str(disk), str(destination)], check=False)
                    print('Diagnostic disk saved' if copy.returncode == 0 else 'Diagnostic disk copy failed',
                          file=sys.stderr, flush=True)
            raise
        finally:
            mirror.close()


if __name__ == '__main__':
    main()
