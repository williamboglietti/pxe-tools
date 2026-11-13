#!/bin/sh
# Healthcheck du container pxe-tools.
# Probe les surfaces actives :
#   - PXE port :80 (nginx pxe.conf, sert les clients PXE)
#   - HTTP admin port :${ADMIN_HTTP_PORT} (serve direct OU redirect vers HTTPS)
#   - HTTPS admin port :${ADMIN_PORT} si https_enabled='on'
#
# Le HTTP listener répond toujours 200 sur /healthz, que ce soit en mode
# redirect (block /healthz exempté du return 301) ou serve direct. Le HTTPS
# listener est absent lorsque https_enabled='off'.
#
# Exit 0 si tous les probes répondent 200, exit 1 sinon.
#
# Appelé par docker-compose.yml healthcheck.test toutes les 30s.
set -eu

ADMIN_PORT="${ADMIN_PORT:-8443}"
ADMIN_HTTP_PORT="${ADMIN_HTTP_PORT:-8080}"

curl -fsS http://127.0.0.1/healthz >/dev/null
curl -fsS "http://127.0.0.1:${ADMIN_HTTP_PORT}/healthz" >/dev/null
DB_CONF=/etc/pxe-tools/db.conf
if [ -f "$DB_CONF" ]; then
    DRIVER=$(grep -o '"driver"[[:space:]]*:[[:space:]]*"[^"]*"' "$DB_CONF" 2>/dev/null \
             | sed 's/.*"\([^"]*\)"$/\1/' || true)
    if [ "$DRIVER" = sqlite ]; then
        DB_PATH=$(grep -o '"path"[[:space:]]*:[[:space:]]*"[^"]*"' "$DB_CONF" 2>/dev/null \
                  | sed 's/.*"\([^"]*\)"$/\1/' || true)
        if [ -n "$DB_PATH" ] && [ -f "$DB_PATH" ]; then
            HTTPS_ENABLED=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='https_enabled' LIMIT 1" 2>/dev/null || true)
            if [ "$HTTPS_ENABLED" = on ]; then
                curl -fsSk "https://127.0.0.1:${ADMIN_PORT}/healthz" >/dev/null
            fi
        fi
    fi
fi
