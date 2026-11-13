#!/bin/bash
# bootstrap-download-image-iso — Téléchargement ISO + extraction kernel/initrd.
#
# Spec catalogue v2 (mai 2026) — utilisé pour les distros dont extract_method
# = 'iso_extract' (Debian, Ubuntu). Le worker brancche sur ce script via
# `run_image_download` en lisant os_images.extract_method.
#
# Input : env IMAGE_ID (row os_images).
# Output : kernel + initrd posés dans /srv/http/netboot/<distro>/<version_full>/<arch>/
# Status : UPDATE os_images SET status='downloaded' + size_bytes (succès) ou
#          status='failed' + error_msg (échec).
#
# Publish live progress dans os_images.message + progress_percent via
# side-channel SQLite.
set -euo pipefail

: "${HTTP_ROOT:=/srv/http}"
PXE_DB_PATH=$(python3 "${PXE_APP_DIR:-/app}/database_selection.py")
export PXE_DB_PATH
: "${IMAGE_ID:=}"

# Vérification d'intégrité (F-05) : gpgv + SHA256 de l'ISO/firmware avant extraction.
# shellcheck source=lib-image-verify.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib-image-verify.sh"

if [ -z "$IMAGE_ID" ]; then
    echo "[download-image-iso] ERREUR : IMAGE_ID non défini" >&2
    exit 2
fi
if [ ! -f "$PXE_DB_PATH" ]; then
    echo "[download-image-iso] DB introuvable : $PXE_DB_PATH" >&2
    exit 2
fi

# Charge la row image — distro/version_full/arch + iso_url + codename/firmware.
# extract_method (COALESCE direct) départage le symlink codename : seul 'direct'
# (la version courante) doit posséder le lien /netboot/<codename>/<arch> que
# consomme le discovery — une archive iso_extract ne doit pas le voler.
row=$(sqlite3 -separator '|' "$PXE_DB_PATH" \
    "SELECT distro, version_full, arch, COALESCE(iso_url,''), COALESCE(codename,''), COALESCE(firmware_url,''), COALESCE(extract_method,'direct')
     FROM os_images WHERE id = $IMAGE_ID")
if [ -z "$row" ]; then
    echo "[download-image-iso] ERREUR : row id=$IMAGE_ID introuvable" >&2
    exit 2
fi
IFS='|' read -r distro version_full arch iso_url codename firmware_url extract_method <<< "$row"

if [ -z "$iso_url" ]; then
    echo "[download-image-iso] ERREUR : iso_url vide pour id=$IMAGE_ID ($distro/$version_full/$arch)" >&2
    sqlite3 "$PXE_DB_PATH" \
        "UPDATE os_images SET status='failed', error_msg='Adresse de téléchargement manquante', updated_at=strftime('%Y-%m-%d %H:%M:%S','now') WHERE id=$IMAGE_ID"
    exit 2
fi

label="$distro $version_full $arch"
dest_dir="$HTTP_ROOT/netboot/$distro/$version_full/$arch"
iso_path="$dest_dir/.tmp.iso"
mkdir -p "$dest_dir"

echo "[download-image-iso] ▶ id=$IMAGE_ID $label"
echo "[download-image-iso]   iso_url=$iso_url"
echo "[download-image-iso]   dest_dir=$dest_dir"

sql_quote() { printf "%s" "$1" | sed "s/'/''/g"; }

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
    awk -v b="$b" 'BEGIN {
        split("o Ko Mo Go To", u, " "); i = 1;
        while (b >= 1024 && i < 5) { b /= 1024; i++ }
        printf("%.1f %s", b, u[i]);
    }'
}

# Mapping arch → path dans l'ISO selon la distro. Couvre Debian/Ubuntu d-i.
iso_paths_for() {
    case "$distro" in
        debian)
            case "$arch" in
                amd64) echo "install.amd/vmlinuz install.amd/initrd.gz" ;;
                arm64) echo "install.a64/vmlinuz install.a64/initrd.gz" ;;
                *) return 1 ;;
            esac
            ;;
        ubuntu)
            # live-server : casper/vmlinuz + casper/initrd (sans .gz)
            echo "casper/vmlinuz casper/initrd"
            ;;
        *) return 1 ;;
    esac
}

