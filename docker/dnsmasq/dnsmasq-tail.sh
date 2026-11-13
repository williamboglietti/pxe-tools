#!/command/with-contenv sh
# dnsmasq-tail — sidecar qui parse les logs dnsmasq en temps réel et POST
# les events DHCP proxy + TFTP au controller PHP.
#
# POURQUOI ce sidecar existe : en mode proxy DHCP (homelab avec DHCP externe
# pfSense), dnsmasq n'invoque PAS son `dhcp-script` car il ne crée pas de
# bail (l'IP vient du DHCP externe). On ne peut donc pas observer le DHCP
# uniquement via le hook. Solution : tail les logs verbose et émettre les
# checkpoints à la place.
#
# Format dnsmasq attendu (log-debug + log-dhcp activés dans pxe.conf.tmpl) :
#   PXE proxy : émet 2 lignes consécutives par cycle, on s'intéresse à la 2ème
#     1. "PXE(iface) 02:00:00:00:00:01 proxy"           (MAC seule, pas d'IP)
#     2. "PXE(iface) 192.0.2.42 02:00:00:00:00:01 lpxelinux.0"  (IP + MAC + bootloader)
#   TFTP serve : "dnsmasq-tftp[N]: sent /srv/tftp/lpxelinux.0 to 192.0.2.42"
#
# Best-effort : chaque POST a un timeout 2s + `|| true` pour ne jamais
# bloquer le tail. Le PHP fait early-reg + checkpoint logging.

set -eu

LOG="${PXE_DNSMASQ_LOG:-/var/log/dnsmasq/dnsmasq.log}"
API=http://127.0.0.1:80/api/internal/dnsmasq-event

# Attente fichier (s6 garantit déjà l'ordre via dependencies.d/render-dnsmasq-conf
# qui crée le fichier, mais filet de sécurité au démarrage).
while [ ! -f "$LOG" ]; do
    sleep 1
done

# tail -F : suit le fichier même en cas de rotation/truncation. Le pipe stdin
# du `while read` est non-bufferisé (POSIX sh : pas de readline buffer comme bash).
tail -F "$LOG" 2>/dev/null | while IFS= read -r line; do
    case "$line" in
        # --- PXE proxy DHCP ---
        # dnsmasq 2.90 journalise "PXE(iface) <mac> proxy" : la MAC du client, sans IP (en proxy
        # DHCP le client n'a pas d'adresse dans l'échange). La MAC suffit à savoir quelle machine
        # démarre en PXE ; l'IP, quand une ligne la porte (anciens formats), est transmise en plus.
        *"PXE("*)
            ip=$(echo "$line" | grep -oE '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b' | head -1 || true)
            mac=$(echo "$line" | grep -oE '\b[0-9a-f]{2}(:[0-9a-f]{2}){5}\b' | head -1 || true)
            if [ -n "$mac" ]; then
                curl -s -o /dev/null --max-time 2 -X POST \
                    "$API?type=dhcp_proxy&mac=$mac&ip=$ip" || true
            fi
            ;;

        # --- TFTP serve ---
        # "sent /srv/tftp/lpxelinux.0 to 192.0.2.42"
        *"sent /"*" to "*)
            ip=$(echo "$line" | sed -n 's/.* to \([0-9.]\{1,\}\).*/\1/p')
            # Extrait juste le nom du fichier (basename) pour limiter la
            # surface du POST : on envoie pas le chemin complet.
            file_path=$(echo "$line" | sed -n 's/.*sent \(\/[^ ]*\) to .*/\1/p')
            file_name=$(basename "$file_path" 2>/dev/null || echo '')
            if [ -n "$ip" ] && [ -n "$file_name" ]; then
                # URL-encode minimal du nom de fichier (juste les caractères dangereux)
                file_enc=$(echo "$file_name" | sed 's/ /%20/g; s/+/%2B/g')
                curl -s -o /dev/null --max-time 2 -X POST \
                    "$API?type=tftp_serve&ip=$ip&file=$file_enc" || true
            fi
            ;;
    esac
done
