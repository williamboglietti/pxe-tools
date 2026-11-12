#!/bin/sh
# Génère un cert TLS self-signed pour le port admin 8443.
# Idempotent : skip si cert.pem + key.pem déjà présents et valides (>30j).
# Storage : volume nommé pxe_tls monté sur /var/lib/pxe-tools/tls
#
# Le certificat reste prêt si HTTPS est réactivé depuis l'interface. Sa
# présence n'ouvre aucun port lorsque https_enabled='off'.
set -eu

TLS_DIR="${TLS_DIR:-/var/lib/pxe-tools/tls}"
CERT="$TLS_DIR/cert.pem"
KEY="$TLS_DIR/key.pem"
CN="${TLS_CN:-pxe-tools.local}"
DAYS="${TLS_DAYS:-3650}"

mkdir -p "$TLS_DIR"

# Never replace existing certificate material automatically. An expired or
# unreadable certificate can be diagnosed and replaced explicitly by its owner.
if [ -e "$CERT" ] || [ -L "$CERT" ] || [ -e "$KEY" ] || [ -L "$KEY" ]; then
    if [ ! -f "$CERT" ] || [ ! -f "$KEY" ]; then
        echo "[bootstrap-tls] ERREUR : certificat ou clé manquante ; fichiers existants conservés." >&2
        exit 1
    fi
    if ! openssl x509 -in "$CERT" -noout -checkend 2592000 >/dev/null 2>&1; then
        echo "[bootstrap-tls] WARN : certificat expiré, bientôt expiré ou illisible ; fichiers conservés." >&2
    fi
    echo "[bootstrap-tls] certificat et clé existants conservés"
    exit 0
fi

echo "[bootstrap-tls] Génération cert self-signed CN=$CN ($DAYS jours)"
openssl req -x509 -nodes \
    -newkey rsa:2048 \
    -keyout "$KEY" \
    -out "$CERT" \
    -days "$DAYS" \
    -subj "/CN=$CN" \
    -addext "subjectAltName=DNS:localhost,DNS:$CN,IP:127.0.0.1"

chmod 0600 "$KEY"
chmod 0644 "$CERT"

echo "[bootstrap-tls] cert généré dans $TLS_DIR"
