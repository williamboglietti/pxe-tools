#!/usr/bin/env bash
# Render and apply the explicitly requested network settings as one job.
set -euo pipefail

renderer=/opt/pxe-tools/init/render-dnsmasq-conf.sh
config="${PXE_DNSMASQ_CONFIG:-/etc/dnsmasq.d/pxe.conf}"
manager="${PXE_SERVICE_MANAGER:-s6}"
ready_file="${PXE_HTTP_READY_FILE:-}"
if [[ -n "$ready_file" && "$ready_file" != /* ]]; then
    echo '[restart-dnsmasq] ERREUR : chemin HTTP PXE invalide.' >&2
    exit 1
fi
# The native HTTP service is intentionally off during the first-run wizard.
# Only this explicitly requested network-application job can start it.
if [[ "$manager" != systemd && -n "$ready_file" && ! -f "$ready_file" ]]; then
    echo '[restart-dnsmasq] ERREUR : le service HTTP PXE est indisponible sur le port 80.' >&2
    exit 1
fi
case "$config" in /*) ;; *) echo '[restart-dnsmasq] Chemin de configuration absolu requis.' >&2; exit 1 ;; esac

case "$manager" in
    s6) service=/run/service/dnsmasq; commands=(s6-svc s6-svstat dnsmasq) ;;
    systemd) service=pxe-tools-dnsmasq.service; commands=(systemctl dnsmasq) ;;
    *) echo '[restart-dnsmasq] ERREUR : superviseur non pris en charge' >&2; exit 1 ;;
esac
for command in "${commands[@]}"; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "[restart-dnsmasq] ERREUR : $command absent" >&2
        exit 1
    fi
done
if [[ ! -x "$renderer" || ( "$manager" == s6 && ! -d "$service" ) ]]; then
    echo '[restart-dnsmasq] ERREUR : service ou générateur indisponible' >&2
    exit 1
fi

render_ok=1
if ! PXE_DNSMASQ_STRICT=1 "$renderer"; then
    render_ok=0
    # Reload only when the complete inert file was actually published.
    inert=$(printf '# Configuration inerte : aucun DNS, DHCP ou TFTP.\nport=0\nno-dhcp-interface=*')
    if [[ ! -f "$config" || "$(cat "$config")" != "$inert" ]]; then
        echo '[restart-dnsmasq] ERREUR : configuration inerte non confirmée ; service inchangé' >&2
        exit 1
    fi
fi

if ! dnsmasq --test -C "$config"; then
    echo '[restart-dnsmasq] ERREUR : configuration dnsmasq invalide ; service inchangé' >&2
    exit 1
fi

if [[ "$manager" == systemd && -n "$ready_file" && ! -f "$ready_file" ]]; then
    if [[ "$render_ok" -ne 1 ]] || ! systemctl start pxe-tools-pxe-http.service || [[ ! -f "$ready_file" ]]; then
        echo '[restart-dnsmasq] ERREUR : le service HTTP PXE est indisponible sur le port 80.' >&2
        exit 1
    fi
fi

if [[ "$manager" == s6 ]]; then
    read -r old_up old_pid < <(s6-svstat -o up,pid "$service")
    s6-svc -r "$service"
else
    old_pid=$(systemctl show --property=MainPID --value "$service")
    systemctl restart "$service"
fi
last_pid=''
for _ in {1..12}; do
    sleep 1
    if [[ "$manager" == s6 ]]; then
        read -r up pid < <(s6-svstat -o up,pid "$service")
    else
        state=$(systemctl show --property=ActiveState --value "$service")
        pid=$(systemctl show --property=MainPID --value "$service")
        [[ "$state" == active ]] && up=true || up=false
    fi
    if [[ "$up" == true && "$pid" =~ ^[1-9][0-9]*$ && "$pid" != "$old_pid" ]]; then
        if [[ "$pid" == "$last_pid" ]]; then
            if [[ "$render_ok" -eq 0 ]]; then
                echo '[restart-dnsmasq] ERREUR : réseau inerte après échec du rendu' >&2
                exit 1
            fi
            if [[ "$manager" == systemd ]]; then
                # Persist only after the confirmed network config is valid and
                # dnsmasq is running; no DHCP is started at package installation.
                if ! systemctl enable --now pxe-tools.target; then
                    echo '[restart-dnsmasq] ERREUR : activation au démarrage non confirmée.' >&2
                    exit 1
                fi
            fi
            echo '[restart-dnsmasq] Configuration appliquée et service redémarré'
            exit 0
        fi
        last_pid=$pid
    else
        last_pid=''
    fi
done
echo '[restart-dnsmasq] ERREUR : redémarrage stable non confirmé' >&2
if [[ "$manager" == s6 ]]; then s6-svstat "$service" >&2 || true
else systemctl status --no-pager "$service" >&2 || true; fi
exit 1
