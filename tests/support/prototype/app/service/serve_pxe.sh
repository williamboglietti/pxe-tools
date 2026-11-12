#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Internal foreground supervisor for an explicitly prepared, trusted bundle.
# It never changes interface addresses or starts a full DHCP address server.
set -eu

fail() { printf '%s\n' 'PXE service configuration refused or service stopped.' >&2; exit 1; }
if [ "$#" -ne 7 ] || [ "$1" != '--enable' ]; then
    printf '%s\n' 'Usage: sh serve_pxe.sh --enable MODE INTERFACE IPV4 TFTP_ROOT HTTP_ROOT CONFIG' >&2
    exit 2
fi
mode=$2
interface=$3
address=$4
tftp_root=$5
http_root=$6
config=$7
case "$mode" in tftp|proxy) ;; *) fail ;; esac
case "$interface" in ''|-*|*[!A-Za-z0-9_.:-]*) fail ;; esac
case "$tftp_root" in /*) ;; *) fail ;; esac
case "$http_root" in /*) ;; *) fail ;; esac
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -d "$tftp_root" ] && [ -d "$http_root" ] && [ -f "$config" ] && [ ! -L "$config" ] || fail
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "${tftp_root##*/}" = 'tftp' ] && [ "${http_root##*/}" = 'http' ] || fail
[ "${tftp_root%/*}" = "${http_root%/*}" ] || fail
[ "$(cd -- "$tftp_root" && pwd -P)" = "$tftp_root" ] || fail
[ "$(cd -- "$http_root" && pwd -P)" = "$http_root" ] || fail
printf '%s\n' "$address" | /usr/bin/busybox grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail
/usr/bin/busybox ip -4 address show dev "$interface" | /usr/bin/busybox grep -Fq "inet $address/" || fail
size=$(/usr/bin/busybox stat -c '%s' "$config") || fail
case "$size" in ''|*[!0-9]*) fail ;; esac
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "$size" -gt 0 ] && [ "$size" -le 16384 ] || fail

# Snapshot into a directory created by this process, before inspecting or using it.
tmp=$(/usr/bin/busybox mktemp -d /tmp/pxe-service.XXXXXX) || fail
uid=$(/usr/bin/busybox id -u) || fail
# An unprivileged service account cannot switch identity inside the daemons:
# they are started as nobody and must be able to read this snapshot.
[ "$uid" -eq 0 ] || /usr/bin/busybox chmod 0711 "$tmp" || fail
http_pid=''
dns_pid=''
# The shell invokes this function from the traps below.
# shellcheck disable=SC2329,SC2317
cleanup() {
    trap - HUP INT TERM 0
    [ -z "$dns_pid" ] || kill "$dns_pid" 2>/dev/null || :
    [ -z "$http_pid" ] || kill "$http_pid" 2>/dev/null || :
    [ -z "$dns_pid" ] || wait "$dns_pid" 2>/dev/null || :
    [ -z "$http_pid" ] || wait "$http_pid" 2>/dev/null || :
    [ ! -f "$tmp/dnsmasq.conf" ] || /usr/bin/busybox rm "$tmp/dnsmasq.conf"
    /usr/bin/busybox rmdir "$tmp" 2>/dev/null || :
}
trap 'cleanup; exit 0' HUP INT TERM
trap 'cleanup' 0
/usr/bin/busybox cp "$config" "$tmp/dnsmasq.conf" || fail
[ "$uid" -eq 0 ] || /usr/bin/busybox chmod 0644 "$tmp/dnsmasq.conf" || fail
size=$(/usr/bin/busybox stat -c '%s' "$tmp/dnsmasq.conf") || fail
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "$size" -gt 0 ] && [ "$size" -le 16384 ] || fail

