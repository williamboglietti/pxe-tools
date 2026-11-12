#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fail closed on stale or ambiguous authenticated Debian Release metadata."""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
import re
import sys

MAX_BYTES = 4 * 1024 * 1024
MAX_AGE_WITHOUT_EXPIRY = timedelta(days=180)
MAX_FUTURE_SKEW = timedelta(minutes=5)
DATE_FORMAT = re.compile(
    r'^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), ([0-9]{1,2}) '
    r'(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) '
    r'([0-9]{4}) ([0-9]{2}):([0-9]{2}):([0-9]{2}) (UTC|GMT|[+-][0-9]{4})$'
)
TARGET_FIELD = re.compile(r'^(Date|Valid-Until)([ \t]*):', re.IGNORECASE)
WEEKDAYS = ('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun')


def parse_date(value: str) -> datetime:
    match = DATE_FORMAT.fullmatch(value)
    if match is None:
        raise ValueError('date de métadonnées invalide')
    try:
        parsed = parsedate_to_datetime(value)
    except (ValueError, TypeError, OverflowError) as error:
        raise ValueError('date de métadonnées invalide') from error
    if parsed is None or parsed.tzinfo is None or WEEKDAYS[parsed.weekday()] != match.group(1):
        raise ValueError('date de métadonnées invalide')
    return parsed.astimezone(timezone.utc)


def check_freshness(contents: bytes, now: datetime) -> None:
    if not contents or len(contents) > MAX_BYTES:
        raise ValueError('taille de métadonnées invalide')
    if now.tzinfo is None or now.utcoffset() is None:
        raise ValueError('horloge sans fuseau horaire')
    try:
        text = contents.decode('utf-8', errors='strict')
    except UnicodeDecodeError as error:
        raise ValueError('encodage de métadonnées invalide') from error
    fields: dict[str, str] = {}
    for line in text.splitlines():
        match = TARGET_FIELD.match(line)
        if not match:
            continue
        key = match.group(1).lower()
        if match.group(2) or key in fields:
            raise ValueError('dates de métadonnées ambiguës ou répétées')
        fields[key] = line[match.end():].strip(' \t')
    if 'date' not in fields:
        raise ValueError('date de métadonnées absente')
    issued = parse_date(fields['date'])
    current = now.astimezone(timezone.utc)
    if issued > current + MAX_FUTURE_SKEW:
        raise ValueError('date de métadonnées dans le futur ; vérifiez l’horloge')
    if 'valid-until' in fields:
        expiry = parse_date(fields['valid-until'])
        if expiry < issued or expiry < current:
            raise ValueError('métadonnées expirées ou dates incohérentes')
    elif current > issued + MAX_AGE_WITHOUT_EXPIRY:
        raise ValueError('métadonnées trop anciennes ; vérifiez l’horloge et le miroir')


def main() -> int:
    if len(sys.argv) != 2:
        print('Usage: debian-release-freshness.py VERIFIED_RELEASE', file=sys.stderr)
        return 2
    try:
        path = Path(sys.argv[1])
        with path.open('rb') as stream:
            contents = stream.read(MAX_BYTES + 1)
        check_freshness(contents, datetime.now(timezone.utc))
    except (OSError, ValueError) as error:
        print('[verify] fraîcheur Debian refusée : ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
