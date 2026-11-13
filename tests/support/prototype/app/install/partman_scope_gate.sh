#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Experimental, fail-closed check at a partman commit.d invocation. This does
# not authenticate the booted code or cover writes performed outside partman.
set -e
export LC_ALL=C
# shellcheck disable=SC2154 # result is assigned inside the trap itself.
trap 'result=$?; [ "$result" -ne 0 ] || result=1; printf "%s\n" "Installer partition plan scope refused." >&2; exit "$result"' EXIT
deny() { exit 1; }
[ "$#" -eq 0 ] || deny
bb=/usr/lib/pxe-tools/busybox
identity=/usr/lib/partman/commit.d/00-pxe-identity
config=/run/pxe-tools/disk-identity
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -f "$identity" ] && [ ! -L "$identity" ] || deny
[ "$("$bb" stat -c '%u:%a:%h' "$identity")" = '0:500:1' ] || deny
/bin/sh "$identity" || deny
IFS= read -r target < "$config" || deny
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -f /lib/partman/lib/base.sh ] && [ ! -L /lib/partman/lib/base.sh ] || deny
# The sourced Debian library supplies DEVICES and the partman protocol helpers.
# shellcheck source=/dev/null
. /lib/partman/lib/base.sh
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "${DEVICES:-}" = /var/lib/partman/devices ] && [ -d "$DEVICES" ] && [ ! -L "$DEVICES" ] || deny
seen=0
for dev in "$DEVICES"/*; do
    [ -d "$dev" ] || continue
    # shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
    [ ! -L "$dev" ] && [ -f "$dev/device" ] && [ ! -L "$dev/device" ] || deny
    device=$("$bb" cat "$dev/device") || deny
    printf '%s\n' "$device" | "$bb" grep -Eq '^/dev/(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$' || deny
    cd "$dev" || deny
    open_dialog IS_CHANGED || deny
    changed=
    read_line changed || deny
    close_dialog || deny
    case "$changed" in yes|no) ;; *) deny ;; esac
    if [ "$device" = "$target" ]; then
        seen=$((seen + 1))
        [ "$seen" -eq 1 ] || deny
    else
        if [ "$changed" != no ]; then
            printf '%s\n' 'Partman reported changes on another disk.' >&2
            deny
        fi
        # Reject even apparently unchanged foreign partitions if partman has
        # assigned a method or format operation to them. Do not infer safety
        # from its partition-table change flag alone.
        for operation in "$dev"/*/method "$dev"/*/format; do
            if [ -e "$operation" ] || [ -L "$operation" ]; then
                printf '%s\n' 'Partman reported an operation on another disk.' >&2
                deny
            fi
        done
    fi
done
[ "$seen" -eq 1 ] || deny
trap - EXIT
printf '%s\n' 'Partman scope check passed for this invocation; no disk was changed.'
