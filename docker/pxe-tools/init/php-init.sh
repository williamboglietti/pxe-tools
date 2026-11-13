#!/bin/sh
# Init DB driver-aware (SQLite / MySQL / MariaDB).
# Exécuté en oneshot s6 AVANT le démarrage du longrun php-fpm.
# Idempotent : crée la DB si absente, lance migrations data.
#
# Source de vérité : /etc/pxe-tools/db.conf (JSON, posé par WizardController
# au /init/db OU par Ansible via templating, OU auto-généré ici en mode
# back-compat si une DB SQLite legacy existe déjà).
#
# Flux :
#   1. Si db.conf absent ET orchestrator.sqlite legacy existe → auto-générer
#      db.conf SQLite (back-compat upgrades).
#   2. Si db.conf absent ET pas de SQLite legacy → laisser php-fpm démarrer ;
#      le wizard /init/db prendra le relais.
#   3. Si db.conf SQLite → sqlite3 < schema.sqlite.sql + php migrate.php.
#   4. Si la configuration effective indique MySQL/MariaDB → refus sans connexion.
set -eu

DB_CONF="/etc/pxe-tools/db.conf"
: "${PXE_APP_DIR:=/app}"
SCHEMA_SQLITE="$PXE_APP_DIR/db/schema.sqlite.sql"
MIGRATE_PHP="$PXE_APP_DIR/db/migrate.php"
# Path par défaut SQLite. Priorité : env PXE_DB_PATH > env ORCHESTRATOR_DB (legacy
# 2026-05) > pxe-tools.sqlite (nouveau défaut). Le path legacy orchestrator.sqlite
# est détecté plus bas si présent et conservé à son chemin existant.
SQLITE_DEFAULT="${PXE_DB_PATH:-${ORCHESTRATOR_DB:-/var/lib/pxe-tools/pxe-tools.sqlite}}"
LEGACY_SQLITE="/var/lib/pxe-tools/orchestrator.sqlite"
ENV_DB_DRIVER=$(printf '%s' "${PXE_DB_DRIVER:-}" | tr '[:upper:]' '[:lower:]')

# Read the effective driver without printing the configuration or credentials.
# Reject duplicate keys and malformed JSON before touching persistent state.
read_config_driver() {
    python3 - "$1" <<'PYTHON'
import json
import sys

def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate key')
        result[key] = value
    return result

try:
    with open(sys.argv[1], encoding='utf-8') as config:
        data = json.load(config, object_pairs_hook=unique_pairs)
    driver = data.get('driver') if isinstance(data, dict) else None
    if not isinstance(driver, str) or driver.lower() not in ('sqlite', 'mysql', 'mariadb'):
        raise ValueError('invalid driver')
    print(driver.lower(), end='')
except (OSError, UnicodeError, ValueError, RecursionError):
    sys.exit(1)
PYTHON
}
refuse_external() {
    echo '[php-init] ERREUR : MySQL/MariaDB ne sont pas encore pris en charge ; configuration et données conservées.' >&2
    exit 1
}
invalid_configuration() {
    echo '[php-init] ERREUR : configuration de base invalide ou ambiguë ; fichier et données conservés.' >&2
    exit 1
}
EFFECTIVE_DB_DRIVER="$ENV_DB_DRIVER"
if [ -e "$DB_CONF" ] || [ -L "$DB_CONF" ]; then
    [ -f "$DB_CONF" ] || invalid_configuration
    EFFECTIVE_DB_DRIVER=$(read_config_driver "$DB_CONF") || invalid_configuration
fi
case "$EFFECTIVE_DB_DRIVER" in
    mysql|mariadb) refuse_external ;;
esac

mkdir -p /etc/pxe-tools
chmod 0770 /etc/pxe-tools
chown root:www-data /etc/pxe-tools 2>/dev/null || true

