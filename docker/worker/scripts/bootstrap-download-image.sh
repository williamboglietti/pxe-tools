#!/bin/bash
# Spec /settings/images (mai 2026) — Télécharge UNE image OS identifiée par IMAGE_ID.
#
# Publish live progress dans os_images.message + progress_percent + updated_at
# (side-channel SQLite). Lit la row os_images correspondante pour récupérer les
# 3 URLs (kernel, initrd, firmware) et le dest_dir.
#
# Status final :
#   - rc=0 + os_images.status='downloaded' + size_bytes + downloaded_at
#   - rc!=0 + os_images.status='failed' + error_msg
#
# Invoqué par worker.py run_image_download (qui set status='downloading' avant).
# Pour usage hors worker : IMAGE_ID=<id> bootstrap-download-image.sh
set -euo pipefail

: "${HTTP_ROOT:=/srv/http}"
PXE_DB_PATH=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py")
export PXE_DB_PATH
: "${IMAGE_ID:=}"

# Vérification d'intégrité (F-05) : gpgv + SHA256 des artefacts avant promotion.
# shellcheck source=lib-image-verify.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib-image-verify.sh"

if ! [[ "$IMAGE_ID" =~ ^[1-9][0-9]*$ ]]; then
    echo "[download-image] ERREUR : IMAGE_ID non défini" >&2
    exit 2
fi

if [ ! -f "$PXE_DB_PATH" ]; then
    echo "[download-image] DB introuvable : $PXE_DB_PATH" >&2
    exit 2
fi

# Charge la row image — kernel/initrd/firmware URLs + meta.
row=$(sqlite3 -separator '|' "$PXE_DB_PATH" \
    "SELECT distro, version, COALESCE(version_full,''), arch, kernel_url, initrd_url,
            COALESCE(codename,''), COALESCE(firmware_url, '')
     FROM os_images WHERE id = $IMAGE_ID")
if [ -z "$row" ]; then
    echo "[download-image] ERREUR : aucune image id=$IMAGE_ID" >&2
    exit 2
fi
IFS='|' read -r distro version version_full arch kernel_url initrd_url codename firmware_url <<< "$row"

safe_component() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,99}$ && "$1" != *..* ]]; }
if ! safe_component "$distro" || ! safe_component "${version_full:-$version}" || ! safe_component "$arch" \
   || { [ -n "$codename" ] && ! safe_component "$codename"; }; then
    echo '[download-image] ERREUR : identité de catalogue invalide.' >&2; exit 1
fi

# Layout v2 : /netboot/<distro>/<version_full>/<arch>/ — aligné avec les templates
# de boot (grub.cfg.j2/pxelinux-default.j2) et pxe_default_netboot_path. Fallback
# legacy v1 /netboot/<version>/<arch>/ si version_full vide (rows v1 non backfillées).
label="$distro ${version_full:-$version} $arch"
if [ -n "$version_full" ]; then
    dest_dir="$HTTP_ROOT/netboot/$distro/$version_full/$arch"
else
    dest_dir="$HTTP_ROOT/netboot/$version/$arch"
fi
if [ "${PXE_SERVICE_MANAGER:-}" = systemd ]; then
    python3 "$(dirname "${BASH_SOURCE[0]}")/validate-native-runtime.py" http-tree "$HTTP_ROOT" "netboot/$distro/${version_full:-$version}/$arch"
fi
stage_dir=$(mktemp -d /var/tmp/pxe-image.XXXXXXXX)
trap 'rm -rf -- "$stage_dir"' EXIT
trap 'exit 1' HUP INT TERM
publisher="$(dirname "${BASH_SOURCE[0]}")/publish-verified-image.py"

echo "[download-image] ▶ id=$IMAGE_ID $label"

# Quote pour SQL safe (échappe les apostrophes).
sql_quote() {
    printf "%s" "$1" | sed "s/'/''/g"
}

# Publish progress dans os_images (live).
publish() {
    local message="$1" pct="$2"
    local safe
    safe=$(sql_quote "$message")
    sqlite3 "$PXE_DB_PATH" \
        "UPDATE os_images SET message='$safe', progress_percent=$pct, updated_at=strftime('%Y-%m-%d %H:%M:%S','now') WHERE id=$IMAGE_ID" \
        2>/dev/null || true
}

