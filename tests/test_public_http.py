# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('public_http', Path(__file__).resolve().parents[1] / 'packaging/ci/public-http.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class HttpTests(unittest.TestCase):
    def test_http_status_and_body_are_returned(self):
        def curl(args, **kwargs):
            self.assertEqual(args[0], 'curl')
            Path(args[args.index('--output') + 1]).write_bytes(b'fixture')
            return subprocess.CompletedProcess(args, 0, b'404')
        with patch.object(module.subprocess, 'run', side_effect=curl):
            self.assertEqual(module.fetch(), (404, b'fixture'))
    def test_dns_failure_is_not_treated_as_404(self):
        with patch.object(module.subprocess, 'run', return_value=subprocess.CompletedProcess([], 6, b'000')):
            with self.assertRaisesRegex(ValueError, 'curl exit 6'): module.fetch()
    def test_oversized_response_rejected(self):
        def curl(args, **kwargs):
            Path(args[args.index('--output') + 1]).write_bytes(b'x' * (module.LIMIT + 1))
            return subprocess.CompletedProcess(args, 0, b'200')
        with patch.object(module.subprocess, 'run', side_effect=curl):
            with self.assertRaisesRegex(ValueError, 'too large'): module.fetch()
