#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Fetch verified Debian inputs and exercise dnsmasq between isolated QEMU VMs.

Requires an independently authenticated Debian archive keyring. Downloads and
VM files are temporary. The native-package test uses a disposable virtual disk;
no test bridges a host disk or network interface into a guest.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import lzma
from multiprocessing import get_context
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "app"))
from artifacts.debian_netboot import verify_debian_netboot  # noqa: E402
from test_qemu_dnsmasq_tftp import PACKAGES, package_records  # noqa: E402
from debian_runtime_packages import BACKUP_PACKAGES, DISCOVERY_PACKAGES, RUNTIME_PACKAGES, runtime_records  # noqa: E402
from debian_disk_modules import disk_module_record  # noqa: E402


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args):
        raise ValueError("unexpected Debian mirror redirect")


def prepare_inputs(args: argparse.Namespace, root: Path) -> None:
    """Download in a short-lived process so decompressed package indexes release RAM."""
    debs = root / "debs"
    opener = build_opener(ProxyHandler({}), NoRedirect())
    base = "https://deb.debian.org/debian/dists/trixie/"
    installer = base + "main/installer-amd64/current/images/"
    inputs = {"InRelease": base + "InRelease", "SHA256SUMS": installer + "SHA256SUMS",
              "Packages.xz": base + "main/binary-amd64/Packages.xz",
              "linux": installer + "netboot/debian-installer/amd64/linux",
              "initrd.gz": installer + "netboot/debian-installer/amd64/initrd.gz"}

    def fetch(item):
        path, url, limit = item
        with opener.open(Request(url, headers={"User-Agent": "PXE-Tools-test/0.1"}), timeout=60) as response, path.open("xb") as output:
            if response.geturl() != url:
                raise ValueError("unexpected download location")
            total = 0
            while chunk := response.read(1024 * 1024):
                total += len(chunk)
                if total > limit:
                    raise ValueError("download exceeds limit")
                output.write(chunk)

    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(fetch, ((root / name, url, 200 * 1024 * 1024) for name, url in inputs.items())))
    # Authenticate Release and netboot pair before using metadata to fetch packages.
    verify_debian_netboot(root / "linux", root / "initrd.gz", root / "SHA256SUMS", root / "InRelease", args.keyring)
    records = package_records((root / "InRelease").read_bytes(), (root / "Packages.xz").read_bytes())
    downloads = []
    for name in PACKAGES:
        path = records[name].get("Filename", "")
        if not re.fullmatch(r"pool/[A-Za-z0-9_+./~%-]+\.deb", path) or ".." in path.split("/"):
            raise ValueError("invalid Debian package path")
        downloads.append((debs / (name + ".deb"), "https://deb.debian.org/debian/" + path, 32 * 1024 * 1024))
    if args.runtime_manager:
        optional = BACKUP_PACKAGES if args.backup_guest else (DISCOVERY_PACKAGES if args.discovery_guest or args.discovery_pxe else ())
        extra_records = runtime_records((root / "InRelease").read_bytes(), (root / "Packages.xz").read_bytes(),
                                        RUNTIME_PACKAGES + optional)
        for name, record in extra_records.items():
            path = record.get("Filename", "")
            if not re.fullmatch(r"pool/[A-Za-z0-9_+./~%-]+\.deb", path) or ".." in path.split("/"):
                raise ValueError("invalid Debian package path")
            downloads.append((debs / (name + ".deb"), "https://deb.debian.org/debian/" + path, 32 * 1024 * 1024))
    if args.disk_guard:
        installer_index = root / 'installer-Packages.xz'
        fetch((installer_index, base + 'main/debian-installer/binary-amd64/Packages.xz', 16 * 1024 * 1024))
        record = disk_module_record((root / 'InRelease').read_bytes(), installer_index.read_bytes(), root / 'initrd.gz')
        downloads.append((root / 'disk-modules.udeb', 'https://deb.debian.org/debian/' + record['Filename'], 16 * 1024 * 1024))
    if args.proxy_boot or args.runtime_manager or args.partman_uefi:
        text = lzma.decompress((root / "Packages.xz").read_bytes(), memlimit=256 * 1024 * 1024).decode("utf-8")
        extra = [("pxelinux", "all"), ("syslinux-common", "all"), ("grub-efi-amd64-signed", "amd64")] if (args.proxy_boot or args.runtime_manager) else []
        if args.uefi or args.partman_uefi:
            extra += [("ovmf", "all")]
        for name, architecture in extra:
            entries = []
            for section in text.split("\n\n"):
                if section.startswith("Package: " + name + "\n"):
                    fields = dict(line.split(": ", 1) for line in section.splitlines() if ": " in line)
                    if fields.get("Architecture") == architecture:
                        entries.append(fields)
            if len(entries) != 1:
                raise ValueError("missing or ambiguous bootloader package")
            path = entries[0].get("Filename", "")
            if not re.fullmatch(r"pool/[A-Za-z0-9_+./~%-]+\.deb", path) or ".." in path.split("/"):
                raise ValueError("invalid Debian package path")
            downloads.append((root / (name + ".deb"), "https://deb.debian.org/debian/" + path, 32 * 1024 * 1024))
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(fetch, downloads))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--keyring", type=Path, required=True)
    parser.add_argument("--timeout", type=int, default=180)
    parser.add_argument("--guest-memory-mib", type=int, help="RAM for the disposable real-partman guest")
    parser.add_argument("--proxy-boot", action="store_true", help="exercise proxy DHCP and BIOS boot in three isolated VMs")
    parser.add_argument("--uefi", action="store_true", help="exercise UEFI proxy boot instead of BIOS")
    parser.add_argument("--download-bundle", action="store_true", help="use the project downloader for the boot bundle")
    parser.add_argument("--runtime-manager", action="store_true", help="exercise the actual selected-bundle runtime in two isolated VMs")
    parser.add_argument("--manager-proxy", action="store_true", help="use proxy mode for the runtime-manager test")
    parser.add_argument("--manager-proxy-boot", action="store_true", help="test manager proxy with separate DHCP and BIOS PXE client")
    parser.add_argument("--manager-kernel-boot", action="store_true", help="select installer and observe kernel through a serial console")
    parser.add_argument("--manager-saved-network", action="store_true", help="start manager from saved desired network settings")
    parser.add_argument("--backup-guest", action="store_true", help="test encrypted database backup in a disposable Debian VM")
    parser.add_argument("--discovery-guest", action="store_true", help="test HTTPS discovery in a disposable Debian VM")
    parser.add_argument("--discovery-pxe", action="store_true", help="test experimental BIOS discovery on an isolated LAN")
    parser.add_argument("--disk-guard", action="store_true", help="test disk identity checks using read-only virtual disks")
    parser.add_argument("--real-partman", action="store_true", help="run actual Debian Installer through the identity gate")
    parser.add_argument("--partman-commit", action="store_true", help="exercise the later partman commit gate")
    parser.add_argument("--partman-cross-target", action="store_true", help="test refusal of changes on another disk")
    parser.add_argument("--partman-two-disks", action="store_true", help="test an unchanged second disk")
    parser.add_argument("--partman-positive-only", action="store_true", help="run only the matching-identity case")
    parser.add_argument("--partman-recipe-fixture", action="store_true", help="exercise a generated standard recipe before writing")
    parser.add_argument("--partman-disposable-write", action="store_true", help="write only a newly created disposable virtual disk")
    parser.add_argument("--partman-fixed-size-experiment", action="store_true", help="try a test-only partman size change")
    parser.add_argument("--partman-fixture-fill", action="store_true", help="use the remaining-space test recipe")
    parser.add_argument("--partman-fixture-mismatch-write", action="store_true", help="verify identity refusal preserves a blank writable disk")
    parser.add_argument("--partman-uefi", action="store_true", help="run the real partman fixture with UEFI firmware")
    parser.add_argument("--partman-fixture-lvm", action="store_true", help="observe pre-commit writes for an LVM identity refusal")
    parser.add_argument("--partman-debug-scope", action="store_true", help="trace the scope hook in the disposable VM")
    parser.add_argument("--native-package", action="store_true", help="test the development package on a Debian cloud VM")
    parser.add_argument("--docker-console", action="store_true", help="test the development Compose console in a Debian VM")
    parser.add_argument("--cloud-image", type=Path, help="absolute path to a Debian cloud qcow2 image")
    parser.add_argument("--cloud-sha512", help="independently obtained SHA-512 of that cloud image")
    parser.add_argument("--docker-base", type=Path, help="absolute path to a local debian:13-slim Docker archive")
    parser.add_argument("--docker-base-sha256", help="independently obtained SHA-256 of that Docker archive")
    parser.add_argument("--debug-screen", type=Path, help="save the disposable client screen on failure")
    args = parser.parse_args()
    image_vm = args.native_package or args.docker_console
    if image_vm and (args.native_package and args.docker_console or args.runtime_manager or args.disk_guard
                     or args.proxy_boot or args.uefi or args.download_bundle or args.debug_screen is not None):
        parser.error("cloud VM tests cannot be combined with other runtime modes")
    if ((image_vm and not (args.cloud_image and args.cloud_sha512))
            or (not image_vm and (args.cloud_image is not None or args.cloud_sha512 is not None))):
        parser.error("cloud VM tests require both --cloud-image and --cloud-sha512, and vice versa")
    if ((args.docker_console and not (args.docker_base and args.docker_base_sha256))
            or (not args.docker_console and (args.docker_base is not None or args.docker_base_sha256 is not None))):
        parser.error("--docker-console requires both --docker-base and --docker-base-sha256, and vice versa")
    if args.runtime_manager and (args.uefi or args.proxy_boot or args.download_bundle or args.debug_screen is not None):
        parser.error("--runtime-manager cannot be combined with other boot modes")
    if args.manager_proxy and not args.runtime_manager:
        parser.error("--manager-proxy requires --runtime-manager")
    if args.manager_proxy_boot and not (args.runtime_manager and args.manager_proxy):
        parser.error("--manager-proxy-boot requires --runtime-manager and --manager-proxy")
    if args.manager_kernel_boot and not args.manager_proxy_boot:
        parser.error("--manager-kernel-boot requires --manager-proxy-boot")
    if args.manager_saved_network and not args.runtime_manager:
        parser.error("--manager-saved-network requires --runtime-manager")
    if args.backup_guest and (not args.runtime_manager or args.manager_proxy or args.manager_proxy_boot or args.manager_kernel_boot or args.manager_saved_network):
        parser.error("--backup-guest requires only --runtime-manager")
    if args.discovery_guest and (not args.runtime_manager or args.backup_guest or args.manager_proxy or args.manager_proxy_boot or args.manager_kernel_boot or args.manager_saved_network):
        parser.error("--discovery-guest requires only --runtime-manager")
    if args.discovery_pxe and (not args.runtime_manager or args.discovery_guest or args.backup_guest or args.manager_proxy or args.manager_proxy_boot or args.manager_kernel_boot or args.manager_saved_network):
        parser.error("--discovery-pxe requires only --runtime-manager")
    if args.disk_guard and (args.runtime_manager or args.proxy_boot or args.uefi or args.download_bundle or args.debug_screen is not None):
        parser.error("--disk-guard cannot be combined with PXE or backup modes")
    if args.real_partman and not args.disk_guard:
        parser.error("--real-partman requires --disk-guard")
    if args.guest_memory_mib is not None and (not args.real_partman or not 256 <= args.guest_memory_mib <= 1024):
        parser.error("--guest-memory-mib requires --real-partman and must be between 256 and 1024")
    if args.partman_commit and not args.real_partman:
        parser.error("--partman-commit requires --real-partman")
    if args.partman_cross_target and not args.partman_commit:
        parser.error("--partman-cross-target requires --partman-commit")
    if args.partman_two_disks and not args.partman_commit:
        parser.error("--partman-two-disks requires --partman-commit")
    if args.partman_positive_only and (not args.partman_commit or args.partman_cross_target):
        parser.error("--partman-positive-only requires --partman-commit without --partman-cross-target")
    if args.partman_recipe_fixture and (not args.partman_positive_only or args.partman_two_disks):
        parser.error("--partman-recipe-fixture requires --partman-positive-only and one disk")
    if args.partman_disposable_write and not args.partman_recipe_fixture:
        parser.error("--partman-disposable-write requires --partman-recipe-fixture")
    if args.partman_fixed_size_experiment and not args.partman_disposable_write:
        parser.error("--partman-fixed-size-experiment requires --partman-disposable-write")
    if args.partman_fixture_fill and not args.partman_fixed_size_experiment:
        parser.error("--partman-fixture-fill requires --partman-fixed-size-experiment")
    if args.partman_fixture_mismatch_write and (not args.partman_fixed_size_experiment or args.partman_fixture_fill):
        parser.error("--partman-fixture-mismatch-write requires --partman-fixed-size-experiment without --partman-fixture-fill")
    if args.partman_uefi and (not args.partman_fixed_size_experiment or args.partman_fixture_mismatch_write or args.partman_fixture_fill):
        parser.error("--partman-uefi requires the fixed-size experiment and the standard positive fixture")
    if args.partman_fixture_lvm and (not args.partman_fixture_mismatch_write or args.partman_uefi):
        parser.error("--partman-fixture-lvm requires the BIOS writable identity-refusal fixture")
    if args.uefi:
        args.proxy_boot = True
    if args.debug_screen is not None and not args.proxy_boot:
        parser.error("--debug-screen requires --proxy-boot or --uefi")
    if args.download_bundle and not args.proxy_boot:
        parser.error("--download-bundle requires --proxy-boot or --uefi")
    with tempfile.TemporaryDirectory(prefix="pxe-dnsmasq-input-") as directory:
        root = Path(directory)
        debs = root / "debs"
        debs.mkdir()
        preparation = get_context("spawn").Process(target=prepare_inputs, args=(args, root))
        preparation.start()
        preparation.join()
        if preparation.exitcode != 0:
            raise RuntimeError("Debian input preparation failed")
        test = ("test_qemu_docker_console.py" if args.docker_console else
                "test_qemu_debian_package.py" if args.native_package else
                ("test_qemu_partman_flow.py" if args.real_partman else "test_qemu_disk_identity.py") if args.disk_guard else
                ("test_qemu_runtime_manager.py" if args.runtime_manager else ("test_qemu_proxy_boot.py" if args.proxy_boot else "test_qemu_dnsmasq_tftp.py")))
        command = [sys.executable, str(ROOT / "tests/integration" / test),
                   "--kernel", str(root / "linux"), "--initrd", str(root / "initrd.gz"),
                   "--sums", str(root / "SHA256SUMS"), "--release", str(root / "InRelease"),
                   "--keyring", str(args.keyring), "--index", str(root / "Packages.xz"),
                   "--deb-dir", str(debs), "--timeout", str(args.timeout)]
        if args.proxy_boot or args.runtime_manager:
            command += ["--pxelinux-deb", str(root / "pxelinux.deb"), "--syslinux-deb", str(root / "syslinux-common.deb"),
                        "--grub-deb", str(root / "grub-efi-amd64-signed.deb")]
        if args.uefi:
            command += ["--uefi", "--ovmf-deb", str(root / "ovmf.deb")]
        if args.download_bundle:
            command.append("--download-bundle")
        if args.manager_proxy:
            command.append("--proxy")
        if args.manager_proxy_boot:
            command.append("--proxy-boot")
        if args.manager_kernel_boot:
            command.append("--kernel-boot")
        if args.manager_saved_network:
            command.append("--saved-network")
        if args.backup_guest:
            command.append("--backup-guest")
        if args.discovery_guest:
            command.append("--discovery-guest")
        if args.discovery_pxe:
            command.append("--discovery-pxe")
        if args.disk_guard:
            command += ['--disk-index', str(root / 'installer-Packages.xz'), '--disk-modules', str(root / 'disk-modules.udeb')]
        if args.partman_commit:
            command.append('--commit-gate')
        if args.partman_cross_target:
            command.append('--cross-target')
        if args.partman_two_disks:
            command.append('--two-disks')
        if args.partman_positive_only:
            command.append('--positive-only')
        if args.partman_recipe_fixture:
            command.append('--recipe-fixture')
        if args.partman_disposable_write:
            command.append('--disposable-write')
        if args.partman_fixed_size_experiment:
            command.append('--fixed-size-experiment')
        if args.partman_fixture_fill:
            command.append('--fixture-fill')
        if args.partman_fixture_mismatch_write:
            command.append('--fixture-mismatch-write')
        if args.partman_uefi:
            command += ['--uefi', '--ovmf-deb', str(root / 'ovmf.deb')]
        if args.partman_fixture_lvm:
            command.append('--fixture-lvm')
        if args.partman_debug_scope:
            command.append('--debug-scope')
        if args.guest_memory_mib is not None:
            command += ['--guest-memory-mib', str(args.guest_memory_mib)]
        if image_vm:
            command += ['--cloud-image', str(args.cloud_image), '--cloud-sha512', args.cloud_sha512]
        if args.docker_console:
            command += ['--docker-base', str(args.docker_base), '--docker-base-sha256', args.docker_base_sha256]
        if args.debug_screen is not None:
            command += ["--debug-screen", str(args.debug_screen)]
        return subprocess.run(command, check=False).returncode


if __name__ == "__main__":
    raise SystemExit(main())
