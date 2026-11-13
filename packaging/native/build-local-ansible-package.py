#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build the optional native Ansible package for local testing or release."""
import argparse
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
CONTROL = '''Package: {package_name}
Version: {version}
Architecture: all
Maintainer: {maintainer}
Depends: {base_package} (>= {minimum_base}), {base_package} (<< {maximum_base}), ansible-core, openssh-client, passwd, systemd
Section: admin
Priority: optional
Description: optional Ansible integration for PXE Tools
 Uses the distribution Ansible Core package through the existing worker.
 Does not enable, start or restart a service; instance data is preserved.
'''
PREINST = '''#!/bin/sh
set -eu
if [ "$1" = install ] && { [ -e /usr/lib/systemd/system/pxe-tools-worker.service.d/50-ansible.conf ] || [ -L /usr/lib/systemd/system/pxe-tools-worker.service.d/50-ansible.conf ]; }; then
    echo 'Existing worker configuration preserved; installation refused.' >&2; exit 1
fi
if [ "$1" = upgrade ] && [ -d /run/systemd/system ] && systemctl is-active --quiet pxe-tools-worker.service; then
    echo 'Stop the PXE Tools worker before upgrading the optional module.' >&2; exit 1
fi
'''
POSTINST = '''#!/bin/sh
set -eu
if [ "$1" = configure ]; then
    account=pxe-tools-ansible
    home=/var/lib/pxe-tools-ansible/home
    if getent passwd "$account" >/dev/null; then
        entry=$(getent passwd "$account")
        [ "$(printf '%s' "$entry" | cut -d: -f6)" = "$home" ] && [ "$(printf '%s' "$entry" | cut -d: -f7)" = /usr/sbin/nologin ] || {
            echo 'Existing Ansible account differs; it was preserved.' >&2; exit 1; }
    else
        useradd --system --user-group --no-create-home --home-dir "$home" --shell /usr/sbin/nologin "$account"
    fi
    uid=$(id -u "$account"); gid=$(id -g "$account")
    [ "$uid" != 0 ] && [ "$gid" != 0 ] || { echo 'Ansible account must not be privileged.' >&2; exit 1; }
    for path in /var/lib/pxe-tools-ansible "$home" /var/lib/pxe-tools-ansible/workspace; do
        if [ -L "$path" ] || { [ -e "$path" ] && [ ! -d "$path" ]; }; then
            echo 'Existing Ansible path is not a regular directory; it was preserved.' >&2; exit 1
        fi
        if [ -d "$path" ]; then
            mode=$(stat -c %a "$path")
            [ "$(stat -c %u "$path")" = "$uid" ] && [ "$(stat -c %g "$path")" = "$gid" ] && [ $((0$mode & 07077)) -eq 0 ] && [ $((0$mode & 0700)) -eq 448 ] || {
                echo 'Existing Ansible directory ownership or permissions differ; it was preserved.' >&2; exit 1; }
        else
            install -d -o "$uid" -g "$gid" -m 0700 "$path"
        fi
    done
    if [ -d /run/systemd/system ]; then systemctl daemon-reload; fi
fi
'''
PRERM = '''#!/bin/sh
set -eu
if [ "$1" = remove ] && [ -d /run/systemd/system ] && systemctl is-active --quiet pxe-tools-worker.service; then
    echo 'Stop the PXE Tools worker before removing the optional module.' >&2; exit 1
fi
'''
POSTRM = '''#!/bin/sh
set -eu
if [ -d /run/systemd/system ]; then systemctl daemon-reload || true; fi
if [ "$1" = purge ]; then echo 'Ansible workspace, home and account were kept.' >&2; fi
'''


def build(destination: Path, version: str = '0.0.1~local1', *, package_name: str = 'pxe-tools-ansible-local-test') -> None:
    if package_name not in ('pxe-tools-ansible-local-test', 'pxe-tools-ansible'):
        raise ValueError('Unsupported package name')
    production = package_name == 'pxe-tools-ansible'
    base_package = 'pxe-tools' if production else 'pxe-tools-local-test'
    minimum_base, maximum_base = ('0.1.0~beta1', '0.2~') if production else ('0.0.1~local2', '0.1~')
    maintainer = 'PXE Tools <security@pxetools.com>' if production else 'PXE Tools local test <maintainers@example.invalid>'
    if not destination.is_absolute() or not destination.parent.is_dir():
        raise ValueError('Use a new absolute path in an existing directory')
    if destination.exists() or destination.is_symlink():
        raise FileExistsError('Refusing to overwrite an existing package')
    if not re.fullmatch(r'[0-9][A-Za-z0-9.+~\-]{0,40}', version):
        raise ValueError('Invalid package version')
    dropin=ROOT/'packaging/native/ansible-worker.conf'
    if dropin.is_symlink() or not dropin.is_file(): raise ValueError('Unsupported source file')
    with tempfile.TemporaryDirectory(prefix='pxe-native-ansible-package-') as temporary:
        root=Path(temporary)/'root'; control=root/'DEBIAN'; control.mkdir(parents=True)
        (control/'control').write_text(CONTROL.format(version=version, package_name=package_name, maintainer=maintainer, base_package=base_package, minimum_base=minimum_base, maximum_base=maximum_base))
        for name,text in [('preinst',PREINST),('postinst',POSTINST),('prerm',PRERM),('postrm',POSTRM)]:
            path=control/name; path.write_text(text); path.chmod(0o755)
        target=root/'usr/lib/systemd/system/pxe-tools-worker.service.d/50-ansible.conf'
        target.parent.mkdir(parents=True); shutil.copyfile(dropin,target)
        doc = root / 'usr/share/doc' / package_name
        doc.mkdir(parents=True)
        (doc / 'copyright').write_text('Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/\n'
                                       'Upstream-Name: PXE Tools\n'
                                       'Source: https://github.com/williamboglietti/pxe-tools\n\n'
                                       'Files: *\nCopyright: PXE Tools contributors\nLicense: LGPL-3.0-only\n'
                                       ' On Debian systems, see /usr/share/common-licenses/LGPL-3.\n')
        archive=Path(temporary)/'package.deb'
        subprocess.run(['dpkg-deb','--build','--root-owner-group',str(root),str(archive)],check=True,stdout=subprocess.DEVNULL)
        with destination.open('xb') as output, archive.open('rb') as source: shutil.copyfileobj(source,output)


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination',type=Path)
    parser.add_argument('--version',default='0.0.1~local1')
    parser.add_argument('--release', action='store_true', help='build the pxe-tools-ansible release package')
    args=parser.parse_args(); build(args.destination,args.version,package_name='pxe-tools-ansible' if args.release else 'pxe-tools-ansible-local-test')
    print('Optional package built:',args.destination)
