# SPDX-License-Identifier: LGPL-3.0-only
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from contextlib import contextmanager
from unittest import mock

path = Path(__file__).resolve().parents[2] / 'docker/worker/scripts/prepare-debian-shim.py'
spec = importlib.util.spec_from_file_location('prepare_debian_shim', path)
shim = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shim)


class DebianShimAcquisition(unittest.TestCase):
    def test_staging_name_cannot_follow_existing_link(self):
        with tempfile.TemporaryDirectory(prefix='pxe-stage-unit-') as temporary:
            root = Path(temporary)
            witness = root / 'witness'
            witness.write_bytes(b'original')
            (root / ('.pxe-shim-' + '0' * 32)).symlink_to(witness)
            fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
            try:
                with mock.patch.object(shim.secrets, 'token_hex', return_value='0' * 32):
                    with self.assertRaises(OSError):
                        shim.write_staged(fd, b'changed')
            finally:
                os.close(fd)
            self.assertEqual(witness.read_bytes(), b'original')

    def test_publication_rolls_back_second_rename_failure_and_sets_mode(self):
        with tempfile.TemporaryDirectory(prefix='pxe-shim-publish-') as temporary:
            root = Path(temporary)
            for name in ('mmx64.efi', 'grubnetx64.efi'):
                (root / name).write_bytes(b'old-' + name.encode())
            fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
            self.addCleanup(os.close, fd)
            original_replace = os.replace
            calls = []
            def fail_second(*args, **kwargs):
                calls.append(args)
                if len(calls) == 2:
                    raise OSError('fixture interrupted second rename')
                return original_replace(*args, **kwargs)
            with mock.patch.object(shim, 'previous_target',
                                   side_effect=lambda d, name: (root / name).read_bytes()), \
                    mock.patch.object(shim.os, 'replace', side_effect=fail_second):
                with self.assertRaises(OSError):
                    shim.publish_pair(fd, b'MZ new shim', b'MZ new mok')
            for name in ('mmx64.efi', 'grubnetx64.efi'):
                self.assertEqual((root / name).read_bytes(), b'old-' + name.encode())
            self.assertEqual(sorted(p.name for p in root.iterdir()), ['grubnetx64.efi', 'mmx64.efi'])
            old_umask = os.umask(0o077)
            try:
                with mock.patch.object(shim, 'previous_target',
                                       side_effect=lambda d, name: (root / name).read_bytes()):
                    shim.publish_pair(fd, b'MZ new shim', b'MZ new mok')
            finally:
                os.umask(old_umask)
            for name in ('mmx64.efi', 'grubnetx64.efi'):
                self.assertEqual((root / name).stat().st_mode & 0o777, 0o644)

    def test_extraction_reads_only_one_regular_bounded_exact_member(self):
        def extract(entries):
            archive = io.BytesIO()
            with tarfile.open(fileobj=archive, mode='w') as tar:
                for name, kind, contents in entries:
                    entry = tarfile.TarInfo(name)
                    if kind == 'link':
                        entry.type = tarfile.SYMTYPE
                        entry.linkname = '/etc/passwd'
                        tar.addfile(entry)
                    else:
                        entry.size = len(contents)
                        tar.addfile(entry, io.BytesIO(contents))
            def fake_run(args, stdout, **kwargs):
                stdout.write(archive.getvalue())
            with mock.patch.object(shim.subprocess, 'run', side_effect=fake_run):
                return shim.extract_member(Path('/unused-fixture.deb'), shim.SHIM_MEMBER)
        self.assertEqual(extract([('../../ignored', 'file', b'other'),
                                  (shim.SHIM_MEMBER, 'file', b'MZ shim')]), b'MZ shim')
        for entries in ([(shim.SHIM_MEMBER, 'link', b'')],
                        [(shim.SHIM_MEMBER, 'file', b'MZ one'), (shim.SHIM_MEMBER, 'file', b'MZ two')],
                        [('./usr/lib/shim/wrong.efi', 'file', b'MZ wrong')]):
            with self.subTest(entries=entries), self.assertRaises(ValueError):
                extract(entries)

    def test_built_using_requires_exact_single_shim_version(self):
        self.assertEqual(shim.shim_build('shim (= 16.1-2~deb13u1)'), '16.1-2~deb13u1')
        for value in ('', 'shim (>= 16.1)', 'shim (= 1), shim (= 2)',
                      'other (= 1)', 'shim (= 1/../../2)'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                shim.shim_build(value)

    def test_signed_hash_parsers_reject_missing_ambiguous_or_invalid(self):
        digest = 'a' * 64
        relative = 'main/installer-amd64/current/images/SHA256SUMS'
        release = f'Date: value\nSHA256:\n {digest} 55 {relative}\n'
        self.assertEqual(shim.release_sha256(release, relative), digest)
        for bad in (release + 'SHA256:\n', release + f' {digest} 2 {relative}\n',
                    release.replace(digest, 'bad'), 'SHA256:\n'):
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                shim.release_sha256(bad, relative)
        name = './netboot/debian-installer/amd64/bootnetx64.efi'
        self.assertEqual(shim.sums_sha256(f'{digest}  {name}\n', name), digest)
        with self.assertRaises(ValueError):
            shim.sums_sha256(f'{digest}  {name}\n{digest}  {name}\n', name)

    def test_fail_closed_preserves_installed_pair_until_all_checks_succeed(self):
        with tempfile.TemporaryDirectory(prefix='pxe-shim-unit-') as temp:
            root = Path(temp); dest = root / 'tftp'; dest.mkdir()
            @contextmanager
            def fixture_lock(directory):
                fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    yield fd
                finally:
                    os.close(fd)
            lock_patch = mock.patch.object(shim, 'locked_destination', side_effect=fixture_lock)
            target_patch = mock.patch.object(shim, 'previous_target',
                                             side_effect=lambda fd, name: (dest / name).read_bytes()
                                             if (dest / name).exists() else None)
            lock_patch.start(); self.addCleanup(lock_patch.stop)
            target_patch.start(); self.addCleanup(target_patch.stop)
            key = root / 'archive.gpg'; key.write_bytes(b'fixture')
            for name in ('grubnetx64.efi', 'mmx64.efi'):
                (dest / name).write_bytes(b'previous-' + name.encode())
            before = {f.name: f.read_bytes() for f in dest.iterdir()}
            candidate = b'MZ authenticated shim fixture'
            boot_name = './netboot/debian-installer/amd64/bootnetx64.efi'
            sums = (hashlib.sha256(candidate).hexdigest() + '  ' + boot_name + '\n').encode()
            relative = 'main/installer-amd64/current/images/SHA256SUMS'
            release = f'SHA256:\n {hashlib.sha256(sums).hexdigest()} {len(sums)} {relative}\n'
            def fetch(url, limit):
                if url.endswith('/InRelease'): return b'signed release fixture'
                if url.endswith('/SHA256SUMS'): return sums
                if url.endswith('/bootnetx64.efi'): return candidate
                raise AssertionError(url)
            def run(args, cwd=None, env=None, output=False):
                if args[0] == 'gpgv':
                    Path(args[args.index('--output') + 1]).write_text(release)
                elif args[0] == 'apt-get' and 'download' in args:
                    (Path(cwd) / 'shim-signed_fixture_amd64.deb').write_bytes(b'package')
                    (Path(cwd) / 'shim-helpers-amd64-signed_fixture_amd64.deb').write_bytes(b'package')
                elif args[0] == 'dpkg-deb':
                    return b'shim (= 16.1-2~deb13u1)'
                return b''
            def expired(args, cwd=None, env=None, output=False):
                if args[0] == 'python3':
                    raise subprocess.CalledProcessError(1, args)
                return run(args, cwd=cwd, env=env, output=output)
            with mock.patch.object(shim, 'fetch', side_effect=fetch), mock.patch.object(shim, 'run', side_effect=expired):
                with self.assertRaises(subprocess.CalledProcessError):
                    shim.acquire(dest, key)
            self.assertEqual({f.name: f.read_bytes() for f in dest.iterdir()}, before)

            def missing_package(args, cwd=None, env=None, output=False):
                if args[0] == 'apt-get' and 'download' in args:
                    (Path(cwd) / 'shim-signed_fixture_amd64.deb').write_bytes(b'package')
                    return b''
                return run(args, cwd=cwd, env=env, output=output)
            with mock.patch.object(shim, 'fetch', side_effect=fetch), mock.patch.object(shim, 'run', side_effect=missing_package):
                with self.assertRaises(ValueError):
                    shim.acquire(dest, key)
            self.assertEqual({f.name: f.read_bytes() for f in dest.iterdir()}, before)

            def mismatched_package(args, cwd=None, env=None, output=False):
                if args[0] == 'dpkg-deb' and 'shim-helpers-' in args[2]:
                    return b'shim (= 16.0-1)'
                return run(args, cwd=cwd, env=env, output=output)
            with mock.patch.object(shim, 'fetch', side_effect=fetch), mock.patch.object(shim, 'run', side_effect=mismatched_package):
                with self.assertRaises(ValueError):
                    shim.acquire(dest, key)
            self.assertEqual({f.name: f.read_bytes() for f in dest.iterdir()}, before)

            with mock.patch.object(shim, 'fetch', side_effect=fetch), mock.patch.object(shim, 'run', side_effect=run), \
                    mock.patch.object(shim, 'extract_member', side_effect=[b'MZ wrong shim', b'MZ mok']):
                with self.assertRaises(ValueError):
                    shim.acquire(dest, key)
            self.assertEqual({f.name: f.read_bytes() for f in dest.iterdir()}, before)
            with mock.patch.object(shim, 'fetch', side_effect=fetch), mock.patch.object(shim, 'run', side_effect=run), \
                    mock.patch.object(shim, 'extract_member', side_effect=[candidate, b'MZ mok']):
                shim.acquire(dest, key)
            self.assertEqual((dest / 'grubnetx64.efi').read_bytes(), candidate)
            self.assertEqual((dest / 'mmx64.efi').read_bytes(), b'MZ mok')


if __name__ == '__main__':
    unittest.main()
