# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worker_upload', Path(__file__).resolve().parents[1] / 'packaging/ci/cloudflare-upload-preflight.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
ACCOUNT = 'a' * 32
TOKEN = 'never-print-this-fixture-secret'

def snapshot():
    return {'subdomain': {'enabled': False, 'previews_enabled': False},
            'deployment': {'id': 'deployed', 'versions': [{'version_id':'active', 'percentage':100}]},
            }

class Tests(unittest.TestCase):
    def test_uploaded_fixture_is_unbound_inert_and_deployment_unchanged(self):
        before = snapshot()
        with patch.object(module, 'observe', side_effect=[before, snapshot()]), \
             patch.object(module, 'observe_public_domain', return_value=(404, 'fixture')), \
             patch.object(module, 'call', return_value={'id':'a'*8+'-'+'b'*4+'-'+'c'*4+'-'+'d'*4+'-'+'e'*12}) as call:
            module.check(ACCOUNT, TOKEN)
            args = call.call_args.args
            self.assertEqual(args[2], f'scripts/{module.WORKER}/versions')
            self.assertIn(b'status: 404', args[3])
            self.assertNotIn(TOKEN.encode(), args[3])
            self.assertNotIn(b'bindings', args[3])
    def test_no_upload_if_preflight_fails(self):
        with patch.object(module, 'observe', side_effect=ValueError('preview enabled')), patch.object(module, 'call') as call:
            with self.assertRaises(ValueError): module.check(ACCOUNT, TOKEN)
            call.assert_not_called()
    def test_fails_if_deployment_changes(self):
        after = snapshot(); after['deployment']['id'] = 'different'
        with patch.object(module, 'observe', side_effect=[snapshot(), after]), \
             patch.object(module, 'observe_public_domain', return_value=(404, 'fixture')), \
             patch.object(module, 'call', return_value={'id':'a'*8+'-'+'b'*4+'-'+'c'*4+'-'+'d'*4+'-'+'e'*12}):
            with self.assertRaisesRegex(ValueError, 'changed'): module.check(ACCOUNT, TOKEN)
    def test_403_upload_still_checks_post_state(self):
        with patch.object(module, 'observe', side_effect=[snapshot(), snapshot()]) as observe, \
             patch.object(module, 'observe_public_domain', return_value=(404, 'fixture')), \
             patch.object(module, 'call', side_effect=ValueError('failure')):
            with self.assertRaises(ValueError): module.check(ACCOUNT, TOKEN)
            self.assertEqual(observe.call_count, 2)
    def test_public_domain_response_change_is_detected(self):
        with patch.object(module, 'observe', side_effect=[snapshot(), snapshot()]), \
             patch.object(module, 'observe_public_domain', side_effect=[(404, 'old'), (200, 'new')]), \
             patch.object(module, 'call', return_value={'id':'a'*8+'-'+'b'*4+'-'+'c'*4+'-'+'d'*4+'-'+'e'*12}):
            with self.assertRaisesRegex(ValueError, 'changed'): module.check(ACCOUNT, TOKEN)
    def test_preview_enabled_fails_before_upload(self):
        with patch.object(module, 'call', return_value={'enabled':False, 'previews_enabled':True}) as call:
            with self.assertRaises(ValueError): module.observe(ACCOUNT, TOKEN)
            self.assertEqual(call.call_count, 1)

if __name__ == '__main__': unittest.main()
