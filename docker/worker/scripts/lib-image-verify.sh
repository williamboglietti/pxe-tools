#!/bin/bash
# lib-image-verify.sh — vérification d'intégrité cryptographique des artefacts OS
# (kernel/initrd/ISO/firmware) AVANT de les promouvoir comme servables.
#
# F-05 : sans ça, un MITM — ou le DNS LAN empoisonné du homelab — peut substituer
# un kernel/initrd/ISO malveillant servi en PXE à TOUT le parc (exécution de code
# au 1er boot). On exige donc une chaîne de confiance cryptographique. Tout échec
# est FAIL-CLOSED (rc≠0) : l'appelant marque l'image 'failed' et ne sert rien.
#
# Sourcé par bootstrap-download-image.sh et bootstrap-download-image-iso.sh.
# Vérification de la signature puis des empreintes des artefacts.
#
# Convention : chaque fonction echo un diagnostic sur STDERR et rend rc≠0 à
# l'échec (le mapping vers un message UI + publish_error reste à l'appelant).
# Le corps tourne dans un sous-shell (trap EXIT confiné → cleanup du tmp sans
# fuite ; set +e local → contrôle de flux explicite quel que soit le set -e
# de l'appelant). Le rc du sous-shell devient le rc de la fonction.

# Keyrings de confiance. Chemins par défaut = image (COPY Dockerfile + apt) ;
# overridables par env pour les tests hors-conteneur.
: "${DEBIAN_ARCHIVE_KEYRING:=/usr/share/keyrings/debian-archive-keyring.gpg}"
: "${DEBIAN_CD_KEYRING:=/usr/share/keyrings/pxe/pxe-debian-cd.gpg}"
: "${UBUNTU_CD_KEYRING:=/usr/share/keyrings/pxe/pxe-ubuntu-cd.gpg}"

# Téléchargement robuste : suit les redirects, retries, -fsS échoue sur HTTP≥400.
_iv_fetch() { curl -fsSL --retry 3 --retry-delay 2 --max-time 120 -o "$2" "$1"; }

# SHA256 hex d'un fichier (1er champ).
_iv_sha256() { sha256sum "$1" 2>/dev/null | cut -d' ' -f1; }

# gpgv générique. Détaché : <kr> <sig> <data>. Clearsigned/inline : <kr> <fichier>.
_iv_gpgv() {
    local kr="$1"; shift
    command -v gpgv >/dev/null 2>&1 || { echo "[verify] gpgv absent de l'image" >&2; return 11; }
    [ -f "$kr" ] || { echo "[verify] keyring introuvable : $kr" >&2; return 10; }
    gpgv --keyring "$kr" "$@" >/dev/null 2>&1
}

# verify_debian_netboot <dest_dir> <kernel_url> <arch>
# Chaîne apt-secure : InRelease signé (debian-archive-keyring) → SHA256 du
# SHA256SUMS de l'installer → SHA256 de linux + initrd.gz locaux. Transport-
# agnostique (la signature GPG fait foi, que le miroir soit HTTP ou HTTPS).
verify_debian_netboot() {
    local dest_dir="$1" kernel_url="$2" arch="$3" tmp
    tmp="$(mktemp -d)" || return 1
    (
        trap 'rm -rf "$tmp"' EXIT; set +e

        # kernel_url = …/dists/<cn>/main/installer-<arch>/current/images/netboot/debian-installer/<arch>/linux
        local left images_url inrel_url sha_url rel_sha
        left="${kernel_url%%/main/installer-*}"                 # …/dists/<cn>
        images_url="${kernel_url%/netboot/debian-installer/*}"  # …/current/images
        if [ "$left" = "$kernel_url" ] || [ "$images_url" = "$kernel_url" ]; then
            echo "[verify] kernel_url Debian inattendu : $kernel_url" >&2; exit 1
        fi
        inrel_url="$left/InRelease"
        sha_url="$images_url/SHA256SUMS"
        rel_sha="main/installer-$arch/current/images/SHA256SUMS"

        # (1) InRelease signé par la clé d'archive Debian.
        _iv_fetch "$inrel_url" "$tmp/InRelease" || { echo "[verify] InRelease injoignable ($inrel_url)" >&2; exit 1; }
        _iv_gpgv "$DEBIAN_ARCHIVE_KEYRING" --output "$tmp/Release" "$tmp/InRelease" || { echo "[verify] signature InRelease INVALIDE" >&2; exit 1; }
        # Read only the plaintext emitted after successful signature validation.
        # Debian stable may omit Valid-Until; the helper applies a 180-day
        # project limit in that case and refuses ambiguous or future dates.
        python3 "$(dirname "${BASH_SOURCE[0]}")/debian-release-freshness.py" "$tmp/Release" || exit 1

        # (2) SHA256 attendu du SHA256SUMS, lu dans la section SHA256: de l'InRelease.
        local exp_sums
        exp_sums="$(awk -v w="$rel_sha" '/^SHA256:/{s=1;next} /^[^ ]/{s=0} s&&$3==w{print $1;exit}' "$tmp/Release")"
        [ -n "$exp_sums" ] || { echo "[verify] $rel_sha absent de l'InRelease signé" >&2; exit 1; }
        _iv_fetch "$sha_url" "$tmp/SHA256SUMS" || { echo "[verify] SHA256SUMS injoignable ($sha_url)" >&2; exit 1; }
        [ "$(_iv_sha256 "$tmp/SHA256SUMS")" = "$exp_sums" ] \
            || { echo "[verify] SHA256SUMS ne correspond pas à l'InRelease signé" >&2; exit 1; }

        # (3) linux + initrd.gz locaux vs le SHA256SUMS désormais vérifié.
        local f rel want have
        for f in linux initrd.gz; do
            rel="./netboot/debian-installer/$arch/$f"
            want="$(awk -v p="$rel" '$2==p{print $1;exit}' "$tmp/SHA256SUMS")"
            [ -n "$want" ] || { echo "[verify] $rel absent du SHA256SUMS" >&2; exit 1; }
            have="$(_iv_sha256 "$dest_dir/$f")"
            [ "$have" = "$want" ] \
                || { echo "[verify] hash $f local ≠ upstream signé (attendu $want, obtenu ${have:-vide})" >&2; exit 1; }
        done
        echo "[verify] ✓ Debian netboot $arch : InRelease GPG + SHA256 linux/initrd OK" >&2
        exit 0
    )
}

