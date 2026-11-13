#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Validate the complete machine snapshot before replacing PXE menus."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('menu_rows', ROOT/'docker/worker/scripts/validate-pxe-menu-rows.py')
rows = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rows)


class MenuRows(unittest.TestCase):
    def test_legitimate_rows_and_ambiguous_values(self):
        machine = {'mac':'02:00:00:00:00:01', 'hostname':'fixture-host', 'status':'configured',
                   'rescue_mode':0, 'local_boot_source':None, 'm_distro':'debian', 'm_version_full':'13.7.0'}
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)/'rows.json'
            def read(data):
                path.write_text(json.dumps(data), encoding='utf-8')
                return rows.validate(path)
            self.assertEqual(read([machine]), ['02:00:00:00:00:01|Zml4dHVyZS1ob3N0|configured|0||debian|13.7.0'])
            self.assertEqual(read([{**machine, 'hostname':'Bureau | Étage'}]),
                             ['02:00:00:00:00:01|QnVyZWF1IHwgw4l0YWdl|configured|0||debian|13.7.0'])
            self.assertEqual(read([]), [])
            for modified in ({**machine, 'hostname':'other\nLABEL injected'},
                             {**machine, 'm_distro':"debian' OR 1=1"},
                             {**machine, 'mac':'02:00:00:00:00:01/../../file'},
                             {**machine, 'rescue_mode':2}):
                with self.subTest(modified=modified), self.assertRaises(ValueError):
                    read([modified])
            with self.assertRaises(ValueError):
                read([machine, machine])


if __name__ == '__main__':
    unittest.main()
