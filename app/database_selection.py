#!/usr/bin/env python3
"""Resolve the database used by the SQLite worker and network renderer."""
import json
import os
import sys

STANDARD_PATH = '/var/lib/pxe-tools/pxe-tools.sqlite'
LEGACY_PATH = '/var/lib/pxe-tools/orchestrator.sqlite'
CONFIG_PATH = '/etc/pxe-tools/db.conf'


def _path(value):
    if (not isinstance(value, str) or not os.path.isabs(value)
            or any(ord(char) < 32 or ord(char) == 127 for char in value)):
        raise RuntimeError('Chemin de base SQLite invalide ; aucune tâche ne sera exécutée.')
    return value


def select_database(config_path=CONFIG_PATH, fallback_path=STANDARD_PATH, legacy_path=LEGACY_PATH, environ=None):
    environ = os.environ if environ is None else environ
    if os.path.lexists(config_path):
        try:
            with open(config_path, encoding='utf-8') as source:
                config = json.load(source)
            if not isinstance(config, dict) or not isinstance(config.get('driver'), str):
                raise ValueError('invalid database config')
            if config['driver'].lower() != 'sqlite':
                raise RuntimeError('Le worker ne prend actuellement en charge que SQLite ; aucune tâche ne sera exécutée.')
            return _path(config.get('path', STANDARD_PATH)), 'config'
        except (OSError, ValueError, UnicodeError) as error:
            raise RuntimeError('Configuration de base illisible ou invalide ; aucune tâche ne sera exécutée.') from error
    driver = environ.get('PXE_DB_DRIVER', '').lower()
    if driver and driver != 'sqlite':
        raise RuntimeError('Le worker ne prend actuellement en charge que SQLite ; aucune tâche ne sera exécutée.')
    if driver == 'sqlite':
        return _path(environ.get('PXE_DB_PATH') or STANDARD_PATH), 'environment'
    fallback = (environ.get('PXE_DB_PATH') or environ.get('ORCHESTRATOR_DB')
                or (legacy_path if os.path.isfile(legacy_path) else fallback_path))
    return _path(fallback), 'default'


if __name__ == '__main__':
    try:
        print(select_database()[0])
    except RuntimeError as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
