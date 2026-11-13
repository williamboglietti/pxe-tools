# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('worker_wait', Path(__file__).resolve().parents[1] / 'packaging/ci/wait-worker-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class Reply:
    status = 200
    def __init__(self, data): self.data = data
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self, size): return self.data

class PropagationTests(unittest.TestCase):
    def test_stale_200_then_expected(self):
        with patch.object(module.urllib.request, 'urlopen', side_effect=[Reply(b'old'), Reply(b'new')]), patch.object(module.time, 'sleep') as sleep:
            module.wait(b'new', attempts=2, delay=1)
            sleep.assert_called_once_with(1)
    def test_stale_200_remains_failure(self):
        with patch.object(module.urllib.request, 'urlopen', return_value=Reply(b'old')), patch.object(module.time, 'sleep'):
            with self.assertRaises(ValueError): module.wait(b'new', attempts=2, delay=1)
