#!/usr/bin/env python3
# SPDX-License-Identifier: LGPL-3.0-only
"""Exercise the generated dnsmasq TFTP-only config between two isolated VMs.

Inputs must come from one Debian 13 amd64 archive snapshot and an independently
trusted keyring. Signatures and package hashes are checked before any guest code
is run. The guests have no disks, host network interfaces, or network forwards.
"""
from __future__ import annotations

import argparse
import gzip
import hashlib
import lzma
import os
from pathlib import Path
import re
import selectors
import shutil
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "app"))
from artifacts.debian_netboot import _clearsigned_payload, verify_debian_netboot  # noqa: E402

PACKAGES = ("busybox-static", "dnsmasq-base", "libc6", "libdbus-1-3", "libgmp10",
            "libhogweed6t64", "libidn2-0", "libnetfilter-conntrack3", "libnettle8t64",
            "libnftables1", "libgcc-s1", "libsystemd0", "libunistring5", "libmnl0",
            "libnfnetlink0", "libjansson4", "libnftnl11", "libxtables12", "gcc-14-base", "libcap2")


def package_records(release: bytes, index: bytes) -> dict[str, dict[str, str]]:
    """Validate the index against an already authenticated release and return required records."""
    matches, inside = [], False
    for line in _clearsigned_payload(release):
        if line == "SHA256:":
            inside = True
            continue
        if inside and not line.startswith(" "):
            inside = False
        if inside:
            match = re.fullmatch(r"\s+([a-fA-F0-9]{64})\s+\d+\s+(\S+)", line)
            if match and match.group(2) == "main/binary-amd64/Packages.xz":
                matches.append(match.group(1).lower())
    if len(matches) != 1 or hashlib.sha256(index).hexdigest() != matches[0]:
        raise ValueError("package index does not match signed release")
    text = lzma.decompress(index, memlimit=256 * 1024 * 1024).decode("utf-8")
    sections = {}
    for section in text.split("\n\n"):
        if not section.startswith("Package: "):
            continue
        fields = dict(line.split(": ", 1) for line in section.splitlines() if ": " in line)
        if fields.get("Package") in PACKAGES and fields.get("Architecture") == "amd64":
            sections.setdefault(fields["Package"], []).append(fields)
    if any(len(sections.get(name, [])) != 1 for name in PACKAGES):
        raise ValueError("missing or ambiguous Debian package")
    return {name: sections[name][0] for name in PACKAGES}


def verify_packages(release: bytes, index: bytes, directory: Path) -> None:
    records = package_records(release, index)
    for name in PACKAGES:
        entries = [records[name]]
        path = directory / (name + ".deb")
        expected = entries[0].get("SHA256", "")
        if not re.fullmatch(r"[a-fA-F0-9]{64}", expected) or path.stat().st_size > 32 * 1024 * 1024:
            raise ValueError("invalid package metadata")
        if hashlib.sha256(path.read_bytes()).hexdigest() != expected.lower():
            raise ValueError("package checksum mismatch")