iso_paths=$(iso_paths_for) || {
    publish_error "Système non pris en charge ($distro $arch)"
    exit 1
}
kernel_in_iso=$(awk '{print $1}' <<< "$iso_paths")
initrd_in_iso=$(awk '{print $2}' <<< "$iso_paths")

# ------------------------------------------------------------------------
# 1. Sonde la taille de l'ISO (pour progress %)
# ------------------------------------------------------------------------
publish "$label · préparation du téléchargement…" 1
iso_size=$(curl -ILs --max-time 10 "$iso_url" 2>/dev/null \
    | awk 'tolower($1) == "content-length:" { gsub("\r","",$2); print $2 }' | tail -1)
if [ -z "$iso_size" ] || [ "$iso_size" = "0" ]; then
    # Fallback : certains miroirs ne renvoient pas Content-Length sur HEAD mais
    # honorent une requête Range — Content-Range donne la taille totale sans
    # télécharger l'ISO (bytes 0-0/TOTAL).
    iso_size=$(curl -sL --max-time 10 -D - -o /dev/null -H "Range: bytes=0-0" "$iso_url" 2>/dev/null \
        | awk 'tolower($1) == "content-range:" { print $NF }' | awk -F/ '{gsub("\r","",$2); print $2}' | tail -1)
fi
iso_size=${iso_size:-0}
echo "[download-image-iso]   taille upstream : $(human_size "$iso_size")"

# ------------------------------------------------------------------------
# 2. Download avec progress live (50% du job)
# ------------------------------------------------------------------------
publish "$label · téléchargement…" 2
rm -f "$iso_path"
wget -c -L --tries=3 --timeout=60 -q -O "$iso_path" "$iso_url" &
wget_pid=$!

while kill -0 "$wget_pid" 2>/dev/null; do
    # Abandon si la row a disparu (factory reset / suppression pendant le DL) — le
    # worker est mono-thread et GELÉ sur ce wget. COUNT(*) (pas SELECT 1) : sur lock
    # SQLite transitoire la sortie est vide ≠ "0" → pas de coupure par faux positif.
    if [ "$(sqlite3 "$PXE_DB_PATH" "SELECT COUNT(*) FROM os_images WHERE id=$IMAGE_ID" 2>/dev/null)" = "0" ]; then
        kill "$wget_pid" 2>/dev/null || true
        wait "$wget_pid" 2>/dev/null || true
        rm -f "$iso_path"
        echo "[download-image-iso] row $IMAGE_ID disparue (reset/suppression) → abandon"
        exit 0
    fi
    got=0
    [ -f "$iso_path" ] && got=$(stat -c%s "$iso_path" 2>/dev/null || echo 0)
    if [ "$iso_size" -gt 0 ]; then
        # 2..50 % linéaire sur la phase DL
        pct=$(( 2 + got * 48 / iso_size ))
        [ "$pct" -gt 50 ] && pct=50
        publish "$label · $(human_size "$got") / $(human_size "$iso_size")" "$pct"
    else
        publish "$label · $(human_size "$got") téléchargés" 25
    fi
    sleep 1
done

if ! wait "$wget_pid"; then
    rm -f "$iso_path"
    publish_error "Échec du téléchargement"
    echo "[download-image-iso] ✗ wget failed" >&2
    exit 1
fi

iso_real_size=$(stat -c%s "$iso_path" 2>/dev/null || echo 0)
echo "[download-image-iso]   ✓ ISO téléchargé ($(human_size "$iso_real_size"))"