# -----------------------------------------------------------------------------
# 0b. Master key pour Admin\Util\Crypto + app/crypto.py (chiffrement libsodium
#     secretbox des secrets en DB : github_push_token, etc.). Elle est
#     générée seulement au premier démarrage d'une configuration vide. Si une
#     base ou un db.conf existe sans la clé, ne pas en générer une nouvelle :
#     les secrets déjà chiffrés deviendraient illisibles. pxe_conf est un volume
#     persistant distinct de pxe_data, tous deux à sauvegarder/restaurer ensemble.
# -----------------------------------------------------------------------------
MASTER_KEY="/etc/pxe-tools/master.key"
set_master_key_owner() {
    # /etc/pxe-tools is writable by the setup wizard. Pin the regular file
    # before changing its owner or mode so a concurrent symlink cannot redirect
    # a root operation outside that directory.
    python3 - "$1" <<'PYTHON'
import grp
import os
import pwd
import stat
import sys

path = sys.argv[1]
fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
try:
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError('master.key must be a private regular file')
    uid = pwd.getpwnam('www-data').pw_uid
    try:
        gid = grp.getgrnam('ansible').gr_gid
    except KeyError:
        gid = grp.getgrnam('www-data').gr_gid
    os.fchmod(fd, 0o440)
    os.fchown(fd, uid, gid)
finally:
    os.close(fd)
PYTHON
}
run_as_web() {
    if [ -x /usr/sbin/runuser ]; then
        /usr/sbin/runuser -u www-data -- "$@"
    else
        /command/s6-setuidgid www-data "$@"
    fi
}
prepare_sessions() {
    python3 - <<'PYTHON'
import os
import pwd

account = pwd.getpwnam('www-data')
root = os.open('/var/lib/pxe-tools', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    try:
        os.mkdir('sessions', 0o700, dir_fd=root)
    except FileExistsError:
        pass
    sessions = os.open('sessions', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW,
                       dir_fd=root)
    try:
        os.fchmod(sessions, 0o700)
        os.fchown(sessions, account.pw_uid, account.pw_gid)
    finally:
        os.close(sessions)
finally:
    os.close(root)
PYTHON
}
if [ -L "$MASTER_KEY" ]; then
    echo "[php-init] ERREUR: master.key ne doit pas être un lien symbolique" >&2
    exit 1
fi
if [ -e "$MASTER_KEY" ] && [ ! -f "$MASTER_KEY" ]; then
    echo "[php-init] ERREUR: master.key existe mais n'est pas un fichier ordinaire" >&2
    exit 1
fi

if [ ! -e "$MASTER_KEY" ]; then
    EXISTING_DB=""
    for candidate in \
        /var/lib/pxe-tools/pxe-tools.sqlite \
        /var/lib/pxe-tools/orchestrator.sqlite \
        "${PXE_DB_PATH:-}" \
        "${ORCHESTRATOR_DB:-}"; do
        if [ -n "$candidate" ] && [ -f "$candidate" ] && [ -s "$candidate" ]; then
            EXISTING_DB="$candidate"
            break
        fi
    done
    if [ -e "$DB_CONF" ] || [ -n "$EXISTING_DB" ] || [ "$ENV_DB_DRIVER" = mysql ] || [ "$ENV_DB_DRIVER" = mariadb ]; then
        echo "[php-init] ERREUR: db.conf ou une base existe sans master.key ; restaurer ensemble pxe_conf et pxe_data" >&2
        exit 1
    fi

    umask 077
    TEMP_MASTER_KEY=$(mktemp /etc/pxe-tools/.master.key.XXXXXX)
    if ! head -c 32 /dev/urandom > "$TEMP_MASTER_KEY"; then
        rm -f "$TEMP_MASTER_KEY"
        echo "[php-init] ERREUR: impossible de générer master.key" >&2
        exit 1
    fi
    KEY_SIZE=$(wc -c < "$TEMP_MASTER_KEY" | tr -d '[:space:]')
    if [ "$KEY_SIZE" != "32" ]; then
        rm -f "$TEMP_MASTER_KEY"
        echo "[php-init] ERREUR: master.key générée avec une taille inattendue" >&2
        exit 1
    fi
    set_master_key_owner "$TEMP_MASTER_KEY"
    if ! ln "$TEMP_MASTER_KEY" "$MASTER_KEY" 2>/dev/null; then
        rm -f "$TEMP_MASTER_KEY"
        if [ ! -f "$MASTER_KEY" ] || [ -L "$MASTER_KEY" ]; then
            echo "[php-init] ERREUR: impossible de créer master.key sans écraser un fichier" >&2
            exit 1
        fi
    else
        rm -f "$TEMP_MASTER_KEY"
        echo "[php-init] master.key créée pour une configuration neuve"
    fi
fi

KEY_SIZE=$(wc -c < "$MASTER_KEY" | tr -d '[:space:]')
if [ "$KEY_SIZE" != "32" ]; then
    echo "[php-init] ERREUR: master.key doit contenir exactement 32 octets" >&2
    exit 1
fi
set_master_key_owner "$MASTER_KEY"

# -----------------------------------------------------------------------------
# 1. Back-compat : auto-génère db.conf SQLite si une DB existante est détectée mais
#     que db.conf n'a pas encore été posé. Garantit qu'un upgrade d'image
#     ne déclenche pas le wizard à nouveau sur un déploiement existant.
# -----------------------------------------------------------------------------
DETECTED_DB=""
for candidate in "$SQLITE_DEFAULT" "$LEGACY_SQLITE" /var/lib/pxe-tools/pxe-tools.sqlite; do
    if [ -f "$candidate" ] && [ -s "$candidate" ]; then
        DETECTED_DB="$candidate"
        break
    fi
done
if [ ! -e "$DB_CONF" ] && [ ! -L "$DB_CONF" ] && [ -n "$DETECTED_DB" ] && \
   [ "$ENV_DB_DRIVER" != mysql ] && [ "$ENV_DB_DRIVER" != mariadb ]; then
    echo "[php-init] db.conf absent + DB SQLite legacy détectée ($DETECTED_DB) → auto-gen db.conf"
    php -r 'require $argv[1]; \Admin\Db::writeInitialConfig(["driver" => "sqlite", "path" => $argv[2]]);' "$PXE_APP_DIR/src/Db.php" "$DETECTED_DB" || {
        echo '[php-init] ERREUR : impossible de créer db.conf sans écraser un fichier.' >&2
        exit 1
    }
    chown www-data:www-data "$DB_CONF" 2>/dev/null || true
fi

# -----------------------------------------------------------------------------
# 2. Si toujours pas de db.conf → wizard /init/db prendra le relais
# -----------------------------------------------------------------------------
if [ ! -f "$DB_CONF" ]; then
    echo "[php-init] db.conf absent → wizard /init/db prendra le relais"
    # On crée juste le répertoire sessions PHP + perms (toujours nécessaires)
    prepare_sessions
    mkdir -p /run/php
    chown www-data:www-data /run/php
    # Le wizard doit pouvoir créer sa base ; ne jamais parcourir récursivement
    # un arbre de données susceptible de contenir des entrées non fiables.
    if [ -L /var/lib/pxe-tools ] || [ ! -d /var/lib/pxe-tools ]; then
        echo '[php-init] ERREUR : répertoire de données absent ou lié.' >&2
        exit 1
    fi
    chown www-data:www-data /var/lib/pxe-tools
    # Le worker historique peut avoir créé une base vide en root avant le
    # wizard. Ne toucher qu'à ces deux fichiers connus, avec O_NOFOLLOW.
    python3 - <<'PYTHON'
import os
import pwd
import stat

directory = os.open('/var/lib/pxe-tools', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    account = pwd.getpwnam('www-data')
    for name in ('pxe-tools.sqlite', 'orchestrator.sqlite'):
        try:
            database = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory)
        except FileNotFoundError:
            continue
        try:
            info = os.fstat(database)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size != 0:
                raise ValueError('unexpected database before setup')
            os.fchown(database, account.pw_uid, account.pw_gid)
        finally:
            os.close(database)
finally:
    os.close(directory)
PYTHON
    # Le wizard peut initialiser le fichier vide sans le supprimer.
    exit 0
fi

# A manually provisioned root-owned configuration may be mode 0600. PHP-FPM
# must read the exact same configuration as the root worker; a silent fallback
# could point it at another database. Preserve contents and the owner, grant
# only the web-service group read access if needed, and fail if not possible.
web_can_read() {
    if [ -x /command/s6-setuidgid ]; then
        /command/s6-setuidgid www-data test -r "$DB_CONF"
    elif command -v runuser >/dev/null 2>&1; then
        runuser -u www-data -- test -r "$DB_CONF"
    else
        return 1
    fi
}
if ! web_can_read; then
    if [ -L "$DB_CONF" ] || ! python3 - "$DB_CONF" <<'PYTHON'
import grp
import os
import stat
import sys

fd = os.open(sys.argv[1], os.O_RDONLY | os.O_NOFOLLOW)
try:
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError('db.conf must be a regular file')
    os.fchmod(fd, stat.S_IMODE(info.st_mode) | 0o040)
    os.fchown(fd, -1, grp.getgrnam('www-data').gr_gid)
finally:
    os.close(fd)
PYTHON
    then
        echo '[php-init] ERREUR : db.conf doit être lisible par le service web ; contenu conservé.' >&2
        exit 1
    fi
    web_can_read || { echo '[php-init] ERREUR : db.conf reste illisible pour le service web.' >&2; exit 1; }
fi

# -----------------------------------------------------------------------------
# 3. Re-read and validate the driver; the file may have changed after startup.
# -----------------------------------------------------------------------------
DRIVER=$(read_config_driver "$DB_CONF") || invalid_configuration
case "$DRIVER" in
    sqlite) ;;
    mysql|mariadb) refuse_external ;;
    *) invalid_configuration ;;
