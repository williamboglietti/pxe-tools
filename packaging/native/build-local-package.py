#!/usr/bin/env python3
"""Build the native PXE Tools package without publishing it.

The local-test package is for isolated fixtures. A fresh installation starts
administration but keeps PXE inactive until the network is confirmed; removal
and purge preserve instance data.
"""
import argparse
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
CONTROL = '''Package: {package_name}
Version: {version}
Architecture: amd64
Maintainer: {maintainer}
Depends: adduser, ca-certificates, cpio, curl, debian-archive-keyring, dnsmasq-base, gettext-base, git, gpgv, grub-efi-amd64-bin, grub-efi-amd64-signed, iproute2, ipxe, mokutil, nginx, openssh-client, openssl, php-cli | php8.4-cli | php8.3-cli | php8.2-cli, php-curl | php8.4-curl | php8.3-curl | php8.2-curl, php-fpm | php8.4-fpm | php8.3-fpm | php8.2-fpm, php-sqlite3 | php8.4-sqlite3 | php8.3-sqlite3 | php8.2-sqlite3, pxelinux, python3 (>= 3.11), python3-jinja2, python3-nacl, sbsigntool, shim-signed, sqlite3, sshpass, syslinux-common, syslinux-efi, systemd, util-linux, wget, xz-utils, 7zip | p7zip-full
Section: admin
Priority: optional
Description: {description}
 {details}
 On a fresh system the administration interface starts without enabling PXE.
'''
PREINST = '''#!/bin/sh
set -eu
if [ "$1" = install ]; then
    for path in /opt/pxe-tools /usr/lib/pxe-tools; do
        if [ -e "$path" ] || [ -L "$path" ]; then
            echo "pxe-tools-local-test: existing $path preserved; installation refused" >&2
            exit 1
        fi
    done
fi
if [ "$1" = upgrade ] && [ -d /run/systemd/system ]; then
    for unit in pxe-tools.target pxe-tools-admin.target pxe-tools-worker.service pxe-tools-dnsmasq-tail.service pxe-tools-nginx.service pxe-tools-pxe-http.service pxe-tools-php.service pxe-tools-dnsmasq.service pxe-tools-init.service; do
        if systemctl is-active --quiet "$unit"; then
            echo 'pxe-tools-local-test: stop the PXE Tools services before upgrading' >&2
            exit 1
        fi
    done
fi
'''
PRERM = '''#!/bin/sh
set -eu
if [ "$1" = remove ] && [ -d /run/systemd/system ]; then
    systemctl stop pxe-tools.target pxe-tools-admin.target
    for unit in pxe-tools-worker.service pxe-tools-dnsmasq-tail.service pxe-tools-nginx.service pxe-tools-pxe-http.service pxe-tools-php.service pxe-tools-dnsmasq.service pxe-tools-init.service; do
        if systemctl is-active --quiet "$unit"; then systemctl stop "$unit"; fi
    done
    systemctl disable pxe-tools.target pxe-tools-admin.target >/dev/null 2>&1 || true
fi
'''
POSTINST = '''#!/bin/sh
set -eu
if [ "$1" = configure ] && [ -d /run/systemd/system ]; then
    systemctl daemon-reload
    # Existing instance data may contain queued jobs or an enabled DHCP setup.
    # Leave its startup under the administrator's control.
    if [ -z "${2:-}" ] && [ ! -e /var/lib/pxe-tools ] && [ ! -L /var/lib/pxe-tools ] \
       && [ ! -e /etc/pxe-tools ] && [ ! -L /etc/pxe-tools ]; then
        if ! systemctl enable pxe-tools-admin.target; then
            echo 'PXE Tools: could not enable the administration service.' >&2
        elif ! systemctl start pxe-tools-admin.target; then
            echo 'PXE Tools: administration did not start; inspect systemd status.' >&2
        fi
    fi
fi
'''
POSTRM = '''#!/bin/sh
set -eu
if [ -d /run/systemd/system ]; then systemctl daemon-reload || true; fi
if [ "$1" = purge ]; then echo 'PXE Tools: instance data and configuration were kept.' >&2; fi
'''


def copy_tree(source: Path, destination: Path) -> None:
    for item in [source, *source.rglob('*')]:
        if item.is_symlink() or not (item.is_file() or item.is_dir()):
            raise ValueError('Unsupported source item: ' + str(item.relative_to(ROOT)))
    shutil.copytree(source, destination, ignore=shutil.ignore_patterns('.DS_Store', '__pycache__', '*.pyc'))


def replace_exact(text: str, old: str, new: str) -> str:
    if old not in text:
        raise ValueError('Expected runtime configuration fragment not found')
    return text.replace(old, new)


