#!/bin/sh
# Rend /srv/tftp/pxelinux.cfg/01-<MAC> pour chaque machine connue selon son
# status. Permet aux machines BIOS legacy (pxelinux) d'avoir un menu PXE
# adapté à leur état (wipe/install/discovery/local boot), à l'image de ce
# que grub.php fait dynamiquement pour les machines UEFI.
#
# Sans ce script, toutes les machines BIOS tombent sur pxelinux.cfg/default
# (= discovery par défaut, hardcoded statique).
#
# Mapping status → menu :
#   wiping             → wipe-preseed (efface disques puis post_action)
#   configured         → install-preseed (PHP dynamique avec params per-MAC)
#   installing         → install (idem configured, juste un état transitoire)
#   installed/provisioned → exit (boot local sur disque)
#   discovered/inventorying/unknown/discovery → discovery (sonde hardware)
#   testing            → disktest-preseed (smartctl/badblocks)
#   rescue_mode=1      → rescue (mode rescue d-i, indépendant du status)
#
# Chaque entrée FIGE l'interface netcfg sur la MAC de la machine via
# `interface=$mac` (netcfg/choose_interface accepte une MAC ; sélection explicite
# PRIORITAIRE sur la détection de lien et sur BOOTIF). En multi-NIC, `interface=
# auto` élisait la 1re interface par nom (carte PCIe enp1s0 < onboard enp2s0) →
# DHCP bloqué si elle n'a pas de câble ; et netcfg n'honore pas BOOTIF seul (sans
# interface=, il prompte). `IPAPPEND 2` reste pour discover.sh (POST même MAC).
# Lit la DB SQLite via sqlite3 CLI. Si la DB n'existe pas (premier boot),
# skip silencieusement (le default reste servi).
#
# Exécuté en oneshot s6 dans bootstrap-render-templates.sh, et doit aussi
# être déclenché par les transitions de status (TODO: hook dans worker.py
# ou enqueue bootstrap:render_templates depuis MachineController).
set -eu

TFTP_ROOT="${TFTP_ROOT:-/srv/tftp}"
DB_PATH=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py") || exit 1
if [ -e /etc/pxe-tools/db.conf ] && [ ! -f "$DB_PATH" ]; then
    echo '[render-per-mac] Base SQLite configurée absente ; menus existants conservés.' >&2
    exit 1
fi
PXELINUX_DIR="$TFTP_ROOT/pxelinux.cfg"

# Lecture ORCHESTRATOR_IP : une valeur vide enregistrée reste vide.
ORCH_IP="${ORCHESTRATOR_IP:-}"
if [ -f "$DB_PATH" ]; then
    if ! db_ip=$(sqlite3 -cmd '.timeout 5000' "$DB_PATH" "SELECT 'present:' || COALESCE(value,'') FROM settings WHERE key='pxe_ip'" 2>/dev/null); then
        echo '[render-per-mac] Base illisible ; menus existants conservés.' >&2
        exit 1
    fi
    case "$db_ip" in present:*) ORCH_IP="${db_ip#present:}" ;; esac
fi
if [ -z "$ORCH_IP" ]; then
    echo "[render-per-mac] ORCHESTRATOR_IP absent (DB + env) → skip"
    exit 0
fi

KERNEL_OPTS="${KERNEL_OPTS:-language=fr country=FR locale=fr_FR.UTF-8 keymap=fr ipv6.disable=1}"
# DEBIAN_DEFAULT_VERSION is a codename (e.g. trixie), not an os_images.distro.
# Discovery paths use the same Debian distribution key as the static menu.
DEFAULT_DISTRO="debian"
DEFAULT_ARCH="${DEBIAN_ARCH:-amd64}"
case "$DEFAULT_ARCH" in
    ''|*[!a-zA-Z0-9_-]*) echo "[render-per-mac] architecture invalide" >&2; exit 1 ;;
esac

