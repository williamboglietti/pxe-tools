#!/bin/bash
# Rendu de TOUS les templates Jinja2 PXE → fichiers servis par nginx/dnsmasq.
# Always rerender (cheap) — variables peuvent changer entre runs.
#
# Cibles :
#   /srv/tftp/pxelinux.cfg/default       (BIOS PXE menu)
#   /srv/tftp/grub/grub.cfg              (UEFI fallback menu)
#   /srv/http/preseed.php                (preseed dynamique par MAC)
#   /srv/http/grub.php                   (config GRUB dynamique par MAC)
#   /var/lib/pxe-tools/preseed-base.cfg  (squelette lu par preseed.php)
#   /var/lib/pxe-tools/data.json         (vars projet pour preseed.php)
#   /srv/http/discovery-preseed.cfg      (boot Debian discovery)
#   /srv/http/wipe-preseed.cfg           (boot Debian wipe)
#   /srv/http/disktest-preseed.cfg       (boot Debian disktest)
set -euo pipefail

: "${TFTP_ROOT:=/srv/tftp}"
: "${HTTP_ROOT:=/srv/http}"
: "${PXE_TOOLS_DATA_DIR:=/var/lib/pxe-tools}"
: "${BOOTSTRAP_TEMPLATES_DIR:=/opt/pxe-tools/templates}"
: "${BOOTSTRAP_SCRIPTS_DIR:=/opt/pxe-tools/bootstrap}"

mkdir -p "$TFTP_ROOT/pxelinux.cfg" "$TFTP_ROOT/grub" "$HTTP_ROOT" "$PXE_TOOLS_DATA_DIR"

render() {
    local src="$1" dst="$2"
    python3 "$BOOTSTRAP_SCRIPTS_DIR/render_template.py" \
        "$BOOTSTRAP_TEMPLATES_DIR/$src" "$dst"
}

# Menus PXE statiques (BIOS + UEFI fallback)
render pxelinux-default.j2     "$TFTP_ROOT/pxelinux.cfg/default"
render grub.cfg.j2             "$TFTP_ROOT/grub/grub.cfg"

# Menus PXE per-MAC pour les machines BIOS legacy (pxelinux.cfg/01-<MAC>).
# Sans ces fichiers, toute machine BIOS retombe sur pxelinux.cfg/default qui
# est en mode "discovery hardcoded" — impossible de faire wipe/install/local
# boot conditionnel par machine. Cf. render-per-mac-pxelinux.sh.
if [ -x "$BOOTSTRAP_SCRIPTS_DIR/render-per-mac-pxelinux.sh" ]; then
    "$BOOTSTRAP_SCRIPTS_DIR/render-per-mac-pxelinux.sh" || {
        echo "[bootstrap-render-templates] ERREUR: render-per-mac-pxelinux a échoué" >&2
        exit 1
    }
fi

# Endpoints PHP runtime (executés par php-fpm via nginx)
render preseed.php.j2          "$HTTP_ROOT/preseed.php"
render grub.php.j2             "$HTTP_ROOT/grub.php"

# Squelette + données partagées (lus par preseed.php au runtime)
render preseed-base.cfg.j2     "$PXE_TOOLS_DATA_DIR/preseed-base.cfg"
render data.json.j2            "$PXE_TOOLS_DATA_DIR/data.json"

# Preseed boots déclenchés par les menus GRUB (wipe, disktest, discovery)
render discovery-preseed.cfg.j2 "$HTTP_ROOT/discovery-preseed.cfg"
render wipe-preseed.cfg.j2      "$HTTP_ROOT/wipe-preseed.cfg"
render disktest-preseed.cfg.j2  "$HTTP_ROOT/disktest-preseed.cfg"

# Scripts shell exécutés par les preseeds — ORCH_IP injecté depuis settings DB
# (pxe_ip), pas de fallback hardcodé. Rendu vers HTTP_ROOT pour servir
# via nginx (root /srv/http) — wget côté machine cible récupère le contenu rendu.
# wipe.sh est désormais rendu dynamiquement par PxeController::wipeScript (route
# /wipe.sh côté front controller) : disques + token inlinés par MAC à la requête.
render discover.sh.j2           "$HTTP_ROOT/discover.sh"
render disktest.sh.j2           "$HTTP_ROOT/disktest.sh"

# Spec H — Script iPXE chainloadé (HTTP boot natif). Toujours rendu (peu coûteux),
# mais utilisé seulement si le menuentry chainload est exposé dans pxelinux/grub
# (conditionnel sur Setting ipxe_enabled — voir grub.cfg.j2 et pxelinux-default.j2).
render boot.ipxe.j2             "$HTTP_ROOT/boot.ipxe"

# Network settings are rendered and applied together by restart_dnsmasq.
# Machine and menu changes must not silently alter an active DHCP service.

echo "[bootstrap-render-templates] OK"
