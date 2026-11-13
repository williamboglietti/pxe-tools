#!/bin/sh
# Wipe boot script — exécuté par partman/early_command du wipe-preseed.
# Rendu DYNAMIQUE par MAC côté serveur (PxeController::wipeScript) : disques,
# modes, action_token, action_id, post_action et wipe_id sont inlinés en clair
# dans le script. Aucun fetch /api/wipe-job → 1 round-trip réseau économisé,
# debug trivial (curl /wipe.sh?mac=X = exactement ce que la machine exécute).
#
# IMPORTANT : tourne dans busybox ash de l'installateur Debian. Pas de bashisms,
# tout en POSIX. Tolérant aux pannes : ne doit JAMAIS faire échouer l'installateur
# (le early_command wrappe déjà avec || true côté preseed).
#
# Checkpoints émis : preseed_loaded_wipe, wipe_script_started, wipe_disks_done,
# puis wipe_reboot_signaled (post_action=reboot) ou wipe_poweroff (sinon).
# Le token + action_id sont injectés au render — un wipe.sh distinct par machine.

ORCH_IP="<?= $pxe_ip ?>"
API_BASE="http://$ORCH_IP/api"
INTERNAL_API="$API_BASE/internal/checkpoint"

# Params inlinés côté serveur (PxeController::wipeScript)
MAC="<?= $mac ?>"
WIPE_ID=<?= $wipe_id ?>

ACTION_TOKEN="<?= $action_token ?? '' ?>"
BOOT_TOKEN="<?= $boot_token ?>"
ACTION_ID="<?= $action_id ?? '' ?>"
ACTION_TYPE="<?= $action_type ?? '' ?>"
POST_ACTION="<?= $post_action ?>"

DISKS_PARSED=$(cat <<'PARSED_EOF'
<?= $disks_parsed ?>
PARSED_EOF
)
SMARTCTL_URL="http://$ORCH_IP/static-bin/smartctl"
SMARTCTL=""; SMARTCTL_TRIED=0

norm_serial() {
    printf '%s' "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | tr 'abcdefghijklmnopqrstuvwxyz' 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
}
valid_serial() {
    case "$1" in ''|0|UNKNOWN|N/A|NA|NONE|NULL|DEFAULT|"NOT AVAILABLE") return 1 ;; esac
    return 0
}
ensure_smartctl() {
    [ "$SMARTCTL_TRIED" = 1 ] && return 0
    SMARTCTL_TRIED=1
    if wget -q --timeout=8 -O /tmp/pxe-wipe-smartctl "$SMARTCTL_URL" 2>/dev/null \
        && chmod +x /tmp/pxe-wipe-smartctl 2>/dev/null && /tmp/pxe-wipe-smartctl --version >/dev/null 2>&1; then
        SMARTCTL=/tmp/pxe-wipe-smartctl
    else rm -f /tmp/pxe-wipe-smartctl; fi
}
read_disk_serial() {
    d="$1"; b="${d##*/}"
    s=$(norm_serial "$(cat "$d/device/serial" 2>/dev/null)")
    valid_serial "$s" || s=""
    if [ -z "$s" ]; then
        c=$(printf '%s' "$b" | sed -n 's/^\(nvme[0-9][0-9]*\)n[0-9][0-9]*$/\1/p')
        [ -n "$c" ] && s=$(norm_serial "$(cat "/sys/class/nvme/$c/serial" 2>/dev/null)")
        valid_serial "$s" || s=""
    fi
    if [ -z "$s" ]; then
        ensure_smartctl
        [ -n "$SMARTCTL" ] && s=$("$SMARTCTL" -i "/dev/$b" 2>/dev/null | sed -n 's/^[Ss]erial [Nn]umber:[[:space:]]*//p' | head -1)
    fi
    norm_serial "$s"
}

# checkpoint : POST best-effort vers /api/internal/checkpoint avec Bearer auth.
# Silencieux, ne fait JAMAIS échouer (|| true à chaque étape). Skip si pas
# d'action parent (wipe legacy sans action_id).
checkpoint() {
    code="$1"
    level="${2:-}"
    [ -z "$ACTION_TOKEN" ] && return 0
    [ -z "$MAC" ] && return 0
    level_param=""
    [ "$level" = "error" ] && level_param="&level=error"
    wget -q --header="Authorization: Bearer $ACTION_TOKEN" \
        --post-data='' \
        -O /dev/null \
        "$INTERNAL_API?mac=$MAC&code=$code$level_param" 2>/dev/null || true
}

