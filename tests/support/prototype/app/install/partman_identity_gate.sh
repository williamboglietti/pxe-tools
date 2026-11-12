#!/bin/sh
# SPDX-License-Identifier: LGPL-3.0-only
# Experimental hook for an installer environment; not installed automatically.
# It does not inspect the partition plan or authorize writes.
set -eu
export LC_ALL=C

deny() { printf '%s\n' 'Installer disk identity gate refused.' >&2; exit 1; }
[ "$#" -eq 0 ] || deny
bb=/usr/lib/pxe-tools/busybox
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -f "$bb" ] && [ -x "$bb" ] && [ ! -L "$bb" ] || deny
[ "$("$bb" id -u)" = 0 ] || deny
config_dir=/run/pxe-tools
config=$config_dir/disk-identity
guard=/usr/lib/pxe-tools/verify_disk_identity.sh
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -d "$config_dir" ] && [ ! -L "$config_dir" ] || deny
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -f "$config" ] && [ ! -L "$config" ] || deny
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ -f "$guard" ] && [ ! -L "$guard" ] || deny
[ "$("$bb" stat -c '%u:%a:%h' "$bb")" = '0:500:1' ] || deny
[ "$("$bb" stat -c '%u:%a' "$config_dir")" = '0:700' ] || deny
[ "$("$bb" stat -c '%u:%a:%h' "$config")" = '0:600:1' ] || deny
case "$("$bb" stat -c '%u:%a:%h' "$guard")" in 0:500:1|0:700:1) ;; *) deny ;; esac
size=$("$bb" stat -c '%s' "$config") || deny
case "$size" in ''|*[!0-9]*) deny ;; esac
# shellcheck disable=SC2015 # A failed condition intentionally rejects the entire guard.
[ "$size" -gt 0 ] && [ "$size" -le 256 ] || deny
/bin/sh "$guard" < "$config" || deny
