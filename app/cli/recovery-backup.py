#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Experimental encrypted full-database + master-key offline recovery archive."""
from __future__ import annotations
import argparse
import getpass
import os
from pathlib import Path
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from backup.openpgp import BackupError  # noqa: E402
from backup.recovery import export_recovery, restore_recovery  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--passphrase-fd', type=int, help='read from an already-open protected non-terminal descriptor')
    commands = parser.add_subparsers(dest='command', required=True)
    export = commands.add_parser('export', help='encrypt the entire database and its master key')
    export.add_argument('--db', type=Path, required=True)
    export.add_argument('--master-key', type=Path, required=True)
    export.add_argument('--output', type=Path, required=True)
    export.add_argument('--confirm-stopped', action='store_true', required=True)
    export.add_argument('--include-accounts', action='store_true', required=True)
    export.add_argument('--include-inventory', action='store_true', required=True)
    export.add_argument('--include-secrets', action='store_true', required=True)
    export.add_argument('--include-master-key', action='store_true', required=True)
    export.add_argument('--db-config', type=Path, help='explicit SQLite configuration file to include')
    export.add_argument('--include-db-config', action='store_true')
    export.add_argument('--confirm-db-config-matches', action='store_true')
    export.add_argument('--mok-dir', type=Path, help='directory containing the current MOK key and certificates')
    export.add_argument('--include-mok', action='store_true')
    export.add_argument('--http-root', type=Path, help='explicit HTTP/PXE asset directory')
    export.add_argument('--include-http', action='store_true')
    export.add_argument('--tftp-root', type=Path, help='explicit TFTP asset directory')
    export.add_argument('--include-tftp', action='store_true')
    restore = commands.add_parser('restore', help='authenticate into a new private, inactive directory')
    restore.add_argument('--input', type=Path, required=True)
    restore.add_argument('--output-dir', type=Path, required=True)
    args = parser.parse_args()
    if args.command == 'export' and (
            args.include_db_config != (args.db_config is not None)
            or args.confirm_db_config_matches != args.include_db_config
            or args.include_mok != (args.mok_dir is not None)
            or args.include_http != (args.http_root is not None)
            or args.include_tftp != (args.tftp_root is not None)):
        parser.error('optional components require their paths and explicit confirmations')
    try:
        if args.passphrase_fd is not None:
            if args.passphrase_fd < 0 or os.isatty(args.passphrase_fd):
                raise BackupError('passphrase descriptor must be a protected non-terminal input')
            with os.fdopen(os.dup(args.passphrase_fd), 'rb') as stream:
                passphrase = stream.readline(1026)
            if passphrase.endswith(b'\n'):
                passphrase = passphrase[:-1]
        else:
            passphrase = getpass.getpass('Passphrase: ').encode('utf-8')
            if args.command == 'export' and passphrase != getpass.getpass('Confirm passphrase: ').encode('utf-8'):
                raise BackupError('passphrases do not match')
        if args.command == 'export':
            export_recovery(args.db, args.master_key, args.output, passphrase,
                            confirm_stopped=args.confirm_stopped, include_accounts=args.include_accounts,
                            include_inventory=args.include_inventory, include_secrets=args.include_secrets,
                            include_master_key=args.include_master_key,
                            db_config=args.db_config, include_db_config=args.include_db_config,
                            confirm_db_config_matches=args.confirm_db_config_matches,
                            mok_dir=args.mok_dir, include_mok=args.include_mok,
                            http_root=args.http_root, include_http=args.include_http,
                            tftp_root=args.tftp_root, include_tftp=args.include_tftp)
            print('Encrypted offline database and key archive created; other instance files are not included.')
        else:
            restore_recovery(args.input, args.output_dir, passphrase)
            print('Database and key restored to a new offline directory; no service was started.')
        return 0
    except (BackupError, OSError, EOFError, UnicodeError) as error:
        print('Recovery operation failed: ' + str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