publish_error() {
    local err="$1"
    local safe
    safe=$(sql_quote "$err")
    sqlite3 "$PXE_DB_PATH" \
        "UPDATE os_images SET status='failed', error_msg='$safe', updated_at=strftime('%Y-%m-%d %H:%M:%S','now') WHERE id=$IMAGE_ID" \
        2>/dev/null || true
}

human_size() {
    local b="${1:-0}"
    if command -v numfmt >/dev/null 2>&1; then
        numfmt --to=iec --suffix=o "$b" 2>/dev/null || printf "%s o" "$b"
    else
        awk -v b="$b" 'BEGIN {
            split("o Ko Mo Go To", u, " ");
            i = 1;
            while (b >= 1024 && i < 5) { b /= 1024; i++ }
            printf("%.1f %s", b, u[i]);
        }'
    fi
}

# Compute weights : kernel pèse peu, initrd moyen, firmware le plus gros.
# On répartit la progression globale (0-100) sur les fichiers pondérés par
# leur Content-Length (récupéré via curl HEAD, plus fiable que wget --spider).
# Fallback : poids fixe si HEAD échoue.
declare -A TOTALS
declare -A WEIGHTS
TOTAL_BYTES=0

# Labels FR grand public par clé technique — affichés dans le message de
# progression pour que l'utilisateur comprenne QUEL fichier se télécharge
# (3 fichiers séquentiels par image : noyau, système minimal, pilotes réseau).
declare -A FILE_LABELS=([kernel]="noyau" [initrd]="système minimal" [firmware]="pilotes réseau")

probe_size() {
    local url="$1"
    local size
    # curl -ILs : -I HEAD only, -L follow redirects, -s silent
    size=$(curl -ILs --max-time 10 "$url" 2>/dev/null | awk 'tolower($1) == "content-length:" { gsub("\r","",$2); print $2 }' | tail -1)
    if [ -z "$size" ] || [ "$size" = "0" ]; then
        # Fallback : certains miroirs ne renvoient pas Content-Length sur HEAD
        # mais honorent une requête Range — Content-Range donne alors la taille
        # totale sans télécharger le fichier (bytes 0-0/TOTAL).
        size=$(curl -sL --max-time 10 -D - -o /dev/null -H "Range: bytes=0-0" "$url" 2>/dev/null \
            | awk 'tolower($1) == "content-range:" { print $NF }' | awk -F/ '{gsub("\r","",$2); print $2}' | tail -1)
    fi
    echo "${size:-0}"
}

# Sonde les 3 tailles séquentiellement (jusqu'à ~10s chacune) : publie entre
# chaque sonde pour rafraîchir updated_at, sinon le cumul (jusqu'à 30s) peut
# dépasser STALE_THRESHOLD_S et déclencher à tort "aucune progression reçue :
# forcer la relance ?" avant même le début du téléchargement.
probe_all_sizes() {
    TOTALS["kernel"]=$(probe_size "$kernel_url")
    publish "$label · préparation du téléchargement…" 0
    TOTALS["initrd"]=$(probe_size "$initrd_url")
    publish "$label · préparation du téléchargement…" 0
    if [ -n "$firmware_url" ]; then
        TOTALS["firmware"]=$(probe_size "$firmware_url")
    else
        TOTALS["firmware"]=0
    fi
    TOTAL_BYTES=$(( TOTALS["kernel"] + TOTALS["initrd"] + TOTALS["firmware"] ))
}

