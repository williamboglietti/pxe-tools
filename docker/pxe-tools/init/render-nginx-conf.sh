#!/bin/sh
# Rendu de la conf nginx admin selon la setting https_enabled.
# Variables ADMIN_PORT / ADMIN_HTTP_PORT permettent à l'utilisateur d'override
# les ports admin via .env quand 8443/8080 sont déjà occupés sur l'host.
# Exécuté en oneshot s6 avant le démarrage du longrun nginx, et re-exécuté
# par worker.py (init_job system:nginx_reload) quand le toggle change runtime.
#
# Sortie :
#   /etc/nginx/conf.d/admin-https.conf  ← seulement si HTTPS est activé
#   /etc/nginx/conf.d/admin-http.conf   ← HTTP listener (ADMIN_HTTP_PORT) :
#       - https_enabled='on'  : rendu depuis admin-http-redirect.conf.tmpl (301 → HTTPS)
#       - https_enabled='off' : rendu depuis admin-http-serve.conf.tmpl    (sert l'app)
#
# Le toggle https_enabled contrôle les deux listeners admin :
#   - 'on'  → "Forcer la redirection HTTPS" (redirect 8080→8443)
#   - 'off' → HTTP seul sur ADMIN_HTTP_PORT (mode reverse proxy externe)
set -eu

: "${ADMIN_PORT:=8443}"
: "${ADMIN_HTTP_PORT:=8080}"
: "${PXE_NGINX_CONF_DIR:=/etc/nginx/conf.d}"
: "${PXE_NGINX_TEMPLATE_DIR:=/opt/pxe-tools/nginx}"
case "$PXE_NGINX_CONF_DIR" in /*) ;; *) echo '[render-nginx-conf] Répertoire de configuration absolu requis.' >&2; exit 1 ;; esac
case "$PXE_NGINX_TEMPLATE_DIR" in /*) ;; *) echo '[render-nginx-conf] Répertoire de modèles absolu requis.' >&2; exit 1 ;; esac
export ADMIN_PORT ADMIN_HTTP_PORT

# Refuse an unmanaged legacy file before rendering instead of deleting it.
if [ -e "$PXE_NGINX_CONF_DIR/admin.conf" ] || [ -L "$PXE_NGINX_CONF_DIR/admin.conf" ]; then
    echo "[render-nginx-conf] ERREUR : ancienne configuration admin présente ; la conserver et résoudre le conflit avant de continuer." >&2
    exit 1
fi

# A fresh instance has no DB yet. Once one is configured, failure to read the
# saved TLS setting must not silently turn HTTPS off. The same PDO path handles
# both supported configuration formats without putting credentials in argv.
HTTPS_ENABLED='off'
DB_CONF='/etc/pxe-tools/db.conf'
if [ -e "$DB_CONF" ] || [ -L "$DB_CONF" ] || [ -n "${PXE_DB_DRIVER:-}" ]; then
    if ! HTTPS_ENABLED=$(php -r '
        require rtrim(getenv("PXE_APP_DIR") ?: "/app", "/") . "/src/Db.php";
        try {
            $file = \Admin\Db::CONFIG_PATH;
            if (file_exists($file) || is_link($file)) {
                $raw = @file_get_contents($file);
                $cfg = $raw === false ? null : json_decode($raw, true);
                if (!is_array($cfg) || !in_array($cfg["driver"] ?? null, ["sqlite", "mysql", "mariadb"], true)) exit(1);
            }
            $cfg = \Admin\Db::config();
            if ($cfg["driver"] === "sqlite" && !is_file($cfg["path"])) exit(1);
            $db = \Admin\Db::get(false);
            $key = \Admin\Db::quoteIdent("key");
            $stmt = $db->prepare("SELECT value FROM settings WHERE $key=? LIMIT 1");
            $stmt->execute(["https_enabled"]);
            $value = $stmt->fetchColumn();
            if ($value === false) $value = "off";
            if (!in_array($value, ["on", "off"], true)) exit(1);
            echo $value;
        } catch (Throwable $error) { exit(1); }
    '); then
        echo "[render-nginx-conf] ERREUR : impossible de lire le réglage HTTPS ; configuration conservée." >&2
        exit 1
    fi
fi

# Filtre limité : ne substitue QUE ADMIN_PORT et ADMIN_HTTP_PORT, laisse les
# `$xxx` nginx intacts ($document_root, $fastcgi_script_name, $host, etc.).

# HTTPS listener seulement lorsque le chiffrement local est activé.
if [ "$HTTPS_ENABLED" = 'off' ]; then
    rm -f "$PXE_NGINX_CONF_DIR/admin-https.conf"
    HTTP_TEMPLATE="$PXE_NGINX_TEMPLATE_DIR/admin-http-serve.conf.tmpl"
    LABEL="HTTP :$ADMIN_HTTP_PORT seul (mode reverse proxy)"
else
    envsubst '${ADMIN_PORT} ${ADMIN_HTTP_PORT}' \
        < "$PXE_NGINX_TEMPLATE_DIR/admin-https.conf.tmpl" \
        > "$PXE_NGINX_CONF_DIR/admin-https.conf"
    HTTP_TEMPLATE="$PXE_NGINX_TEMPLATE_DIR/admin-http-redirect.conf.tmpl"
    LABEL="HTTPS :$ADMIN_PORT + HTTP :$ADMIN_HTTP_PORT redirect → HTTPS"
fi
envsubst '${ADMIN_PORT} ${ADMIN_HTTP_PORT}' \
    < "$HTTP_TEMPLATE" \
    > "$PXE_NGINX_CONF_DIR/admin-http.conf"

echo "[render-nginx-conf] configuration admin appliquée — $LABEL"
