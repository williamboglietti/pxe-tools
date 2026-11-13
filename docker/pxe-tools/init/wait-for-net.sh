#!/bin/sh
# wait-for-net — poll l'interface PXE jusqu'à ce qu'elle ait une IPv4 UP.
#
# POURQUOI : dnsmasq avec `bind-interfaces` + `interface=enp7s0` fait son
# bind() au démarrage. Si l'interface n'est pas encore UP (cas typique
# observé au restart du container en network_mode: host), les bind sur
# udp/67 (DHCP) et udp/4011 (PXE service) échouent silencieusement, alors
# que tftp (udp/69) marche quand même (peut bind sur 127.0.0.1 + 0.0.0.0).
# Résultat : dnsmasq vivant mais pourri (TFTP only, plus de DHCP proxy →
# les machines ne PXE-bootent plus). Le seul remède runtime était
# `s6-svc -r /run/service/dnsmasq` après stabilisation de l'interface.
#
# Ce script bloque tant que l'interface configurée n'a pas une IPv4 visible
# côté `ip -o -4 addr`. Le service s6 dnsmasq dépend de ce oneshot via
# dependencies.d/wait-for-net.
#
# Best-effort : timeout dur (30s) puis exit 0 pour ne pas bloquer le boot
# du container indéfiniment. Si l'interface n'a toujours pas d'IP au bout
# de 30s, c'est un problème d'infra plus large (mauvais nom d'iface, NIC
# disabled, etc.) — dnsmasq démarrera quand même et l'admin pourra
# diagnostiquer via les logs.
set -eu

if ! DB=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py" 2>/dev/null); then
    echo '[wait-for-net] Base indisponible ; le rendu réseau restera inerte.' >&2
    exit 0
fi
if [ -e /etc/pxe-tools/db.conf ] && [ ! -f "$DB" ]; then
    echo '[wait-for-net] Base SQLite configurée absente ; aucune interface sélectionnée.' >&2
    exit 0
fi
PXE_INTERFACE="${PXE_INTERFACE:-}"

# Fallback DB si env non set (même logique que render-dnsmasq-conf.sh)
if [ -f "$DB" ]; then
    if ! db_interface=$(sqlite3 "$DB" "SELECT 'present:' || COALESCE(value,'') FROM settings WHERE key='pxe_interface'" 2>/dev/null); then
        echo '[wait-for-net] Lecture de la base impossible ; aucune interface sélectionnée.' >&2
        exit 0
    fi
    case "$db_interface" in present:*) PXE_INTERFACE="${db_interface#present:}" ;; esac
fi

if [ -z "$PXE_INTERFACE" ]; then
    echo "[wait-for-net] pxe_interface non configuré (DB + env vides) → skip (dnsmasq démarrera en stub)"
    exit 0
fi

TIMEOUT=30
START=$(date +%s)
echo "[wait-for-net] Attente d'une IPv4 sur $PXE_INTERFACE (timeout ${TIMEOUT}s)..."

while :; do
    if ip -o -4 addr show dev "$PXE_INTERFACE" 2>/dev/null | grep -q 'inet '; then
        IP=$(ip -o -4 addr show dev "$PXE_INTERFACE" | awk '{print $4}' | head -1)
        echo "[wait-for-net] $PXE_INTERFACE UP avec $IP"
        exit 0
    fi
    NOW=$(date +%s)
    if [ $((NOW - START)) -ge "$TIMEOUT" ]; then
        echo "[wait-for-net] Timeout ${TIMEOUT}s sans IPv4 sur $PXE_INTERFACE — dnsmasq démarrera quand même (bind risque de planter sur 67/4011)" >&2
        exit 0
    fi
    sleep 1
done