def build_overlay(extract: Path, module: bytes, config: str, *, server: bool, target: Path) -> None:
    root = target.parent / ("overlay-server" if server else "overlay-client")
    root.mkdir()
    # Only binaries and shared libraries needed by this disposable guest.
    for relative in ("usr/bin/busybox", "usr/sbin/dnsmasq"):
        source = extract / relative
        dest = root / relative
        dest.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, dest)
    for relative in ("usr/lib/x86_64-linux-gnu", "usr/lib64"):
        source = extract / relative
        if source.exists():
            shutil.copytree(source, root / relative, symlinks=True, dirs_exist_ok=True)
    (root / "etc").mkdir(exist_ok=True)
    (root / "etc/passwd").write_text("root:x:0:0:root:/root:/bin/sh\nnobody:x:65534:65534:nobody:/:/bin/sh\n", encoding="ascii")
    (root / "etc/group").write_text("root:x:0:\nnogroup:x:65534:\n", encoding="ascii")
    (root / "etc/pxe-test.conf").write_text(config, encoding="ascii")
    (root / "e1000.ko").write_bytes(module)
    common = """#!/bin/sh
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
/usr/bin/busybox --install -s /usr/bin
mount -t proc proc /proc
mount -t sysfs sysfs /sys
mount -t devtmpfs devtmpfs /dev
mkdir -p /run /tmp /srv/tftp
insmod /e1000.ko || { echo PXE_TEST_MODULE_FAILED; exec sleep 120; }
ip link set lo up
ip link set eth0 up
"""
    if server:
        service = root / 'opt/pxe-tools/serve_pxe.sh'
        service.parent.mkdir(parents=True)
        shutil.copyfile(ROOT / 'app/service/serve_pxe.sh', service)
        body = """ip addr add 192.0.2.1/24 dev eth0
echo PXE_TFTP_FIXTURE > /srv/tftp/probe.txt
mkdir -p /srv/http
echo PXE_HTTP_FIXTURE > /srv/http/probe.txt
if /bin/sh /opt/pxe-tools/serve_pxe.sh tftp eth0 192.0.2.1 /srv/tftp /srv/http /etc/pxe-test.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 120
fi
cp /etc/pxe-test.conf /etc/pxe-bad.conf
echo 'dhcp-range=192.0.2.50,192.0.2.60,255.255.255.0,1h' >> /etc/pxe-bad.conf
if /bin/sh /opt/pxe-tools/serve_pxe.sh --enable tftp eth0 192.0.2.1 /srv/tftp /srv/http /etc/pxe-bad.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 120
fi
if /bin/sh /opt/pxe-tools/serve_pxe.sh --enable proxy eth0 192.0.2.1 /srv/tftp /srv/http /etc/pxe-test.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 120
fi
if /bin/sh /opt/pxe-tools/serve_pxe.sh --enable tftp eth0 192.0.2.9 /srv/tftp /srv/http /etc/pxe-test.conf; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 120
fi
# A port conflict must leave the pre-existing listener alone and clean up the
# dnsmasq process started by the unsuccessful attempt.
/usr/bin/busybox httpd -f -p 192.0.2.1:80 -h /srv/http &
occupied=$!
sleep 1
kill -0 "$occupied" || { echo PXE_TEST_POLICY_FAILED; exec sleep 120; }
/bin/sh /opt/pxe-tools/serve_pxe.sh --enable tftp eth0 192.0.2.1 /srv/tftp /srv/http /etc/pxe-test.conf &
conflict=$!
sleep 4
if kill -0 "$conflict" 2>/dev/null || ! kill -0 "$occupied" 2>/dev/null || pidof dnsmasq >/dev/null 2>&1; then
  echo PXE_TEST_POLICY_FAILED; exec sleep 120
fi
wait "$conflict" || true
kill "$occupied"
wait "$occupied" || true
echo PXE_TEST_POLICY_OK
/bin/sh /opt/pxe-tools/serve_pxe.sh --enable tftp eth0 192.0.2.1 /srv/tftp /srv/http /etc/pxe-test.conf &
pid=$!
sleep 4
kill -0 "$pid" || { echo PXE_TEST_SERVER_FAILED; exec sleep 120; }
dnsid=$(pidof dnsmasq) || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 120; }
awk '/^Uid:/ { if ($2 == 65534 && $3 == 65534) ok=1 } END { exit !ok }' "/proc/$dnsid/status" || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 120; }
ps | awk '$2 == "nobody" && /httpd -f/ { ok=1 } END { exit !ok }' || { echo PXE_TEST_PRIVILEGE_FAILED; exec sleep 120; }
echo PXE_TEST_SERVER_READY
read -r command </dev/console
kill -TERM "$pid"
wait "$pid" || true
set -- /tmp/pxe-service.*
if pidof dnsmasq >/dev/null 2>&1 || ps | grep -q '[h]ttpd -f' || [ -e "$1" ]; then echo PXE_TEST_SHUTDOWN_FAILED; else echo PXE_TEST_SERVICES_STOPPED; fi
exec sleep 120
"""
    else:
        body = """ip addr add 192.0.2.2/24 dev eth0
ip addr show eth0
ip route show
ping -c 1 -W 2 192.0.2.1 || echo PXE_TEST_PING_FAILED
attempt=0
while [ "$attempt" -lt 8 ]; do
  if tftp -g -r probe.txt -l /tmp/probe.txt 192.0.2.1 && grep -qx PXE_TFTP_FIXTURE /tmp/probe.txt; then
    echo PXE_TEST_TFTP_OK
    if udhcpc -i eth0 -n -q -t 2 -T 1 -s /usr/bin/true >/dev/null 2>&1; then
      echo PXE_TEST_UNEXPECTED_DHCP; exec sleep 120
    fi
    echo PXE_TEST_NO_DHCP
    if wget -q -T 5 -O /tmp/http.txt http://192.0.2.1/probe.txt && grep -qx PXE_HTTP_FIXTURE /tmp/http.txt; then
      echo PXE_TEST_HTTP_OK
    else
      echo PXE_TEST_HTTP_FAILED
    fi
    exec sleep 120
  fi
  attempt=$((attempt + 1))
  sleep 1
done
echo PXE_TEST_TFTP_FAILED
exec sleep 120
"""
    (root / "init").write_text(common + body, encoding="ascii")
    (root / "init").chmod(0o755)
    paths = ["."] + ["./" + str(path.relative_to(root)) for path in sorted(root.rglob("*"))]
    listing = ("\n".join(paths) + "\n").encode()
    archive = subprocess.run(["cpio", "-o", "--format", "newc"], cwd=root, input=listing,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True).stdout
    target.write_bytes(gzip.compress(archive))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("kernel", "initrd", "sums", "release", "keyring", "index", "deb_dir"):
        parser.add_argument("--" + name.replace("_", "-"), type=Path, required=True)
    parser.add_argument("--timeout", type=int, default=180)
    args = parser.parse_args()
    if not 30 <= args.timeout <= 600:
        parser.error("timeout must be between 30 and 600 seconds")
    for program in ("qemu-system-x86_64", "dpkg-deb", "cpio", "bsdtar", "php"):
        if not shutil.which(program):
            parser.error(program + " is required")
    # A short private path also fits macOS's Unix-socket path length limit.
    with tempfile.TemporaryDirectory(prefix="pxe-vm-", dir="/tmp") as directory:
        root = Path(directory)
        kernel, initrd = root / "linux", root / "initrd.gz"
        shutil.copyfile(args.kernel, kernel)
        shutil.copyfile(args.initrd, initrd)
        verify_debian_netboot(kernel, initrd, args.sums, args.release, args.keyring)
        if args.index.stat().st_size > 40 * 1024 * 1024:
            raise ValueError("package index too large")
        verify_packages(args.release.read_bytes(), args.index.read_bytes(), args.deb_dir)
        extracted = root / "extracted"
        extracted.mkdir()
        for name in PACKAGES:
            subprocess.run(["dpkg-deb", "-x", str(args.deb_dir / (name + ".deb")), str(extracted)],
                           stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        raw_initrd = gzip.decompress(initrd.read_bytes())
        listing = subprocess.run(["cpio", "-it"], input=raw_initrd, stdout=subprocess.PIPE,
                                 stderr=subprocess.DEVNULL, check=True).stdout.decode().splitlines()
        modules = [path for path in listing if re.fullmatch(r"(?:\./)?usr/lib/modules/[^/]+/kernel/drivers/net/ethernet/intel/e1000/e1000\.ko\.xz", path)]
        if len(modules) != 1:
            raise ValueError("e1000 module unavailable or ambiguous")
        compressed = subprocess.run(["bsdtar", "-xOf", "-", modules[0]], input=raw_initrd,
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True).stdout
        module = lzma.decompress(compressed)
        php = ("require $argv[1]; require $argv[2]; "
               "echo Admin\\Pxe\\DnsmasqConfiguration::render('tftp',"
               "['pxe_interface'=>'eth0','pxe_ip'=>'192.0.2.1','tftp_root'=>'/srv/tftp'],"
               "[['name'=>'eth0','ipv4'=>['192.0.2.1/24']]]);")
        config = subprocess.check_output(["php", "-r", php, str(ROOT / "app/src/Util/NetworkConfiguration.php"),
                                          str(ROOT / "app/src/Pxe/DnsmasqConfiguration.php")], text=True)
        server_overlay, client_overlay = root / "server.gz", root / "client.gz"
        build_overlay(extracted, module, config, server=True, target=server_overlay)
        build_overlay(extracted, module, config, server=False, target=client_overlay)
        # Linux initramfs supports consecutive compressed cpio archives; QEMU's
        # -initrd expects one file on this platform.
        combined = []
        for name, overlay in (("server-initrd.gz", server_overlay), ("client-initrd.gz", client_overlay)):
            destination = root / name
            with destination.open("wb") as output:
                for part in (initrd, overlay):
                    with part.open("rb") as source:
                        shutil.copyfileobj(source, output)
            combined.append(destination)
        socket_path = str(root / "lan.sock")
        common = ["qemu-system-x86_64", "-machine", "pc,accel=tcg", "-m", "512", "-smp", "1",
                  "-display", "none", "-serial", "stdio", "-monitor", "none", "-no-reboot",
                  "-kernel", str(kernel), "-append", "console=ttyS0,115200n8 net.ifnames=0 biosdevname=0"]
        processes = []
        try:
            for server, guest_initrd, mac in ((True, combined[0], "52:54:00:00:00:01"),
                                               (False, combined[1], "52:54:00:00:00:02")):
                net = ("stream,id=lan,server=" + ("on" if server else "off") +
                       ",addr.type=unix,addr.path=" + socket_path.replace(",", ",,") +
                       ("" if server else ",reconnect-ms=1000"))
                args_qemu = common + ["-initrd", str(guest_initrd), "-netdev", net,
                                      "-device", "e1000,netdev=lan,mac=" + mac]
                processes.append(subprocess.Popen(args_qemu, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.STDOUT))
            selector = selectors.DefaultSelector()
            logs = [bytearray(), bytearray()]
            for i, process in enumerate(processes):
                assert process.stdout is not None
                selector.register(process.stdout, selectors.EVENT_READ, i)
            deadline = time.monotonic() + args.timeout
            stopping = False
            while time.monotonic() < deadline:
                for key, _ in selector.select(timeout=1):
                    data = os.read(key.fileobj.fileno(), 65536)
                    if data:
                        logs[key.data].extend(data)
                    else:
                        selector.unregister(key.fileobj)
                if any(p.poll() is not None for p in processes):
                    break
                complete = (b"PXE_TEST_SERVER_READY" in logs[0] and b"PXE_TEST_POLICY_OK" in logs[0]
                            and b"PXE_TEST_NO_DHCP" in logs[1] and b"PXE_TEST_TFTP_OK" in logs[1]
                            and b"PXE_TEST_HTTP_OK" in logs[1])
                if complete and not stopping:
                    assert processes[0].stdin
                    processes[0].stdin.write(b'stop\n')
                    processes[0].stdin.flush()
                    stopping = True
                if complete and b"PXE_TEST_SERVICES_STOPPED" in logs[0]:
                    print("Isolated TFTP/HTTP supervisor: transfer, no DHCP offer, rejected unsafe config and shutdown verified")
                    return 0
                if any(marker in logs[i] for i in (0, 1) for marker in (b"PXE_TEST_MODULE_FAILED", b"PXE_TEST_SERVER_FAILED", b"PXE_TEST_UNEXPECTED_DHCP", b"PXE_TEST_TFTP_FAILED", b"PXE_TEST_POLICY_FAILED", b"PXE_TEST_HTTP_FAILED", b"PXE_TEST_SHUTDOWN_FAILED", b"PXE_TEST_PRIVILEGE_FAILED")):
                    break
            for label, log in zip(("server", "client"), logs):
                print(label + ":\n" + log[-1800:].decode("utf-8", errors="replace"), file=sys.stderr)
            return 1
        finally:
            for process in processes:
                if process.poll() is None:
                    process.terminate()
            for process in processes:
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


if __name__ == "__main__":
    raise SystemExit(main())
