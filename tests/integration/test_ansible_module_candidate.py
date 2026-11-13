#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Build the optional package and exercise a real isolated Ansible Vault run."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def run(args, *, env=None, cwd=None):
    return subprocess.run(args, check=True, capture_output=True, text=True, env=env, cwd=cwd).stdout


def main():
    with tempfile.TemporaryDirectory(prefix='pxe-ansible-ci-') as temporary:
        root = Path(temporary)
        package = root / 'module.deb'
        builder = ROOT / 'packaging/native/build-local-ansible-package.py'
        run(['python3', str(builder), str(package), '--release', '--version', '0.1.0~beta1'])
        for field, expected in [('Package', 'pxe-tools-ansible'), ('Version', '0.1.0~beta1'), ('Architecture', 'all')]:
            assert run(['dpkg-deb', '-f', str(package), field]).strip() == expected
        depends = run(['dpkg-deb', '-f', str(package), 'Depends'])
        for dependency in ('pxe-tools (>= 0.1.0~beta1)', 'pxe-tools (<< 0.2~)', 'ansible-core'):
            assert dependency in depends
        contents = run(['dpkg-deb', '-c', str(package)])
        assert './usr/lib/systemd/system/pxe-tools-worker.service.d/50-ansible.conf' in contents
        duplicate = subprocess.run(['python3', str(builder), str(package), '--release', '--version', '0.1.0~beta1'],
                                   capture_output=True)
        assert duplicate.returncode != 0

        vault = root / 'vault.yml'
        password = root / 'password'
        playbook = root / 'playbook.yml'
        result = root / 'result'
        password.write_text('fixture-only-password\n')
        password.chmod(0o600)
        vault.write_text('fixture_secret: fixture-value\n')
        playbook.write_text('''- hosts: localhost
  gather_facts: false
  vars_files: [vault.yml]
  tasks:
    - ansible.builtin.assert:
        that: fixture_secret == "fixture-value"
    - ansible.builtin.copy:
        content: completed
        dest: RESULT_PATH
        mode: "0600"
'''.replace('RESULT_PATH', str(result)))
        env = {'HOME': str(root), 'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8',
               'ANSIBLE_LOCAL_TEMP': str(root / 'ansible-tmp'), 'PYTHONNOUSERSITE': '1'}
        run(['/usr/bin/ansible-vault', 'encrypt', str(vault), '--vault-password-file', str(password)], env=env, cwd=root)
        assert vault.read_text().startswith('$ANSIBLE_VAULT;')
        run(['/usr/bin/ansible-playbook', '-i', 'localhost,', '-c', 'local', str(playbook),
             '--vault-password-file', str(password)], env=env, cwd=root)
        assert result.read_text() == 'completed'
    print('Optional package metadata and isolated Vault playbook verified.')


if __name__ == '__main__':
    main()
