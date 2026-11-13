#!/usr/bin/env python3
"""Refuse unsafe staged HTTP/TFTP assets before a native restoration."""
import argparse
import os
from pathlib import Path
import stat
import sys


def check(path):
    path = Path(path)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError(f'chemin absolu sans .. requis : {path}')

    def verify(p, directory=False):
        info = p.lstat()
        if info.st_uid != 0 or info.st_mode & 0o7022:
            raise ValueError(f'propriétaire ou permissions non sûrs : {p}')
        if directory and not stat.S_ISDIR(info.st_mode):
            raise ValueError(f'répertoire réel requis : {p}')
        if not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
            raise ValueError(f'type de fichier interdit : {p}')
        if stat.S_ISREG(info.st_mode) and info.st_nlink != 1:
            raise ValueError(f'lien physique interdit : {p}')

    for ancestor in reversed(path.parents):
        verify(ancestor, directory=True)
    verify(path, directory=True)
    for root, dirs, files in os.walk(path, followlinks=False, onerror=lambda e: (_ for _ in ()).throw(e)):
        for name in dirs + files:
            verify(Path(root) / name)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('trees', nargs='+', help='arbres restaurés HTTP/TFTP à contrôler')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('exécuter en root')
    try:
        for tree in args.trees:
            check(tree)
    except (OSError, ValueError) as exc:
        print(f'Contrôle refusé : {exc}', file=sys.stderr)
        return 1
    print('Arbres contrôlés ; vérifiez aussi leur contenu avant toute activation.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
