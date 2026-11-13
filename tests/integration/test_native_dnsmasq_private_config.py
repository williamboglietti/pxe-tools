#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""The native dnsmasq renderer must publish outside the web-writable tree."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(os.geteuid() == 0 and Path('/.dockerenv').exists(),
                     'requires a disposable root container')
class NativeDnsmasqPrivateConfig(unittest.TestCase):
    def test_native_config_path_and_inert_render_are_private(self):
        env_lines = (ROOT / 'packaging/native/default.env').read_text().splitlines()
        values = dict(line.split('=', 1) for line in env_lines if '=' in line)
        path = values['PXE_DNSMASQ_CONFIG']
        self.assertEqual(path, '/var/lib/pxe-tools-native/rendered/dnsmasq.conf')
        unit = (ROOT / 'packaging/native/pxe-tools-dnsmasq.service').read_text()
        self.assertIn('--conf-file=' + path, unit)
        with tempfile.TemporaryDirectory(prefix='pxe-dnsmasq-private-') as temporary:
            root = Path(temporary)
            root.chmod(0o755)
            rendered = root / 'rendered'
            rendered.mkdir(mode=0o755)
            output = rendered / 'dnsmasq.conf'
            env = dict(os.environ, PXE_APP_DIR=str(ROOT / 'app'),
                       PXE_DNSMASQ_CONFIG=str(output), PXE_DB_PATH=str(root / 'absent.sqlite'),
                       PXE_INTERFACE='', DHCP_MODE='tftp_only')
            result = subprocess.run(['/bin/sh', str(ROOT / 'docker/pxe-tools/init/render-dnsmasq-conf.sh')],
                                    env=env, capture_output=True, text=True, timeout=15)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            before = output.read_bytes()
            self.assertIn(b'no-dhcp-interface=*', before)
            attempt = subprocess.run(['/usr/sbin/runuser', '-u', 'www-data', '--',
                                      'python3', '-c', 'import os,sys; os.unlink(sys.argv[1])',
                                      str(output)], capture_output=True, text=True, timeout=10)
            self.assertNotEqual(attempt.returncode, 0)
            self.assertIn('PermissionError', attempt.stderr)
            self.assertEqual(output.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
