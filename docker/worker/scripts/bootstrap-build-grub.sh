#!/bin/bash
# Build GRUB EFI custom : http builtin + grub-early.cfg embedded.
# Spec D : loop sur archs (x86_64-efi signé MOK + arm64-efi non signé).
# Idempotent : skip si binaire existe ET grub-early.cfg pas plus récent.
# REBUILD=1 force la régénération.
set -euo pipefail

: "${TFTP_ROOT:=/srv/tftp}"
: "${MOK_DIR:=/etc/pxe-tools/mok}"
: "${BOOTSTRAP_TEMPLATES_DIR:=/opt/pxe-tools/templates}"
: "${BOOTSTRAP_SCRIPTS_DIR:=/opt/pxe-tools/bootstrap}"
: "${REBUILD:=0}"

# PXE_IP : IP du serveur PXE Tools (anciennement ORCHESTRATOR_IP). Settings DB en
# priorité (posée par le wizard /init/network), fallback env legacy. Aligné sur
# render-dnsmasq-conf.sh et render_template.py. Sans cette source, on fail proprement.
DB=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py")
if [ -e /etc/pxe-tools/db.conf ] && [ ! -f "$DB" ]; then
    echo '[bootstrap-grub] Base SQLite configurée absente.' >&2
    exit 1
fi
db_ip_present=0
if [ -f "$DB" ]; then
    db_ip=$(sqlite3 "$DB" "SELECT 'present:' || COALESCE(value,'') FROM settings WHERE key='pxe_ip'" 2>/dev/null) || {
        echo '[bootstrap-grub] Lecture de la base impossible.' >&2; exit 1;
    }
    case "$db_ip" in present:*) PXE_IP="${db_ip#present:}"; db_ip_present=1 ;; esac
fi
# Back-compat env vars : si PXE_IP non posée mais ORCHESTRATOR_IP l'est, hériter.
if [ "$db_ip_present" = 0 ]; then PXE_IP="${PXE_IP:-${ORCHESTRATOR_IP:-}}"; fi
# Pas d'IP serveur PXE (bootstrap initial AVANT l'étape réseau du wizard, ou
# après un factory reset qui vide settings) → on SKIP proprement. Le GRUB EFI
# embarque l'IP, donc inutile/impossible de le builder maintenant ; le wizard
# (/init/complete) et applyConfig réenqueuent build_grub une fois l'IP posée.
if [ -z "${PXE_IP:-}" ]; then
    echo "[bootstrap-grub] PXE_IP absente (settings/env) → skip ; build différé après config réseau."
    exit 0
fi
# Export les 2 noms : nouveau (PXE_IP) + legacy (ORCHESTRATOR_IP) pour les
# templates Jinja2 et scripts shell pas encore renommés.
export PXE_IP ORCHESTRATOR_IP="$PXE_IP"

WORK_DIR="$MOK_DIR"
EARLY_CFG="$WORK_DIR/grub-early.cfg"

mkdir -p "$TFTP_ROOT"

# Render grub-early.cfg avec PXE_IP courant
echo "[bootstrap-grub] Rendering grub-early.cfg..."
python3 "$BOOTSTRAP_SCRIPTS_DIR/render_template.py" \
    "$BOOTSTRAP_TEMPLATES_DIR/grub-early.cfg.j2" \
    "$EARLY_CFG"

# Modules essentiels — équivalent orchestrator_grub_modules dans defaults Ansible
GRUB_MODULES=(all_video boot cat chain configfile echo efifwsetup efinet ext2 fat font gettext gfxmenu gfxterm gzio halt help http iso9660 keystatus linux loadenv loopback ls memdisk minicmd net normal part_gpt part_msdos png reboot regexp search search_fs_file search_fs_uuid search_label sleep test tftp true video)

