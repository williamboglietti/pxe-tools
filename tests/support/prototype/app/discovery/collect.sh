#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Read-only discovery prototype. Prints sensitive inventory to stdout only.
# No networking, block-device reads, mounts or hardware changes are performed.
set -eu
export LC_ALL=C

usage() { printf '%s\n' 'Usage: collect.sh --interface NAME [--sys-root PATH --proc-root PATH]' >&2; exit 2; }
fail() { printf '%s\n' 'Discovery could not produce a complete report.' >&2; exit 1; }
sys=/sys
proc=/proc
iface=
while [ "$#" -gt 0 ]; do
    [ "$#" -ge 2 ] || usage
    case "$1" in
        --interface) [ -z "$iface" ] || usage; iface=$2 ;;
        --sys-root) sys=$2 ;;
        --proc-root) proc=$2 ;;
        *) usage ;;
    esac
    shift 2
done
case "$sys:$proc" in /*:/*) ;; *) usage ;; esac
case "$iface" in ''|.|..|*[!a-zA-Z0-9_.:-]*) usage ;; esac
[ "${#iface}" -le 15 ] || usage
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -d "$sys/class/net/$iface" ] && [ -d "$sys/class/block" ] && [ -d "$proc" ] || fail

read_limited() {
    # sysfs attributes are short. Unreadable values remain absent; ordinary
    # display strings are capped and sanitized below before JSON encoding.
    if [ -r "$1" ] && [ -f "$1" ]; then head -c 257 "$1" 2>/dev/null || :; fi
}
json_string() {
    # Display metadata is limited to printable ASCII. JSON control characters,
    # quotes and backslashes from a device can never inject structure.
    printf '"'
    printf '%s' "$1" | tr -cd '\040-\176' | sed 's/\\/\\\\/g; s/"/\\"/g'
    printf '"'
}
mac_address() {
    raw=$(read_limited "$1" | tr 'A-F' 'a-f')
    [ "${#raw}" -eq 17 ] || return 1
    printf '%s\n' "$raw" | grep -Eq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || return 1
    first=${raw%%:*}
    [ "$raw" != 00:00:00:00:00:00 ] && [ "$((0x$first & 1))" -eq 0 ] || return 1
    printf '%s' "$raw"
}
main_mac=$(mac_address "$sys/class/net/$iface/address") || fail
# Same sysfs evidence used by the private discovery; missing firmware data is
# unknown, not silently interpreted as BIOS. Namespaces may still hide sysfs.
boot_mode=null
if [ -d "$sys/firmware/efi" ]; then
    boot_mode='"UEFI"'
elif [ -d "$sys/firmware" ] && [ -r "$sys/firmware" ] && [ -x "$sys/firmware" ]; then
    boot_mode='"BIOS"'
fi
cpu_model=
memory_kib=
if [ -r "$proc/cpuinfo" ]; then
    cpu_model=$(head -c 262144 "$proc/cpuinfo" 2>/dev/null | sed -n 's/^model name[[:space:]]*:[[:space:]]*//p' | head -n 1)
fi
if [ -r "$proc/meminfo" ]; then
    memory_kib=$(head -c 16384 "$proc/meminfo" 2>/dev/null | sed -n 's/^MemTotal:[[:space:]]*\([0-9][0-9]*\)[[:space:]]*kB[[:space:]]*$/\1/p' | head -n 1)
fi
case "$memory_kib" in ''|*[!0-9]*) memory_bytes=null ;; *)
    memory_kib=$(printf '%s' "$memory_kib" | sed 's/^0*//')
    [ -n "$memory_kib" ] || memory_kib=0
    if [ "${#memory_kib}" -le 13 ]; then memory_bytes=$((memory_kib * 1024)); else memory_bytes=null; fi ;;
esac

# Refuse unexpectedly large inventories before producing any partial JSON.
disks=0
for entry in "$sys"/class/block/*; do
    [ -e "$entry" ] || continue
    name=${entry##*/}
    printf '%s\n' "$name" | grep -Eq '^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$' || continue
    [ ! -e "$entry/partition" ] || continue
    disks=$((disks + 1))
    [ "$disks" -le 128 ] || fail
done
interfaces=0
for entry in "$sys"/class/net/*; do
    [ -e "$entry" ] || continue
    interfaces=$((interfaces + 1))
    [ "$interfaces" -le 128 ] || fail
done

printf '{"mac":'
json_string "$main_mac"
printf ',"interface":'
json_string "$iface"
printf ',"inventory":{"vendor":'
json_string "$(read_limited "$sys/class/dmi/id/sys_vendor")"
printf ',"product":'
json_string "$(read_limited "$sys/class/dmi/id/product_name")"
printf ',"serial":'
json_string "$(read_limited "$sys/class/dmi/id/product_serial")"
printf ',"bios_vendor":'
json_string "$(read_limited "$sys/class/dmi/id/bios_vendor")"
printf ',"bios_version":'
json_string "$(read_limited "$sys/class/dmi/id/bios_version")"
printf ',"boot_mode":%s' "$boot_mode"
printf ',"cpu_model":'
json_string "$cpu_model"
printf ',"memory_bytes":%s' "$memory_bytes"
printf '},"disks":['
separator=
for entry in "$sys"/class/block/*; do
    [ -e "$entry" ] || continue
    name=${entry##*/}
    printf '%s\n' "$name" | grep -Eq '^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$' || continue
    [ ! -e "$entry/partition" ] || continue
    serial=
    for path in "$entry/serial" "$entry/device/serial" "$entry/device/device/serial"; do
        serial=$(read_limited "$path" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
        [ -z "$serial" ] || break
    done
    # Never turn a truncated, non-ASCII or malformed serial into an identity.
    case "$serial" in *'
'*) serial= ;; esac
    if [ "${#serial}" -gt 128 ] || ! printf '%s\n' "$serial" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._:+ /-]*$'; then serial=; fi
    sectors=$(read_limited "$entry/size")
    case "$sectors" in ''|*[!0-9]*) bytes=null ;; *)
        sectors=$(printf '%s' "$sectors" | sed 's/^0*//')
        [ -n "$sectors" ] || sectors=0
        if [ "${#sectors}" -le 13 ]; then bytes=$((sectors * 512)); else bytes=null; fi ;;
    esac
    printf '%s{"name":' "$separator"
    json_string "/dev/$name"
    printf ',"size_bytes":%s,"serial":' "$bytes"
    json_string "$serial"
    printf ',"vendor":'
    json_string "$(read_limited "$entry/device/vendor")"
    printf ',"model":'
    json_string "$(read_limited "$entry/device/model")"
    printf ',"metadata_collection":{"filesystem_status":"unavailable","mmc_status":"unavailable"}}'
    separator=,
done
printf '],"interfaces":['
separator=
for entry in "$sys"/class/net/*; do
    [ -e "$entry" ] || continue
    name=${entry##*/}
    case "$name" in ''|.|..|*[!a-zA-Z0-9_.:-]*) continue ;; esac
    [ "${#name}" -le 15 ] || continue
    mac=$(mac_address "$entry/address") || continue
    printf '%s{"name":' "$separator"
    json_string "$name"
    printf ',"mac":'
    json_string "$mac"
    printf '}'
    separator=,
done
printf ']}\n'