# Résout le chemin netboot pour un couple (distro, version_full, arch) :
#   - version_full non vide  → /netboot/<distro>/<version_full>/<arch>
#   - version_full vide/NULL → résolution via DB sur la dernière os_images
#                              downloaded du distro (cf. OsImage::latestForDistro).
# Si la résolution échoue (aucune image), retombe sur le layout legacy
# /netboot/<distro>/<arch> pour rétro-compat (le 404 sera signalé côté kernel
# load et la machine reboot via timeout PXE).
#
# Stdout : "<distro>/<version_full>/<arch>" ou "<distro>/<arch>" en fallback legacy.
# Args   : $1=distro_codename, $2=version_full (peut être vide), $3=arch
resolve_netboot_path() {
    rdistro="$1"
    rvf="$2"
    rarch="$3"
    if [ -z "$rdistro" ]; then rdistro="$DEFAULT_DISTRO"; fi
    if [ -z "$rarch" ];   then rarch="$DEFAULT_ARCH";     fi
    if [ -z "$rvf" ]; then
        rvf=$(sqlite3 "$DB_PATH" \
            "SELECT version_full FROM os_images
             WHERE distro='$rdistro' AND enabled=1 AND status='downloaded'
               AND version_full IS NOT NULL AND version_full!=''
             ORDER BY version_full DESC LIMIT 1" 2>/dev/null || true)
    fi
    if [ -n "$rvf" ]; then
        echo "$rdistro/$rvf/$rarch"
    else
        # Fallback layout legacy (pré-v2 ; peut ne pas exister sur disque mais
        # on tente quand même — l'admin verra le 404 et téléchargera via
        # /settings/images).
        echo "$rdistro/$rarch"
    fi
}

# Chemin netboot par défaut (pour les machines sans install_config_json :
# discovery, wipe, disktest, rescue). Match the static menu: use a downloaded
# direct Debian image for this architecture, never an unrelated ISO extract.
DEFAULT_VERSION=""
if [ -f "$DB_PATH" ]; then
    DEFAULT_VERSION=$(sqlite3 "$DB_PATH" \
    "SELECT version_full FROM os_images
     WHERE distro='debian' AND arch='$DEFAULT_ARCH' AND enabled=1 AND status='downloaded'
       AND COALESCE(extract_method,'direct')='direct'
       AND version_full IS NOT NULL AND version_full!=''
     ORDER BY version_full DESC LIMIT 1" 2>/dev/null || true)
fi
if [ -n "$DEFAULT_VERSION" ]; then
    DEFAULT_NETBOOT_PATH="debian/$DEFAULT_VERSION/$DEFAULT_ARCH"
else
    DEFAULT_NETBOOT_PATH="debian/$DEFAULT_ARCH"
fi

mkdir -p "$PXELINUX_DIR"

# Permissions : permet à www-data (PHP-FPM, qui supprime un fichier per-MAC
# lors de Machine::delete) d'unlink dans ce dossier. Sans le write bit sur
# le DIRECTORY, www-data ne peut PAS supprimer un fichier même si le fichier
# lui-même est writable. Idempotent (chgrp/chmod sans effet si déjà ok).
chgrp www-data "$PXELINUX_DIR" 2>/dev/null || true
chmod g+w "$PXELINUX_DIR" 2>/dev/null || true

# Cleanup : enlève les anciens fichiers 01-<MAC> avant regénération.
# Évite les fichiers orphelins pour des machines supprimées.
find "$PXELINUX_DIR" -maxdepth 1 -name '01-*' -type f -delete 2>/dev/null || true

if [ ! -f "$DB_PATH" ]; then
    echo "[render-per-mac] DB absente → seul pxelinux.cfg/default reste servi"
    exit 0
fi

# Boucle sur les machines via sqlite3. Format de sortie : pipe-separated
# (default sqlite3 sortie). On lit colonne par colonne.
# Format MAC pxelinux : 01-aa-bb-cc-dd-ee-ff (tout lowercase, séparateur tirets).
#
# install_config_json est extrait via json_extract pour récupérer le couple
# (distro, version_full) du snapshot d'install. NULL si snapshot vide ou clé
# absente (legacy preset sans version_full → resolve_netboot_path fallback latest).
count=0
sqlite3 "$DB_PATH" \
    "SELECT mac, hostname, status, rescue_mode, local_boot_source,
            COALESCE(json_extract(install_config_json, '\$.distro'), '') AS m_distro,
            COALESCE(json_extract(install_config_json, '\$.version_full'), '') AS m_version_full
     FROM machines" \
    2>/dev/null | \
while IFS='|' read -r mac hostname status rescue_mode local_boot_source m_distro m_version_full; do
    [ -z "$mac" ] && continue
    # Normalise MAC : lowercase + tirets (au cas où la DB aurait des colons)
    mac_lc=$(echo "$mac" | tr 'A-Z:' 'a-z-')
    out="$PXELINUX_DIR/01-$mac_lc"

    # Path netboot per-machine : utilisé dans les sections qui consomment
    # le snapshot d'install (configured/installing/reinstall). Les autres
    # sections (discovery/wipe/disktest/rescue) restent sur DEFAULT_NETBOOT_PATH.
    machine_netboot_path=$(resolve_netboot_path "${m_distro:-$DEFAULT_DISTRO}" "$m_version_full" "$DEFAULT_ARCH")

    # rescue_mode=1 override tout
    if [ "$rescue_mode" = "1" ]; then
        cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} en mode rescue
DEFAULT rescue
PROMPT 0
TIMEOUT 30
LABEL rescue
  MENU LABEL Rescue (Debian Installer rescue mode)
  KERNEL http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/initrd.gz $KERNEL_OPTS rescue/enable=true
  IPAPPEND 2
EOF
        count=$((count + 1))
        continue
    fi

    # Les opérations actives priment. Hors opération, seul un succès PXE ou
    # un choix manuel autorise le boot du disque, quel que soit le statut UI.
    routing_status="$status"
    case "$status" in
        wiping|testing|configured|installing|inventorying) ;;
        *) case "$local_boot_source" in pxe|manual) routing_status=localboot ;; esac ;;
    esac

    case "$routing_status" in
        wiping)
            cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} en cours de wipe
