#!/bin/bash
# Refresh du catalogue Debian dans os_images :
#   1. Parse Release files pour récupérer minor_version (ex "13.4")
#   2. Découvre nouvelles releases majeures (skip sid/experimental/*-backports/...)
#   3. Valide kernel_url + initrd_url via curl HEAD
#
# Déclenché manuellement par bouton "Refresh catalogue" sur /settings/images
# (POST /settings/images/refresh → init_jobs row bootstrap:refresh_catalog).
# NE PAS auto-enqueue au démarrage.
#
# Publish live via side-channel SQLite (init_jobs.message + progress_percent + updated_at).
set -euo pipefail

: "${HTTP_ROOT:=/srv/http}"
PXE_DB_PATH=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py")
export PXE_DB_PATH
: "${DEBIAN_MIRROR:=http://deb.debian.org/debian}"
: "${DEBIAN_FIRMWARE_BASE:=https://cdimage.debian.org/cdimage/firmware}"
: "${WORKER_JOB_ID:=}"

if [ ! -f "$PXE_DB_PATH" ]; then
    echo "[refresh-catalog] ERREUR : DB introuvable : $PXE_DB_PATH" >&2
    exit 2
fi

sql_quote() {
    printf "%s" "$1" | sed "s/'/''/g"
}

publish() {
    local message="$1" pct="$2"
    [ -z "$WORKER_JOB_ID" ] && return 0
    local safe
    safe=$(sql_quote "$message")
    sqlite3 "$PXE_DB_PATH" \
        "UPDATE init_jobs SET message='$safe', progress_percent=$pct, updated_at=strftime('%Y-%m-%d %H:%M:%S','now') WHERE id=$WORKER_JOB_ID" \
        2>/dev/null || true
}

# Mapping inline codename → channel. Script v1 legacy ; en v2 la source de
# vérité est Admin\Catalog\DebianCatalog::MAPPING (PHP), invoquée par
# bootstrap-refresh-catalog-v2.sh + refresh-catalog.php.
# Un codename non listé ici sera traité comme 'testing' (fallback).
channel_for() {
    case "$1" in
        bookworm) echo "oldstable" ;;
        trixie)   echo "stable" ;;
        forky)    echo "testing" ;;
        *)        echo "testing" ;;
    esac
}

# ============================================================================
# 1. Parse Release files pour récupérer minor_version
# ============================================================================
publish "Lecture des Release files…" 5

versions=$(sqlite3 "$PXE_DB_PATH" \
    "SELECT DISTINCT version FROM os_images WHERE distro='debian' ORDER BY version")
total=$(printf '%s\n' "$versions" | grep -c . || true)
[ "$total" = "0" ] && total=1

count=0
for ver in $versions; do
    count=$((count + 1))
    release_url="$DEBIAN_MIRROR/dists/$ver/Release"
    # `|| true` neutralise l'exit 23 de curl (SIGPIPE quand awk `exit` ferme
    # stdout avant que curl ait fini d'écrire). Sans ça, pipefail + set -e
    # tue le script alors que la donnée a bien été extraite.
    minor=$(curl -fsSL --max-time 15 "$release_url" 2>/dev/null \
            | awk -F': ' '/^Version: / { print $2; exit }' || true)
    channel=$(channel_for "$ver")
    if [ -n "$minor" ]; then
        safe_minor=$(sql_quote "$minor")
        safe_chan=$(sql_quote "$channel")
        # COALESCE(NULLIF(channel, ''), '...') : ne pas écraser un channel déjà posé
        sqlite3 "$PXE_DB_PATH" \
            "UPDATE os_images SET minor_version='$safe_minor', channel=COALESCE(NULLIF(channel, ''), '$safe_chan') WHERE distro='debian' AND version='$ver'"
        echo "[refresh-catalog]   $ver → minor_version=$minor channel=$channel"
    else
        echo "[refresh-catalog]   $ver → Release file inaccessible, skip minor_version" >&2
    fi
    pct=$(( 5 + count * 30 / total ))
    publish "Lecture Release : $ver" "$pct"
done

