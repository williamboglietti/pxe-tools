#!/bin/sh
# dnsmasq dhcp-script — best-effort signal d'event DHCP au controller PHP.
# Spec : docs/superpowers/specs/2026-05-16-actions-checkpoints-tracking-design.md
# (§ Sources de signaux — checkpoint dhcp_request_*)
#
# Invocation (man dnsmasq, --dhcp-script) :
#   $1 = action  ('add' | 'old' | 'del' | 'tftp' | 'arp' | …)
#   $2 = MAC     (aa:bb:cc:dd:ee:ff)
#   $3 = IP      (192.0.2.42)
#   $4 = hostname (optionnel)
#
# Seuls 'add' (nouvel allocation) et 'old' (renouvellement) déclenchent un POST.
# 'del' et autres events sont ignorés (no-op).
#
# POURQUOI POST en best-effort : si le PHP est down ou lent, le hook ne doit
# pas bloquer dnsmasq (qui fork pour chaque event mais respect --max-time).
# La réponse 200 vide est toujours retournée, on n'analyse pas le body.

action="${1:-}"
mac="${2:-}"
ip="${3:-}"

# Skip si event non pertinent ou args incomplets (cas tftp/arp/del).
case "$action" in
    add|old) ;;
    *) exit 0 ;;
esac

if [ -z "$mac" ] || [ -z "$ip" ]; then
    exit 0
fi

# POST sur l'API locale. URL hardcodée loopback car le hook tourne dans le
# même container que le controller PHP (appliance unifiée). Le filtre IP
# côté serveur exige loopback ou réseau Docker interne.
#
# Options :
#   --max-time 2 : timeout strict 2s (best-effort, pas de retry)
#   -s -o /dev/null : silencieux + body jeté
#   |  || true : ne jamais propager l'erreur (le hook doit toujours exit 0)
curl --max-time 2 -s -o /dev/null -X POST \
    "http://127.0.0.1:80/api/internal/dhcp-event?mac=${mac}&ip=${ip}" \
    || true

exit 0
