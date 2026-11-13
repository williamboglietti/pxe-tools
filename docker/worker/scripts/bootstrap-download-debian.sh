#!/bin/bash
# Télécharge kernel/initrd/firmware Debian par version dans HTTP_ROOT/netboot/<version>/
# Idempotent : skip si fichier existe déjà (force re-download via FORCE=1).
set -euo pipefail

: "${HTTP_ROOT:=/srv/http}"
: "${DEBIAN_VERSIONS:=bookworm,trixie}"
: "${DEBIAN_ARCH:=amd64}"
: "${DEBIAN_MIRROR:=http://deb.debian.org/debian}"
: "${DEBIAN_FIRMWARE_BASE:=https://cdimage.debian.org/cdimage/firmware}"
: "${FORCE:=0}"

IFS=',' read -ra VERSIONS <<< "$DEBIAN_VERSIONS"

download_if_missing() {
    local url="$1" dest="$2" label="$3"
    if [ "$FORCE" = "1" ] || [ ! -f "$dest" ]; then
        echo "[bootstrap-debian] Downloading $label : $url"
        # -c (continue) : reprise au point d'arrêt si le .tmp existe (cas
        # download interrupted par un restart container).
        # -L : suivre les redirects HTTPS (cdimage.debian.org → fastly).
        wget -c -L --tries=3 --timeout=60 -O "$dest.tmp" "$url" \
            && mv "$dest.tmp" "$dest"
    else
        echo "[bootstrap-debian] $dest already present, skip"
    fi
}

for ver in "${VERSIONS[@]}"; do
    dest_dir="$HTTP_ROOT/netboot/$ver"
    mkdir -p "$dest_dir"

    base="$DEBIAN_MIRROR/dists/$ver/main/installer-$DEBIAN_ARCH/current/images/netboot/debian-installer/$DEBIAN_ARCH"

    download_if_missing "$base/linux"         "$dest_dir/linux"         "kernel ($ver)"
    download_if_missing "$base/initrd.gz"     "$dest_dir/initrd.gz"     "initrd ($ver)"

    fw_url="$DEBIAN_FIRMWARE_BASE/$ver/current/firmware.cpio.gz"
    download_if_missing "$fw_url"             "$dest_dir/firmware.cpio.gz" "firmware ($ver, ~500MB)"
done

echo "[bootstrap-debian] OK"