DEFAULT wipe
PROMPT 0
TIMEOUT 30
LABEL wipe
  MENU LABEL Wipe disques (avant installation)
  KERNEL http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/initrd.gz $KERNEL_OPTS auto=true priority=critical url=http://$ORCH_IP/wipe-preseed.cfg?mac=$mac interface=$mac netcfg/dhcp_timeout=30
  IPAPPEND 2
EOF
            ;;
        testing)
            cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} en cours de tests disque
DEFAULT disktest
PROMPT 0
TIMEOUT 30
LABEL disktest
  MENU LABEL Tests disque (smartctl + badblocks)
  KERNEL http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/initrd.gz $KERNEL_OPTS auto=true priority=critical url=http://$ORCH_IP/disktest-preseed.cfg?mac=$mac interface=$mac netcfg/dhcp_timeout=30
  IPAPPEND 2
EOF
            ;;
        configured|installing)
            # Installation via preseed.php?mac=$mac (qui sert le preseed
            # généré depuis machine.install_config_json).
            cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} → installation auto
DEFAULT install
PROMPT 0
TIMEOUT 30
LABEL install
  MENU LABEL Installation Debian ${m_distro:-$DEFAULT_DISTRO} ${m_version_full} (auto)
  KERNEL http://$ORCH_IP/netboot/$machine_netboot_path/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$machine_netboot_path/initrd.gz $KERNEL_OPTS auto=true priority=critical url=http://$ORCH_IP/preseed.php?mac=$mac interface=$mac netcfg/dhcp_timeout=30 hostname=${hostname:-unassigned}
  IPAPPEND 2
EOF
            ;;
        localboot)
            # Machine OK → chainload sur le 1er disque via chain.c32.
            # POURQUOI chain.c32 et pas LOCALBOOT 0 : LOCALBOOT 0 demande au PXE ROM
            # de sortir et de laisser la BIOS continuer la séquence de boot.
            # Certaines BIOS (Acer V3.15) ne réessayent PAS les boot devices
            # après une sortie PXE → écran avec curseur clignotant. chain.c32
            # charge directement le MBR du premier disque (hd0) sans repasser
            # par la BIOS, ce qui fonctionne indépendamment du firmware.
            # Workaround historique : débrancher l'Ethernet au démarrage forçait
            # la BIOS à skip PXE et booter local correctement.
            cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} déjà installée → boot local (chain.c32 hd0)
DEFAULT localboot
PROMPT 0
TIMEOUT 30
LABEL localboot
  MENU LABEL Démarrer sur le système installé
  COM32 chain.c32
  APPEND hd0
LABEL reinstall
  MENU LABEL Forcer la réinstallation
  KERNEL http://$ORCH_IP/netboot/$machine_netboot_path/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$machine_netboot_path/initrd.gz $KERNEL_OPTS auto=true priority=critical url=http://$ORCH_IP/preseed.php?mac=$mac interface=$mac netcfg/dhcp_timeout=30 hostname=${hostname:-unassigned}
  IPAPPEND 2
EOF
            ;;
        *)
            # discovered, inventorying, unknown, failed → on retombe sur
            # le menu discovery (sonde hardware + ack /api/discovery).
            cat > "$out" <<EOF
# Géré par pxe-tools-worker (NE PAS éditer)
# Machine ${hostname:-$mac} status=${status:-unknown} → discovery
DEFAULT discovery
PROMPT 0
TIMEOUT 30
LABEL discovery
  MENU LABEL Hardware Discovery
  KERNEL http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/linux
  APPEND vga=788 initrd=http://$ORCH_IP/netboot/$DEFAULT_NETBOOT_PATH/initrd.gz $KERNEL_OPTS auto=true priority=critical url=http://$ORCH_IP/discovery-preseed.cfg interface=$mac netcfg/dhcp_timeout=30
  IPAPPEND 2
EOF
            ;;
    esac
    count=$((count + 1))
    echo "[render-per-mac] $out (status=$status, hostname=${hostname:-?})"
done

echo "[render-per-mac] done"
