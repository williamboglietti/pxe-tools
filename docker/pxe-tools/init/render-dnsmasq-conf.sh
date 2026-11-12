#!/bin/sh
# Génère la configuration dnsmasq choisie (par défaut /etc/dnsmasq.d/pxe.conf) depuis :
#   1. Les settings DB (sqlite3) — sources principales (Spec B Lot 2 wizard)
#   2. Les env vars compose — fallback compat (avant migration DB)
#
# Idempotent : appelé au démarrage puis par l'application explicite du réseau.
set -eu

CONFIG="${PXE_DNSMASQ_CONFIG:-/etc/dnsmasq.d/pxe.conf}"
case "$CONFIG" in /*) ;; *) echo '[render-dnsmasq-conf] Chemin de configuration absolu requis.' >&2; exit 1 ;; esac
CONFIG_DIR=$(dirname "$CONFIG")
CONFIG_TMP=$(mktemp "$CONFIG_DIR/.pxe.conf.XXXXXXXX")
trap 'if [ -n "$CONFIG_TMP" ]; then rm -f "$CONFIG_TMP"; fi' EXIT

publish_config() {
    chmod 644 "$CONFIG_TMP"
    mv -f "$CONFIG_TMP" "$CONFIG"
    CONFIG_TMP=''
}

# Install an inert configuration before reporting invalid or incomplete input.
# An already running dnsmasq keeps its previous configuration until restarted.
write_inert_config() {
    cat > "$CONFIG_TMP" <<'STUB'
# Configuration inerte : aucun DNS, DHCP ou TFTP.
port=0
no-dhcp-interface=*
STUB
    publish_config
}

# Startup must keep the administration UI reachable with an inert dnsmasq.
# Explicit apply jobs request strict failure and show it in the job status.
fail_config() {
    write_inert_config
    echo "[render-dnsmasq-conf] ERREUR: $1" >&2
    if [ "${PXE_DNSMASQ_STRICT:-0}" = 1 ]; then exit 1; fi
    echo "[render-dnsmasq-conf] Configuration inerte au démarrage ; corriger les paramètres." >&2
    exit 0
}

# Use exactly the worker's database selection rather than a stale environment
# path. Unsupported or unreadable configurations must leave the service inert.
if ! DB=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py" 2>/dev/null); then
    fail_config "configuration de base de données indisponible ou non prise en charge"
fi
if [ -e /etc/pxe-tools/db.conf ] && [ ! -f "$DB" ]; then
    fail_config "base SQLite configurée absente"
fi

# A missing key may use a legacy environment value; a present empty value
# must remain empty. Database read failures are errors, not missing settings.
get_setting() {
    key="$1"
    fallback="${2:-}"
    if [ -f "$DB" ]; then
        # Startup and template jobs may briefly write SQLite at the same time.
        # Wait for a short-lived lock; a persistent failure still fails closed.
        if ! v=$(sqlite3 -cmd '.timeout 5000' "$DB" "SELECT 'present:' || COALESCE(value, '') FROM settings WHERE key='$key'" 2>/dev/null); then
            return 1
        fi
        case "$v" in present:*) printf '%s' "${v#present:}"; return 0 ;; esac
    fi
    printf '%s' "$fallback"
}

read_error() { fail_config "impossible de lire les paramètres réseau"; }

PXE_INTERFACE=$(get_setting 'pxe_interface' "${PXE_INTERFACE:-}") || read_error
PXE_IP=$(get_setting 'pxe_ip' "${PXE_IP:-${ORCHESTRATOR_IP:-}}") || read_error
case "${DHCP_MODE:-tftp_only}" in
    tftp_only) LEGACY_PROXY=off ;;
    proxy) LEGACY_PROXY=on ;;
    *) LEGACY_PROXY=invalid ;;
esac
DHCP_PROXY_ENABLED=$(get_setting 'dhcp_proxy_enabled' "$LEGACY_PROXY") || read_error
DHCP_PROXY_NETWORK=$(get_setting 'dhcp_proxy_network' "${DHCP_PROXY_NETWORK:-}") || read_error
DHCP_PROXY_NETMASK=$(get_setting 'dhcp_proxy_netmask' "${DHCP_PROXY_NETMASK:-}") || read_error

: "${TFTP_ROOT:=/srv/tftp}"
: "${PXE_DNSMASQ_LOG:=/var/log/dnsmasq/dnsmasq.log}"
case "$PXE_DNSMASQ_LOG" in /*) ;; *) fail_config "chemin de journal dnsmasq absolu requis" ;; esac
: "${BIOS_BOOTLOADER:=lpxelinux.0}"
: "${UEFI_BOOTLOADER:=grubnetx64.efi}"
: "${UEFI_ARM64_BOOTLOADER:=grubnetaa64.efi}"
: "${UEFI_ENABLED:=true}"

case "$DHCP_PROXY_ENABLED" in
    on|off) ;;
    *) fail_config "état du proxy DHCP invalide" ;;
esac
if [ -z "$PXE_INTERFACE" ]; then
    if [ "$DHCP_PROXY_ENABLED" = on ]; then
        fail_config "interface requise pour le proxy DHCP"
    fi
    write_inert_config
    echo "[render-dnsmasq-conf] Interface absente : configuration inerte."
    exit 0
fi

case "$PXE_INTERFACE" in
    *[!a-zA-Z0-9_.:-]* ) fail_config "interface invalide" ;;
esac
if [ "${#PXE_INTERFACE}" -gt 64 ]; then
    fail_config "interface invalide"
fi

# Backward-compat : DHCP_MODE est conservée pour le templating envsubst, mais
# elle est désormais dérivée de dhcp_proxy_enabled (DB) ou de l'env legacy.
if [ "$DHCP_PROXY_ENABLED" = "on" ]; then
    DHCP_MODE="proxy"
else
    DHCP_MODE="tftp_only"
fi

echo "[render-dnsmasq-conf] sources : iface=$PXE_INTERFACE, ip=$PXE_IP, dhcp_proxy=$DHCP_PROXY_ENABLED"

case "$DHCP_MODE" in
proxy)
    if [ -z "$PXE_IP" ] || [ -z "$DHCP_PROXY_NETWORK" ] || [ -z "$DHCP_PROXY_NETMASK" ]; then
        fail_config "configuration du proxy DHCP incomplète"
    elif ! python3 - "$PXE_IP" "$DHCP_PROXY_NETWORK" "$DHCP_PROXY_NETMASK" <<'PYCODE'
import ipaddress
import sys
try:
    ipaddress.IPv4Address(sys.argv[1])
    ipaddress.IPv4Network((sys.argv[2], sys.argv[3]), strict=True)
except (ValueError, IndexError):
    raise SystemExit(1)
PYCODE
    then
        fail_config "adresse ou plage du proxy DHCP invalide"
    else
        UEFI_DHCP_BOOT=""
        UEFI_PXE_SERVICES=""
        if [ "$UEFI_ENABLED" = "true" ]; then
            UEFI_DHCP_BOOT="dhcp-boot=tag:efi-x86,${UEFI_BOOTLOADER},,${PXE_IP}
dhcp-boot=tag:efi-x86-ebc,${UEFI_BOOTLOADER},,${PXE_IP}
dhcp-boot=tag:efi-arm64,${UEFI_ARM64_BOOTLOADER},,${PXE_IP}"
            UEFI_PXE_SERVICES="pxe-service=x86-64_EFI,\"Install reseau (UEFI x86_64)\",${UEFI_BOOTLOADER},${PXE_IP}
pxe-service=BC_EFI,\"Install reseau (UEFI BC)\",${UEFI_BOOTLOADER},${PXE_IP}
pxe-service=ARM64_EFI,\"Install reseau (UEFI ARM64)\",${UEFI_ARM64_BOOTLOADER},${PXE_IP}"
        fi

        PROXY_DHCP_BLOCK=$(cat <<EOF
# Mode PROXY-DHCP (cohabite avec un DHCP existant)
dhcp-range=${DHCP_PROXY_NETWORK},proxy,${DHCP_PROXY_NETMASK}

dhcp-match=set:bios,option:client-arch,0
dhcp-match=set:bios64,option:client-arch,5
dhcp-match=set:efi-x8632,option:client-arch,6
dhcp-match=set:efi-x86,option:client-arch,7
dhcp-match=set:efi-x86-ebc,option:client-arch,9
dhcp-match=set:efi-arm,option:client-arch,10
dhcp-match=set:efi-arm64,option:client-arch,11

dhcp-boot=tag:bios,${BIOS_BOOTLOADER},,${PXE_IP}
dhcp-boot=tag:bios64,${BIOS_BOOTLOADER},,${PXE_IP}
${UEFI_DHCP_BOOT}

pxe-service=x86PC,"Install reseau (BIOS)",${BIOS_BOOTLOADER},${PXE_IP}
${UEFI_PXE_SERVICES}
EOF
)
    fi
    ;;
tftp_only)
    PROXY_DHCP_BLOCK="# Mode TFTP_ONLY — DHCP proxy désactivé (dhcp_proxy_enabled=off). Le DHCP externe doit annoncer les options 66/67."
    ;;
esac

# Crée le répertoire log dnsmasq (log-facility=/var/log/dnsmasq/dnsmasq.log
# défini dans pxe.conf.tmpl). Le fichier est touché ici pour que le sidecar
# dnsmasq-tail puisse `tail -F` immédiatement au démarrage, sans attendre que
# dnsmasq ait reçu son 1er event.
mkdir -p "$(dirname "$PXE_DNSMASQ_LOG")"
if [ -L "$PXE_DNSMASQ_LOG" ] || { [ -e "$PXE_DNSMASQ_LOG" ] && [ ! -f "$PXE_DNSMASQ_LOG" ]; }; then
    fail_config "journal dnsmasq invalide"
fi
if [ ! -e "$PXE_DNSMASQ_LOG" ]; then
    touch "$PXE_DNSMASQ_LOG"
    chmod 644 "$PXE_DNSMASQ_LOG"
fi

export PXE_INTERFACE TFTP_ROOT DHCP_MODE PROXY_DHCP_BLOCK PXE_DNSMASQ_LOG
envsubst '${PXE_INTERFACE} ${TFTP_ROOT} ${DHCP_MODE} ${PROXY_DHCP_BLOCK} ${PXE_DNSMASQ_LOG}' \
    < /opt/pxe-tools/dnsmasq/pxe.conf.tmpl \
    > "$CONFIG_TMP"
if ! dnsmasq --test -C "$CONFIG_TMP" >/dev/null 2>&1; then
    fail_config "configuration dnsmasq générée invalide"
fi
publish_config

echo "[render-dnsmasq-conf] Config générée :"
cat "$CONFIG"
