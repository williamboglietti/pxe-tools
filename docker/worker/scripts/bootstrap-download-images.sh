#!/bin/bash
# Rétro-compat (mai 2026) : ce script est conservé uniquement pour les init_jobs
# legacy `bootstrap:download_images` déjà en file. Il NE télécharge plus rien
# directement — il marque simplement toutes les images enabled+pending/failed
# pour que le worker (run_image_download) les pickle via la nouvelle voie
# /settings/images.
#
# Le téléchargement des images est désormais géré image-par-image avec live
# progress sur la page /settings/images (cf. bootstrap-download-image.sh
# singulier).
set -euo pipefail

PXE_DB_PATH=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py")
export PXE_DB_PATH

if [ ! -f "$PXE_DB_PATH" ]; then
    if [ -e /etc/pxe-tools/db.conf ]; then
        echo '[bootstrap-images] Base SQLite configurée absente.' >&2
        exit 1
    fi
    echo "[bootstrap-images] DB introuvable : $PXE_DB_PATH — skip"
    exit 0
fi

# Reset à pending les rows downloading orphelines (équivalent reaper côté script).
sqlite3 "$PXE_DB_PATH" \
    "UPDATE os_images SET status='pending', error_msg='Reaper legacy script' WHERE status='downloading'"

# Les images enabled+failed sont remises à 'pending' : le worker ne pioche QUE
# 'pending' (fetch_pending_image_download — 'failed' est terminal), donc ce reset
# est le filet de sécurité qui relance à l'init les téléchargements en échec.
sqlite3 "$PXE_DB_PATH" \
    "UPDATE os_images SET status='pending', error_msg=NULL WHERE enabled=1 AND status='failed'"
count=$(sqlite3 "$PXE_DB_PATH" \
    "SELECT COUNT(*) FROM os_images WHERE enabled=1 AND status='pending'")
echo "[bootstrap-images] $count image(s) en file — worker les traite via /settings/images"
exit 0
