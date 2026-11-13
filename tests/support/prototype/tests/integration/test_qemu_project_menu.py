#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Boot the project BIOS menu using verified Debian packages in an isolated VM.

This opt-in test uses QEMU's isolated user network, its built-in TFTP server and
one explicitly forwarded, read-only HTTP fixture process. No host disk or LAN
interface is attached to the guest. It does not install an operating system.
"""
from __future__ import annotations

import argparse
import hashlib
import lzma
import os
from pathlib import Path
import re
import selectors
import shlex
import shutil
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "app"))
from artifacts.debian_netboot import verify_debian_netboot, _clearsigned_payload  # noqa: E402


def package_checks(release: bytes, index: bytes, packages: dict[str, Path]) -> None:
    lines = _clearsigned_payload(release)
    entries = []
    inside = False
    for line in lines:
        if line == "SHA256:":
            inside = True
            continue
        if inside and not line.startswith(" "):
            inside = False
        if inside:
            match = re.fullmatch(r"\s+([a-fA-F0-9]{64})\s+\d+\s+(\S+)", line)
            if match and match.group(2) == "main/binary-amd64/Packages.xz":
                entries.append(match.group(1).lower())
    if len(entries) != 1 or hashlib.sha256(index).hexdigest() != entries[0]:
        raise ValueError("package index does not match signed release")
    # The source was bounded before decompression, and this is a disposable test.
    text = lzma.decompress(index, memlimit=256 * 1024 * 1024).decode("utf-8")
    for name, path in packages.items():
        found = []
        for section in text.split("\n\n"):
            if not section.startswith("Package: " + name + "\n"):
                continue
            fields = dict(line.split(": ", 1) for line in section.splitlines() if ": " in line)
            if fields.get("Architecture") == "all" and re.fullmatch(r"[a-fA-F0-9]{64}", fields.get("SHA256", "")):
                found.append(fields["SHA256"].lower())
        if len(found) != 1 or hashlib.sha256(path.read_bytes()).hexdigest() != found[0]:
            raise ValueError("package checksum missing, ambiguous, or different")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("kernel", "initrd", "sums", "release", "keyring", "index", "pxelinux_deb", "syslinux_deb"):
        parser.add_argument("--" + name.replace("_", "-"), type=Path, required=True)
    parser.add_argument("--timeout", type=int, default=180)
    args = parser.parse_args()
    if not 30 <= args.timeout <= 900:
        parser.error("timeout must be between 30 and 900 seconds")
    qemu = shutil.which("qemu-system-x86_64")
    dpkg = shutil.which("dpkg-deb")
    php = shutil.which("php")
    if not all((qemu, dpkg, php)):
        parser.error("qemu-system-x86_64, dpkg-deb and php are required")
    with tempfile.TemporaryDirectory(prefix="pxe-menu-test-") as directory:
        root = Path(directory)
        tftp, http, extract = root / "tftp", root / "http", root / "extract"
        for folder in (tftp, http, extract):
            folder.mkdir()
        for source, target in ((args.kernel, http / "linux"), (args.initrd, http / "initrd.gz"),
                               (args.sums, root / "SHA256SUMS"), (args.release, root / "InRelease"),
                               (args.index, root / "Packages.xz"), (args.pxelinux_deb, root / "pxelinux.deb"),
                               (args.syslinux_deb, root / "syslinux-common.deb")):
            shutil.copyfile(source, target)
        verify_debian_netboot(http / "linux", http / "initrd.gz", root / "SHA256SUMS", root / "InRelease", args.keyring)
        index = (root / "Packages.xz").read_bytes()
        if len(index) > 40 * 1024 * 1024:
            raise ValueError("package index too large")
        package_checks((root / "InRelease").read_bytes(), index,
                       {"pxelinux": root / "pxelinux.deb", "syslinux-common": root / "syslinux-common.deb"})
        for package in (root / "pxelinux.deb", root / "syslinux-common.deb"):
            subprocess.run([dpkg, "-x", str(package), str(extract)], check=True, stdout=subprocess.DEVNULL)
        for src, name in ((extract / "usr/lib/PXELINUX/lpxelinux.0", "lpxelinux.0"),
                          *[(extract / "usr/lib/syslinux/modules/bios" / name, name)
                            for name in ("ldlinux.c32", "menu.c32", "libcom32.c32", "libutil.c32")]):
            shutil.copyfile(src, tftp / name)
        generator = ("require $argv[1]; echo Admin\\Pxe\\PxelinuxMenu::renderManualInstaller("
                     "'10.0.2.100','/netboot/debian/linux','/netboot/debian/initrd.gz');")
        menu = subprocess.check_output([php, "-r", generator, str(ROOT / "app/src/Pxe/PxelinuxMenu.php")], text=True)
        if "DEFAULT local\n" not in menu:
            raise ValueError("unexpected default boot selection")
        # Serial access and a harmless kernel marker are only for the test.
        menu = "SERIAL 0 115200\n" + menu.replace("APPEND initrd=", "APPEND console=ttyS0,115200n8 pxe_test=project_menu initrd=")
        (tftp / "pxelinux.cfg").mkdir()
        (tftp / "pxelinux.cfg/default").write_text(menu, encoding="ascii")
        fixture = ROOT / "tests/integration/qemu_http_fixture.py"
        command = f"{shlex.quote(sys.executable)} -u {shlex.quote(str(fixture))}"
        net = ("user,id=net0,restrict=on,tftp=" + str(tftp).replace(",", ",,") +
               ",bootfile=lpxelinux.0,guestfwd=tcp:10.0.2.100:80-cmd:" + command.replace(",", ",,"))
        env = dict(os.environ, PXE_TEST_HTTP_ROOT=str(http), PYTHONDONTWRITEBYTECODE="1")
        process = subprocess.Popen([qemu, "-machine", "pc,accel=tcg", "-m", "768", "-smp", "1",
                                    "-display", "none", "-serial", "stdio", "-monitor", "none", "-no-reboot",
                                    "-boot", "n", "-netdev", net, "-device", "e1000,netdev=net0"],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=env)
        try:
            assert process.stdout is not None and process.stdin is not None
            selector = selectors.DefaultSelector()
            selector.register(process.stdout, selectors.EVENT_READ)
            seen = bytearray()
            selected = False
            deadline = time.monotonic() + args.timeout
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    break
                if selector.select(timeout=1):
                    data = os.read(process.stdout.fileno(), 65536)
                    if not data:
                        break
                    seen.extend(data)
                    if not selected and b"Debian installer (manual)" in seen:
                        # The local-disk entry is the configured default; choose installer explicitly.
                        process.stdin.write(b"\x1b[A\r")
                        process.stdin.flush()
                        selected = True
                    if selected and b"pxe_test=project_menu" in seen and b"Linux version" in seen:
                        print("Isolated BIOS project menu: manual selection and HTTP kernel boot reached")
                        return 0
            print("Project menu test did not reach the expected kernel marker", file=sys.stderr)
            print(seen[-5000:].decode("utf-8", errors="replace"), file=sys.stderr)
            return 1
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


if __name__ == "__main__":
    raise SystemExit(main())
