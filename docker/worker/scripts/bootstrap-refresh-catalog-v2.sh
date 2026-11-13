#!/bin/bash
# bootstrap-refresh-catalog-v2 — wrapper qui appelle refresh-catalog.php.
#
# Le travail réel se fait en PHP (refresh-catalog.php) pour pouvoir
# instancier DistroCatalog et utiliser PDO via /etc/pxe-tools/db.conf. Ce
# wrapper bash existe pour rester aligné au pattern worker (1 script bash
# par job_type).
#
# Variables :
#   WORKER_JOB_ID : id init_job pour side-channel progress (passé par worker.py)
set -euo pipefail

SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
PHP_SCRIPT="$SCRIPT_DIR/refresh-catalog.php"

if [ ! -f "$PHP_SCRIPT" ]; then
    echo "[refresh-catalog-v2] ERREUR : $PHP_SCRIPT introuvable" >&2
    exit 2
fi

echo "[refresh-catalog-v2] ▶ php $PHP_SCRIPT (WORKER_JOB_ID=${WORKER_JOB_ID:-})"
exec php "$PHP_SCRIPT"
