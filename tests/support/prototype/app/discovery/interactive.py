#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Manual discovery on a trusted boot environment; no unattended installation."""
from __future__ import annotations

import getpass
import json
import re
import subprocess
import sys
import warnings

from submit import DeliveryError, MAX_REPORT, _address, pin_server, submit

COLLECTOR = '/usr/lib/pxe-tools/discovery/collect.sh'


def _secret() -> bytes:
    # getpass otherwise falls back to echoed input; convert that warning into
    # a refusal before it reads any token.
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        value = getpass.getpass('Jeton à usage unique (saisie masquée) : ')
    try:
        token = value.encode('ascii')
    except UnicodeEncodeError as error:
        raise DeliveryError('invalid token') from error
    if re.fullmatch(rb'[A-Za-z0-9_-]{43}', token) is None:
        raise DeliveryError('invalid token')
    return token


def main() -> int:
    if not sys.stdin.isatty() or not sys.stdout.isatty():
        print('Une console interactive est requise ; aucun jeton ne sera demandé.', file=sys.stderr)
        return 1
    print('Découverte manuelle — aucun disque ne sera modifié.')
    print('Le démarrage PXE peut être modifié sur le réseau : utilisez uniquement un code de démarrage authentifié.')
    try:
        if input('Continuer dans cet environnement de confiance ? Saisir OUI : ').strip() != 'OUI':
            print('Découverte annulée.')
            return 1
        interface = input('Interface réseau : ').strip()
        if re.fullmatch(r'[A-Za-z0-9_.:-]{1,15}', interface) is None or interface in ('.', '..') or interface.startswith('-'):
            raise DeliveryError('invalid interface')
        url = input('URL HTTPS complète de réception : ').strip()
        _address(url)
        fingerprint = input('SHA-256 du certificat serveur vérifié séparément (64 caractères) : ').strip()
        pem = pin_server(url, fingerprint)
        print('Certificat et nom du serveur vérifiés.')
        result = subprocess.run(['/bin/sh', COLLECTOR, '--interface', interface], stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=60, check=False)
        if result.returncode != 0 or len(result.stdout) > MAX_REPORT:
            raise DeliveryError('discovery failed')
        report = json.loads(result.stdout)
        if not isinstance(report, dict) or not isinstance(report.get('disks'), list) or not isinstance(report.get('interfaces'), list):
            raise DeliveryError('invalid discovery')
        print(f"Inventaire préparé : {len(report['disks'])} disque(s), {len(report['interfaces'])} interface(s).")
        if input('Transmettre cet inventaire sensible ? Saisir OUI : ').strip() != 'OUI':
            print('Envoi annulé.')
            return 1
        token = _secret()
        submit(url, None, token, result.stdout, ca_pem=pem, leaf_pin=fingerprint)
        print('Inventaire accepté ; le jeton ne peut plus être réutilisé.')
        return 0
    except (DeliveryError, OSError, ValueError, EOFError, KeyboardInterrupt, getpass.GetPassWarning, subprocess.SubprocessError):
        print('\nDécouverte non confirmée ; vérifier la confiance, la console et le service.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
