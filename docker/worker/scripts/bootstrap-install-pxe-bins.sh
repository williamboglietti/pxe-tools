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

# UEFI : shim signé Microsoft = entrypoint annoncé en DHCP option 67 (filename)
# Le shim charge ensuite grubx64.efi (signé MOK) via TFTP.
copy_if_changed /usr/lib/shim/shimx64.efi.signed       "$TFTP_ROOT/grubnetx64.efi" "shim (grubnetx64.efi)"
# MokManager (présent sur certaines versions seulement)
if [ -f /usr/lib/shim/mmx64.efi.signed ]; then
    copy_if_changed /usr/lib/shim/mmx64.efi.signed     "$TFTP_ROOT/mmx64.efi"      "MokManager"
elif [ -f /usr/lib/shim/mmx64.efi ]; then
    # Ubuntu's shim-signed package uses this name for its signed MokManager.
    copy_if_changed /usr/lib/shim/mmx64.efi            "$TFTP_ROOT/mmx64.efi"      "MokManager"
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
