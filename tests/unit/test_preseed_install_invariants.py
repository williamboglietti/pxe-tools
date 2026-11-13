"""Execute the actual PHP preseed renderer on disposable data, with URL I/O disabled."""
import base64
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class PreseedInvariants(unittest.TestCase):
    def render(self, config, inventory=None, running_action_token=None, global_root_hash=None, template=None, expected_status=200,
               module_enabled=None, module_key=None, stale_key=None, machine_status='configured',
               installation_status=None, previous_installation=False):
        with tempfile.TemporaryDirectory(prefix='pxe-preseed-review-') as tmp:
            data = Path(tmp)
            connection = sqlite3.connect(data / 'pxe-tools.sqlite')
            connection.executescript((ROOT / 'app/db/schema.sqlite.sql').read_text())
            connection.execute("INSERT INTO settings(key,value) VALUES ('pxe_subnet','192.0.2.0/24')")
            if module_enabled is not None:
                connection.execute("INSERT INTO settings(key,value) VALUES ('ansible_enabled',?)", (module_enabled,))
            if module_key is not None:
                connection.execute("INSERT INTO settings(key,value) VALUES ('ansible_ssh_pubkey',?)", (module_key,))
            connection.execute("INSERT INTO machines(mac,status,install_config_json,inventory_json) VALUES (?,?,?,?)", ('02:00:00:00:00:01',machine_status,json.dumps(config), None if inventory is None else json.dumps(inventory)))
            if previous_installation:
                connection.execute("INSERT INTO actions(id,machine_id,type,status,token,created_by) VALUES (6,1,'install_only','success',?,'test')", ('e' * 64,))
                connection.execute("INSERT INTO installations(machine_id,install_status,action_id) VALUES (1,'success',6)")
            if running_action_token:
                connection.execute("INSERT INTO actions(id,machine_id,type,status,token,created_by) VALUES (7,1,'install_only','running',?,'test')", (running_action_token,))
            if installation_status is not None:
                connection.execute("INSERT INTO installations(machine_id,install_status,action_id) VALUES (1,?,?)", (installation_status, 7 if running_action_token else None))
            connection.commit()
            connection.close()
            (data / 'preseed-base.cfg').write_text(template or '{{BOOTLOADER_BLOCK}}\n{{NETWORK_BLOCK}}\n{{PACKAGES_BLOCK}}\n{{ACCOUNTS_BLOCK}}\n{{PARTITION_BLOCK}}\n{{ADMIN_USER_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
            (data / 'data.json').write_text(json.dumps({'pxe_ip':'192.0.2.1', 'root_password_hash':global_root_hash,
                                                        'ansible_pubkey':stale_key}))
            renderer = (ROOT / 'docker/worker/templates/preseed.php.j2').read_text()
            renderer = re.sub(r"\{\{ '(\{\{[A-Z_]+\}\})' \}\}", lambda m: m[1], renderer)
            if running_action_token:
                # Pas de serveur HTTP en test : l'identifiant d'installation normalement obtenu via /api/install-start.
                renderer = renderer.replace("@file_get_contents('http://127.0.0.1/api/install-start?mac=' . urlencode($mac), false, $ctx)", "'{\"installation_id\": 42}'")
            renderer = renderer.replace('{{ pxe_data_dir }}', tmp).replace('{{ pxe_ip }}', '192.0.2.1')
            (data / 'renderer.php').write_text(renderer)
            (data / 'run.php').write_text("<?php register_shutdown_function(function () { file_put_contents(__DIR__.'/http.status', (string)(http_response_code() ?: 200)); }); $_GET['mac']='02:00:00:00:00:01'; $_SERVER['REMOTE_ADDR']='192.0.2.2'; require __DIR__.'/renderer.php';")
            env = dict(os.environ, PXE_DB_DRIVER='sqlite', PXE_DB_PATH=str(data / 'pxe-tools.sqlite'))
            result = subprocess.run([os.environ.get('PHP_BINARY','php'), '-d', 'allow_url_fopen=0', str(data / 'run.php')], env=env, capture_output=True, text=True)
            if result.returncode:
                self.fail('preseed renderer failed: ' + result.stderr[:500])
            self.assertEqual(result.stderr, '')
            self.assertEqual(int((data / 'http.status').read_text()), expected_status)
            return result.stdout

    def test_machine_ready_retries_only_connection_failures_and_stops(self):
        output = self.render({'distro': 'debian'})
        def decoded(path):
            matches = re.findall(r'echo "([A-Za-z0-9+/=]+)" \| base64 -d > ' + re.escape(path), output)
            self.assertEqual(len(matches), 1)
            return base64.b64decode(matches[0]).decode()
        script = decoded('/usr/local/sbin/pxe-machine-ready')
        unit = decoded('/etc/systemd/system/pxe-machine-ready.service')
        self.assertIn('ExecStart=/usr/local/sbin/pxe-machine-ready\n', unit)
        self.assertIn('TimeoutStartSec=250\n', unit)
        self.assertIn('http://192.0.2.1/api/machine-ready?mac=02:00:00:00:00:01', script)
        with tempfile.TemporaryDirectory(prefix='pxe-ready-') as tmp:
            root = Path(tmp)
            curl = root / 'curl'
            curl.write_text('''#!/bin/bash
n=$(cat "$COUNT")
n=$((n + 1))
printf '%s' "$n" > "$COUNT"
if [ "$n" -le "$FAILURES" ]; then exit "$CODE"; fi
''')
            sleep = root / 'sleep'
            sleep.write_text('#!/bin/bash\nexit 0\n')
            curl.chmod(0o755)
            sleep.chmod(0o755)
            runnable = root / 'ready'
            runnable.write_text(script.replace('/usr/bin/curl', str(curl)).replace('/bin/sleep', str(sleep)))
            for failures, code, expected_status, expected_calls in [(2, 7, 0, 3), (100, 7, 7, 24),
                                                                    (100, 22, 22, 1), (100, 28, 28, 1)]:
                with self.subTest(code=code, failures=failures):
                    count = root / 'count'
                    count.write_text('0')
                    env = dict(os.environ, COUNT=str(count), FAILURES=str(failures), CODE=str(code))
                    result = subprocess.run(['/bin/bash', str(runnable)], env=env, capture_output=True)
                    self.assertEqual(result.returncode, expected_status)
                    self.assertEqual(int(count.read_text()), expected_calls)

    def test_install_completion_carries_current_action_token(self):
        token = 'a' * 64
        output = self.render({'distro': 'debian'}, running_action_token=token)
        self.assertIn("-H 'Authorization: Bearer " + token + "'", output)
        self.assertIn('/api/install-end?mac=', output)
        without_action = self.render({'distro': 'debian'})
        self.assertNotIn('/api/install-end?mac=', without_action)

    def test_preseed_closes_after_install_and_reopens_for_new_action(self):
        config = {'distro': 'debian'}
        token = 'a' * 64
        self.assertIn('d-i ', self.render(config, running_action_token=token,
                                         installation_status='in_progress'))
        for state in ('success', 'failed', 'aborted'):
            with self.subTest(state=state):
                self.assertEqual(self.render(config, running_action_token=token,
                                             installation_status=state,
                                             expected_status=404), '')
        self.assertEqual(self.render(config, machine_status='installed',
                                     previous_installation=True, expected_status=404), '')
        self.assertEqual(self.render(config, machine_status='configured',
                                     previous_installation=True, expected_status=404), '')
        self.assertIn('d-i ', self.render(config, running_action_token=token,
                                         previous_installation=True))

    def test_signed_boot_packages_are_added_only_for_debian_uefi_amd64(self):
        config = {'distro': 'debian', 'codename': 'trixie',
                  'advanced': {'packages': {'pkgsel_include': ['sudo', 'curl']}}}
        output = self.render(config, template='{{ACCOUNTS_BLOCK}}\n{{PACKAGES_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
        self.assertIn('d-i pkgsel/include string sudo curl', output)
        match = re.search(r'd-i preseed/early_command string \\\n\s*([^\n]+)', output)
        self.assertIsNotNone(match)
        command = match.group(1)
        with tempfile.TemporaryDirectory(prefix='pxe-efi-hook-') as tmp:
            root = Path(tmp)
            bindir = root / 'bin'; bindir.mkdir()
            (bindir/'debconf-get').write_text('#!/bin/sh\nprintf "sudo curl\\n"\n')
            (bindir/'debconf-set').write_text('#!/bin/sh\nprintf "%s\\n" "$*" > "$PXE_TEST_OUTPUT"\n')
            (bindir/'uname').write_text('#!/bin/sh\nprintf "%s\\n" "$PXE_TEST_ARCH"\n')
            for p in bindir.iterdir(): p.chmod(0o755)
            efi = root/'efi'; out = root/'out'
            env = dict(os.environ, PATH=str(bindir)+':'+os.environ['PATH'], PXE_TEST_OUTPUT=str(out))
            for has_efi, arch, expected in [(True, 'x86_64', True), (False, 'x86_64', False),
                                            (True, 'aarch64', False)]:
                with self.subTest(has_efi=has_efi, arch=arch):
                    if efi.exists(): efi.rmdir()
                    if has_efi: efi.mkdir()
                    if out.exists(): out.unlink()
                    env['PXE_TEST_ARCH'] = arch
                    subprocess.run(['sh', '-c', command.replace('/sys/firmware/efi', str(efi))], env=env, check=True)
                    self.assertEqual(out.exists(), expected)
                    if expected:
                        self.assertEqual(out.read_text().split(), ['pkgsel/include', 'sudo', 'curl',
                                         'grub-efi-amd64-signed', 'shim-signed'])
            env['PXE_TEST_ARCH'] = 'x86_64'
            if not efi.exists(): efi.mkdir()
            if out.exists(): out.unlink()
            (bindir/'debconf-get').write_text('#!/bin/sh\nexit 1\n')
            result = subprocess.run(['sh', '-c', command.replace('/sys/firmware/efi', str(efi))], env=env)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(out.exists())
        other = self.render({'distro': 'debian', 'codename': 'bookworm'},
                            template='{{ACCOUNTS_BLOCK}}\n{{PACKAGES_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
        self.assertNotIn('grub-efi-amd64-signed', other)
        empty = self.render({'distro': 'debian', 'codename': 'trixie',
                             'advanced': {'packages': {'pkgsel_include': []}}},
                            template='{{ACCOUNTS_BLOCK}}\n{{PACKAGES_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
        self.assertRegex(empty, r'(?m)^d-i pkgsel/include string\s*$')
        active = self.render(config, running_action_token='fixture-token',
                             template='{{ACCOUNTS_BLOCK}}\n{{PACKAGES_BLOCK}}\n{{EARLY_COMMAND_BLOCK}}\n')
        self.assertEqual(active.count('d-i preseed/early_command string'), 1)
        self.assertIn('grub-efi-amd64-signed shim-signed', active)
        self.assertIn('partitioning_started', active)

    def test_hostname_and_domain_are_emitted_only_when_configured(self):
        template = '{{HOSTNAME_BLOCK}}\n{{ACCOUNTS_BLOCK}}\n'
        cases = [
            ({}, False, False),
            ({'hostname': 'fixture-node'}, True, False),
            ({'domain': 'example.test'}, False, True),
            ({'hostname': 'fixture-node', 'domain': 'example.test'}, True, True),
            ({'domain': ''}, False, False),
        ]
        for fields, has_name, has_domain in cases:
            with self.subTest(fields=fields):
                output = self.render({'distro': 'debian', **fields}, template=template)
                self.assertEqual('d-i netcfg/get_hostname string fixture-node' in output, has_name)
                self.assertEqual('d-i netcfg/hostname string fixture-node' in output, has_name)
                self.assertEqual('d-i netcfg/get_domain string example.test' in output, has_domain)
                self.assertEqual('d-i netcfg/get_domain string' in output, has_domain)

    def test_explicit_empty_apt_services_is_preserved(self):
        template = '{{ACCOUNTS_BLOCK}}\n{{APT_BLOCK}}\n'
        unspecified = self.render({'distro':'debian'}, template=template)
        self.assertNotIn('apt-setup/services-select', unspecified)
        disabled = self.render({'distro':'debian', 'advanced':{'apt':{'services_select':[]}}}, template=template)
        self.assertRegex(disabled, r'(?m)^d-i apt-setup/services-select multiselect\s*$')
        selected = self.render({'distro':'debian', 'advanced':{'apt':{'services_select':['security']}}}, template=template)
        self.assertIn('d-i apt-setup/services-select multiselect security', selected)

    def test_automation_account_requires_enabled_module_and_current_valid_key(self):
        template = '{{ACCOUNTS_BLOCK}}\n{{ADMIN_USER_BLOCK}}\n{{ANSIBLE_PUBKEY}}\n'
        config = {'distro':'debian', 'admin_user_login':'operator', 'admin_user_pwhash':'fixture-hash'}
        current = 'ssh-ed25519 AAAA fixture@example.test'
        stale = 'ssh-ed25519 AAAA stale@example.test'
        for state in (None, 'off'):
            with self.subTest(state=state):
                output = self.render(config, template=template, module_enabled=state, module_key=current, stale_key=stale)
                self.assertNotIn('/home/ansible/.ssh/authorized_keys', output)
                self.assertNotIn('NOPASSWD:ALL', output)
                self.assertNotIn(current, output)
                self.assertNotIn(stale, output)
        enabled = self.render(config, template=template, module_enabled='on', module_key=current, stale_key=stale)
        self.assertIn('/home/ansible/.ssh/authorized_keys', enabled)
        self.assertIn(base64.b64encode((current + '\n').encode()).decode(), enabled)
        self.assertNotIn(stale, enabled)
        missing = self.render(config, template=template, module_enabled='on', stale_key=stale)
        self.assertNotIn('/home/ansible/.ssh/authorized_keys', missing)
        invalid = self.render(config, template=template, module_enabled='on', module_key='invalid-key', expected_status=422)
        self.assertIn('clé publique du module Ansible invalide', invalid)

    def test_root_without_machine_password_is_locked_even_if_enabled_and_global_hash_exists(self):
        config = {'distro':'debian', 'root_login_mode':'enabled', 'admin_user_login':'operator',
                  'admin_user_pwhash':'fixture-admin-hash'}
        output = self.render(config, global_root_hash='fixture-global-hash')
        self.assertIn('passwd/root-login boolean false', output)
        self.assertIn('passwd/make-user boolean true', output)
        self.assertNotIn('fixture-global-hash', output)
        self.assertNotIn('useradd -m', output)

    def test_custom_legacy_root_placeholder_never_inherits_the_global_hash(self):
        output = self.render({'distro':'debian'}, global_root_hash='fixture-global-hash',
                             template='d-i passwd/root-login boolean false\nroot={{ROOT_PASSWORD_HASH}}\n')
        self.assertIn('root=\n', output)
        self.assertNotIn('fixture-global-hash', output)

    def test_explicit_machine_root_hash_is_rendered_only_for_that_machine(self):
        config = {'distro':'debian', 'root_login_mode':'enabled', 'root_password_hash':'fixture-machine-hash'}
        output = self.render(config, global_root_hash='fixture-global-hash')
        self.assertIn('passwd/root-login boolean true', output)
        self.assertIn('fixture-machine-hash', output)
        self.assertNotIn('fixture-global-hash', output)
        other = self.render({'distro':'debian', 'root_login_mode':'enabled'}, global_root_hash='fixture-global-hash')
        self.assertNotIn('fixture-machine-hash', other)

    def test_disabled_mode_does_not_expose_a_previous_machine_hash_in_legacy_template(self):
        output = self.render({'distro':'debian', 'root_login_mode':'disabled', 'root_password_hash':'fixture-old-machine-hash'},
                             template='d-i passwd/root-login boolean false\nroot={{ROOT_PASSWORD_HASH}}\n')
        self.assertIn('root=\n', output)
        self.assertNotIn('fixture-old-machine-hash', output)

    def test_key_only_admin_has_locked_password_without_interactive_prompt(self):
        output = self.render({'distro':'debian', 'root_login_mode':'disabled',
                              'admin_user_login':'operator',
                              'admin_user_pubkeys':'ssh-ed25519 AAAA fixture@example.test'})
        self.assertIn('passwd/make-user boolean true', output)
        self.assertIn('passwd/user-password-crypted password !', output)
        self.assertIn('/home/operator/.ssh/authorized_keys', output)
        self.assertNotIn('useradd -m', output)

    def test_ssh_password_auth_matches_explicit_admin_access_method(self):
        template = '{{ACCOUNTS_BLOCK}}\n{{ADMIN_USER_BLOCK}}\n'
        key_only = self.render({'distro':'debian', 'root_login_mode':'disabled',
                                'admin_user_login':'operator',
                                'admin_user_pubkeys':'ssh-ed25519 AAAA fixture@example.test'}, template=template)
        self.assertIn('PasswordAuthentication no/', key_only)
        self.assertNotIn('PasswordAuthentication yes/', key_only)
        password = self.render({'distro':'debian', 'root_login_mode':'disabled',
                                'admin_user_login':'operator', 'admin_user_pwhash':'fixture-hash'}, template=template)
        self.assertIn('PasswordAuthentication yes/', password)
        self.assertIn('PermitRootLogin no/', password)

    def test_incompatible_legacy_account_templates_are_rejected_without_emitting_credentials(self):
        config = {'distro':'debian', 'root_login_mode':'disabled'}
        for template in [
            'd-i passwd/root-login boolean true\nd-i passwd/root-password-crypted password {{ROOT_PASSWORD_HASH}}\n',
            'd-i passwd/root-login boolean false\nd-i passwd/root-login boolean true\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/root-login boolean true\n',
            'd-i passwd/root-login boolean false\nd-i passwd/root-password-crypted password static-hash\n',
            'd-i passwd/root-password-crypted password {{ROOT_PASSWORD_HASH}}\n',
            '# {{ACCOUNTS_BLOCK}}\n# d-i passwd/root-login boolean false\n',
            'd-i passwd/root-login boolean false\nuser-setup passwd/root-password password plaintext\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/make-user boolean false\n',
            'd-i passwd/root-login boolean false\nd-i passwd/make-user boolean false\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/username string another-user\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/user-password-crypted password another-hash\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/user-password password plaintext\n',
        ]:
            with self.subTest(template=template):
                output = self.render(config, global_root_hash='fixture-global-hash', template=template, expected_status=422)
                self.assertIn('ERREUR', output)
                self.assertNotIn('fixture-global-hash', output)
                self.assertNotIn('static-hash', output)

    def test_compatible_custom_account_directives_are_preserved(self):
        config = {'distro':'debian', 'root_login_mode':'disabled', 'admin_user_login':'operator',
                  'admin_user_pubkeys':'ssh-ed25519 AAAA fixture@example.test'}
        template = ('{{ACCOUNTS_BLOCK}}\n'
                    'd-i passwd/make-user boolean true\n'
                    'd-i passwd/username string operator\n'
                    'd-i passwd/user-password-crypted password !\n'
                    '{{ADMIN_USER_BLOCK}}\n')
        output = self.render(config, template=template)
        self.assertIn('passwd/username string operator', output)
        self.assertIn('/home/operator/.ssh/authorized_keys', output)

    def test_enabled_root_also_rejects_make_user_override_of_dynamic_block(self):
        config = {'distro':'debian', 'root_login_mode':'enabled', 'root_password_hash':'fixture-machine-hash'}
        for directive in ['passwd/make-user boolean true', 'passwd/root-login boolean false',
                          'passwd/root-password-crypted password other-hash']:
            with self.subTest(directive=directive):
                output = self.render(config, template='{{ACCOUNTS_BLOCK}}\nd-i ' + directive + '\n', expected_status=422)
                self.assertIn('ERREUR', output)
                self.assertNotIn('fixture-machine-hash', output)

    def test_legacy_explicit_root_mode_with_machine_hash_is_preserved(self):
        template = 'd-i passwd/root-login boolean true\nd-i passwd/root-password-crypted password {{ROOT_PASSWORD_HASH}}\n'
        output = self.render({'distro':'debian', 'root_login_mode':'enabled', 'root_password_hash':'fixture-machine-hash'},
                             template=template)
        self.assertIn('passwd/root-login boolean true', output)
        self.assertIn('passwd/root-password-crypted password fixture-machine-hash', output)

    def test_enabled_legacy_template_requires_the_machine_root_hash_placeholder(self):
        config = {'distro':'debian', 'root_login_mode':'enabled', 'root_password_hash':'fixture-machine-hash'}
        for template in [
            'd-i passwd/root-login boolean true\nd-i passwd/root-password-crypted password static-shared-hash\n',
            'd-i passwd/root-login boolean true\n',
            'd-i passwd/root-login boolean false\nd-i passwd/root-password-crypted password {{ROOT_PASSWORD_HASH}}\n',
            '{{ACCOUNTS_BLOCK}}\nd-i passwd/root-password-crypted password fixture-machine-hash\n',
            'd-i passwd/root-password-crypted password {{ROOT_PASSWORD_HASH}}\nd-i passwd/root-password-crypted password static-shared-hash\n',
        ]:
            with self.subTest(template=template):
                output = self.render(config, template=template, expected_status=422)
                self.assertIn('ERREUR', output)
                self.assertNotIn('fixture-machine-hash', output)
                self.assertNotIn('static-shared-hash', output)

    def test_legacy_override_cannot_change_partition_or_lv_reserve(self):
        config = {'distro':'debian','partition_mode':'custom','root_login_mode':'disabled',
            'partition_custom_json':json.dumps({'target_disks':['/dev/sda'], 'disks':[{'path':'/dev/sda','partitions':[
                {'id':'sda1','fs':'ext4','mount':'/boot','size_mb':1024,'reserved_pct':0},
                {'id':'sda2','fs':'xfs','mount':'/data','size_mb':1024,'reserved_pct':9},
                {'id':'sda3','fs':'lvm-pv','fill':True}]}],
                'vgs':[{'name':'sys','pvs':['sda3']}], 'logical_volumes':[
                    {'vg':'sys','name':'root','fs':'ext4','mount':'/','size_mb':4096,'reserved_pct':2},
                    {'vg':'sys','name':'var','fs':'ext4','mount':'/var','fill':True,'reserved_pct':None}], 'raid_arrays':[]}),
            'advanced':{'post_install':{'tune2fs_reserved':[{'mount':'/boot','reserved_pct':8}]}}}
        output = self.render(config)
        self.assertIn('/boot:0', output)
        self.assertIn('/:2', output)
        self.assertNotIn('/boot:8', output)
        self.assertNotIn('/data:9', output)
        self.assertNotIn('/var:', output)
        self.assertIn('grub-installer/with_other_os boolean false', output)
        self.assertIn('passwd/root-login boolean false', output)

    def test_raid_members_use_kernel_partition_paths_for_nvme_and_sata(self):
        for disks, expected in [
            (['/dev/nvme0n1', '/dev/nvme1n1'], '/dev/nvme0n1p1#/dev/nvme1n1p1'),
            (['/dev/sda', '/dev/sdb'], '/dev/sda1#/dev/sdb1'),
        ]:
            with self.subTest(disks=disks):
                plan = {'target_disks':disks, 'disks':[
                    {'path':path,'partitions':[{'id':f'member{i}','fs':'raid-member','fill':True}]}
                    for i,path in enumerate(disks)],
                    'raid_arrays':[{'name':'md0','level':1,'members':['member0','member1'],'spares':[],'is_pv':True}],
                    'vgs':[{'name':'sys','pvs':['md0']}],
                    'logical_volumes':[{'vg':'sys','name':'root','fs':'ext4','mount':'/','fill':True}]}
                output = self.render({'distro':'debian','partition_mode':'custom','partition_custom_json':json.dumps(plan)})
                self.assertIn(expected, output)

    def test_explicit_grub_choice_is_not_replaced_by_default(self):
        output = self.render({'distro':'debian','partition_mode':'default','advanced':{'bootloader':{'with_other_os':True}}})
        self.assertIn('grub-installer/with_other_os boolean true', output)
        self.assertNotIn('tune2fs -m', output)

    BOND_MACS = ('02:88:77:66:55:05', '02:88:77:66:55:06')

    def bond_config(self, primary=1):
        static = {'ip':'192.0.2.15','cidr':23,'gateway':'192.0.2.1','dns':['192.0.2.1']}
        nics = [
            {'match_by':'mac','match':{'mac':self.BOND_MACS[0]},'mode':'dhcp','bond':{'role':'slave','name':'bond0','mode':'active-backup'}},
            {'match_by':'mac','match':{'mac':self.BOND_MACS[1]},'mode':'static','static':static,'bond':{'role':'master','name':'bond0','mode':'active-backup'}},
        ]
        return {'distro':'debian','network':{'interfaces':nics,'primary':primary}}

    def bond_script(self, output):
        payloads = re.findall(r"echo (\S+) \| base64 -d > /tmp/pxe-bond\.sh", output)
        self.assertEqual(len(payloads), 1)
        return base64.b64decode(payloads[0]).decode()

    def run_bond_script(self, script, dhcp_interfaces):
        """Exécute le script late_command sur un faux /etc/network et un faux `ip`."""
        with tempfile.TemporaryDirectory(prefix='pxe-bond-') as tmp:
            root = Path(tmp)
            (root / 'etc/network/interfaces.d').mkdir(parents=True)
            (root / 'etc/network/interfaces').write_text(dhcp_interfaces)
            (root / 'bin').mkdir()
            fake_ip = root / 'bin/ip'
            fake_ip.write_text("#!/bin/sh\nprintf 'enp1s0 UP %s <BROADCAST>\\nenp2s0 UP %s <BROADCAST>\\n' " + " ".join(self.BOND_MACS) + "\n")
            fake_ip.chmod(0o755)
            script = script.replace('/etc/network', f'{root}/etc/network')
            subprocess.run(['/bin/bash', '-c', script], check=True, env={'PATH': f'{root}/bin:/usr/bin:/bin'})
            return ((root / 'etc/network/interfaces').read_text(),
                    (root / 'etc/network/interfaces.d/pxe-bond-bond0').read_text())

    def test_bond_generates_native_ifupdown_stanza_and_removes_installer_iface(self):
        output = self.render(self.bond_config())
        self.assertRegex(output, r'd-i pkgsel/include string .*\bifenslave\b')
        self.assertIn('d-i netcfg/get_ipaddress string 192.0.2.15', output)
        installer_interfaces = (
            "source /etc/network/interfaces.d/*\n\nauto lo\niface lo inet loopback\n\n"
            "# The primary network interface\nallow-hotplug enp2s0\niface enp2s0 inet static\n"
            "\taddress 192.0.2.15\n\tnetmask 255.255.254.0\n\tgateway 192.0.2.1\n")
        interfaces, bond = self.run_bond_script(self.bond_script(output), installer_interfaces)
        self.assertNotIn('enp2s0', interfaces)
        self.assertIn('iface lo inet loopback', interfaces)
        self.assertIn('iface enp1s0 inet manual', bond)
        self.assertIn('iface enp2s0 inet manual', bond)
        self.assertIn('iface bond0 inet static', bond)
        self.assertIn('address 192.0.2.15', bond)
        self.assertIn('netmask 255.255.254.0', bond)
        self.assertIn('bond-mode active-backup', bond)
        self.assertIn('bond-miimon 100', bond)
        self.assertIn('bond-primary enp2s0', bond)
        self.assertIn('bond-primary-reselect always', bond)
        self.assertIn('bond-updelay 5000', bond)
        self.assertNotIn('hwaddress', bond)
        # Primaire (index 1 → enp2s0) listée en premier : le bond hérite de sa MAC.
        self.assertIn('bond-slaves enp2s0 enp1s0', bond)

    def test_bond_without_two_resolvable_macs_emits_nothing(self):
        config = self.bond_config()
        del config['network']['interfaces'][0]['match']
        output = self.render(config)
        self.assertNotIn('pxe-bond', output)

    def test_preferred_slave_can_differ_from_ip_master(self):
        output = self.render(self.bond_config(primary=0))
        self.assertIn('netcfg/choose_interface select ' + self.BOND_MACS[0], output)
        self.assertIn('netcfg/get_ipaddress string 192.0.2.15', output)
        bond = self.run_bond_script(self.bond_script(output), 'auto lo\niface lo inet loopback\n')[1]
        self.assertIn('bond-primary enp1s0', bond)
        self.assertIn('bond-slaves enp1s0 enp2s0', bond)

    def test_bond_mode_outside_allowlist_falls_back_to_active_backup(self):
        config = self.bond_config()
        config['network']['interfaces'][1]['bond']['mode'] = 'active-backup\n    post-up rm -rf /'
        bond = self.run_bond_script(self.bond_script(self.render(config)), 'auto lo\niface lo inet loopback\n')[1]
        self.assertIn('bond-mode active-backup\n', bond)
        self.assertNotIn('rm -rf', bond)

    def test_link_readiness_settings_only_apply_to_active_backup(self):
        config = self.bond_config()
        config['network']['interfaces'][1]['bond']['mode'] = '802.3ad'
        bond = self.run_bond_script(self.bond_script(self.render(config)), 'auto lo\niface lo inet loopback\n')[1]
        self.assertIn('bond-mode 802.3ad', bond)
        self.assertNotIn('bond-primary ', bond)
        self.assertNotIn('bond-primary-reselect', bond)
        self.assertNotIn('bond-updelay', bond)

    def test_secondary_nic_without_mac_does_not_comment_out_rest_of_late_command(self):
        # d-i joint les lignes du late_command : un `#` neutraliserait tout ce qui suit.
        nics = [{'match_by':'mac','match':{'mac':'02:88:77:66:55:05'},'mode':'dhcp'}, {'match_by':'primary','mode':'dhcp'}]
        output = self.render({'distro':'debian','network':{'interfaces':nics,'primary':0}},
                             running_action_token='a' * 64)
        self.assertNotIn('# DEBUG', output)
        self.assertIn("in-target /bin/echo 'NIC secondaire #2 ignoree (match.mac absent)' ; \\\n", output)
        self.assertIn('/api/install-end', output)

if __name__ == '__main__': unittest.main()
