#!/bin/bash
# Initialise le MOK keypair dans MOK_DIR :
#   - Si vide : génère une clé propre à cette instance.
#   - Sinon (déjà présent) : skip pour ne pas casser les machines déjà enrôlées en SB.
# Pour regen FORCE : appeler avec REGEN=1 (génère un nouveau keypair, OVERRIDE l'existant).
# Expose MOK.der via HTTP root (pour download client → enrôlement mokutil).
set -euo pipefail

: "${MOK_DIR:=/etc/pxe-tools/mok}"
: "${HTTP_ROOT:=/srv/http}"
: "${MOK_CN:=PXE Tools MOK}"
: "${MOK_KEY_BITS:=2048}"
: "${REGEN:=0}"

if [ "${PXE_SERVICE_MANAGER:-}" = systemd ]; then
    python3 "$(dirname "${BASH_SOURCE[0]}")/validate-native-runtime.py" mok "$MOK_DIR"
    if [ "$REGEN" != 1 ] && [ "$MOK_DIR" != /etc/pxe-tools/mok ] \
        && [ ! -e "$MOK_DIR/MOK.key" ] && [ ! -L "$MOK_DIR/MOK.key" ] \
        && { [ -e /etc/pxe-tools/mok/MOK.key ] || [ -L /etc/pxe-tools/mok/MOK.key ]; }; then
        echo '[bootstrap-mok] Une ancienne clé MOK existe : migrer et vérifier son identité avant de démarrer.' >&2
        exit 1
    fi
else
    mkdir -p "$MOK_DIR"
fi

if [ "$REGEN" = "1" ]; then
    # Backup ancien keypair (utile si l'utilisateur veut rollback)
    if [ -f "$MOK_DIR/MOK.key" ]; then
        ts=$(date +%Y%m%d-%H%M%S)
        echo "[bootstrap-mok] Backup ancien MOK vers $MOK_DIR/backup-$ts/"
        mkdir -p "$MOK_DIR/backup-$ts"
        cp "$MOK_DIR"/MOK.{key,pem,der} "$MOK_DIR/backup-$ts/" 2>/dev/null || true
    fi

    echo "[bootstrap-mok] Génération nouveau MOK (CN='$MOK_CN', $MOK_KEY_BITS bits)..."
    openssl req -newkey rsa:"$MOK_KEY_BITS" -nodes \
        -keyout "$MOK_DIR/MOK.key" \
        -new -x509 -sha256 -days 3650 \
        -subj "/CN=$MOK_CN/" \
        -out "$MOK_DIR/MOK.pem"
    openssl x509 -in "$MOK_DIR/MOK.pem" -outform DER -out "$MOK_DIR/MOK.der"
elif [ ! -f "$MOK_DIR/MOK.key" ]; then
    echo "[bootstrap-mok] Génération de la clé de cette instance (CN='$MOK_CN', $MOK_KEY_BITS bits)"
    openssl req -newkey rsa:"$MOK_KEY_BITS" -nodes \
        -keyout "$MOK_DIR/MOK.key" \
        -new -x509 -sha256 -days 3650 \
        -subj "/CN=$MOK_CN/" \
        -out "$MOK_DIR/MOK.pem"
    openssl x509 -in "$MOK_DIR/MOK.pem" -outform DER -out "$MOK_DIR/MOK.der"
else
    echo "[bootstrap-mok] MOK déjà présent dans $MOK_DIR, skip (REGEN=1 pour forcer)"
fi

chmod 0700 "$MOK_DIR"
chmod 0600 "$MOK_DIR/MOK.key"
chmod 0644 "$MOK_DIR/MOK.pem" "$MOK_DIR/MOK.der"

# Expose MOK.der via HTTP pour enrôlement client (mokutil --import MOK.der)
if [ "${PXE_SERVICE_MANAGER:-}" = systemd ]; then
    python3 "$(dirname "${BASH_SOURCE[0]}")/publish-public-mok.py" "$MOK_DIR" "$HTTP_ROOT"
else
    mkdir -p "$HTTP_ROOT"
    cp "$MOK_DIR/MOK.der" "$HTTP_ROOT/MOK.der"
    chmod 0644 "$HTTP_ROOT/MOK.der"
fi

echo "[bootstrap-mok] OK"
