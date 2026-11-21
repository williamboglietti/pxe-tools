#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise freshness and the publication default without real keys or APT state."""
from datetime import datetime, timezone
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


client = load('client_transition_policy', 'packaging/native/prepare-client-key-transition.py')
publish = load('publish_transition_policy', 'packaging/ci/stage-published-client-transitions.py')


class TransitionPolicy(unittest.TestCase):
    now = datetime(2026, 11, 18, 12, tzinfo=timezone.utc)

    def signed_text(self, dates):
        return ('-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA256\n\n' + dates +
                '\n-----BEGIN PGP SIGNATURE-----\nfixture\n').encode()

    def test_freshness_requires_unexpired_nonfuture_new_index(self):
        good = 'Date: Wed, 18 Nov 2026 11:00:00 UTC\nValid-Until: Thu, 19 Nov 2026 11:00:00 UTC'
        client.require_fresh_release(self.signed_text(good), now=self.now)
        for dates in (good.replace('Thu, 19 Nov 2026 11:00:00', 'Wed, 18 Nov 2026 11:30:00'),
                      good.replace('Wed, 18 Nov 2026 11:00:00', 'Wed, 18 Nov 2026 13:00:00'),
                      'Date: Wed, 18 Nov 2026 11:00:00 UTC', good + '\n' + good):
            with self.subTest(dates=dates), self.assertRaises(ValueError):
                client.require_fresh_release(self.signed_text(dates), now=self.now)

    def test_empty_publication_policy_does_not_change_assets(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            assets = root / 'assets'
            assets.mkdir()
            headers = assets / '_headers'
            headers.write_bytes(b'existing headers\n')
            config = root / 'config.json'
            config.write_text(json.dumps({'version': 1, 'transitions': []}))
            self.assertEqual(publish.prepare(assets, config, root / 'missing', 'A' * 40), 0)
            self.assertEqual(headers.read_bytes(), b'existing headers\n')
            self.assertEqual({p.name for p in assets.iterdir()}, {'_headers'})

    def test_duplicate_or_untrusted_config_is_rejected_before_staging(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            assets = root / 'assets'
            assets.mkdir()
            (assets / '_headers').write_text('headers\n')
            config = root / 'config.json'
            config.write_text('{"version": 1, "transitions": [], "transitions": []}')
            with self.assertRaisesRegex(ValueError, 'duplicate'):
                publish.prepare(assets, config, root / 'missing', 'A' * 40)
            self.assertFalse((assets / 'apt-transition').exists())


if __name__ == '__main__':
    unittest.main()