esac
echo "[php-init] driver=$DRIVER"

# -----------------------------------------------------------------------------
# 4a. SQLite : init schema + migrate
# -----------------------------------------------------------------------------
if [ "$DRIVER" = "sqlite" ]; then
    DB_PATH=$(grep -o '"path"[[:space:]]*:[[:space:]]*"[^"]*"' "$DB_CONF" | sed 's/.*"\([^"]*\)"$/\1/')
    DB_DIR=$(dirname "$DB_PATH")
    # Le paquet et l'image montent les données dans ce répertoire. Un chemin
    # externe ne doit jamais commander une écriture ou un chown exécutés en root.
    if [ "$DB_DIR" != /var/lib/pxe-tools ] || [ ! -d "$DB_DIR" ] || [ -L "$DB_DIR" ] \
       || [ -L "$DB_PATH" ] || { [ -e "$DB_PATH" ] && [ ! -f "$DB_PATH" ]; }; then
        echo '[php-init] ERREUR : chemin SQLite hors du répertoire de données ou lié ; configuration conservée.' >&2
        exit 1
    fi
    # Changer seulement la propriété du répertoire et du fichier de base,
    # sans suivre de lien ni parcourir d'autres fichiers de l'instance.
    python3 - "$DB_PATH" <<'PYTHON'