# Accept only the exact vocabulary emitted by DnsmasqConfiguration. This
# prevents included configuration or an accidental address-allocating DHCP.
seen='|'
count=0
while IFS= read -r line || [ -n "$line" ]; do
    token=''
    case "$line" in
        'port=0') token=port ;;
        "interface=$interface") token=interface ;;
        'bind-interfaces') token=bind ;;
        "enable-tftp=$interface") token=tftp ;;
        "tftp-root=$tftp_root") token=root ;;
        'no-dhcpv6-interface=*') token=no6 ;;
        'no-dhcpv4-interface=*') [ "$mode" = tftp ] || fail; token=no4 ;;
        'dhcp-match=set:bios,option:client-arch,0') [ "$mode" = proxy ] || fail; token=match0 ;;
        'dhcp-match=set:efi-x86,option:client-arch,7') [ "$mode" = proxy ] || fail; token=match7 ;;
        'dhcp-match=set:efi-x86-ebc,option:client-arch,9') [ "$mode" = proxy ] || fail; token=match9 ;;
        "dhcp-boot=tag:bios,lpxelinux.0,,$address") [ "$mode" = proxy ] || fail; token=boot0 ;;
        "dhcp-boot=tag:efi-x86,bootx64.efi,,$address") [ "$mode" = proxy ] || fail; token=boot7 ;;
        "dhcp-boot=tag:efi-x86-ebc,bootx64.efi,,$address") [ "$mode" = proxy ] || fail; token=boot9 ;;
        "pxe-service=x86PC,\"Network boot (BIOS)\",lpxelinux.0,$address") [ "$mode" = proxy ] || fail; token=service0 ;;
        "pxe-service=x86-64_EFI,\"Network boot (UEFI)\",bootx64.efi,$address") [ "$mode" = proxy ] || fail; token=service7 ;;
        "pxe-service=BC_EFI,\"Network boot (UEFI BC)\",bootx64.efi,$address") [ "$mode" = proxy ] || fail; token=service9 ;;
        dhcp-range=*)
            [ "$mode" = proxy ] || fail
            printf '%s\n' "$line" | /usr/bin/busybox grep -Eq '^dhcp-range=([0-9]{1,3}\.){3}[0-9]{1,3},proxy,([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail
            token=range ;;
        *) fail ;;
    esac
    case "$seen" in *"|$token|"*) fail ;; esac
    seen="$seen$token|"
    count=$((count + 1))
done < "$tmp/dnsmasq.conf"
if [ "$mode" = tftp ]; then [ "$count" -eq 7 ] || fail; else [ "$count" -eq 16 ] || fail; fi
/usr/sbin/dnsmasq --test --conf-file="$tmp/dnsmasq.conf" >/dev/null 2>&1 || fail

# Configuration is explicit; no existing service is stopped and no external
# network or interface is changed.
if [ "$uid" -eq 0 ]; then
    # The daemons drop privileges themselves after binding.
    /usr/bin/busybox httpd -f -p "$address:80" -u nobody:nogroup -h "$http_root" &
    http_pid=$!
    /usr/sbin/dnsmasq --keep-in-foreground --conf-file="$tmp/dnsmasq.conf" --user=nobody --group=nogroup --leasefile-ro --quiet-dhcp --quiet-tftp --log-facility=- &
    dns_pid=$!
else
    # The service account holds only the capabilities granted by its unit or
    # container. Each daemon starts as nobody with the network subset it needs,
    # so neither can read the instance data.
    /usr/bin/setpriv --reuid nobody --regid nogroup --clear-groups \
        --inh-caps -all,+net_bind_service --ambient-caps -all,+net_bind_service \
        /usr/bin/busybox httpd -f -p "$address:80" -h "$http_root" &
    http_pid=$!
    /usr/bin/setpriv --reuid nobody --regid nogroup --clear-groups \
        --inh-caps -all,+net_bind_service,+net_admin,+net_raw --ambient-caps -all,+net_bind_service,+net_admin,+net_raw \
        /usr/sbin/dnsmasq --keep-in-foreground --conf-file="$tmp/dnsmasq.conf" --leasefile-ro --quiet-dhcp --quiet-tftp --log-facility=- &
    dns_pid=$!
fi
while kill -0 "$http_pid" 2>/dev/null && kill -0 "$dns_pid" 2>/dev/null; do
    /usr/bin/busybox sleep 1
done
fail
