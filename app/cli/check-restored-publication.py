#!/usr/bin/env python3
"""Refuse unsafe staged HTTP/TFTP assets before a native restoration."""
import argparse
import os
from pathlib import Path
import stat
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from backup.assets import netboot_alias_target
from backup.openpgp import BackupError


def check(path, *, tftp=False):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError(f'chemin absolu sans .. requis : {path}')

    aliases = {}
    kinds = {}

    def verify(p, directory=False, staged=False):
        info = p.lstat()
        if staged and stat.S_ISLNK(info.st_mode):
            if tftp or info.st_uid != 0:
                raise ValueError(f'lien interdit : {p}')
            name = 'http/' + str(p.relative_to(path))
            aliases[name] = os.readlink(p)
            return
        if staged:
            name = ('tftp' if tftp else 'http') + ('/' + str(p.relative_to(path)) if p != path else '')
            kinds[name] = 'dir' if stat.S_ISDIR(info.st_mode) else 'file'
        menu = staged and tftp and p == path / 'pxelinux.cfg'
        lock = staged and tftp and p == path / '.pxe-menu.lock'
        permitted_group_write = menu or lock
        forbidden_mode = 0o7002 | (0 if permitted_group_write else 0o020)
        if info.st_uid != 0 or info.st_mode & forbidden_mode or (permitted_group_write and info.st_gid != 0):
            raise ValueError(f'propriétaire ou permissions non sûrs : {p}')
        if directory and not stat.S_ISDIR(info.st_mode):
            raise ValueError(f'répertoire réel requis : {p}')
        if not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
            raise ValueError(f'type de fichier interdit : {p}')
        if menu and not stat.S_ISDIR(info.st_mode):
            raise ValueError(f'répertoire de menus requis : {p}')
        if lock and not stat.S_ISREG(info.st_mode):
            raise ValueError(f'fichier de verrou requis : {p}')
        if staged and not lock:
            required = 0o005 if stat.S_ISDIR(info.st_mode) else 0o004
            if info.st_mode & required != required:
                raise ValueError(f'élément non lisible par les services : {p}')
        if stat.S_ISREG(info.st_mode) and info.st_nlink != 1:
            raise ValueError(f'lien physique interdit : {p}')

    for ancestor in reversed(path.parents):
        verify(ancestor, directory=True)
    verify(path, directory=True, staged=True)
    for root, dirs, files in os.walk(path, followlinks=False, onerror=lambda e: (_ for _ in ()).throw(e)):
        for name in dirs + files:
            verify(Path(root) / name, staged=True)
    for name, target in aliases.items():
        try:
            netboot_alias_target(name, target, kinds)
        except BackupError as exc:
            raise ValueError(f'alias netboot invalide : {name}') from exc


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--http', help='arbre HTTP restauré à contrôler')
    parser.add_argument('--tftp', help='arbre TFTP restauré à contrôler')
    args = parser.parse_args()
    if not (args.http or args.tftp):
        parser.error('indiquer --http et/ou --tftp')
    if os.geteuid() != 0:
        parser.error('exécuter en root')
    try:
        if args.http:
            check(args.http)
        if args.tftp:
            check(args.tftp, tftp=True)
    except (OSError, ValueError, BackupError) as exc:
        print(f'Contrôle refusé : {exc}', file=sys.stderr)
        return 1
    print('Arbres contrôlés ; vérifiez aussi leur contenu avant toute activation.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