import os
import pwd
import stat
import sys

root = '/var/lib/pxe-tools'
path = sys.argv[1]
if os.path.dirname(path) != root:
    raise ValueError('SQLite path outside the data directory')
name = os.path.basename(path)
if name in ('', '.', '..'):
    raise ValueError('invalid SQLite filename')
uid = pwd.getpwnam('www-data').pw_uid
gid = pwd.getpwnam('www-data').pw_gid
directory = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
try:
    if not stat.S_ISDIR(os.fstat(directory).st_mode):
        raise ValueError('invalid SQLite directory')
    try:
        database = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=directory)
    except FileNotFoundError:
        database = None
    try:
        if database is not None:
            info = os.fstat(database)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                raise ValueError('SQLite database must be a regular file')
            os.fchown(database, uid, gid)
        os.fchown(directory, uid, gid)
    finally:
        if database is not None:
            os.close(database)
finally:
    os.close(directory)
PYTHON

    if [ ! -f "$DB_PATH" ]; then
        if [ -f "$SCHEMA_SQLITE" ]; then
            echo "[php-init] DB SQLite absente — init depuis $SCHEMA_SQLITE"
            run_as_web sqlite3 "$DB_PATH" < "$SCHEMA_SQLITE"
        else
            echo "[php-init] WARN: $SCHEMA_SQLITE introuvable, DB non initialisée" >&2
        fi
    fi

    if [ -f "$MIGRATE_PHP" ] && [ -f "$DB_PATH" ]; then
        echo "[php-init] Migrations data SQLite : $MIGRATE_PHP"
        run_as_web php "$MIGRATE_PHP" || { echo "[php-init] ERREUR : migration refusée ou échouée ; démarrage arrêté." >&2; exit 1; }
    fi
fi

# -----------------------------------------------------------------------------
# 5. Sessions PHP + socket php-fpm — toujours nécessaires
# -----------------------------------------------------------------------------
prepare_sessions

mkdir -p /run/php
chown www-data:www-data /run/php