# ------------------------------------------------------------------------
# 2b. Vérification d'intégrité de l'ISO (F-05) — AVANT toute extraction.
#     SHA256SUMS du dossier + signature CD détachée (.sign Debian / .gpg Ubuntu)
#     vérifiée via clé officielle épinglée, puis SHA256 de l'ISO. Fail-closed.
# ------------------------------------------------------------------------
publish "$label · vérification d'intégrité…" 52
if ! verify_iso_gpg "$iso_path" "$iso_url" "$distro"; then
    rm -f "$iso_path"
    publish_error "Vérification d'intégrité de l'image échouée (signature ou somme de contrôle invalide) — image rejetée."
    echo "[download-image-iso] ✗ id=$IMAGE_ID intégrité ISO INVALIDE" >&2
    exit 1
fi

publish "$label · extraction du système depuis l'image téléchargée…" 55

# ------------------------------------------------------------------------
# 3. Extraction via 7z (50..90 %)
#
# 7z n'expose pas de progression exploitable ici (extraction ciblée de 2
# fichiers dans une ISO de plusieurs centaines de Mo à quelques Go) — mais
# c'est une opération bloquante qui peut dépasser 30s sur une grosse image
# (Ubuntu live-server). Sans heartbeat, updated_at ne bouge plus pendant toute
# la durée → l'UI affichait à tort "aucune progression reçue : forcer la
# relance ?" alors que l'extraction travaillait normalement. On la passe donc
# en tâche de fond et on republie le même message toutes les 5s pour garder
# updated_at frais (même pattern que le monitoring wget ci-dessus).
# ------------------------------------------------------------------------
extract_tmp=$(mktemp -d)
7z e -y "-o$extract_tmp" "$iso_path" "$kernel_in_iso" "$initrd_in_iso" >/dev/null 2>&1 &
extract_pid=$!
while kill -0 "$extract_pid" 2>/dev/null; do
    publish "$label · extraction du système depuis l'image téléchargée…" 55
    sleep 5
done
if ! wait "$extract_pid"; then
    rm -rf "$extract_tmp"
    rm -f "$iso_path"
    publish_error "Échec de la préparation du système"
    echo "[download-image-iso] ✗ 7z extract failed (paths $kernel_in_iso, $initrd_in_iso)" >&2
    exit 1
fi

kernel_basename=$(basename "$kernel_in_iso")
initrd_basename=$(basename "$initrd_in_iso")
extracted_kernel="$extract_tmp/$kernel_basename"
extracted_initrd="$extract_tmp/$initrd_basename"

if [ ! -s "$extracted_kernel" ] || [ ! -s "$extracted_initrd" ]; then
    rm -rf "$extract_tmp"
    rm -f "$iso_path"
    publish_error "Échec de la préparation du système"
    exit 1
fi

# Normalise les noms : on stocke en /srv/http/netboot/<distro>/<version_full>/<arch>/
# avec linux + initrd.gz pour rester compatible avec render_template (legacy convention).
mv "$extracted_kernel" "$dest_dir/linux"
# initrd.gz pour debian (déjà .gz). Pour ubuntu (casper/initrd non-.gz), on
# renomme en initrd.gz quand même pour conserver une convention unique côté
# templates GRUB. Le contenu est gzip-compressé dans les 2 cas.
mv "$extracted_initrd" "$dest_dir/initrd.gz"
rm -rf "$extract_tmp"

# Explique le "saut" de taille avant qu'il ne soit visible : l'espace conservé
# (noyau + initrd) est bien plus petit que l'ISO complète — normal, seuls ces
# deux fichiers servent au démarrage réseau, le reste de l'ISO (paquets,
# installeur graphique, etc.) est inutile ici et n'a jamais été conservé.
extracted_size=$(( $(stat -c%s "$dest_dir/linux" 2>/dev/null || echo 0) + $(stat -c%s "$dest_dir/initrd.gz" 2>/dev/null || echo 0) ))
publish "$label · système extrait ($(human_size "$extracted_size")) — l'image téléchargée ($(human_size "$iso_real_size")) va être supprimée, seuls le noyau et le système minimal sont conservés" 88