log_line() {
    msg="[$(date +%H:%M:%S)] $1"
    echo "$msg"
    if [ "$WIPE_ID" -gt 0 ] 2>/dev/null; then
        enc=$(echo "$msg" | sed 's/%/%25/g; s/ /%20/g; s|/|%2F|g; s/:/%3A/g; s/=/%3D/g; s/&/%26/g; s/+/%2B/g')
        wget -q -O /dev/null --header="Authorization: Bearer $BOOT_TOKEN" \
            "$API_BASE/wipe-progress?wipe_id=$WIPE_ID&mac=$MAC&line=$enc" 2>/dev/null || true
    fi
}

echo "================================================================"
echo " Wipe boot — mac=$MAC wipe_id=$WIPE_ID"
echo "================================================================"

# preseed_loaded_wipe est conceptuellement "preseed Debian a démarré le wipe" —
# on l'émet ici (pas dans wipe-preseed.cfg early_command) car wipe-preseed.cfg
# est statique côté template ; wipe.sh est le 1er endroit où on a token + mac
# + action_id simultanément côté machine.
checkpoint "preseed_loaded_wipe"
checkpoint "wipe_script_started"

n_disks=$(echo "$DISKS_PARSED" | grep -c '|' || echo 0)
log_line "Wipe job #$WIPE_ID démarré ($n_disks disque(s))"
log_line "Cibles : $(echo "$DISKS_PARSED" | tr '\n' ' ')"

# ---- Helper : détection SSD/NVMe (rotational=0 ET discard_max_bytes>0) ----
is_ssd_or_nvme() {
    blk_name=$(basename "$1")
    rota=$(cat "/sys/block/$blk_name/queue/rotational" 2>/dev/null)
    disc=$(cat "/sys/block/$blk_name/queue/discard_max_bytes" 2>/dev/null)
    [ "$rota" = "0" ] && [ -n "$disc" ] && [ "$disc" -gt 0 ] 2>/dev/null
}

get_size_mb() {
    blk_name=$(basename "$1")
    blocks=$(cat "/sys/block/$blk_name/size" 2>/dev/null)
    [ -z "$blocks" ] && { echo 0; return; }
    # blocks de 512 octets → MB
    echo $((blocks / 2 / 1024))
}

# ---- Wipe progress monitor : lit /proc/$PID/io (wchar) toutes les 10s -----
monitor_dd() {
    dd_pid=$1
    label=$2
    total_mb=$3
    last_report=0
    while kill -0 "$dd_pid" 2>/dev/null; do
        sleep 10
        if [ -r "/proc/$dd_pid/io" ]; then
            wchar=$(awk '/^wchar:/{print $2}' "/proc/$dd_pid/io" 2>/dev/null)
            if [ -n "$wchar" ] && [ "$wchar" -gt 0 ] 2>/dev/null; then
                mb_done=$((wchar / 1024 / 1024))
                pct="?"
                if [ "$total_mb" -gt 0 ] 2>/dev/null; then
                    pct=$((mb_done * 100 / total_mb))
                fi
                if [ "$mb_done" != "$last_report" ]; then
                    log_line "  $label : $mb_done / $total_mb MB (${pct}%)"
                    last_report=$mb_done
                fi
            fi
        fi
    done
}

# ---- Wipe principal --------------------------------------------------------
overall_status="success"

