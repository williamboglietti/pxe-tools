#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Test the client-certificate transition solely on disposable fixture trees."""
import hashlib
import importlib.util
import os
import shutil
import subprocess
import sys
from pathlib import Path
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from unittest.mock import patch

if os.environ.get('PXE_APT_ROTATION_FIXTURE') != '1':
    raise SystemExit('Run only in the disposable APT rotation fixture')
ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(os.environ['PXE_ROTATION_FIXTURE_DIR'])
PRIMARY = os.environ['PXE_ROTATION_PRIMARY']
OLD_SIGNER = os.environ['PXE_ROTATION_OLD_SIGNER']
NEW_SIGNER = os.environ['PXE_ROTATION_NEW_SIGNER']
tool_path = Path(os.environ.get('PXE_CLIENT_TRANSITION_TOOL', ROOT / 'packaging/native/prepare-client-key-transition.py'))
spec = importlib.util.spec_from_file_location('pxe_client_transition_fixture', tool_path)
transition = importlib.util.module_from_spec(spec)
spec.loader.exec_module(transition)


class ClientTransitionFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='pxe-rotation-client-', dir='/tmp')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / '.pxe-rotation-fixture').write_text('PXE_TOOLS_ROTATION_DISPOSABLE\n')
        (self.root / 'run/lock').mkdir(parents=True)
        (self.root / 'var/lib/dpkg').mkdir(parents=True)
        keys = self.root / 'etc/apt/keyrings'
        sources = self.root / 'etc/apt/sources.list.d'
        keys.mkdir(parents=True)
        sources.mkdir(parents=True)
        self.key = keys / 'pxe-tools-archive-keyring.gpg'
        self.old = (FIXTURE / 'old-public.gpg').read_bytes()
        self.new = FIXTURE / 'new-public.gpg'
        self.key.write_bytes(self.old)
        self.anchor = keys / ('.pxe-tools-archive-keyring.' + hashlib.sha256(self.old).hexdigest() + '.created')
        os.link(self.key, self.anchor)
        self.source = sources / 'pxe-tools.sources'
        self.source.write_text('Types: deb\nURIs: https://packages.pxetools.com/apt\nSuites: stable\n'
                               'Components: main\nArchitectures: amd64\n'
                               'Signed-By: /etc/apt/keyrings/pxe-tools-archive-keyring.gpg\n')
        os.link(self.source, sources / ('.pxe-tools-sources.' + hashlib.sha256(self.source.read_bytes()).hexdigest() + '.created'))

    def run_transition(self, *, apply=False, new=None, signer=NEW_SIGNER):
        return transition.rehearse(self.root, new or self.new, FIXTURE / 'old/dists/stable/InRelease',
                                   FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER, signer, apply=apply)

    def test_read_only_candidate_inspection_leaves_client_files_unchanged(self):
        before = (self.key.read_bytes(), self.source.read_bytes(), self.key.stat().st_ino,
                  self.anchor.stat().st_ino, self.key.stat().st_mtime_ns)
        report, _, _ = transition.inspect_candidate(
            self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
            FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER, NEW_SIGNER,
            owner=None, apt_config=transition.CONFIG)
        self.assertTrue(report['inspection_only'])
        self.assertFalse(report['applied'])
        self.assertEqual((self.key.read_bytes(), self.source.read_bytes(), self.key.stat().st_ino,
                          self.anchor.stat().st_ino, self.key.stat().st_mtime_ns), before)
        self.assertFalse(list(self.key.parent.glob('.pxe-key-transition-*')))

    def test_system_inspection_rejects_fixture_apply_before_querying_system(self):
        argv = ['prepare-client-key-transition.py', '--inspect-system', '--apply-to-fixture',
                '--new-certificate', str(self.new),
                '--old-inrelease', str(FIXTURE / 'old/dists/stable/InRelease'),
                '--new-inrelease', str(FIXTURE / 'new/dists/stable/InRelease'),
                '--primary-fingerprint', PRIMARY, '--old-signing-fingerprint', OLD_SIGNER,
                '--new-signing-fingerprint', NEW_SIGNER]
        with patch.object(sys, 'argv', argv), patch.object(transition.uninstall, '_apt_effective_config') as query:
            with self.assertRaisesRegex(ValueError, 'cannot apply'):
                transition.main()
            query.assert_not_called()

    def test_preview_and_atomic_apply_preserve_original_witness(self):
        before = self.key.stat()
        report = self.run_transition()
        self.assertFalse(report['applied'])
        self.assertEqual(self.key.read_bytes(), self.old)
        self.assertEqual(self.key.stat().st_ino, before.st_ino)
        report = self.run_transition(apply=True)
        self.assertTrue(report['applied'])
        self.assertTrue(report['client_keyring_modified'])
        self.assertEqual(self.key.read_bytes(), self.new.read_bytes())
        self.assertEqual(self.anchor.read_bytes(), self.old)
        self.assertFalse(os.path.samefile(self.key, self.anchor))
        self.assertEqual(transition.uninstall.apt_cleanup_plan(self.root, owner=None, apt_config=transition.CONFIG), (True, False))
        with self.assertRaises(ValueError):
            self.run_transition(apply=True)
        self.assertFalse(list(self.key.parent.glob('.pxe-key-transition-*')))

    def test_shared_key_and_bad_fingerprint_refuse_without_writes(self):
        (self.source.parent / 'other.list').write_text('deb [signed-by=/etc/apt/keyrings/pxe-tools-archive-keyring.gpg] https://example.invalid stable main\n')
        with self.assertRaises(ValueError):
            self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)
        (self.source.parent / 'other.list').unlink()
        with self.assertRaises(ValueError):
            self.run_transition(apply=True, signer='0' * 40)
        self.assertEqual(self.key.read_bytes(), self.old)

    @unittest.skipUnless(shutil.which('apt-get'), 'requires a disposable Linux APT client')
    def test_updated_fixture_keyring_accepts_new_signed_repository(self):
        client = self.root / 'client'
        (client / 'parts').mkdir(parents=True)
        (client / 'status').write_text('')
        def update(label, repo):
            lists = client / ('lists-' + label)
            (lists / 'partial').mkdir(parents=True)
            source = client / (label + '.list')
            source.write_text(f'deb [signed-by={self.key}] file:{FIXTURE / repo} stable main\n')
            user = subprocess.check_output(['id', '-un'], text=True).strip()
            options = ['-o', f'APT::Sandbox::User={user}', '-o', f'Dir::Etc::sourcelist={source}',
                       '-o', f'Dir::Etc::sourceparts={client / "parts"}',
                       '-o', f'Dir::State::lists={lists}', '-o', f'Dir::State::status={client / "status"}',
                       '-o', 'Dir::Cache::pkgcache=', '-o', 'Dir::Cache::srcpkgcache=',
                       '-o', 'APT::Update::Error-Mode=any']
            return subprocess.run(['apt-get', *options, 'update'], capture_output=True, text=True)
        old = update('old', 'old')
        self.assertEqual(old.returncode, 0, old.stdout + old.stderr)
        stale = update('stale', 'new')
        self.assertNotEqual(stale.returncode, 0, stale.stdout + stale.stderr)
        self.run_transition(apply=True)
        # During edge propagation, an updated client may still encounter the
        # old index. The overlap certificate must accept both generations.
        overlap = update('overlap', 'old')
        self.assertEqual(overlap.returncode, 0, overlap.stdout + overlap.stderr)
        updated = update('updated', 'new')
        self.assertEqual(updated.returncode, 0, updated.stdout + updated.stderr)
        self.assertEqual(self.anchor.read_bytes(), self.old)

    def test_offline_recovery_uses_transferred_signed_data_without_network(self):
        media = self.root / 'transferred-media'
        media.mkdir()
        shutil.copyfile(self.new, media / 'new-public.gpg')
        shutil.copyfile(FIXTURE / 'expired-old.InRelease', media / 'old.InRelease')
        shutil.copyfile(FIXTURE / 'new/dists/stable/InRelease', media / 'new.InRelease')
        with patch('socket.socket', side_effect=AssertionError('network access prohibited')):
            report = transition.rehearse(self.root, media / 'new-public.gpg', media / 'old.InRelease',
                                         media / 'new.InRelease', PRIMARY, OLD_SIGNER, NEW_SIGNER, apply=True)
        self.assertTrue(report['applied'])
        self.assertEqual(self.key.read_bytes(), self.new.read_bytes())
        self.assertEqual(self.anchor.read_bytes(), self.old)

    def test_expired_new_index_refuses_without_modification(self):
        with patch.object(transition, 'datetime', wraps=datetime) as clock:
            clock.now.return_value = datetime.now(timezone.utc) + timedelta(days=3)
            with self.assertRaisesRegex(ValueError, 'expired or not yet valid'):
                self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)

    def test_modified_or_wrong_signed_release_is_rejected_without_writes(self):
        altered = self.root / 'bad.InRelease'
        data = (FIXTURE / 'new/dists/stable/InRelease').read_bytes()
        altered.write_bytes(data.replace(b'PXE Tools', b'PXE Fool!', 1))
        self.assertNotEqual(altered.read_bytes(), data)
        with self.assertRaises(ValueError):
            transition.rehearse(self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
                                altered, PRIMARY, OLD_SIGNER, NEW_SIGNER, apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)
        with self.assertRaises(ValueError):
            transition.rehearse(self.root, self.new, FIXTURE / 'new/dists/stable/InRelease',
                                FIXTURE / 'old/dists/stable/InRelease', PRIMARY, OLD_SIGNER, NEW_SIGNER, apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)

    def test_interrupted_replace_cleans_staging_and_preserves_key_and_anchor(self):
        with patch.object(transition.os, 'replace', side_effect=OSError('fixture interruption')):
            with self.assertRaisesRegex(OSError, 'fixture interruption'):
                self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)
        self.assertTrue(os.path.samefile(self.key, self.anchor))
        self.assertFalse(list(self.key.parent.glob('.pxe-key-transition-*')))

    def test_failure_after_replace_reports_uncertain_state_and_keeps_witness(self):
        real_fsync = os.fsync
        calls = 0
        def fail_on_directory(descriptor):
            nonlocal calls
            calls += 1
            if calls == 2:
                raise OSError('fixture directory flush failed')
            return real_fsync(descriptor)
        with patch.object(transition.os, 'fsync', side_effect=fail_on_directory):
            with self.assertRaisesRegex(RuntimeError, 'replaced.*durability is uncertain'):
                self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.new.read_bytes())
        self.assertEqual(self.anchor.read_bytes(), self.old)
        self.assertFalse(list(self.key.parent.glob('.pxe-key-transition-*')))

    def test_source_symlink_and_changed_witness_are_refused(self):
        source_content = self.source.read_bytes()
        self.source.unlink()
        outside = self.root / 'other-source'
        outside.write_bytes(source_content)
        self.source.symlink_to(outside)
        with self.assertRaises(ValueError):
            self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)
        self.source.unlink()
        self.source.write_bytes(source_content)
        self.anchor.write_bytes(b'changed witness')
        with self.assertRaises(ValueError):
            self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), b'changed witness')

    def test_only_explicitly_marked_fixture_in_tmp_is_allowed(self):
        with self.assertRaises(ValueError):
            transition.rehearse(Path('/'), self.new, FIXTURE / 'old/dists/stable/InRelease',
                                FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER, NEW_SIGNER, apply=True)
        (self.root / '.pxe-rotation-fixture').unlink()
        with self.assertRaises(ValueError):
            self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)

    def test_mount_on_key_or_witness_refuses_replacement(self):
        for mounted in (self.key, self.anchor, self.source):
            with self.subTest(mounted=mounted), self.assertRaisesRegex(ValueError, 'mountpoint'):
                transition.apply_with_locks(self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
                                            FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER,
                                            NEW_SIGNER, owner=None, config_provider=lambda: transition.CONFIG,
                                            mounts_provider=lambda: (str(mounted),))
            self.assertEqual(self.key.read_bytes(), self.old)

    def test_owner_checks_apply_to_the_same_code_path(self):
        with self.assertRaisesRegex(ValueError, 'unsafe|ownership'):
            transition.apply_with_locks(self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
                                        FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER,
                                        NEW_SIGNER, owner=-1, config_provider=lambda: transition.CONFIG,
                                        mounts_provider=lambda: ())
        self.assertEqual(self.key.read_bytes(), self.old)
        report = transition.apply_with_locks(self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
                                             FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER,
                                             NEW_SIGNER, owner=os.getuid(), config_provider=lambda: transition.CONFIG,
                                             mounts_provider=lambda: ())
        self.assertTrue(report['applied'])

    def test_busy_installer_lock_refuses_without_writes(self):
        with transition.uninstall.installer_lock(self.root / 'run/lock/pxe-tools-apt-install.lock', owner=None):
            with self.assertRaises(BlockingIOError):
                self.run_transition(apply=True)
        self.assertEqual(self.key.read_bytes(), self.old)

    def test_busy_dpkg_frontend_lock_refuses_without_writes(self):
        lock = self.root / 'var/lib/dpkg/lock-frontend'
        child = subprocess.Popen([sys.executable, '-c',
                                  'import fcntl,sys; f=open(sys.argv[1], "w"); '
                                  'fcntl.lockf(f, fcntl.LOCK_EX); print("locked", flush=True); sys.stdin.read()',
                                  str(lock)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        try:
            self.assertEqual(child.stdout.readline().strip(), 'locked')
            with self.assertRaises(BlockingIOError):
                self.run_transition(apply=True)
        finally:
            child.communicate(timeout=10)
        self.assertEqual(self.key.read_bytes(), self.old)

    def test_mount_appearing_during_staging_aborts_without_replacement(self):
        with patch.object(transition.os, 'replace', wraps=transition.os.replace) as replace:
            mounts = [(), (), (str(self.key),)]
            with self.assertRaisesRegex(ValueError, 'mountpoint'):
                transition.apply_with_locks(self.root, self.new, FIXTURE / 'old/dists/stable/InRelease',
                                            FIXTURE / 'new/dists/stable/InRelease', PRIMARY, OLD_SIGNER,
                                            NEW_SIGNER, owner=None, config_provider=lambda: transition.CONFIG,
                                            mounts_provider=lambda: mounts.pop(0))
            replace.assert_not_called()
        self.assertEqual(self.key.read_bytes(), self.old)
        self.assertFalse(list(self.key.parent.glob('.pxe-key-transition-*')))

    def test_system_application_requires_acknowledgement_before_any_lock(self):
        argv = ['prepare-client-key-transition.py', '--apply-system', '--new-certificate', str(self.new),
                '--old-inrelease', str(FIXTURE / 'old/dists/stable/InRelease'),
                '--new-inrelease', str(FIXTURE / 'new/dists/stable/InRelease'),
                '--primary-fingerprint', PRIMARY, '--old-signing-fingerprint', OLD_SIGNER,
                '--new-signing-fingerprint', NEW_SIGNER]
        with patch.object(sys, 'argv', argv), patch.object(transition, 'apply_with_locks') as apply:
            with self.assertRaisesRegex(ValueError, 'independent fingerprint'):
                transition.main()
            apply.assert_not_called()

    def test_system_application_has_no_fixture_root_override(self):
        argv = ['prepare-client-key-transition.py', '--apply-system', '--acknowledge-independent-fingerprints',
                '--new-certificate', str(self.new), '--old-inrelease', str(FIXTURE / 'old/dists/stable/InRelease'),
                '--new-inrelease', str(FIXTURE / 'new/dists/stable/InRelease'), '--primary-fingerprint', PRIMARY,
                '--old-signing-fingerprint', OLD_SIGNER, '--new-signing-fingerprint', NEW_SIGNER]
        with patch.object(sys, 'argv', argv), patch.object(os, 'geteuid', return_value=0), \
                patch.object(transition, 'apply_with_locks', return_value={}) as apply, \
                patch.dict(os.environ):
            transition.main()
            self.assertEqual(apply.call_args.args[0], Path('/'))
            self.assertEqual(apply.call_args.kwargs['owner'], 0)


if __name__ == '__main__':
    unittest.main()
