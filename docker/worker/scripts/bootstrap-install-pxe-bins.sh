#!/bin/bash
# Copie les binaires PXE depuis les paquets apt vers TFTP_ROOT :
#   - lpxelinux.0 + modules syslinux (BIOS PXE avec support HTTP)
#   - shimx64.efi.signed → grubnetx64.efi (entrypoint UEFI signé Microsoft)
#   - mmx64.efi (MokManager pour enrôlement MOK côté client)
# Idempotent : ne réécrase que si les fichiers source ont changé.
set -euo pipefail

: "${TFTP_ROOT:=/srv/tftp}"

mkdir -p "$TFTP_ROOT" "$TFTP_ROOT/pxelinux.cfg"

copy_if_changed() {
    local src="$1" dst="$2" label="$3"
    if [ ! -f "$src" ]; then
        echo "[bootstrap-pxe-bins] WARN: source $src introuvable ($label), skip" >&2
        return
    fi
    if [ ! -f "$dst" ] || ! cmp -s "$src" "$dst"; then
        cp "$src" "$dst"
        echo "[bootstrap-pxe-bins] $label : $dst"
    fi
}

# BIOS
copy_if_changed /usr/lib/PXELINUX/lpxelinux.0          "$TFTP_ROOT/lpxelinux.0"     "lpxelinux.0"
for mod in ldlinux.c32 libcom32.c32 libutil.c32 menu.c32 vesamenu.c32 chain.c32; do
    copy_if_changed "/usr/lib/syslinux/modules/bios/$mod" "$TFTP_ROOT/$mod" "syslinux module $mod"
done

# Ubuntu's shim trusts Canonical kernels, not Debian's signed netboot kernel.
# Use an authenticated, version-matched Debian pair for the Debian PXE target.
# The native worker selects a current Debian archive keyring before this runs.
host_id=''
if [ -r /etc/os-release ]; then host_id=$(. /etc/os-release; printf '%s' "${ID:-}"); fi
if [ "${PXE_SERVICE_MANAGER:-}" = systemd ] && [ "$host_id" = ubuntu ]; then
    : "${DEBIAN_ARCHIVE_KEYRING=/usr/share/keyrings/debian-archive-keyring.gpg}"
    python3 "$(dirname "${BASH_SOURCE[0]}")/prepare-debian-shim.py" "$TFTP_ROOT" "$DEBIAN_ARCHIVE_KEYRING"
else
    copy_if_changed /usr/lib/shim/shimx64.efi.signed "$TFTP_ROOT/grubnetx64.efi" "shim (grubnetx64.efi)"
    if [ -f /usr/lib/shim/mmx64.efi.signed ]; then
        copy_if_changed /usr/lib/shim/mmx64.efi.signed "$TFTP_ROOT/mmx64.efi" "MokManager"
    elif [ -f /usr/lib/shim/mmx64.efi ]; then
        copy_if_changed /usr/lib/shim/mmx64.efi "$TFTP_ROOT/mmx64.efi" "MokManager"
    fi
fi

# Spec H — iPXE binaires (chainload depuis pxelinux/grub).
# - ipxe.lkrn : kernel chargeable par pxelinux (BIOS, KERNEL ipxe.lkrn)
# - ipxe.efi  : binaire UEFI chainloadable depuis grub (chainloader ipxe.efi)
# - undionly.kpxe : alternative BIOS via undionly (compat hardware ancien)
# Optionnel : copie seulement si paquet ipxe installé.
for bin in ipxe.lkrn ipxe.efi undionly.kpxe; do
    if [ -f "/usr/lib/ipxe/$bin" ]; then
        copy_if_changed "/usr/lib/ipxe/$bin" "$TFTP_ROOT/$bin" "iPXE $bin"
    fi
done

echo "[bootstrap-pxe-bins] OK"
