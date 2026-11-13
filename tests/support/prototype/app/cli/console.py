#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Initialize and run the experimental local console without starting PXE."""
from __future__ import annotations

import argparse
import getpass
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import warnings

APP = Path(__file__).resolve().parents[1]


class ConsoleError(Exception):
    pass


def protected_directory(path: Path, *, create: bool) -> Path:
    if not path.is_absolute() or path.name in ('', '.', '..') or path.is_symlink():
        raise ConsoleError('Un chemin absolu vers un répertoire privé est requis.')
    parent = path.parent.resolve(strict=True)
    parent_info = parent.stat()
    if not stat.S_ISDIR(parent_info.st_mode) or parent_info.st_uid != os.geteuid() or parent_info.st_mode & 0o022:
        raise ConsoleError('Le répertoire parent doit appartenir à cet utilisateur et être protégé en écriture.')
    target = parent / path.name
    if create:
        try:
            target.mkdir(mode=0o700)
        except FileExistsError:
            pass
    info = target.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise ConsoleError('Le répertoire doit appartenir à cet utilisateur et être privé.')
    return target


def protected_database(path: Path) -> None:
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_nlink != 1:
        raise ConsoleError('La base doit être un fichier privé appartenant à cet utilisateur.')


def password_from_fd(fd: int) -> bytes:
    if fd < 3 or os.isatty(fd):
        raise ConsoleError('Le descripteur de mot de passe doit être protégé et distinct du terminal.')
    info = os.fstat(fd)
    anonymous_pipe = stat.S_ISFIFO(info.st_mode) and info.st_nlink == 0
    if (not (stat.S_ISFIFO(info.st_mode) or stat.S_ISREG(info.st_mode))
            or info.st_uid != os.geteuid() or (not anonymous_pipe and info.st_mode & 0o077)):
        raise ConsoleError('Le descripteur de mot de passe doit appartenir à cet utilisateur et être privé.')
    data = bytearray()
    while len(data) <= 74:
        chunk = os.read(fd, 75 - len(data))
        if not chunk:
            break
        data.extend(chunk)
    return validate_password(bytes(data).removesuffix(b'\n').removesuffix(b'\r'))


def validate_password(password: bytes) -> bytes:
    if not 12 <= len(password) <= 72 or any(item in password for item in (b'\0', b'\n', b'\r')):
        raise ConsoleError('Le mot de passe doit contenir de 12 à 72 octets, sans retour à la ligne.')
    return password


def prompt_password() -> bytes:
    if not sys.stdin.isatty():
        raise ConsoleError('Un terminal est requis pour la saisie masquée du mot de passe.')
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        first = getpass.getpass('Mot de passe administrateur : ')
        second = getpass.getpass('Confirmer le mot de passe : ')
    if first != second:
        raise ConsoleError('Les mots de passe ne correspondent pas.')
    return validate_password(first.encode('utf-8'))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='action', required=True)
    init = commands.add_parser('init', help='créer une base et un compte administrateur')
    init.add_argument('--state', type=Path, required=True)
    init.add_argument('--username', required=True)
    init.add_argument('--password-fd', type=int, help='lire le mot de passe depuis un descripteur privé')
    serve = commands.add_parser('serve', help='démarrer la console locale au premier plan')
    serve.add_argument('--state', type=Path, required=True)
    serve.add_argument('--port', type=int, default=8080)
    acquire = commands.add_parser('acquire', help='traiter une demande Debian, sans activer les services PXE')
    acquire.add_argument('--state', type=Path, required=True)
    acquire.add_argument('--keyring', type=Path, required=True)
    acquire.add_argument('--keyring-sha256', required=True)
    acquire.add_argument('--server-ip', required=True)
    worker = commands.add_parser('worker', help='traiter les demandes Debian au fil de l’eau, sans activer les services PXE')
    pxe = commands.add_parser('pxe', help='appliquer la configuration enregistrée aux services PXE')
    for service in (worker, pxe):
        service.add_argument('--state', type=Path, required=True)
        service.add_argument('--keyring', type=Path, required=True)
        service.add_argument('--keyring-sha256', required=True)
    pxe.add_argument('--confirm-activation', action='store_true', help='autoriser le démarrage de services réseau')
    args = parser.parse_args()
    try:
        if os.geteuid() == 0:
            raise ConsoleError('Exécutez la console avec un compte non privilégié.')
        if args.action == 'init' and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', args.username) is None:
            raise ConsoleError('Identifiant administrateur invalide.')
        if args.action == 'serve' and not 1024 <= args.port <= 65535:
            raise ConsoleError('Le port doit être compris entre 1024 et 65535.')
        state = protected_directory(args.state, create=args.action == 'init')
        database = state / 'database.sqlite'
        if args.action == 'init' and (database.exists() or database.is_symlink()):
            raise ConsoleError('Une base existe déjà ; elle ne sera pas remplacée.')
        if args.action == 'init':
            protected_directory(state / 'sessions', create=True)
            password = password_from_fd(args.password_fd) if args.password_fd is not None else prompt_password()
            result = subprocess.run(['php', '-d', 'display_errors=stderr', str(APP / 'cli/init.php'),
                                     str(database), args.username], input=password + b'\n',
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
            if result.returncode:
                raise ConsoleError('Initialisation impossible ; vérifiez le répertoire avant une nouvelle tentative.')
            print('Base et administrateur créés. Aucun service PXE n’a été démarré.')
            return 0
        protected_database(database)
        if args.action in ('acquire', 'worker', 'pxe'):
            if not args.keyring.is_absolute() or re.fullmatch(r'[a-fA-F0-9]{64}', args.keyring_sha256) is None:
                raise ConsoleError('Le trousseau et son empreinte SHA-256 sont requis.')
            staging = protected_directory(state / 'staging', create=True)
            artifacts = protected_directory(state / 'artifacts', create=True)
            trust = ['--keyring', str(args.keyring), '--keyring-sha256', args.keyring_sha256]
            if args.action == 'pxe':
                if not args.confirm_activation:
                    raise ConsoleError('Ajoutez --confirm-activation : cette commande démarre des services réseau.')
                os.execv(sys.executable, [sys.executable, str(APP / 'cli/supervise_pxe.py'), '--state', str(state),
                                          *trust, '--confirm-activation'])
            mode = ['run-once', '--server-ip', args.server_ip] if args.action == 'acquire' else ['run-loop']
            os.execv(sys.executable, [sys.executable, str(APP / 'cli/netboot_job.py'), '--db', str(database),
                                      mode[0], *trust, *mode[1:], '--staging', str(staging), '--served', str(artifacts)])
            raise AssertionError('exec returned unexpectedly')
        sessions = protected_directory(state / 'sessions', create=False)
        environment = dict(os.environ, PXE_DB_PATH=str(database), PXE_SESSION_DIR=str(sessions),
                           PXE_SERVICE_STATUS=str(state / 'run/pxe-service.json'))
        print(f'Console locale : http://127.0.0.1:{args.port}/', flush=True)
        os.execvpe('php', ['php', '-d', 'display_errors=stderr', '-S', f'127.0.0.1:{args.port}',
                           '-t', str(APP / 'public')], environment)
        raise AssertionError('exec returned unexpectedly')
    except ConsoleError as error:
        print(str(error), file=sys.stderr)
        return 1
    except (OSError, UnicodeError, EOFError, KeyboardInterrupt, getpass.GetPassWarning):
        print('Console indisponible : vérifiez les chemins, permissions et paramètres.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