# ------------------------------------------------------------------------
# 3b. Firmware non-free (firmware.cpio.gz) — 2e initrd chargé par TOUS les
#     menus GRUB/PXE. Sans lui, GRUB échoue sur fichier manquant au boot.
#     Non-fatal : absent pour les distros sans firmware_url (non-Debian).
# ------------------------------------------------------------------------
if [ -n "${firmware_url:-}" ]; then
    publish "$label · téléchargement des pilotes réseau…" 90
    if wget -q --tries=3 --timeout=60 -O "$dest_dir/firmware.cpio.gz" "$firmware_url"; then
        # F-05 : le firmware est un 2e initrd chargé au boot → un cpio trafiqué =
        # exécution de code en initramfs. Vérif obligatoire ; échec = fatal.
        if verify_firmware "$dest_dir/firmware.cpio.gz" "$firmware_url"; then
            echo "[download-image-iso]   ✓ firmware.cpio.gz vérifié ($(human_size "$(stat -c%s "$dest_dir/firmware.cpio.gz" 2>/dev/null || echo 0)"))"
        else
            rm -f "$dest_dir/firmware.cpio.gz" "$dest_dir/linux" "$dest_dir/initrd.gz"
            publish_error "Vérification d'intégrité du firmware échouée — image rejetée."
            echo "[download-image-iso] ✗ id=$IMAGE_ID intégrité firmware INVALIDE" >&2
            exit 1
        fi
    else
        rm -f "$dest_dir/firmware.cpio.gz"
        echo "[download-image-iso]   ⚠ firmware injoignable ($firmware_url) — skip (non-fatal)"
    fi
fi

# ------------------------------------------------------------------------
# 3c. Symlink codename → version_full. Les menus discovery/install/rescue
#     (grub.php, boot.ipxe) construisent /netboot/<codename>/<arch>/ alors que
#     l'extraction pose en /netboot/<distro>/<version_full>/<arch>/. Sans ce
#     lien, le boot discovery 404 sur le kernel/initrd.
#
#     HYBRIDE : le lien codename ne pointe que vers la version COURANTE
#     ('direct'). Une point release archivée (iso_extract) qui le créerait
#     ferait dévier le discovery vers un kernel/initrd figé (skew anna). On le
#     réserve donc à extract_method='direct'.
# ------------------------------------------------------------------------
if [ -n "${codename:-}" ] && [ "$extract_method" = "direct" ]; then
    mkdir -p "$HTTP_ROOT/netboot/$codename"
    ln -sfn "../$distro/$version_full/$arch" "$HTTP_ROOT/netboot/$codename/$arch"
    echo "[download-image-iso]   symlink netboot/$codename/$arch → ../$distro/$version_full/$arch"
fi

publish "$label · finalisation…" 95

# ------------------------------------------------------------------------
# 4. Cleanup ISO (par défaut on supprime ; option keep_iso=on conserve)
# ------------------------------------------------------------------------
keep_iso=$(sqlite3 "$PXE_DB_PATH" "SELECT value FROM settings WHERE key='keep_iso'" 2>/dev/null || echo "")
if [ "$keep_iso" = "on" ]; then
    mv "$iso_path" "$dest_dir/source.iso"
    echo "[download-image-iso]   keep_iso=on → ISO conservée à $dest_dir/source.iso"
else
    rm -f "$iso_path"
    echo "[download-image-iso]   cleanup ISO (.tmp.iso supprimé)"
fi

# ------------------------------------------------------------------------
# 5. Mark downloaded + size_bytes (somme kernel+initrd+firmware, pas l'ISO)
# ------------------------------------------------------------------------
size=$(( $(stat -c%s "$dest_dir/linux" 2>/dev/null || echo 0) \
      + $(stat -c%s "$dest_dir/initrd.gz" 2>/dev/null || echo 0) \
      + $(stat -c%s "$dest_dir/firmware.cpio.gz" 2>/dev/null || echo 0) ))
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

echo "[download-image-iso]   ✓ id=$IMAGE_ID ok ($(human_size "$size") sur disque)"
exit 0
