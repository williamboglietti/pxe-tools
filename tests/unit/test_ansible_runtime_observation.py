"""The UI observation must never install or execute an absent module."""
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'app'))
import worker


class RuntimeObservation(unittest.TestCase):
    def test_absent_volume_does_not_start_a_subprocess(self):
        with patch.dict(os.environ,{},clear=True), \
             patch.object(worker,'resolve_ansible_runtime',side_effect=AssertionError('unexpected runtime access')), \
             patch.object(worker,'db_set_setting') as save, patch.object(worker.time,'time',return_value=1000):
            worker.publish_ansible_runtime_status()
        self.assertEqual(json.loads(save.call_args.args[1]),{'state':'not_configured','checked_at':1000})

    def test_ready_and_incompatible_are_reported_without_paths(self):
        for failure,expected in ((None,'ready'),(RuntimeError('private detail'),'unavailable')):
            with self.subTest(expected=expected), patch.dict(os.environ,{'PXE_ANSIBLE_RUNTIME':'/var/lib/pxe-ansible'},clear=True), \
                 patch.object(worker,'resolve_ansible_runtime',side_effect=failure), \
                 patch.object(worker,'db_set_setting') as save, patch.object(worker.time,'time',return_value=1000):
                worker.publish_ansible_runtime_status()
            self.assertEqual(json.loads(save.call_args.args[1]),{'state':expected,'checked_at':1000})

    def test_system_mode_is_checked_and_unknown_mode_fails_closed(self):
        for mode in ('system', 'unknown'):
            with self.subTest(mode=mode), patch.dict(os.environ, {'PXE_ANSIBLE_MODE':mode}, clear=True), \
                 patch.object(worker,'resolve_ansible_runtime',side_effect=RuntimeError('fixture detail')) as resolve, \
                 patch.object(worker,'db_set_setting') as save, patch.object(worker.time,'time',return_value=1000):
                worker.publish_ansible_runtime_status()
            resolve.assert_called_once()
            self.assertEqual(json.loads(save.call_args.args[1]),{'state':'unavailable','checked_at':1000})

    def test_system_and_volume_configuration_cannot_be_combined(self):
        with patch.dict(os.environ, {'PXE_ANSIBLE_MODE':'system','PXE_ANSIBLE_RUNTIME':'/var/lib/pxe-ansible'}, clear=True), \
             patch.object(worker.subprocess,'run',side_effect=AssertionError('unexpected runtime access')):
            with self.assertRaisesRegex(RuntimeError,'ambiguë'):
                worker.resolve_ansible_runtime()


if __name__=='__main__':unittest.main()
