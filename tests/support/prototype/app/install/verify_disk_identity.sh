#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Experimental read-only guard. Read a /dev path and raw serial on stdin.
# This alone does not authorize or carry out an installation.
set -eu
export LC_ALL=C
bb=/usr/lib/pxe-tools/busybox

deny() { printf '%s\n' 'Disk identity could not be verified.' >&2; exit 1; }
IFS= read -r target || deny
IFS= read -r expected || deny
[ "$#" -eq 0 ] || deny
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -x "$bb" ] && [ ! -L "$bb" ] || deny
printf '%s\n' "$target" | "$bb" grep -Eq '^/dev/(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$' || deny
name=${target#/dev/}
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -b "$target" ] && [ -e "/sys/class/block/$name" ] && [ ! -e "/sys/class/block/$name/partition" ] || deny

normalize() {
    printf '%s\n' "$1" | "$bb" sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | "$bb" tr '[:lower:]' '[:upper:]'
}
acceptable() {
    value=$1
    [ "${#value}" -le 128 ] || return 1
    case "$value" in *'
'*) return 1 ;; esac
    printf '%s\n' "$value" | "$bb" grep -Eq '^[A-Z0-9][A-Z0-9._:+ /-]*$' || return 1
    case "$value" in ''|0|UNKNOWN|N/A|NA|NONE|NULL|'NOT AVAILABLE'|DEFAULT) return 1 ;; esac
    if printf '%s\n' "$value" | "$bb" grep -Eq '^(0+|F+)$'; then return 1; fi
}
read_serial() {
    disk=$1
    for source in "$disk/serial" "$disk/device/serial" "$disk/device/device/serial"; do
        if [ -r "$source" ]; then
            value=$("$bb" cat "$source") || return 1
            value=$(normalize "$value") || return 1
            if [ -n "$value" ]; then printf '%s\n' "$value"; return 0; fi
        fi
    done
    return 1
}

expected=$(normalize "$expected") || deny
acceptable "$expected" || deny
count=0
target_match=0
for entry in /sys/class/block/*; do
    [ -e "$entry" ] || continue
    candidate=${entry##*/}
    # Ignore partitions and non-disk device classes. Hardware eMMC areas are
    # intentionally outside this allowlist and can never be targets.
    printf '%s\n' "$candidate" | "$bb" grep -Eq '^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$' || continue
    [ ! -e "$entry/partition" ] || continue
    [ -b "/dev/$candidate" ] || deny
    # A stale or incorrectly created /dev node must not stand in for sysfs.
    device_hex=$("$bb" stat -c '%t:%T' "/dev/$candidate") || deny
    major=${device_hex%%:*}
    minor=${device_hex#*:}
    case "$major:$minor" in *[!0-9a-fA-F:]*|:*|*:) deny ;; esac
    device_number=$(printf '%d:%d' "0x$major" "0x$minor") || deny
    sys_number=$("$bb" cat "$entry/dev") || deny
    [ "$device_number" = "$sys_number" ] || deny
    serial=$(read_serial "$entry") || deny
    acceptable "$serial" || deny
    if [ "$serial" = "$expected" ]; then
        count=$((count + 1))
        [ "$candidate" != "$name" ] || target_match=1
    fi
done
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "$count" -eq 1 ] && [ "$target_match" -eq 1 ] || deny
printf '%s\n' 'Disk identity verified for this check; no disk was changed.'
