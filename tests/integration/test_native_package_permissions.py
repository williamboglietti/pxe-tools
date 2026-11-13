#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Package modes do not inherit writable sources or the builder's umask."""
import io
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def main():
    with tempfile.TemporaryDirectory(prefix='pxe-package-modes-') as temporary:
        work = Path(temporary)
        source = work/'source'
        shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns('.git', '__pycache__', '*.pyc', '.DS_Store'))
        subprocess.run(['git', 'init', '-q', str(source)], check=True)
        subprocess.run(['git', '-C', str(source), 'add', '-A'], check=True)
        unsafe_dir = source/'app'
        unsafe_file = unsafe_dir/'worker.py'
        unsafe_conf = source/'packaging/native/ansible-worker.conf'
        unsafe_unit = source/'packaging/native/pxe-tools-worker.service'
        unsafe_dir.chmod(0o777)
        unsafe_file.chmod(0o666)
        unsafe_conf.chmod(0o666)
        unsafe_unit.chmod(0o664)
        builder = source/'packaging/native/build-apt-package-candidate.py'
        untracked = source/'app/fixture-untracked'; untracked.write_text('fixture-only'); untracked.chmod(0o644)
        denied = subprocess.run(['python3', str(builder), str(work/'untracked.deb'), '--version', '0.1.0~fixture',
                                 '--maintainer', 'Fixture <fixture@example.invalid>'], capture_output=True)
        assert denied.returncode != 0 and not (work/'untracked.deb').exists()
        untracked.unlink()
        unsafe_file.chmod(0o600)
        denied = subprocess.run(['python3', str(builder), str(work/'private.deb'), '--version', '0.1.0~fixture',
                                 '--maintainer', 'Fixture <fixture@example.invalid>'], capture_output=True)
        assert denied.returncode != 0 and not (work/'private.deb').exists()
        unsafe_file.chmod(0o666)
        for mask in (0o002, 0o022, 0o077):
            for name, script, options in (
                ('base', 'build-apt-package-candidate.py', ['--maintainer', 'Fixture <fixture@example.invalid>']),
                ('ansible', 'build-local-ansible-package.py', ['--release']),
            ):
                package = work/f'{name}-{mask:03o}.deb'
                subprocess.run(['python3', str(source/'packaging/native'/script), str(package),
                                '--version', '0.1.0~modefixture', *options], check=True,
                               stdout=subprocess.DEVNULL, umask=mask)
                for command in ('--fsys-tarfile', '--ctrl-tarfile'):
                    data = subprocess.check_output(['dpkg-deb', command, str(package)])
                    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
                        for member in archive.getmembers():
                            if member.isdir():
                                assert member.mode == 0o755, (name, mask, member.name, oct(member.mode))
                            elif member.isfile():
                                assert member.mode in (0o644, 0o755), (name, mask, member.name, oct(member.mode))
                            else:
                                raise AssertionError(('unsupported package item', member.name))
        assert unsafe_dir.stat().st_mode & 0o777 == 0o777
        assert unsafe_file.stat().st_mode & 0o777 == 0o666
        assert unsafe_conf.stat().st_mode & 0o777 == 0o666
        assert unsafe_unit.stat().st_mode & 0o777 == 0o664
        shutil.rmtree(source/'.git')
        denied = subprocess.run(['python3', str(builder), str(work/'no-git.deb'), '--version', '0.1.0~fixture',
                                 '--maintainer', 'Fixture <fixture@example.invalid>'], capture_output=True)
        assert denied.returncode != 0 and not (work/'no-git.deb').exists()
    print('Both packages retain safe, readable modes across umasks without modifying sources.')


if __name__ == '__main__': main()