def build(destination: Path, version: str, *, package_name: str = 'pxe-tools-local-test',
          maintainer: str = 'PXE Tools local test <maintainers@example.invalid>') -> None:
    if package_name not in ('pxe-tools-local-test', 'pxe-tools'):
        raise ValueError('unsupported package name')
    if not re.fullmatch(r'[^<>\r\n]{1,100} <[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+>', maintainer):
        raise ValueError('maintainer must be a single name and email address')
    if not destination.is_absolute() or not destination.parent.is_dir():
        raise ValueError('Use a new absolute path in an existing directory')
    if destination.exists() or destination.is_symlink():
        raise FileExistsError('Refusing to overwrite an existing package')
    if not re.fullmatch(r'[0-9][A-Za-z0-9.+~\-]{0,40}', version):
        raise ValueError('Invalid package version')
    with tempfile.TemporaryDirectory(prefix='pxe-native-package-') as temporary:
        root = Path(temporary)/'root'; control = root/'DEBIAN'; control.mkdir(parents=True)
        description, details = (('PXE network installation management',
                                 'Web administration and PXE services for Debian installations.')
                                if package_name == 'pxe-tools' else
                                ('experimental local test of PXE Tools',
                                 'This package is intended for isolated test environments.'))
        (control/'control').write_text(CONTROL.format(version=version, package_name=package_name,
                                                       maintainer=maintainer, description=description, details=details))
        for name, text in [('preinst',PREINST),('postinst',POSTINST),('prerm',PRERM),('postrm',POSTRM)]:
            p=control/name; p.write_text(text.replace('pxe-tools-local-test', package_name)); p.chmod(0o755)
        copy_tree(ROOT/'app', root/'usr/lib/pxe-tools/app')
        copy_tree(ROOT/'docker/worker/scripts', root/'opt/pxe-tools/scripts')
        copy_tree(ROOT/'docker/worker/templates', root/'opt/pxe-tools/templates')
        copy_tree(ROOT/'docker/pxe-tools/init', root/'opt/pxe-tools/init')
        copy_tree(ROOT/'docker/pxe-tools/static-bin', root/'usr/lib/pxe-tools/static-bin')
        copy_tree(ROOT/'docker/pxe-tools/keyrings', root/'usr/lib/pxe-tools/keyrings')
        # Carry the selected core licence and the currently verified upstream
        # notices. Other components still require provenance and legal review.
        docs=root/'usr/share/doc'/package_name; docs.mkdir(parents=True)
        for name in ('COPYING', 'COPYING.LESSER', 'THIRD-PARTY.md'):
            shutil.copy2(ROOT/name, docs/name)
        copy_tree(ROOT/'third_party/licenses', docs/'licenses')
        native=root/'usr/lib/pxe-tools/native'; native.mkdir(parents=True)
        for name in ('default.env','init.sh','start-php-fpm.sh','php-fpm.conf','nginx.conf','pxe-nginx.conf'):
            shutil.copy2(ROOT/'packaging/native'/name, native/name)
        units=root/'usr/lib/systemd/system'; units.mkdir(parents=True)
        for unit in (sorted((ROOT/'packaging/native').glob('pxe-tools.*'))
                     + sorted((ROOT/'packaging/native').glob('pxe-tools-*.service'))
                     + sorted((ROOT/'packaging/native').glob('pxe-tools-*.target'))):
            shutil.copy2(unit, units/unit.name)
        templates=root/'opt/pxe-tools/nginx'; templates.mkdir(parents=True)
        for name in ('admin-http-serve.conf.tmpl','admin-http-redirect.conf.tmpl','admin-https.conf.tmpl'):
            data=(ROOT/'docker/pxe-tools/nginx'/name).read_text()
            data=data.replace('/app/public','/usr/lib/pxe-tools/app/public')
            data=data.replace('/run/php/php8.4-fpm.sock','/run/pxe-tools-php/fpm.sock')
            data=data.replace('/var/log/nginx/','/var/log/pxe-tools/')
            data=data.replace('include fastcgi_params;', 'include /etc/nginx/fastcgi_params;')
            (templates/name).write_text(data)
        pxe=(ROOT/'docker/pxe-tools/nginx/pxe.conf').read_text()
        pxe=replace_exact(pxe,'/app/public','/usr/lib/pxe-tools/app/public')
        pxe=replace_exact(pxe,'/srv/http','/var/lib/pxe-tools/http')
        pxe=replace_exact(pxe,'/run/php/php8.4-fpm.sock','/run/pxe-tools-php/fpm.sock')
        pxe=pxe.replace('/var/log/nginx/','/var/log/pxe-tools/')
        pxe=replace_exact(pxe,'include fastcgi_params;','include /etc/nginx/fastcgi_params;')
        (native/'pxe.conf').write_text(pxe)
        dns=root/'opt/pxe-tools/dnsmasq'; dns.mkdir(parents=True)
        text=(ROOT/'docker/dnsmasq/pxe.conf.tmpl').read_text()
        text=replace_exact(text,'dhcp-script=/usr/local/bin/dhcp-hook.sh','dhcp-script=/opt/pxe-tools/native/dhcp-hook.sh')
        (dns/'pxe.conf.tmpl').write_text(text)
        helpers=root/'opt/pxe-tools/native'; helpers.mkdir(parents=True)
        for name in ('dhcp-hook.sh','dnsmasq-tail.sh'):
            shutil.copy2(ROOT/'docker/dnsmasq'/name, helpers/name)
        for folder in (root/'opt/pxe-tools/scripts',root/'opt/pxe-tools/init',helpers):
            for path in folder.glob('*.sh'): path.chmod(0o755)
        for path in (root/'usr/lib/pxe-tools/static-bin').iterdir():
            if path.is_file() and path.suffix != '.md': path.chmod(0o755)
        archive=Path(temporary)/'package.deb'
        subprocess.run(['dpkg-deb','--build','--root-owner-group',str(root),str(archive)],check=True,stdout=subprocess.DEVNULL)
        # Exclusive open prevents destroying a previously produced artifact.
        with destination.open('xb') as output, archive.open('rb') as source:
            shutil.copyfileobj(source,output)


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination',type=Path)
    parser.add_argument('--version',default='0.0.1~local2')
    args=parser.parse_args()
    build(args.destination,args.version)
    print('Experimental local package built:',args.destination)