# build_one_arch <grub_arch> <output_basename> <sign:0|1>
#   <grub_arch>     : x86_64-efi | arm64-efi (passé à grub-mkimage -O)
#   <output_basename> : grubx64.efi pour le chargeur appelé par shim,
#                       grubnetaa64.efi pour le chargeur ARM expérimental.
#   L’entrée DHCP x86 grubnetx64.efi est réservée au shim signé Microsoft.
#   <sign>          : 1 → sbsign avec MOK (UEFI Secure Boot x86), 0 → laisser non signé (ARM)
build_one_arch() {
    local grub_arch="$1" out_name="$2" sign="$3"
    local out_path="$TFTP_ROOT/$out_name"
    local unsigned="$WORK_DIR/${out_name}.unsigned"
    local pkg_dir="/usr/lib/grub/$grub_arch"

    if [ ! -d "$pkg_dir" ]; then
        echo "[bootstrap-grub] WARN: paquet grub $grub_arch absent ($pkg_dir) — skip" >&2
        return 0
    fi

    local needs_rebuild=0
    if [ "$REBUILD" = "1" ]; then
        needs_rebuild=1
    elif [ ! -f "$out_path" ]; then
        needs_rebuild=1
    elif [ "$EARLY_CFG" -nt "$out_path" ]; then
        needs_rebuild=1
    fi

    if [ "$needs_rebuild" = "1" ]; then
        echo "[bootstrap-grub] Building $out_name ($grub_arch, TFTP from $PXE_IP)..."
        local -a sbat_args=()
        if [ "$sign" = "1" ]; then
            python3 "$BOOTSTRAP_SCRIPTS_DIR/extract-grub-sbat.py" > "$WORK_DIR/grub-sbat.csv"
            sbat_args=(--sbat "$WORK_DIR/grub-sbat.csv")
        fi
        grub-mkimage \
            -O "$grub_arch" \
            -o "$unsigned" \
            -p "(tftp,$PXE_IP)/grub" \
            -c "$EARLY_CFG" \
            "${sbat_args[@]}" \
            "${GRUB_MODULES[@]}"

        if [ "$sign" = "1" ]; then
            echo "[bootstrap-grub] Signing $out_name with MOK..."
            sbsign \
                --key "$MOK_DIR/MOK.key" \
                --cert "$MOK_DIR/MOK.pem" \
                --output "$out_path" \
                "$unsigned"
            echo "[bootstrap-grub] Verifying signature $out_name..."
            sbverify --cert "$MOK_DIR/MOK.pem" "$out_path"
        else
            # Spec D : ARM64 non signé (pas de Secure Boot ARM dans cette spec).
            # Cible : RPi UEFI, Jetson, serveurs ARM avec SB désactivé.
            mv "$unsigned" "$out_path"
            echo "[bootstrap-grub] $out_name livré non signé (Secure Boot ARM non géré)"
        fi
        rm -f "$unsigned"
    else
        echo "[bootstrap-grub] $out_path à jour, skip rebuild"
    fi

    # Modules runtime de l'arch (chargés par le binaire EFI via TFTP). Ils doivent venir
    # du même paquet GRUB que le binaire qu'on vient de construire : le volume TFTP
    # survit aux changements d'image, et après un changement de version de GRUB (2.06 →
    # 2.12 avec Debian 13) un binaire neuf chargerait sinon les anciens .mod restés là.
    if [ "$needs_rebuild" = "1" ] || [ ! -d "$TFTP_ROOT/grub/$grub_arch" ]; then
        echo "[bootstrap-grub] Copying GRUB runtime modules ($grub_arch)..."
        mkdir -p "$TFTP_ROOT/grub"
        rm -rf "${TFTP_ROOT:?}/grub/$grub_arch"
        cp -r "$pkg_dir" "$TFTP_ROOT/grub/"
    fi
}

# Keep shim at the DHCP entry point and the MOK-signed GRUB at the name shim
# expects. Older builds put GRUB at the shim path; restore shim only after the
# new loader has been built and signed successfully.
build_one_arch x86_64-efi grubx64.efi 1
build_one_arch arm64-efi  grubnetaa64.efi 0
shim=/usr/lib/shim/shimx64.efi.signed
if [ ! -f "$shim" ] || [ ! -f "$TFTP_ROOT/grubx64.efi" ]; then
    echo '[bootstrap-grub] ERREUR : shim ou GRUB signé indisponible.' >&2
    exit 1
fi
tmp_shim=$(mktemp "$TFTP_ROOT/.shim.XXXXXXXX")
trap 'rm -f -- "$tmp_shim"' EXIT
cp "$shim" "$tmp_shim"
chmod 0644 "$tmp_shim"
mv -f -- "$tmp_shim" "$TFTP_ROOT/grubnetx64.efi"
trap - EXIT

echo "[bootstrap-grub] OK"
