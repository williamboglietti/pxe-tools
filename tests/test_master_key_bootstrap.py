"""Contrat du premier démarrage et de la conservation de master.key."""

from __future__ import annotations

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docker/pxe-tools/init/php-init.sh"


def run_init(temp_root: Path) -> subprocess.CompletedProcess[str]:
    script = SOURCE.read_text(encoding="utf-8")
    script = script.replace("/etc/pxe-tools", str(temp_root / "etc/pxe-tools"))
    script = script.replace("/var/lib/pxe-tools", str(temp_root / "var/lib/pxe-tools"))
    script = script.replace("/run/php", str(temp_root / "run/php"))
    target = temp_root / "php-init.sh"
    target.write_text(script, encoding="utf-8")
    fake_bin = temp_root / "bin"
    fake_bin.mkdir()
    fake_chown = fake_bin / "chown"
    fake_chown.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
    fake_chown.chmod(0o755)
    env = {
        "PATH": f"{fake_bin}:{os.environ.get('PATH', '/usr/bin:/bin')}",
        "HOME": str(temp_root),
        "LC_ALL": "C",
    }
    return subprocess.run(
        ["/bin/sh", str(target)],
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=10,
    )


class MasterKeyBootstrapTests(unittest.TestCase):
    def test_compose_mounts_configuration_in_named_volume(self):
        compose = (ROOT / "compose/docker-compose.yml").read_text(encoding="utf-8")
        self.assertIn("- pxe_conf:/etc/pxe-tools", compose)
        self.assertIn("- pxe_data:/var/lib/pxe-tools", compose)
        self.assertRegex(compose, r"(?m)^  pxe_conf: ?\{\}$")
        self.assertRegex(compose, r"(?m)^  pxe_data: ?\{\}$")
        self.assertNotIn("- /etc/pxe-tools:/etc/pxe-tools", compose)
        self.assertNotIn("- /var/lib/pxe-tools:/var/lib/pxe-tools", compose)

    def test_new_empty_install_generates_one_32_byte_key(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-new-") as directory:
            root = Path(directory)
            result = run_init(root)
            key = root / "etc/pxe-tools/master.key"
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue(key.is_file())
            self.assertEqual(len(key.read_bytes()), 32)
            self.assertEqual(key.stat().st_mode & 0o777, 0o440)

    def test_missing_key_with_existing_db_conf_fails_without_generating(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-conflict-") as directory:
            root = Path(directory)
            config = root / "etc/pxe-tools/db.conf"
            config.parent.mkdir(parents=True)
            config.write_text('{"driver":"sqlite"}\n', encoding="utf-8")
            result = run_init(root)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("restaurer ensemble pxe_conf et pxe_data", result.stderr)
            self.assertFalse((root / "etc/pxe-tools/master.key").exists())

    def test_missing_key_with_nonempty_sqlite_fails_without_generating(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-db-") as directory:
            root = Path(directory)
            database = root / "var/lib/pxe-tools/pxe-tools.sqlite"
            database.parent.mkdir(parents=True)
            database.write_bytes(b"existing database")
            result = run_init(root)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root / "etc/pxe-tools/master.key").exists())

    def test_existing_key_is_preserved(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-preserve-") as directory:
            root = Path(directory)
            key = root / "etc/pxe-tools/master.key"
            key.parent.mkdir(parents=True)
            original = bytes(range(32))
            key.write_bytes(original)
            result = run_init(root)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(key.read_bytes(), original)

    def test_wrong_length_key_fails_closed(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-invalid-") as directory:
            root = Path(directory)
            key = root / "etc/pxe-tools/master.key"
            key.parent.mkdir(parents=True)
            key.write_bytes(b"too short")
            result = run_init(root)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("exactement 32 octets", result.stderr)
            self.assertEqual(key.read_bytes(), b"too short")

    def test_symlink_master_key_fails_closed(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-symlink-") as directory:
            root = Path(directory)
            key = root / "etc/pxe-tools/master.key"
            key.parent.mkdir(parents=True)
            target = root / "outside.key"
            target.write_bytes(bytes(range(32)))
            key.symlink_to(target)
            result = run_init(root)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("lien symbolique", result.stderr)
            self.assertEqual(target.read_bytes(), bytes(range(32)))

    def test_zero_byte_sqlite_placeholder_does_not_block_new_install(self):
        with tempfile.TemporaryDirectory(prefix="pxe-master-key-empty-db-") as directory:
            root = Path(directory)
            database = root / "var/lib/pxe-tools/pxe-tools.sqlite"
            database.parent.mkdir(parents=True)
            database.touch()
            result = run_init(root)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(len((root / "etc/pxe-tools/master.key").read_bytes()), 32)
            self.assertTrue(database.is_file())
            self.assertEqual(database.read_bytes(), b"")


if __name__ == "__main__":
    unittest.main()