# ============================================================================
# 2. Découverte de nouvelles releases (parse listing Apache /dists/)
# ============================================================================
publish "Découverte de nouvelles releases…" 40

# Filter : skip sid, experimental, stable/testing/unstable/oldstable/oldoldstable
# (aliases), *-backports, *-proposed-updates, *-updates, *-security.
discovered=$(curl -fsSL --max-time 15 "$DEBIAN_MIRROR/dists/" 2>/dev/null \
    | grep -oE 'href="[a-z][a-z0-9]*/"' \
    | sed -e 's/href="//' -e 's|/"||' \
    | grep -vE '^(sid|experimental|stable|testing|unstable|oldstable|oldoldstable)$' \
    | grep -vE '\-(backports|proposed-updates|updates|security)$' \
    | sort -u || true)

new_count=0
for codename in $discovered; do
    existing=$(sqlite3 "$PXE_DB_PATH" \
        "SELECT COUNT(*) FROM os_images WHERE distro='debian' AND version='$codename'")
    [ "$existing" != "0" ] && continue

    channel=$(channel_for "$codename")
    safe_chan=$(sql_quote "$channel")

    for arch in amd64 arm64; do
        base="$DEBIAN_MIRROR/dists/$codename/main/installer-$arch/current/images/netboot/debian-installer/$arch"
        kernel_url="$base/linux"
        initrd_url="$base/initrd.gz"
        firmware_url="$DEBIAN_FIRMWARE_BASE/$codename/current/firmware.cpio.gz"
        sqlite3 "$PXE_DB_PATH" <<SQL
INSERT INTO os_images (distro, version, arch, kernel_url, initrd_url, firmware_url, enabled, status, channel)
VALUES ('debian', '$codename', '$arch', '$kernel_url', '$initrd_url', '$firmware_url', 0, 'pending', '$safe_chan')
SQL
        new_count=$((new_count + 1))
    done
    echo "[refresh-catalog]   découvert : $codename (channel=$channel, 2 archs INSERT)"
done

publish "$new_count row(s) nouvelle(s)" 60

# ============================================================================
# 3. Validation des URLs (curl HEAD)
# ============================================================================
publish "Validation des URLs…" 65

rows=$(sqlite3 -separator '|' "$PXE_DB_PATH" \
    "SELECT id, version, arch, kernel_url, initrd_url FROM os_images WHERE distro='debian'")
total_rows=$(printf '%s\n' "$rows" | grep -c . || true)
[ "$total_rows" = "0" ] && total_rows=1

checked=0
errors=0
while IFS='|' read -r id version arch kurl iurl; do
    [ -z "$id" ] && continue
    checked=$((checked + 1))

    err=""
    # -f fail on >=400, -s silent, -S show errors, -I HEAD, -L follow redirects
    if ! curl -fsSIL --max-time 10 "$kurl" >/dev/null 2>&1; then
        err="URL kernel inaccessible"
    elif ! curl -fsSIL --max-time 10 "$iurl" >/dev/null 2>&1; then
        err="URL initrd inaccessible"
    fi

    if [ -n "$err" ]; then
        errors=$((errors + 1))
        safe_err=$(sql_quote "$err")
        sqlite3 "$PXE_DB_PATH" \
            "UPDATE os_images SET error_msg='$safe_err' WHERE id=$id"
        echo "[refresh-catalog]   ✗ id=$id $version/$arch : $err" >&2
    else
        # Reset error_msg si on avait posé une erreur URL précédemment (best-effort).
        sqlite3 "$PXE_DB_PATH" \
            "UPDATE os_images SET error_msg=NULL WHERE id=$id AND error_msg LIKE 'URL %'"
    fi

    pct=$(( 65 + checked * 30 / total_rows ))
    publish "Validation $version/$arch ($checked/$total_rows)" "$pct"
done <<< "$rows"

publish "Catalogue à jour ($errors erreur(s))" 100

echo "[refresh-catalog] ▶ $count Release file(s) parsé(s), $new_count row(s) ajoutée(s), $errors URL(s) invalide(s)"
exit 0
