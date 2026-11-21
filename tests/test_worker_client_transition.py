# SPDX-License-Identifier: LGPL-3.0-only
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('wait_client_transition',
    Path(__file__).resolve().parents[1] / 'packaging/ci/wait-worker-client-transitions.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TransitionPropagation(unittest.TestCase):
    def test_empty_policy_makes_no_request(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(module.http, 'fetch_transition') as fetch:
            module.wait(Path(tmp))
            fetch.assert_not_called()

    def test_missing_and_stale_objects_retry_until_exact(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            directory = root / 'apt-transition/sha256' / ('a' * 64)
            directory.mkdir(parents=True)
            for name in module.NAMES:
                (directory / name).write_bytes(name.encode())
            attempts = {}
            def fetch(ident, name):
                self.assertEqual(ident, 'a' * 64)
                attempts[name] = attempts.get(name, 0) + 1
                if attempts[name] == 1:
                    return (200, b'stale') if name == 'new.InRelease' else (404, b'')
                return 200, name.encode()
            sleeps = []
            with patch.object(module.http, 'fetch_transition', side_effect=fetch):
                module.wait(root, clock=lambda: 0, sleep=sleeps.append)
            self.assertEqual(attempts, {name: 2 for name in module.NAMES})
            self.assertEqual(sleeps, [10])

    def test_deadline_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            directory = root / 'apt-transition/sha256' / ('a' * 64)
            directory.mkdir(parents=True)
            for name in module.NAMES:
                (directory / name).write_bytes(name.encode())
            times = iter((0, 121))
            with patch.object(module.http, 'fetch_transition') as fetch:
                with self.assertRaisesRegex(ValueError, 'deadline'):
                    module.wait(root, clock=lambda: next(times), sleep=lambda _: None)
                fetch.assert_not_called()


if __name__ == '__main__':
    unittest.main()