# Download un fichier avec live progress (publie dans os_images en continu).
# $1=url $2=dest $3=key ('kernel'/'initrd'/'firmware', clé TOTALS/FILE_LABELS)
# $4=cumulative_bytes_before_this_file $5=index (1-based) $6=nombre total de fichiers
download_with_progress() {
    local url="$1" dest="$2" key="$3" prior="$4" idx="$5" count="$6"
    local flabel="${FILE_LABELS[$key]:-$key} ($idx/$count)"
    if [ -z "$url" ]; then
        return 0  # firmware peut être vide pour certaines distros
    fi
    echo "[download-image]   download : $url"

    # BUG historique : cette valeur cherchait TOTALS["linux"|"initrd.gz"|
    # "firmware.cpio.gz"] (basename du fichier) alors que probe_all_sizes()
    # peuple TOTALS["kernel"|"initrd"|"firmware"] (clé sémantique) — le lookup
    # ratait TOUJOURS, $total valait 0 en permanence, d'où le message sans
    # total ("· 35 Mo téléchargés") quel que soit l'état réel du réseau.
    local total="${TOTALS[$key]:-0}"
    wget -L --tries=3 --timeout=60 -q -O "$dest.tmp" "$url" &
    local wget_pid=$!

    # Boucle de monitoring (1 update/s).
    while kill -0 "$wget_pid" 2>/dev/null; do
        # Abandon si la row a disparu (factory reset / suppression pendant le DL).
        # Le worker est mono-thread et GELÉ sur ce wget : sans ça, un reset ne serait
        # traité qu'après la fin du download (potentiellement plusieurs minutes).
        # COUNT(*) (pas SELECT 1) : sur lock SQLite transitoire la sortie est vide
        # ≠ "0" → on NE coupe PAS par erreur (faux positif évité), on retentera au tick suivant.
        if [ "$(sqlite3 "$PXE_DB_PATH" "SELECT COUNT(*) FROM os_images WHERE id=$IMAGE_ID" 2>/dev/null)" = "0" ]; then
            kill "$wget_pid" 2>/dev/null || true
            wait "$wget_pid" 2>/dev/null || true
            rm -f "$dest.tmp"
            echo "[download-image] row $IMAGE_ID disparue (reset/suppression) → abandon"
            exit 0
        fi
        local got=0
        [ -f "$dest.tmp" ] && got=$(stat -c%s "$dest.tmp" 2>/dev/null || echo 0)
        local file_pct=0
        if [ "$total" -gt 0 ]; then
            file_pct=$(( got * 100 / total ))
        fi
        # Global % = (prior + got) / total_all
        local global_pct=0
        if [ "$TOTAL_BYTES" -gt 0 ]; then
            global_pct=$(( (prior + got) * 100 / TOTAL_BYTES ))
            [ "$global_pct" -gt 99 ] && global_pct=99  # garde 100% pour le mark_downloaded final
        fi
        local msg
        if [ "$total" -gt 0 ]; then
            msg="Téléchargement · $flabel · $(human_size "$got") / $(human_size "$total") · $file_pct%"
        else
            msg="Téléchargement · $flabel · $(human_size "$got") téléchargés"
        fi
        publish "$msg" "$global_pct"
        sleep 1
    done

    if wait "$wget_pid"; then
        mv "$dest.tmp" "$dest"
        return 0
    else
        rm -f "$dest.tmp"
        return 1
    fi
}

# ============================================================================
# Main
# ============================================================================
publish "$label · préparation du téléchargement…" 0
probe_all_sizes
echo "[download-image]   total estimé : $(human_size "$TOTAL_BYTES")"

prior=0
err=""
total_files=2
[ -n "$firmware_url" ] && total_files=3

if ! download_with_progress "$kernel_url" "$stage_dir/linux" "kernel" "$prior" 1 "$total_files"; then
    err="Échec du téléchargement"
else
    prior=$(( prior + TOTALS["kernel"] ))
fi

if [ -z "$err" ]; then
    if ! download_with_progress "$initrd_url" "$stage_dir/initrd.gz" "initrd" "$prior" 2 "$total_files"; then
        err="Échec du téléchargement"
    else
        prior=$(( prior + TOTALS["initrd"] ))
    fi
fi

if [ -z "$err" ] && [ -n "$firmware_url" ]; then
    if ! download_with_progress "$firmware_url" "$stage_dir/firmware.cpio.gz" "firmware" "$prior" 3 "$total_files"; then
        err="Échec du téléchargement"
    fi
fi

if [ -z "$err" ] && { [ ! -s "$stage_dir/linux" ] || [ ! -s "$stage_dir/initrd.gz" ]; }; then
    err="Image incomplète"
fi
if [ -n "$err" ]; then
    publish_error "$err"
    echo "[download-image] ✗ id=$IMAGE_ID $err" >&2
    exit 1
fi