# Vérifie TOUTES les cibles avant le premier dd/blkdiscard. Un seul numéro
# absent, ambigu ou différent annule l'ensemble du job avant toute écriture.
VERIFIED_DISKS=""
while IFS='|' read -r CHECK_DISK CHECK_MODE CHECK_SERIAL_B64; do
    [ -z "$CHECK_DISK" ] && continue
    if [ -z "$CHECK_SERIAL_B64" ] || [ "$CHECK_SERIAL_B64" = '-' ]; then
        log_line "[BLOCKED] $CHECK_DISK : identite serie absente du snapshot"
        overall_status="failed"
        continue
    fi
    CHECK_EXPECTED=$(printf '%s' "$CHECK_SERIAL_B64" | base64 -d 2>/dev/null || printf '%s' "$CHECK_SERIAL_B64" | base64 -D 2>/dev/null)
    CHECK_EXPECTED=$(norm_serial "$CHECK_EXPECTED")
    if ! valid_serial "$CHECK_EXPECTED"; then
        log_line "[BLOCKED] $CHECK_DISK : identite serie cible invalide"
        overall_status="failed"
        continue
    fi
    CHECK_MATCH=""; CHECK_COUNT=0
    for candidate in /sys/block/*; do
        [ -e "$candidate/size" ] || continue
        candidate_name=$(basename "$candidate")
        case "$candidate_name" in loop*|ram*|dm-*|md*|sr*|fd*|zram*|nbd*|mmcblk*boot*|mmcblk*gp*|mmcblk*rpmb) continue ;; esac
        candidate_serial=$(read_disk_serial "$candidate")
        valid_serial "$candidate_serial" || continue
        [ "$candidate_serial" = "$CHECK_EXPECTED" ] || continue
        CHECK_MATCH="$candidate_name"; CHECK_COUNT=$((CHECK_COUNT + 1))
    done
    if [ "$CHECK_COUNT" -ne 1 ]; then
        if [ "$CHECK_COUNT" -eq 0 ]; then reason="numero absent, illisible ou different"; else reason="numero de serie ambigu"; fi
        log_line "[BLOCKED] $CHECK_DISK : $reason"
        overall_status="failed"
        continue
    fi
    VERIFIED_DISKS="$VERIFIED_DISKS/dev/$CHECK_MATCH|$CHECK_MODE|$CHECK_SERIAL_B64
"
done <<HEREDOC_PREFLIGHT_DISKS
$DISKS_PARSED
HEREDOC_PREFLIGHT_DISKS
if [ "$overall_status" = "success" ]; then
    DISKS_PARSED="$VERIFIED_DISKS"
else
    DISKS_PARSED=""
fi

# Itère sur les tuples "name|mode|serial" validés ci-dessus. Heredoc (pas
# pipe) pour que la boucle while tourne dans LE MÊME shell — sinon
# overall_status="failed" serait perdu (sous-shell d'un pipe).
while IFS='|' read -r DISK DISK_MODE DISK_SERIAL_B64; do
    [ -z "$DISK" ] && continue
    if [ ! -b "$DISK" ]; then
        log_line "[SKIP] $DISK : block device introuvable"
        overall_status="failed"
        continue
    fi

    if [ -z "$DISK_SERIAL_B64" ] || [ "$DISK_SERIAL_B64" = '-' ]; then
        log_line "[BLOCKED] $DISK : identite serie absente du snapshot"
        overall_status="failed"
        continue
    fi
    EXPECTED_SERIAL=$(printf '%s' "$DISK_SERIAL_B64" | base64 -d 2>/dev/null || printf '%s' "$DISK_SERIAL_B64" | base64 -D 2>/dev/null)
    EXPECTED_SERIAL=$(norm_serial "$EXPECTED_SERIAL")
    if ! valid_serial "$EXPECTED_SERIAL"; then
        log_line "[BLOCKED] $DISK : identite serie cible invalide"
        overall_status="failed"
        continue
    fi
    MATCH=""; MATCH_COUNT=0
    for candidate in /sys/block/*; do
        [ -e "$candidate/size" ] || continue
        candidate_name=$(basename "$candidate")
        case "$candidate_name" in loop*|ram*|dm-*|md*|sr*|fd*|zram*|nbd*|mmcblk*boot*|mmcblk*gp*|mmcblk*rpmb) continue ;; esac
        candidate_serial=$(read_disk_serial "$candidate")
        valid_serial "$candidate_serial" || continue
        [ "$candidate_serial" = "$EXPECTED_SERIAL" ] || continue
        MATCH="$candidate_name"; MATCH_COUNT=$((MATCH_COUNT + 1))
    done
    if [ "$MATCH_COUNT" -ne 1 ]; then
        if [ "$MATCH_COUNT" -eq 0 ]; then reason="numero absent, illisible ou different"; else reason="numero de serie ambigu"; fi
        log_line "[BLOCKED] $DISK : $reason"
        overall_status="failed"
        continue
    fi
    if [ "$DISK" != "/dev/$MATCH" ]; then
        log_line "Disque remappé par numéro de série : $DISK → /dev/$MATCH"
        DISK="/dev/$MATCH"
    fi

    SIZE_MB=$(get_size_mb "$DISK")
    log_line "Disque $DISK (mode=$DISK_MODE) : ${SIZE_MB} MB"

    if is_ssd_or_nvme "$DISK"; then
        DISK_TYPE="SSD/NVMe (TRIM-capable)"
    else
        DISK_TYPE="HDD"
    fi
    log_line "  Type : $DISK_TYPE"

    case "$DISK_MODE:$DISK_TYPE" in
        partial:*)
            # 10 GB début + 4 GB fin (casse GPT primary + secondary)
            log_line "  Wipe partiel : 10 GB début + 4 GB fin"
            dd if=/dev/zero of="$DISK" bs=1M count=10240 conv=fsync 2>/tmp/dd.err &
            DDPID=$!
            monitor_dd $DDPID "  partial-start ($DISK)" 10240
            wait $DDPID
            rc=$?
            if [ $rc -ne 0 ]; then
                log_line "  [WARN] dd début rc=$rc : $(head -3 /tmp/dd.err 2>/dev/null)"
            fi

            if [ "$SIZE_MB" -gt 4096 ]; then
                SKIP_MB=$((SIZE_MB - 4096))
                log_line "  Wipe fin : 4 GB à partir de ${SKIP_MB} MB"
                dd if=/dev/zero of="$DISK" bs=1M seek=$SKIP_MB count=4096 conv=fsync 2>/tmp/dd.err &
                DDPID=$!
                monitor_dd $DDPID "  partial-end ($DISK)" 4096
                wait $DDPID
                rc=$?
                if [ $rc -ne 0 ]; then
                    log_line "  [WARN] dd fin rc=$rc : $(head -3 /tmp/dd.err 2>/dev/null)"
                fi
            fi
            log_line "  ✓ Wipe partiel terminé sur $DISK"
            ;;

        "full:SSD/NVMe (TRIM-capable)")
            log_line "  Wipe complet via blkdiscard (TRIM)"
            if blkdiscard "$DISK" 2>/tmp/bd.err; then
                log_line "  ✓ blkdiscard OK sur $DISK"
            else
                log_line "  [WARN] blkdiscard a échoué : $(head -2 /tmp/bd.err 2>/dev/null)"
                log_line "  Fallback dd zeros sur tout le disque"
                dd if=/dev/zero of="$DISK" bs=1M conv=fsync 2>/tmp/dd.err &
                DDPID=$!
                monitor_dd $DDPID "  full-fallback ($DISK)" "$SIZE_MB"
                wait $DDPID
            fi
            ;;

        full:HDD)
            log_line "  Wipe complet via dd zeros (HDD : peut être très long)"
            dd if=/dev/zero of="$DISK" bs=1M conv=fsync 2>/tmp/dd.err &
            DDPID=$!
            monitor_dd $DDPID "  full ($DISK)" "$SIZE_MB"
            wait $DDPID
            rc=$?
            if [ $rc -ne 0 ]; then
                # rc=1 quand dd atteint la fin du device (ENOSPC) — c'est normal
                if [ $rc -eq 1 ]; then
                    log_line "  ✓ dd terminé sur $DISK (fin du disque atteinte)"
                else
                    log_line "  [WARN] dd rc=$rc : $(head -3 /tmp/dd.err 2>/dev/null)"
                fi
            else
                log_line "  ✓ Wipe complet terminé sur $DISK"
            fi
            ;;
        *)
            log_line "  [SKIP] mode='$DISK_MODE' inconnu pour $DISK ($DISK_TYPE)"
            overall_status="failed"
            ;;
    esac
done <<HEREDOC_DISKS
$DISKS_PARSED
HEREDOC_DISKS

log_line "Wipe job #$WIPE_ID terminé (status=$overall_status)"

if [ "$overall_status" = "success" ]; then
    checkpoint "wipe_disks_done"
else
    checkpoint "wipe_disks_done" "error"
fi

# Checkpoint final — code différent selon post_action :
#   - wipe_only  → wipe_poweroff (action s'arrête, pas de chain install)
#   - reinstall  → wipe_reboot_signaled (chain consomme l'install_config et
#                  la machine reboote vers la phase install)
# L'émettre avant wipe-end : cet appel peut rendre l'action terminale et
# l'endpoint checkpoint rejette alors les pings suivants.
if [ "$POST_ACTION" = "reboot" ]; then
    checkpoint "wipe_reboot_signaled"
else
    checkpoint "wipe_poweroff"
fi

# ---- POST end --------------------------------------------------------------
wget -q -O /dev/null --header="Authorization: Bearer $BOOT_TOKEN" \
    "$API_BASE/wipe-end?wipe_id=$WIPE_ID&mac=$MAC&status=$overall_status" 2>/dev/null || true

echo "================================================================"
case "$POST_ACTION" in
    reboot)
        echo " Redémarrage dans 5 secondes..."
        sleep 5
        reboot -f 2>/dev/null
        ;;
    *)
        echo " Extinction dans 5 secondes..."
        sleep 5
        ;;
esac
echo "================================================================"
poweroff -f 2>/dev/null
halt -p 2>/dev/null
halt -f 2>/dev/null
exit 0