# verify_iso_gpg <iso_path> <iso_url> <distro>
# SHA256SUMS du dossier de l'ISO + sa signature détachée (.sign Debian / .gpg
# Ubuntu) vérifiée vs le keyring CD officiel de la distro, puis SHA256 de l'ISO.
verify_iso_gpg() {
    local iso_path="$1" iso_url="$2" distro="$3" tmp
    tmp="$(mktemp -d)" || return 1
    (
        trap 'rm -rf "$tmp"' EXIT; set +e

        local dir base sums_url sig_url keyring
        dir="${iso_url%/*}"; base="${iso_url##*/}"
        sums_url="$dir/SHA256SUMS"
        case "$distro" in
            debian) sig_url="$dir/SHA256SUMS.sign"; keyring="$DEBIAN_CD_KEYRING" ;;
            ubuntu) sig_url="$dir/SHA256SUMS.gpg";  keyring="$UBUNTU_CD_KEYRING" ;;
            *) echo "[verify] distro ISO non couverte par la vérif : $distro" >&2; exit 2 ;;
        esac

        _iv_fetch "$sums_url" "$tmp/SHA256SUMS"     || { echo "[verify] SHA256SUMS ISO injoignable ($sums_url)" >&2; exit 1; }
        _iv_fetch "$sig_url"  "$tmp/SHA256SUMS.sig" || { echo "[verify] signature SHA256SUMS injoignable ($sig_url)" >&2; exit 1; }
        _iv_gpgv "$keyring" "$tmp/SHA256SUMS.sig" "$tmp/SHA256SUMS" \
            || { echo "[verify] signature SHA256SUMS ISO INVALIDE (clé CD $distro)" >&2; exit 1; }

        # Ligne du fichier : "<sha>  name" ou "<sha> *name" (marqueur binaire) ; tolère ./.
        local want have
        want="$(awk -v f="$base" '{n=$2; sub(/^[*]/,"",n); sub(/^\.\//,"",n); if(n==f){print $1; exit}}' "$tmp/SHA256SUMS")"
        [ -n "$want" ] || { echo "[verify] $base absent du SHA256SUMS signé" >&2; exit 1; }
        have="$(_iv_sha256 "$iso_path")"
        [ "$have" = "$want" ] \
            || { echo "[verify] hash ISO ≠ upstream signé (attendu $want, obtenu ${have:-vide})" >&2; exit 1; }
        echo "[verify] ✓ ISO $distro : signature CD + SHA256 OK ($base)" >&2
        exit 0
    )
}

# verify_firmware <file> <firmware_url>
# firmware.cpio.gz vérifié via SHA256SUMS + .sign du dossier cdimage/firmware/
# (signés par la clé CD Debian). Un cpio firmware trafiqué = code en initramfs.
verify_firmware() {
    local file="$1" fw_url="$2" tmp
    tmp="$(mktemp -d)" || return 1
    (
        trap 'rm -rf "$tmp"' EXIT; set +e

        local dir base sums_url sig_url
        dir="${fw_url%/*}"; base="${fw_url##*/}"
        sums_url="$dir/SHA256SUMS"; sig_url="$dir/SHA256SUMS.sign"

        _iv_fetch "$sums_url" "$tmp/SHA256SUMS"     || { echo "[verify] firmware SHA256SUMS injoignable ($sums_url)" >&2; exit 1; }
        _iv_fetch "$sig_url"  "$tmp/SHA256SUMS.sig" || { echo "[verify] firmware signature injoignable ($sig_url)" >&2; exit 1; }
        _iv_gpgv "$DEBIAN_CD_KEYRING" "$tmp/SHA256SUMS.sig" "$tmp/SHA256SUMS" \
            || { echo "[verify] signature firmware SHA256SUMS INVALIDE" >&2; exit 1; }

        local want have
        want="$(awk -v f="$base" '{n=$2; sub(/^[*]/,"",n); sub(/^\.\//,"",n); if(n==f){print $1; exit}}' "$tmp/SHA256SUMS")"
        [ -n "$want" ] || { echo "[verify] $base absent du SHA256SUMS firmware" >&2; exit 1; }
        have="$(_iv_sha256 "$file")"
        [ "$have" = "$want" ] \
            || { echo "[verify] hash firmware ≠ upstream signé (attendu $want, obtenu ${have:-vide})" >&2; exit 1; }
        echo "[verify] ✓ firmware : signature CD + SHA256 OK ($base)" >&2
        exit 0
    )
}
