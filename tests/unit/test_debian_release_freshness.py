#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise freshness checks on already-authenticated Debian Release fields."""
from datetime import datetime, timedelta, timezone
from email.utils import format_datetime
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    'debian_release_freshness', ROOT / 'docker/worker/scripts/debian-release-freshness.py')
assert spec is not None and spec.loader is not None
freshness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(freshness)
NOW = datetime(2026, 11, 12, 12, 0, 0, tzinfo=timezone.utc)


def header(issued: datetime, expiry: datetime | None = None) -> bytes:
    content = 'Origin: Debian\nDate: ' + format_datetime(issued, usegmt=True) + '\n'
    if expiry is not None:
        content += 'Valid-Until: ' + format_datetime(expiry, usegmt=True) + '\n'
    return (content + 'SHA256:\n abc 1 file\n').encode()


class ReleaseFreshnessTest(unittest.TestCase):
    def test_recent_stable_without_valid_until(self):
        freshness.check_freshness(header(NOW - timedelta(days=61)), NOW)

    def test_180_day_boundary_when_valid_until_missing(self):
        freshness.check_freshness(header(NOW - timedelta(days=180)), NOW)
        with self.assertRaises(ValueError):
            freshness.check_freshness(header(NOW - timedelta(days=180, seconds=1)), NOW)

    def test_future_skew_boundary(self):
        freshness.check_freshness(header(NOW + timedelta(minutes=5)), NOW)
        with self.assertRaises(ValueError):
            freshness.check_freshness(header(NOW + timedelta(minutes=5, seconds=1)), NOW)

    def test_valid_until_is_enforced_when_present(self):
        freshness.check_freshness(header(NOW - timedelta(days=2), NOW + timedelta(days=5)), NOW)
        freshness.check_freshness(header(NOW - timedelta(days=2), NOW), NOW)
        with self.assertRaises(ValueError):
            freshness.check_freshness(header(NOW - timedelta(days=2), NOW - timedelta(seconds=1)), NOW)
        with self.assertRaises(ValueError):
            freshness.check_freshness(header(NOW - timedelta(days=2), NOW - timedelta(days=3)), NOW)

    def test_duplicate_dates_rejected_case_insensitively(self):
        date = format_datetime(NOW, usegmt=True)
        for text in (f'Date: {date}\ndate: {date}\n',
                     f'Date: {date}\nValid-Until: {date}\nvalid-until: {date}\n',
                     f'Date: {date}\nDate : {date}\n'):
            with self.subTest(text=text), self.assertRaises(ValueError):
                freshness.check_freshness(text.encode(), NOW)

    def test_missing_malformed_or_ambiguous_dates_rejected(self):
        values = [b'', b'Origin: Debian\n', b'Date: 2026-11-12\n',
                  b'Date: Fri, 12 Nov 2026 12:00:00 GMT\n',
                  b'Date: Thu, 12 Nov 2026 12:00:00 -0000\n',
                  b'Date: Thu, 12 Nov 2026 12:00:00 GMT\nValid-Until: invalid\n',
                  b'Date: Thu, 12 Nov 2026 12:00:00 GMT\n\xff',
                  b'x' * (freshness.MAX_BYTES + 1)]
        for value in values:
            with self.subTest(value=value[:100]), self.assertRaises(ValueError):
                freshness.check_freshness(value, NOW)

    def test_clock_must_have_timezone(self):
        with self.assertRaises(ValueError):
            freshness.check_freshness(header(NOW), NOW.replace(tzinfo=None))


if __name__ == '__main__':
    unittest.main()
