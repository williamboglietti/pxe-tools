# PXE Tools — image appliance unique
# Tout le stack (nginx + php-fpm + dnsmasq + worker.py + outils GRUB/PXE)
# tourne sous s6-overlay v3 dans un seul container, network_mode: host.
# Image locale de développement, sans publication.

FROM debian:trixie-slim

ENV DEBIAN_FRONTEND=noninteractive

# ----------------------------------------------------------------------------
# Paquets système — tout ce que faisaient les 4 images custom précédentes
# ----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Web stack
    nginx \
    php8.4-fpm \
    php8.4-sqlite3 \
    php8.4-mysql \
    php8.4-opcache \
    php8.4-cli \
    php8.4-curl \
    # NOTE : libsodium pour Admin\Util\Crypto est dispo par défaut dans
    # php8.4-cli sur Debian Trixie (depuis PHP 7.2, sodium est builtin).
    # Pas besoin d'un php8.4-sodium séparé (ce package n'existe pas).
    # DHCP/TFTP
    dnsmasq \
    # Worker runtime
    python3 \
    python3-jinja2 \
    # libsodium bindings pour app/crypto.py (déchiffrement vault_password)
    python3-nacl \
    # Les runtimes Ansible sont installés séparément par choix explicite.
    openssh-client \
    sshpass \
    sqlite3 \
    # Git client utilisé par les fonctions de synchronisation configurées
    git \
    # Le pilote et le client restent présents pour compatibilité de construction ;
    # MySQL/MariaDB ne sont pas activés dans la version actuelle.
    mariadb-client \
    # Téléchargements & crypto
    wget \
    curl \
    ca-certificates \
    openssl \
    # gpgv : vérif d'intégrité des images OS (F-05, lib-image-verify.sh).
    gpgv \
    # debian-archive-keyring (F-05) : fournit /usr/share/keyrings/debian-archive-keyring.gpg
    # qui signe l'InRelease des suites → racine de confiance du netboot Debian.
    debian-archive-keyring \
    # ISO extraction pour catalogue v2 (Debian/Ubuntu netinst → vmlinuz+initrd).
    # Fournit `7z` ; p7zip-full n'est plus qu'un paquet de transition vers 7zip.
    7zip \
    # Templates / envsubst
    gettext-base \
    # GRUB EFI signé MOK
    grub-efi-amd64-bin \
    grub-efi-amd64-signed \
    sbsigntool \
    mokutil \
    shim-signed \
    # NOTE Spec D : grub-efi-arm64-bin est Architecture:arm64 → nécessite
    # dpkg --add-architecture arm64 + repo Debian arm64. À ajouter quand on
    # aura une machine ARM pour test e2e. Le script bootstrap-build-grub.sh
    # skip silencieusement si /usr/lib/grub/arm64-efi/ absent (no-op pour amd64).\
    # PXE BIOS bootloader + modules
    pxelinux \
    syslinux-common \
    syslinux-efi \
    # Spec H — iPXE chainload (HTTP boot natif). Fournit ipxe.efi (UEFI),
    # undionly.kpxe (BIOS PXE legacy), ipxe.lkrn (kernel chargeable depuis
    # pxelinux). Optionnel runtime : activé via Setting ipxe_enabled.
    ipxe \
    # Manipulation initrd
    cpio \
    # Décompression s6-overlay tarball
    xz-utils \
    && rm -rf /var/lib/apt/lists/*

# Aucun Ansible ni collection n’est inclus dans cette image de base.

# ----------------------------------------------------------------------------
# s6-overlay v3 — process supervisor multi-service
# ----------------------------------------------------------------------------
ARG S6_OVERLAY_VERSION=3.2.0.0
# SHA256 épinglés (release officielle v3.2.0.0). On FIGE le hash dans l'image
# versionnée au lieu de re-télécharger le .sha256 voisin de la release : un
# attaquant capable de substituer le tarball (asset GitHub compromis, MITM du
# réseau CI, cache GitHub Actions empoisonné) substituerait aussi son .sha256
# → vérif au vert. Le hash figé, lui, casse le build dans ces trois cas.
# Au bump de S6_OVERLAY_VERSION, régénérer les deux valeurs via :
#   curl -sL .../releases/download/v<VERSION>/s6-overlay-{noarch,x86_64}.tar.xz.sha256
ARG S6_OVERLAY_NOARCH_SHA256=4b0c0907e6762814c31850e0e6c6762c385571d4656eb8725852b0b1586713b6
ARG S6_OVERLAY_X86_64_SHA256=ad982a801bd72757c7b1b53539a146cf715e640b4d8f0a6a671a3d1b560fe1e2
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-noarch.tar.xz /tmp/
ADD https://github.com/just-containers/s6-overlay/releases/download/v${S6_OVERLAY_VERSION}/s6-overlay-x86_64.tar.xz /tmp/
RUN echo "${S6_OVERLAY_NOARCH_SHA256}  /tmp/s6-overlay-noarch.tar.xz" | sha256sum -c - \
    && echo "${S6_OVERLAY_X86_64_SHA256}  /tmp/s6-overlay-x86_64.tar.xz" | sha256sum -c - \
    && tar -C / -Jxpf /tmp/s6-overlay-noarch.tar.xz \
    && tar -C / -Jxpf /tmp/s6-overlay-x86_64.tar.xz \
    && rm /tmp/s6-overlay-*.tar.xz

# Licences et notices des composants redistribués avec cette image.
COPY COPYING COPYING.LESSER THIRD-PARTY.md /usr/share/doc/pxe-tools/
COPY third_party/licenses/ /usr/share/doc/pxe-tools/licenses/

# s6-overlay env :
#   S6_KEEP_ENV=1                 → propage les vars d'env depuis docker run vers les services
#   S6_BEHAVIOUR_IF_STAGE2_FAILS=2 → si un oneshot fail, arrête le container (au lieu de continuer en zombie)
#   S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 → s6 attend indéfiniment les longruns (pas de timeout boot)
ENV S6_KEEP_ENV=1 \
    S6_BEHAVIOUR_IF_STAGE2_FAILS=2 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_VERBOSITY=1

# Les clés MOK sont générées par instance, jamais lors du build de l'image.

# ----------------------------------------------------------------------------
# Code app — PHP + worker.py + scripts shell servis par nginx (.sh aux clients PXE)
# Note : path /app/ identique côté nginx ET php-fpm (même container = même FS, plus
# besoin de COPY dupliqué comme dans l'ancienne stack à 4 images).
#
# NOTE Tailwind v4 : le CSS est compilé en local par scripts/dev-css.sh (ou
# scripts/build-css.sh) puis commité (app/public/style.tailwind.css). On ne
# build pas dans l'image car le binaire Go standalone Tailwind crashe en SIGILL
# sur les CPU sans AVX (vu sur AMD E1-1200 de pxe). Le compilé est versionné.
# ----------------------------------------------------------------------------
COPY app/ /app/
# Version only affects application metadata, not the package/runtime layers.
ARG APP_VERSION=0.1.0-dev
RUN printf '%s\n' "$APP_VERSION" > /app/src/build-version.txt
RUN chmod +x /app/worker.py \
    && chown -R root:root /app \
    && chmod -R go-w /app

# ----------------------------------------------------------------------------
# Binaires statiques (smartctl, badblocks, dmidecode) — servis par nginx sous
# /static-bin/ et téléchargés par discover.sh / disktest.sh / wipe.sh dans le
# busybox d-i (qui n'a aucun de ces outils en natif).
# ----------------------------------------------------------------------------
COPY docker/pxe-tools/static-bin/ /srv/http/static-bin/
RUN find /srv/http/static-bin/ -type f ! -name '*.md' -exec chmod +x {} \; \
    && chown -R root:root /srv/http/static-bin/ \
    && chmod -R go-w /srv/http/static-bin/

# ----------------------------------------------------------------------------
# Bootstrap scripts (rendu templates, build GRUB, etc.) — repris du worker actuel
# ----------------------------------------------------------------------------
COPY docker/worker/scripts/ /opt/pxe-tools/scripts/
COPY docker/worker/templates/ /opt/pxe-tools/templates/
RUN chmod +x /opt/pxe-tools/scripts/*.sh

# Keyrings de confiance pour la vérif d'intégrité des images OS (F-05) : clés CD
# Debian/Ubuntu officielles, épinglées et dé-armurées (committées), qui signent
# les SHA256SUMS des ISO/firmware. Le netboot Debian utilise debian-archive-keyring
# (apt, ci-dessus). Provenance + fingerprints : docker/pxe-tools/keyrings/README.md.
COPY docker/pxe-tools/keyrings/*.gpg /usr/share/keyrings/pxe/

# ----------------------------------------------------------------------------
# Config dnsmasq (template envsubst, repris du Dockerfile dnsmasq actuel)
# Le .tmpl vit hors de /etc/dnsmasq.d/ pour ne pas être lu par dnsmasq
# (qui charge tous les fichiers du dir, peu importe l'extension).
# render-dnsmasq-conf.sh substitue puis écrit /etc/dnsmasq.d/pxe.conf.
# ----------------------------------------------------------------------------
COPY docker/dnsmasq/pxe.conf.tmpl /opt/pxe-tools/dnsmasq/pxe.conf.tmpl
COPY docker/dnsmasq/dhcp-hook.sh  /usr/local/bin/dhcp-hook.sh
COPY docker/dnsmasq/dnsmasq-tail.sh /usr/local/bin/dnsmasq-tail.sh
RUN chmod +x /usr/local/bin/dhcp-hook.sh /usr/local/bin/dnsmasq-tail.sh
RUN cat > /etc/dnsmasq.conf <<'EOF'
conf-dir=/etc/dnsmasq.d/
EOF

# ----------------------------------------------------------------------------
# Config nginx :
#   - nginx.conf, pxe.conf : statiques (placés directement dans /etc/nginx/)
#   - admin.conf.tmpl : templated avec envsubst au boot par s6 oneshot
#     `render-nginx-conf` pour permettre override des ports via ADMIN_PORT /
#     ADMIN_HTTP_PORT (env vars). Source : /opt/pxe-tools/nginx/, output :
#     /etc/nginx/conf.d/admin.conf.
# ----------------------------------------------------------------------------
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default
COPY docker/pxe-tools/nginx/nginx.conf       /etc/nginx/nginx.conf
COPY docker/pxe-tools/nginx/pxe.conf         /etc/nginx/conf.d/pxe.conf
COPY docker/pxe-tools/nginx/admin-https.conf.tmpl         /opt/pxe-tools/nginx/admin-https.conf.tmpl
COPY docker/pxe-tools/nginx/admin-http-redirect.conf.tmpl /opt/pxe-tools/nginx/admin-http-redirect.conf.tmpl
COPY docker/pxe-tools/nginx/admin-http-serve.conf.tmpl    /opt/pxe-tools/nginx/admin-http-serve.conf.tmpl

# ----------------------------------------------------------------------------
# Config php-fpm (pool + opcache tuning)
# ----------------------------------------------------------------------------
COPY docker/pxe-tools/php-fpm/zz-pxe.conf  /etc/php/8.4/fpm/pool.d/zz-pxe.conf
COPY docker/pxe-tools/php-fpm/opcache.ini  /etc/php/8.4/fpm/conf.d/99-opcache.ini
# On désactive le pool 'www' par défaut de Debian (notre pool zz-pxe le remplace)
RUN rm -f /etc/php/8.4/fpm/pool.d/www.conf

# ----------------------------------------------------------------------------
# s6-overlay services (oneshots bootstrap + 4 longruns + bundle user)
# ----------------------------------------------------------------------------
COPY docker/pxe-tools/s6-rc/ /etc/s6-overlay/s6-rc.d/
RUN find /etc/s6-overlay/s6-rc.d -name 'run' -o -name 'up' -o -name 'down' -o -name 'finish' \
    | xargs -r chmod +x

# Scripts d'init custom (php-init, bootstrap-tls, render-dnsmasq-conf)
COPY docker/pxe-tools/init/ /opt/pxe-tools/init/
RUN chmod +x /opt/pxe-tools/init/*.sh

# ----------------------------------------------------------------------------
# Volumes persistants (mountés via docker-compose)
# ----------------------------------------------------------------------------
RUN mkdir -p \
        /var/lib/pxe-tools \
        /var/lib/pxe-tools/tls \
        /var/lib/pxe-tools/postboot \
        /etc/pxe-tools/mok \
        /srv/http \
        /srv/http/netboot \
        /srv/tftp \
        /run/php \
    && chown -R www-data:www-data /var/lib/pxe-tools \
    && chown root:root /srv/http /srv/http/netboot \
    && chmod 0755 /srv/http /srv/http/netboot \
    && printf 'pxe-netboot-root-v1\n' > /srv/http/.pxe-netboot-root-v1 \
    && chmod 0644 /srv/http/.pxe-netboot-root-v1 \
    && chmod 0750 /var/lib/pxe-tools/postboot
# Note : /srv/tftp reste root:root. Le container tourne déjà en root pour pouvoir
# bind dnsmasq sur :67/:69 via network_mode: host, donc pas besoin de drop privs.

# ----------------------------------------------------------------------------
# Env defaults — peuvent être override par docker-compose
# ----------------------------------------------------------------------------
ENV PXE_DB_PATH=/var/lib/pxe-tools/pxe-tools.sqlite \
    PXE_TOOLS_DATA_DIR=/var/lib/pxe-tools \
    BOOTSTRAP_SCRIPTS_DIR=/opt/pxe-tools/scripts \
    BOOTSTRAP_TEMPLATES_DIR=/opt/pxe-tools/templates \
    MOK_DIR=/etc/pxe-tools/mok \
    TLS_DIR=/var/lib/pxe-tools/tls \
    TFTP_ROOT=/srv/tftp \
    HTTP_ROOT=/srv/http \
    PXE_SERVICE_MANAGER=s6 \
    ANSIBLE_WORKDIR=/home/ansible/ansible \
    WORKER_POLL_S=5 \
    PYTHONUNBUFFERED=1 \
    DEBIAN_DEFAULT_VERSION=trixie \
    DEBIAN_ARCH=amd64 \
    DEBIAN_MIRROR=http://deb.debian.org/debian \
    DEBIAN_FIRMWARE_BASE=https://cdimage.debian.org/cdimage/firmware \
    KERNEL_OPTS="language=fr country=FR locale=fr_FR.UTF-8 keymap=fr ipv6.disable=1" \
    BIOS_BOOTLOADER=lpxelinux.0 \
    UEFI_BOOTLOADER=grubnetx64.efi \
    UEFI_ENABLED=true \
    ADMIN_PORT=8443 \
    ADMIN_HTTP_PORT=8080
# Lot 3 Spec B : DEBIAN_VERSIONS et DHCP_MODE retirés — gérés en DB respectivement
# via la table os_images et la setting dhcp_proxy_enabled.

# Compte conservé pour les données et fonctions facultatives existantes. Le container tourne en root
# pour permettre s6-overlay de lancer dnsmasq (bind :67) et nginx (bind :80).
ARG ANSIBLE_UID=1001
ARG ANSIBLE_GID=1001
RUN groupadd -g "${ANSIBLE_GID}" ansible \
    && useradd -m -u "${ANSIBLE_UID}" -g ansible -s /bin/bash ansible \
    && mkdir -p /home/ansible/.ssh /home/ansible/ansible \
    && chown -R ansible:ansible /home/ansible

# Ports déclaratifs (informatif uniquement — network_mode: host les expose
# directement sur l'host). Les ports admin réels sont contrôlés par les env
# vars ADMIN_PORT / ADMIN_HTTP_PORT (default 8443/8080).
EXPOSE 80 8443 8080 67/udp 69/udp 4011/udp

# Healthcheck : PXE HTTP, admin HTTP et admin TLS lorsqu'il est activé.
# start_period long car bootstrap-debian-images peut télécharger ~1.5 GB au 1er boot.
HEALTHCHECK --interval=30s --timeout=10s --start-period=600s --retries=3 \
    CMD /opt/pxe-tools/init/healthcheck.sh || exit 1

# s6-overlay init system
ENTRYPOINT ["/init"]