# ============================================================================
# Vérification d'intégrité (F-05) — AVANT de promouvoir l'artefact comme servable.
# Debian netboot : chaîne InRelease signée → SHA256SUMS → linux/initrd. Fail-closed :
# au moindre doute on conserve les fichiers déjà publiés, marque l'image 'failed' et on sort.
# ============================================================================
if [ "$distro" = "debian" ]; then
    publish "$label · vérification d'intégrité…" 99
    if ! verify_debian_netboot "$stage_dir" "$kernel_url" "$arch"; then
        publish_error "Vérification d'intégrité échouée (signature ou somme de contrôle invalide) — artefact rejeté."
        echo "[download-image] ✗ id=$IMAGE_ID intégrité netboot Debian INVALIDE" >&2
        exit 1
    fi
    if [ -n "$firmware_url" ] && [ -f "$stage_dir/firmware.cpio.gz" ]; then
        if ! verify_firmware "$stage_dir/firmware.cpio.gz" "$firmware_url"; then
            publish_error "Vérification d'intégrité du firmware échouée — artefact rejeté."
            echo "[download-image] ✗ id=$IMAGE_ID intégrité firmware INVALIDE" >&2
            exit 1
        fi
    fi
else
    # Direct non-Debian (Alma/Rocky/CentOS/Fedora) : pas de source checksum signée
    # câblée → best-effort non vérifié (hors périmètre menace F-05, distros non
    # installables aujourd'hui). À durcir quand on les rendra installables.
    echo "[download-image]   ⚠ $distro : intégrité non vérifiée (best-effort)" >&2
fi

# Nothing in the HTTP tree is replaced until the complete download is verified.
# The publisher copies from root-private staging through no-follow dirfds.
python3 "$publisher" "$stage_dir/linux" "$dest_dir/linux"
python3 "$publisher" "$stage_dir/initrd.gz" "$dest_dir/initrd.gz"
if [ -n "$firmware_url" ]; then
    python3 "$publisher" "$stage_dir/firmware.cpio.gz" "$dest_dir/firmware.cpio.gz"
fi

# Symlink codename → version_full (idempotent, calqué sur le script ISO). Les
# menus rescue/discovery (pxe_default_netboot_path) et certains chemins legacy
# résolvent par codename. Uniquement en layout v2 (version_full + codename).
if [ -n "$version_full" ] && [ -n "$codename" ]; then
    if [ "${PXE_SERVICE_MANAGER:-}" = systemd ]; then
        python3 "$(dirname "${BASH_SOURCE[0]}")/validate-native-runtime.py" http-tree "$HTTP_ROOT" "netboot/$codename"
    fi
    python3 "$publisher" --alias "../$distro/$version_full/$arch" "$HTTP_ROOT/netboot/$codename/$arch"
    echo "[download-image]   symlink netboot/$codename/$arch → ../$distro/$version_full/$arch"
fi

# Mark downloaded : taille totale + status='downloaded'.
size=$(( $(stat -c%s "$stage_dir/linux" 2>/dev/null || echo 0) \
      + $(stat -c%s "$stage_dir/initrd.gz" 2>/dev/null || echo 0) \
      + $(stat -c%s "$stage_dir/firmware.cpio.gz" 2>/dev/null || echo 0) ))
# Commit the completed image and a subsequent menu render together. A render
# already running may have read the old image state, so only a pending render
# can be reused.
sqlite3 -cmd ".timeout 5000" "$PXE_DB_PATH" <<SQL
BEGIN IMMEDIATE;
UPDATE os_images SET status='downloaded', downloaded_at=CURRENT_TIMESTAMP, size_bytes=$size, error_msg=NULL, progress_percent=100, message=NULL, updated_at=strftime('%Y-%m-%d %H:%M:%S','now') WHERE id=$IMAGE_ID;
INSERT INTO init_jobs(job_type, status, message)
SELECT 'bootstrap:render_templates', 'pending', 'Mise à jour des menus après téléchargement'
WHERE changes() = 1 AND NOT EXISTS (
    SELECT 1 FROM init_jobs WHERE job_type = 'bootstrap:render_templates' AND status = 'pending'
);
COMMIT;
SQL

echo "[download-image]   ✓ id=$IMAGE_ID ok ($(human_size "$size"))"
exit 0
