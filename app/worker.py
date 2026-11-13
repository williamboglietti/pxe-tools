#!/usr/bin/env python3
"""
PXE Tools — worker daemon multi-job.

Deux types de jobs traités :

1. **Init jobs** (`init_jobs` table) — bootstrap initial du serveur PXE.
   Types : bootstrap:download_debian, bootstrap:generate_mok,
   bootstrap:build_grub, bootstrap:install_pxe_bins, bootstrap:render_templates.
   Créés automatiquement au premier démarrage si les volumes sont vides.

2. **Ansible jobs** (`installations` table) — exécution d'ansible-playbook
   pour les machines clientes provisionnées via PXE. Inchangé depuis la version
   originale.

Les init jobs ont **priorité absolue** sur les ansible jobs (pas de provisioning
client tant que le serveur PXE n'est pas bootstrappé).
"""

import hashlib
import grp
import json
import ipaddress
import logging
import os
import pwd
import re
import shlex
import socket
import sqlite3
import stat
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request
from contextlib import contextmanager
from typing import Callable, Optional
from database_selection import select_database
from pxe_menu_lock import menu_lock

from ansible_vault import (
    VaultLocked,
    ansible_vault_password_fd,
    purge_unlock_dir,
    unlock_pending,
)

DB_PATH = (
    os.environ.get("PXE_DB_PATH")
    or os.environ.get("ORCHESTRATOR_DB")  # legacy 2026-05, gardé pour back-compat
    or "/var/lib/pxe-tools/pxe-tools.sqlite"
)
DB_FALLBACK_PATH = DB_PATH
DB_LEGACY_PATH = '/var/lib/pxe-tools/orchestrator.sqlite'
DB_CONFIG_PATH = '/etc/pxe-tools/db.conf'
WORKDIR = os.environ.get("ANSIBLE_WORKDIR", "/home/ansible/ansible")
POLL_INTERVAL = int(os.environ.get("WORKER_POLL_S", "5"))
SCRIPTS_DIR = os.environ.get("BOOTSTRAP_SCRIPTS_DIR", "/opt/pxe-tools/bootstrap")
TFTP_ROOT = os.environ.get("TFTP_ROOT", "/srv/tftp")
HTTP_ROOT = os.environ.get("HTTP_ROOT", "/srv/http")
MOK_DIR = os.environ.get("MOK_DIR", "/etc/pxe-tools/mok")
DEBIAN_VERSIONS = [v.strip() for v in os.environ.get("DEBIAN_VERSIONS", "bookworm,trixie").split(",") if v.strip()]

# URL locale du controller PHP. Le worker tourne dans le même container
# (appliance unifiée) — loopback HTTP fonctionne et passe le filtre IP du
# InternalApiController (`isLoopback`).
#
# SEC-07 : host hardcodé loopback (pas d'env override possible). Seul le
# path reste configurable via env pour permettre des mocks de test. Refus
# de démarrer si la valeur résultante n'est pas loopback.
CHECKPOINT_API_HOST = "127.0.0.1"
CHECKPOINT_API_PATH = os.environ.get(
    "CHECKPOINT_API_PATH", "/api/internal/checkpoint"
)
CHECKPOINT_API_URL = f"http://{CHECKPOINT_API_HOST}:80{CHECKPOINT_API_PATH}"

# Garde-fou : si quelqu'un override CHECKPOINT_API_HOST en monkey-patch ou
# fork malicieux, on refuse le démarrage. La constante est ré-évaluée ici
# pour capturer un éventuel override post-import.
if CHECKPOINT_API_HOST not in ("127.0.0.1", "::1", "localhost"):
    sys.exit(f"[worker] CHECKPOINT_API_HOST non-loopback refusé: {CHECKPOINT_API_HOST!r}")

# Templates Jinja2 pour le rendu postboot.sh par action.
POSTBOOT_TEMPLATES_DIR = os.environ.get(
    "BOOTSTRAP_TEMPLATES_DIR", "/opt/pxe-tools/templates"
)

# SEC-01 : output dir HORS document root nginx. Les fichiers postboot.sh
# contiennent un token clair — ils ne doivent jamais être servis en static
# par nginx (autoindex énumère, location / sert sans auth). Servis par la
# route PHP /postboot/{id}/postboot.sh après vérification REMOTE_ADDR vs
# dernière IP DHCP loggée pour la MAC de l'action.
POSTBOOT_OUTPUT_DIR = os.environ.get(
    "POSTBOOT_OUTPUT_DIR", "/var/lib/pxe-tools/postboot"
)

# Mapping job_type → (script, message UI, env override).
# env override : dict de variables passées au sous-process (au-dessus de l'env worker).
BOOTSTRAP_JOBS = [
    # Initial bootstrap (auto-enqueued au premier démarrage, idempotent)
    ("bootstrap:install_pxe_bins",   "bootstrap-install-pxe-bins.sh",   "Copie des binaires PXE (lpxelinux.0, shim, modules)", {}),
    ("bootstrap:generate_mok",       "bootstrap-generate-mok.sh",       "Initialisation des clés MOK (Secure Boot)",          {}),
    # Spec E : remplace bootstrap:download_debian — boucle sur os_images.enabled=1
    # depuis la DB sqlite (au lieu de l'env var DEBIAN_VERSIONS).
    ("bootstrap:download_images",    "bootstrap-download-images.sh",    "Téléchargement des images OS",                       {}),
    ("bootstrap:build_grub",         "bootstrap-build-grub.sh",         "Build du GRUB EFI custom signé MOK",                  {}),
    ("bootstrap:render_templates",   "bootstrap-render-templates.sh",   "Génération des menus PXE (BIOS + UEFI)",             {}),
    # Maintenance (déclenchés via UI /settings/regenerate-mok et /settings/rebuild-grub)
    ("bootstrap:regen_mok",          "bootstrap-generate-mok.sh",       "Régénération de la clé MOK",                          {"REGEN": "1"}),
    ("bootstrap:rebuild_grub",       "bootstrap-build-grub.sh",         "Reconstruction du GRUB EFI",                          {"REBUILD": "1"}),
    # Lot 3 Spec B : appliquer une nouvelle conf au runtime (utile après wizard
    # ou changement de paramètres réseau dans /settings).
    ("bootstrap:restart_dnsmasq",    "bootstrap-restart-dnsmasq.sh",    "Génère et applique la configuration réseau",         {}),
    # Native-only, explicit administrator request; never auto-enqueued.
    ("system:release_nginx_port", "bootstrap-release-nginx-port.sh", "Libération du port HTTP", {}),
    # Refresh catalogue Debian (v1) : parse Release files (minor_version), découvre
    # nouvelles releases majeures, valide les URLs. Déclenché par bouton UI
    # sur /settings/images, jamais auto-enqueued au démarrage.
    ("bootstrap:refresh_catalog",    "bootstrap-refresh-catalog.sh",    "Refresh du catalogue Debian",                         {}),
    # Refresh catalogue v2 : itère sur DistroCatalog::all() (Debian + 5 autres
    # distros), peuple catalog_entries. Remplace v1 dans la nouvelle UI.
    ("bootstrap:refresh_catalog_v2", "bootstrap-refresh-catalog-v2.sh", "Refresh du catalogue multi-distros",                  {}),
    ("bootstrap:auto_refresh_catalog_v2", "bootstrap-refresh-catalog-v2.sh", "Actualisation automatique du catalogue",          {}),
]

AUTO_REFRESH_JOB_TYPE = "bootstrap:auto_refresh_catalog_v2"
MANUAL_REFRESH_JOB_TYPES = ("bootstrap:refresh_catalog", "bootstrap:refresh_catalog_v2")


# ============================================================================
# DB helpers
# ============================================================================

@contextmanager
def db_connect():
    """Context manager qui ouvre une connexion SQLite, gère commit/rollback
    ET ferme la connexion à la sortie.

    Le pattern `with sqlite3.connect(...) as db:` natif ne ferme PAS la
    connexion à la fin du bloc — il commit/rollback la transaction. Sans
    fermeture explicite, le worker (process long-running) accumule des
    file descriptors sur la DB à chaque appel → fuite jusqu'à blocage
    SQLite (database locked) quand le journal traîne après un crash.

    Cette version explicite garantit que la connection est close() même
    en cas d'exception.

    AUTO-COMMIT ON CONTEXT EXIT : conn.commit() est appelé automatiquement
    à la sortie du bloc `with db_connect() as db:` (sauf exception → rollback).
    Ne pas appeler db.commit() explicitement dans le corps du bloc — redondant."""
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    # WAL : readers (FPM/PHP servant l'UI) ne sont plus bloqués par les
    # writers (worker en flot continu pendant un install ansible). Mode
    # persistant côté DB ; ce PRAGMA est noop une fois posé.
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA busy_timeout = 5000")
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def now() -> str:
    # UTC pour cohérence avec SQLite CURRENT_TIMESTAMP (utilisé par
    # InitGuard::listJobs pour calculer l'âge des heartbeat).
    return time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime())


# ============================================================================
# Init jobs (bootstrap server PXE)
# ============================================================================

def needs_bootstrap() -> bool:
    """Heuristique : si grubx64.efi ou lpxelinux.0 manquent → bootstrap requis."""
    return not (
        os.path.isfile(os.path.join(TFTP_ROOT, "grubx64.efi"))
        and os.path.isfile(os.path.join(TFTP_ROOT, "lpxelinux.0"))
    )


INITIAL_JOB_TYPES = (
    "bootstrap:install_pxe_bins",
    "bootstrap:generate_mok",
    # bootstrap:download_images n'est plus enqueué automatiquement : les images
    # OS sont gérées indépendamment via /settings/images (catégorie image_downloads
    # ci-dessous). Conservé dans BOOTSTRAP_JOBS pour rétro-compat des rows legacy.
    "bootstrap:build_grub",
    "bootstrap:render_templates",
)


def enqueue_initial_bootstrap() -> None:
    """Enqueue uniquement les jobs INITIAL_JOB_TYPES (pas regen_mok/rebuild_grub
    qui sont déclenchés manuellement via UI). Skip si déjà présent en
    pending/running/success."""
    with db_connect() as db:
        existing = {row["job_type"] for row in db.execute(
            "SELECT job_type FROM init_jobs WHERE status IN ('pending', 'running', 'success')"
        )}
        for job_type, _, message, _ in BOOTSTRAP_JOBS:
            if job_type not in INITIAL_JOB_TYPES:
                continue
            if job_type not in existing:
                db.execute(
                    "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)",
                    (job_type, message),
                )
                print(f"[worker] enqueued {job_type}", flush=True)


def fetch_pending_init_job(vault_locked: bool = False) -> dict | None:
    # Un playbook sans déverrouillage reste pending : il ne doit pas bloquer
    # les autres init jobs (bootstrap, images) qui passent devant lui.
    vault_filter = ""
    if vault_locked:
        # Disabled Ansible requests must be claimed so the dispatcher can
        # report their refusal instead of leaving them pending forever.
        if is_ansible_enabled():
            vault_filter += "AND job_type NOT GLOB 'machine:ansible_redeploy:*' "
    with db_connect() as db:
        # Dépendance build_grub → generate_mok : build_grub signe le binaire GRUB
        # avec /etc/pxe-tools/mok/MOK.key, produite par generate_mok. Au factory
        # reset, le wizard peut enqueuer build_grub AVANT que le job de reset
        # n'enqueue generate_mok (id plus bas) ; piochés en FIFO (ORDER BY id),
        # build_grub passait alors AVANT et échouait « MOK.key absent ». On ne le
        # pioche donc PAS tant qu'un generate_mok est pending/running → il attend
        # que la clé existe. Défensif : indépendant de l'ordre/source d'enqueue.
        row = db.execute(
            "SELECT * FROM init_jobs WHERE status = 'pending' "
            "AND NOT (job_type = 'bootstrap:build_grub' AND EXISTS ("
            "    SELECT 1 FROM init_jobs WHERE job_type = 'bootstrap:generate_mok' "
            "    AND status IN ('pending', 'running'))) "
            "AND NOT (job_type = ? AND ("
            "    EXISTS (SELECT 1 FROM machines WHERE status IN ('installing', 'wiping', 'testing')) "
            " OR EXISTS (SELECT 1 FROM actions WHERE status IN ('pending', 'running')) "
            " OR EXISTS (SELECT 1 FROM init_jobs WHERE job_type IN (?, ?) "
            "            AND status IN ('pending', 'running')))) "
            + vault_filter +
            "ORDER BY id LIMIT 1",
            (AUTO_REFRESH_JOB_TYPE, *MANUAL_REFRESH_JOB_TYPES),
        ).fetchone()
        return dict(row) if row else None


def update_init_job(job_id: int, **fields) -> None:
    if not fields:
        return
    cols = ", ".join(f"{k} = ?" for k in fields)
    values = list(fields.values()) + [job_id]
    with db_connect() as db:
        db.execute(f"UPDATE init_jobs SET {cols} WHERE id = ?", values)


def append_init_log(job_id: int, chunk: str) -> None:
    """Append déjà-bufferisé (chunk = batch de lignes). Une seule UPDATE SQLite."""
    if not chunk:
        return
    with db_connect() as db:
        db.execute(
            "UPDATE init_jobs SET log = COALESCE(log, '') || ?, updated_at = ? WHERE id = ?",
            (chunk, now(), job_id),
        )


def reap_running_zombies() -> int:
    """Au démarrage du worker, reclasse en 'failed' les jobs laissés 'running'
    par un précédent crash/restart. Sans ça, les zombies persistent
    indéfiniment et bloquent la file (fetch_pending ne les voit pas)."""
    with db_connect() as db:
        cur = db.execute(
            "UPDATE init_jobs "
            "SET status='failed', ended_at=?, updated_at=?, "
            "    message=COALESCE(message, '') || ' [reaper: worker redémarré]' "
            "WHERE status='running'",
            (now(), now()),
        )
        return cur.rowcount


# Batch log : flush en DB toutes les FLUSH_INTERVAL_S secondes OU
# toutes les FLUSH_MAX_LINES lignes (whichever first). Évite la lock
# contention SQLite face au PHP qui poll en parallèle (pas de WAL).
FLUSH_INTERVAL_S = 2.0
FLUSH_MAX_LINES = 50


def run_bootstrap_job(job: dict) -> None:
    """Exécute le script shell associé au job_type. Batch les writes log
    pour ne pas saturer SQLite. Heartbeat updated_at à chaque flush."""
    job_id = job["id"]
    job_type = job["job_type"]

    optional_repo_job = (job_type == "ansible_repo:pull"
                         or job_type.startswith(("machine:ansible_redeploy:", "action:ansible_retry:",
                                                 "preset:push_github:", "preset:delete_github:",
                                                 "preset:pull_github")))
    if optional_repo_job and not is_ansible_enabled():
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message="Module Ansible désactivé ; aucune synchronisation ou exécution.")
        return

    # Handlers Python (jobs sans script shell associé).
    if job_type.startswith("preset:push_github:"):
        run_preset_push_github(job)
        return
    if job_type.startswith("preset:delete_github:"):
        run_preset_delete_github(job)
        return
    if job_type == "preset:pull_github" or job_type == "preset:pull_github:autoseed":
        run_preset_pull_github(job)
        return
    if job_type == "bootstrap:cleanup_per_mac_files":
        run_cleanup_per_mac_files(job)
        return
    if job_type == "system:factory_reset_wipe":
        run_factory_reset_wipe_job(job)
        return
    if job_type.startswith("action:render_postboot:"):
        run_render_postboot_job(job)
        return
    if job_type.startswith("action:reboot:"):
        run_reboot_job(job)
        return
    if job_type.startswith("action:ansible_retry:"):
        run_ansible_retry_job(job)
        return
    if job_type.startswith("machine:reboot:"):
        run_machine_power_job(job, verb="reboot")
        return
    if job_type.startswith("machine:shutdown:"):
        run_machine_power_job(job, verb="poweroff")
        return
    if job_type.startswith("machine:ansible_redeploy:"):
        run_ansible_redeploy_job(job)
        return
    if job_type == "ansible_repo:pull":
        run_ansible_repo_pull_job(job)
        return
    if job_type == "system:nginx_reload":
        run_nginx_reload_job(job)
        return

    entry = next(((s, e) for t, s, _, e in BOOTSTRAP_JOBS if t == job_type), None)
    if not entry:
        update_init_job(job_id, status="failed", message="Type de tâche inconnu ou non disponible.", ended_at=now(), updated_at=now())
        return
    script_name, env_override = entry

    script_path = os.path.join(SCRIPTS_DIR, script_name)
    if not os.path.isfile(script_path):
        update_init_job(job_id, status="failed", message=f"Script introuvable : {script_path}", ended_at=now(), updated_at=now())
        return

    if job_type == AUTO_REFRESH_JOB_TYPE:
        if not start_auto_catalog_refresh(job_id):
            return
    else:
        update_init_job(job_id, status="running", started_at=now(), updated_at=now(), progress_percent=10)
    env_summary = " ".join(f"{k}={v}" for k, v in env_override.items()) if env_override else ""
    header = f"[worker] $ {env_summary} {shlex.quote(script_path)}\n".strip() + "\n"

    # Job-aware env : permet aux scripts (bootstrap-download-images.sh)
    # d'écrire eux-mêmes dans init_jobs (progress_percent, message) via le
    # side-channel SQLite, sans repasser par stdout.
    proc_env = {**os.environ, **env_override, "WORKER_JOB_ID": str(job_id)}
    try:
        proc = subprocess.Popen(
            ["/bin/bash", script_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            env=proc_env,
        )
    except Exception as e:
        append_init_log(job_id, header + f"[worker] Échec lancement : {e}\n")
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now())
        return

    assert proc.stdout is not None
    buffer = [header]
    last_flush = time.monotonic()

    def flush() -> None:
        nonlocal buffer, last_flush
        if buffer:
            append_init_log(job_id, "".join(buffer))
            buffer = []
        last_flush = time.monotonic()

    try:
        for line in proc.stdout:
            buffer.append(line)
            elapsed = time.monotonic() - last_flush
            if len(buffer) >= FLUSH_MAX_LINES or elapsed >= FLUSH_INTERVAL_S:
                flush()
        rc = proc.wait()
    finally:
        # Garantit qu'on n'oublie pas les dernières lignes même en cas
        # d'exception (e.g. KeyboardInterrupt remontant).
        try:
            flush()
        finally:
            proc.stdout.close()

    final = "success" if rc == 0 else "failed"
    append_init_log(job_id, f"\n[worker] exit code = {rc}\n")
    update_init_job(job_id, status=final, ended_at=now(), updated_at=now(), progress_percent=100 if rc == 0 else 0)
    if job_type == AUTO_REFRESH_JOB_TYPE and final == "success":
        db_set_setting("catalog_auto_refresh_last_at", str(int(time.time())))
    print(f"[worker] {job_type} → {final}", flush=True)


# ============================================================================
# Handlers Python (jobs sans script shell)
# ============================================================================

def _git_ssh_cmd() -> str:
    """Use verified SSH host keys and an optionally configured Git identity."""
    args = ["ssh", "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes"]
    key = os.environ.get("PXE_GIT_SSH_KEY", "").strip()
    if key:
        args += ["-i", key, "-o", "IdentitiesOnly=yes"]
    return shlex.join(args)


def _run_git(cmd, cwd: str, env: dict | None = None, log_lines: list[str] | None = None):
    """CODE-05 — Wrapper subprocess git commun aux handlers preset push/pull.

    Insère `-c safe.directory=*` (le worker tourne en root mais WORKDIR est
    owned par `ansible` UID différent — git 2.35+ refuse dubious ownership
    sinon ; pas de risque sécu, on est dans un container isolé).
    Loggue la commande + stdout + stderr dans log_lines (mutated in-place).
    Retourne le CompletedProcess pour que le caller inspecte returncode.
    """
    if log_lines is None:
        log_lines = []
    full = ["git", "-c", "safe.directory=*"] + cmd[1:]
    log_lines.append(f"[worker] $ git {' '.join(shlex.quote(c) for c in cmd[1:])}\n")
    r = subprocess.run(full, cwd=cwd, capture_output=True, text=True, env=env)
    if r.stdout:
        log_lines.append(r.stdout if r.stdout.endswith("\n") else r.stdout + "\n")
    if r.stderr:
        log_lines.append(r.stderr if r.stderr.endswith("\n") else r.stderr + "\n")
    return r


def _repository_scope_module():
    """Keep the legacy worker importable without activating a repository profile."""
    app_dir = os.path.dirname(os.path.abspath(__file__))
    if app_dir not in sys.path:
        sys.path.insert(0, app_dir)
    import repository_scope
    return repository_scope


def _repository_profile() -> dict | None:
    path = db_setting("ansible_repo_profile", "").strip()
    if not path:
        return None
    return _repository_scope_module().load_profile(
        WORKDIR, path, env={**os.environ, "GIT_SSH_COMMAND": _git_ssh_cmd()})


def _preset_relative_directory() -> str:
    profile = _repository_profile()
    return profile["presets_path"] if profile else "host_vars"


def _repository_playbook(role: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]*", role):
        raise ValueError("Nom de playbook invalide")
    profile = _repository_profile()
    if profile:
        path = profile["playbooks"].get(role)
        if path is None:
            raise ValueError(f"Playbook {role!r} absent du profil PXE")
        return path
    _legacy_repository_guard()
    return f"playbooks/{role}.yml"


def _pull_preset_repository(log_lines: list[str]) -> None:
    ok, detail = git_pull_workdir(log_lines.append)
    if not ok:
        raise RuntimeError(detail.strip() or "Actualisation du dépôt des modèles refusée")


def _legacy_repository_guard() -> None:
    if os.path.lexists(os.path.join(WORKDIR, '.git', 'info', 'sparse-checkout')):
        raise ValueError("Clone filtré conservé ; préparer un clone complet contrôlé avant de retirer le profil PXE")


def _safe_preset_target(relative: str, env: dict, *, writing: bool = False) -> str:
    """Profile output must never follow or replace someone else's local file."""
    root = os.path.realpath(WORKDIR)
    target = os.path.join(WORKDIR, relative)
    if os.path.commonpath([root, os.path.realpath(target)]) != root:
        raise ValueError("Cible du modèle hors du dépôt")
    current = WORKDIR
    for part in relative.split('/'):
        current = os.path.join(current, part)
        if os.path.islink(current):
            raise ValueError("Lien symbolique interdit dans le dossier des modèles")
    if os.path.lexists(target) and not os.path.isfile(target):
        raise ValueError("La cible du modèle n’est pas un fichier ordinaire")
    if writing and db_setting("ansible_repo_profile", "").strip():
        if os.path.exists(target):
            tracked = subprocess.run(["git", "-c", "safe.directory=*", "ls-files", "--error-unmatch", "--", relative],
                                     cwd=WORKDIR, env=env, capture_output=True)
            if tracked.returncode != 0:
                raise ValueError("Fichier local non suivi conservé ; publication refusée")
        ignored = subprocess.run(["git", "-c", "safe.directory=*", "check-ignore", "--quiet", "--", relative],
                                 cwd=WORKDIR, env=env, capture_output=True)
        if ignored.returncode == 0:
            raise ValueError("Fichier local ignoré conservé ; publication refusée")
    return target


@contextmanager
def _preset_file_change(relative: str, env: dict, log_lines: list[str]):
    target = _safe_preset_target(relative, env, writing=True)
    original = None
    original_mode = None
    if os.path.isfile(target):
        with open(target, 'rb') as source:
            original = source.read()
        original_mode = os.stat(target).st_mode & 0o777
    try:
        yield target
    except BaseException:
        # Only this job's path was staged; a committed failed push stays ahead
        # because the actual push happens after this compensated transaction.
        _run_git(['git', 'reset', '-q', 'HEAD', '--', relative], WORKDIR, env, log_lines)
        if original is None:
            if os.path.lexists(target):
                os.unlink(target)
        else:
            with open(target, 'wb') as output:
                output.write(original)
            os.chmod(target, original_mode)
        raise


# Empreinte de chaque modèle au dernier échange réussi avec GitHub (push ou import).
# Le pull écrase la base avec GitHub : sans cette empreinte il ne peut pas distinguer
# « GitHub a changé » de « la base a été modifiée hors interface (SQL, CLI, autre
# code) » et perdait alors la modification (constaté sur le modèle monitor).
def _preset_sync_payload(row) -> dict:
    payload = dict(row)
    # Les identifiants et horodatages appartiennent à la base locale.
    # Les publier rendrait le JSON différent après chaque import distant.
    for local_column in ("id", "created_at", "updated_at"):
        payload.pop(local_column, None)
    return payload


def _preset_sync_hash(payload: dict) -> str:
    return hashlib.sha256(json.dumps(payload, sort_keys=True, default=str).encode()).hexdigest()


def _remember_preset_sync(db, preset_id: int, payload: dict) -> None:
    db.execute(
        "INSERT INTO settings (key, value) VALUES (?, ?) "
        "ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        (f"preset_sync_hash:{preset_id}", _preset_sync_hash(payload)),
    )


def _preset_has_unpublished_changes(db, row) -> bool:
    """Sans empreinte connue (1er pull après la mise à jour) : comportement historique."""
    saved = db.execute(
        "SELECT value FROM settings WHERE key = ?", (f"preset_sync_hash:{row['id']}",)
    ).fetchone()
    return saved is not None and saved["value"] != _preset_sync_hash(_preset_sync_payload(row))



def _push_preset_repository(env: dict, log_lines: list[str]) -> None:
    branch = _verify_repository_remote(push=True)
    def run_git(cmd, env=None):
        return _run_git(cmd, WORKDIR, env, log_lines)

    # Push : la clé de déploiement SSH est souvent en lecture seule.
    # On override l'URL avec HTTPS + PAT GitHub si dispo en DB.
    push_url = None
    gh_token = None
    gh_token_enc = db_setting("github_push_token", "")
    if gh_token_enc:
        try:
            sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
            from crypto import decrypt as crypto_decrypt
            gh_token = crypto_decrypt(gh_token_enc)
            gh_user = db_setting("github_push_user", "") or "x-access-token"
            # Récup remote URL existante (SSH format git@github.com:owner/repo.git)
            remote_r = subprocess.run(
                ["git", "-c", "safe.directory=*", "remote", "get-url", "origin"],
                cwd=WORKDIR, capture_output=True, text=True,
            )
            remote_url = remote_r.stdout.strip()
            log_lines.append("[worker] Authentification de publication Git préparée\n")
            # Conversion SSH → HTTPS. Couvre :
            #   - SCP : git@host:owner/repo.git
            #   - URL : ssh://git@host[:port]/owner/repo.git
            m = re.match(r"^(?:ssh://)?git@([^:/]+)(?::\d+)?[:/](.+?)(?:\.git)?$", remote_url)
            if m:
                host, owner_repo = m.group(1), m.group(2)
                if host not in {"github.com", "ssh.github.com"}:
                    raise ValueError("Le jeton GitHub ne peut pas être envoyé à cet hôte")
                # ssh.github.com:443 est un alias SSH-over-HTTPS — pour le push HTTPS
                # on cible le host HTTPS canonique github.com (sans le préfixe ssh.).
                https_host = "github.com" if host in {"github.com", "ssh.github.com"} else host
                push_url = f"https://{gh_user}:{gh_token}@{https_host}/{owner_repo}.git"
                log_lines.append(f"[worker] push via HTTPS+PAT (user={gh_user}, host={https_host})\n")
            else:
                log_lines.append(f"[worker] remote URL non parseable, fallback git push\n")
        except Exception as e:
            raise RuntimeError(f"Authentification GitHub impossible ({type(e).__name__})") from e

    if push_url:
        # The SSH-to-HTTPS conversion creates a second URL that Git could
        # rewrite independently of origin. Do not risk sending the GitHub
        # token to a host selected by url.*.insteadOf/pushInsteadOf.
        rewrites = subprocess.run(
            ['git', '-c', 'safe.directory=*', '-C', WORKDIR, 'config', '--get-regexp',
             r'^url\..*\.(insteadof|pushinsteadof)$'],
            capture_output=True, text=True, timeout=10,
        )
        if rewrites.returncode != 1:
            raise RuntimeError('Publication avec jeton refusée : réécritures Git présentes ou impossibles à vérifier')
        # Push explicite vers URL HTTPS+PAT, on évite de log l'URL (contient le token)
        push_cmd = ["git", "-c", "safe.directory=*", "push", push_url, f"HEAD:refs/heads/{branch}"]
        log_lines.append("[worker] $ git push <https://***:***@host/owner/repo.git> HEAD\n")
        r = subprocess.run(push_cmd, cwd=WORKDIR, capture_output=True, text=True)
        if r.stdout:
            safe_stdout = r.stdout.replace(gh_token, "***") if gh_token else r.stdout
            log_lines.append(safe_stdout if safe_stdout.endswith("\n") else safe_stdout + "\n")
        if r.stderr:
            # Masque le token si présent dans stderr
            safe_stderr = r.stderr.replace(gh_token, "***") if gh_token else r.stderr
            log_lines.append(safe_stderr if safe_stderr.endswith("\n") else safe_stderr + "\n")
        if r.returncode != 0:
            raise RuntimeError(f"git push (HTTPS+PAT) a échoué (rc={r.returncode})")
    else:
        r = run_git(["git", "push", "origin", f"HEAD:refs/heads/{branch}"], env=env)
        if r.returncode != 0:
            raise RuntimeError(f"git push a échoué (rc={r.returncode})")

def run_preset_push_github(job: dict) -> None:
    """Pousse un preset (JSON dump du row DB) dans le dossier déclaré par le
    profil, ou host_vars en mode historique, pour une restauration si l'outil
    PXE Tools est reset. Le slug est dérivé de presets.name."""
    job_id = job["id"]
    job_type = job["job_type"]

    parts = job_type.split(":")
    if len(parts) != 3 or not parts[2].isdigit():
        update_init_job(job_id, status="failed",
                        message=f"job_type invalide : {job_type}",
                        ended_at=now(), updated_at=now())
        return
    preset_id = int(parts[2])

    update_init_job(job_id, status="running", started_at=now(), updated_at=now(),
                    progress_percent=10)
    log_lines = [f"[worker] preset:push_github preset_id={preset_id}\n"]

    def flush_log() -> None:
        if log_lines:
            append_init_log(job_id, "".join(log_lines))
            log_lines.clear()

    # CODE-05 : factorisation _run_git module-level (cf. en haut du fichier).
    def run_git(cmd, env=None):
        return _run_git(cmd, WORKDIR, env, log_lines)

    try:
        with db_connect() as db:
            row = db.execute("SELECT * FROM presets WHERE id=?", (preset_id,)).fetchone()
        if not row:
            raise RuntimeError(f"preset id={preset_id} introuvable")
        preset = _preset_sync_payload(row)
        name = preset.get("name") or f"preset-{preset_id}"
        slug = re.sub(r"[^a-z0-9-]+", "-", name.lower()).strip("-") or f"preset-{preset_id}"
        log_lines.append(f"[worker] preset name={name!r} → slug={slug!r}\n")

        if not os.path.isdir(os.path.join(WORKDIR, ".git")):
            raise RuntimeError(f"repo ansible non cloné dans {WORKDIR} — vérifier ansible_repo_url + bootstrap")

        env = {**os.environ, "GIT_SSH_COMMAND": _git_ssh_cmd()}

        _pull_preset_repository(log_lines)
        _verify_repository_remote(push=True)
        update_init_job(job_id, progress_percent=30, updated_at=now())
        flush_log()

        preset_directory = _preset_relative_directory()
        rel_path = f"{preset_directory}/{slug}.json"
        with _preset_file_change(rel_path, env, log_lines) as target:
            os.makedirs(os.path.dirname(target), exist_ok=True)
            with open(target, "w") as f:
                json.dump(preset, f, indent=2, sort_keys=True, default=str)
            log_lines.append(f"[worker] wrote {rel_path} ({os.path.getsize(target)} bytes)\n")
            update_init_job(job_id, progress_percent=50, updated_at=now())

            r = run_git(["git", "add", rel_path])
            if r.returncode != 0:
                raise RuntimeError(f"git add a échoué (rc={r.returncode})")

            diff = subprocess.run(["git", "-c", "safe.directory=*", "diff", "--cached", "--quiet"], cwd=WORKDIR)
            if diff.returncode == 0:
                log_lines.append("[worker] aucun nouveau changement à committer (preset identique au repo)\n")
            else:
                r = run_git(["git", "-c", "user.name=PXE Tools", "-c", "user.email=pxe-tools@example.invalid", "commit", "-m", f"preset: backup {slug} from PXE Tools"])
                if r.returncode != 0:
                    raise RuntimeError(f"git commit a échoué (rc={r.returncode})")

        update_init_job(job_id, progress_percent=80, updated_at=now())
        flush_log()

        # Push systématique : couvre le cas d'un commit local créé lors d'un
        # run précédent dont le push avait échoué (rc=128 sur deploy key
        # read-only par exemple). `git push` est no-op si déjà à jour.
        # CODE-06 : `if True:` retiré (artefact dev).

        _push_preset_repository(env, log_lines)

        with db_connect() as db:
            _remember_preset_sync(db, preset_id, preset)
        log_lines.append(f"[worker] preset {slug!r} sauvegardé sur GitHub avec succès\n")
        flush_log()
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                        progress_percent=100,
                        message=f"Preset {slug} sauvegardé sur GitHub")
        print(f"[worker] preset:push_github:{preset_id} → success", flush=True)

    except Exception as e:
        log_lines.append(f"[worker] ERREUR : {e}\n")
        flush_log()
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"Erreur push GitHub : {e}")
        print(f"[worker] preset:push_github:{preset_id} → failed: {e}", flush=True)


def run_preset_delete_github(job: dict) -> None:
    """Retire un modèle supprimé ou renommé du dépôt GitHub."""
    job_id = job["id"]
    slug = job["job_type"].removeprefix("preset:delete_github:")
    if not re.fullmatch(r"[a-z0-9-]+", slug):
        update_init_job(job_id, status="failed", message="Nom de modèle invalide",
                        ended_at=now(), updated_at=now())
        return
    update_init_job(job_id, status="running", started_at=now(), updated_at=now())
    log_lines = []
    try:
        if not os.path.isdir(os.path.join(WORKDIR, ".git")):
            raise RuntimeError("Dépôt Ansible absent")
        env = {**os.environ, "GIT_SSH_COMMAND": _git_ssh_cmd()}
        _pull_preset_repository(log_lines)
        _verify_repository_remote(push=True)
        rel_path = f"{_preset_relative_directory()}/{slug}.json"
        with _preset_file_change(rel_path, env, log_lines) as target:
            if os.path.isfile(target):
                os.unlink(target)
                if _run_git(["git", "add", "-u", "--", rel_path], WORKDIR, env, log_lines).returncode != 0:
                    raise RuntimeError("git add a échoué")
                staged = _run_git(["git", "diff", "--cached", "--quiet"], WORKDIR, env, log_lines)
                if staged.returncode == 1:
                    if _run_git(["git", "-c", "user.name=PXE Tools", "-c",
                                 "user.email=pxe-tools@example.invalid", "commit", "-m",
                                 f"preset: remove {slug} from PXE Tools"], WORKDIR, env, log_lines).returncode != 0:
                        raise RuntimeError("git commit a échoué")
                elif staged.returncode != 0:
                    raise RuntimeError("git diff a échoué")
        _push_preset_repository(env, log_lines)
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                        progress_percent=100, message=f"Modèle {slug} supprimé de GitHub")
    except Exception as e:
        log_lines.append(f"[worker] ERREUR : {e}\n")
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"Erreur suppression GitHub : {e}")
    finally:
        if log_lines:
            append_init_log(job_id, "".join(log_lines))


def run_preset_pull_github(job: dict) -> None:
    """Synchronise les presets depuis le dépôt Ansible : git pull
    + lecture des modèles JSON du profil (host_vars en mode historique)
    + UPSERT en DB via le CLI PHP.

    Deux variantes via job_type :
      - 'preset:pull_github'           → pull manuel (déclenché par l'UI)
      - 'preset:pull_github:autoseed'  → seed initial DR (hook bootstrap)
        skip silencieux si la table presets a déjà des entrées ou si le
        flag settings.presets_seeded='1' est posé.
    """
    job_id = job["id"]
    job_type = job["job_type"]
    is_autoseed = job_type.endswith(":autoseed")

    update_init_job(job_id, status="running", started_at=now(), updated_at=now(),
                    progress_percent=5)
    log_lines = [f"[worker] {job_type} starting\n"]

    def flush_log() -> None:
        if log_lines:
            append_init_log(job_id, "".join(log_lines))
            log_lines.clear()

    # CODE-05 : factorisation _run_git module-level.
    def run_git(cmd, env=None):
        return _run_git(cmd, WORKDIR, env, log_lines)

    try:
        # Autoseed : double garde (count + flag) pour ne jamais écraser une
        # DB déjà peuplée par l'utilisateur après un premier seed.
        if is_autoseed:
            with db_connect() as db:
                existing = db.execute("SELECT COUNT(*) AS n FROM presets").fetchone()
                seeded = db.execute(
                    "SELECT value FROM settings WHERE key='presets_seeded'"
                ).fetchone()
            if (existing and existing["n"] > 0) or (seeded and seeded["value"] == "1"):
                log_lines.append("[worker] autoseed skip : presets déjà présents ou flag posé\n")
                flush_log()
                update_init_job(job_id, status="success", ended_at=now(),
                                updated_at=now(), progress_percent=100,
                                message="Autoseed skip (presets déjà présents)")
                return

        repo_url = db_setting("ansible_repo_url", "")
        if not repo_url:
            raise RuntimeError("ansible_repo_url non configuré (cf. /settings/ansible)")

        if not os.path.isdir(os.path.join(WORKDIR, ".git")):
            raise RuntimeError(
                f"repo ansible non cloné dans {WORKDIR} — vérifier bootstrap_ansible_workspace"
            )

        env = {**os.environ, "GIT_SSH_COMMAND": _git_ssh_cmd()}
        _pull_preset_repository(log_lines)
        update_init_job(job_id, progress_percent=25, updated_at=now())
        flush_log()

        preset_directory = _preset_relative_directory()
        host_vars_dir = os.path.join(WORKDIR, preset_directory)
        if not os.path.isdir(host_vars_dir):
            log_lines.append(f"[worker] aucun dossier {preset_directory}/ dans le repo, rien à importer\n")
            flush_log()
            update_init_job(job_id, status="success", ended_at=now(),
                            updated_at=now(), progress_percent=100,
                            message=f"Aucun {preset_directory}/ dans le repo")
            return

        if db_setting("ansible_repo_profile", "").strip():
            tracked = subprocess.run(["git", "-c", "safe.directory=*", "ls-tree", "-r", "-z", "--name-only", "HEAD", "--", preset_directory],
                                     cwd=WORKDIR, env=env, capture_output=True, text=True)
            if tracked.returncode != 0:
                raise RuntimeError("Impossible de lire les modèles suivis dans Git")
            files = sorted(os.path.basename(path) for path in tracked.stdout.split('\0')
                           if path.endswith('.json') and os.path.dirname(path) == preset_directory)
            for fname in files:
                _safe_preset_target(f"{preset_directory}/{fname}", env)
        else:
            files = sorted(f for f in os.listdir(host_vars_dir)
                           if f.endswith(".json") and os.path.isfile(os.path.join(host_vars_dir, f)))
        if not files:
            log_lines.append(f"[worker] {preset_directory}/ est vide, rien à importer\n")
            flush_log()
            update_init_job(job_id, status="success", ended_at=now(),
                            updated_at=now(), progress_percent=100,
                            message=f"{preset_directory}/ vide")
            return

        log_lines.append(f"[worker] {len(files)} fichier(s) à traiter\n")
        flush_log()

        cli_script = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cli", "preset-import.php")
        if not os.path.isfile(cli_script):
            raise RuntimeError(f"CLI introuvable : {cli_script}")

        created = 0
        updated = 0
        unchanged = 0
        failed = 0
        skipped = 0
        for idx, fname in enumerate(files, start=1):
            path = os.path.join(host_vars_dir, fname)
            slug = fname[:-5]
            with db_connect() as db:
                deleting = db.execute(
                    "SELECT 1 FROM init_jobs WHERE job_type = ? "
                    "AND status IN ('pending', 'running') LIMIT 1",
                    (f"preset:delete_github:{slug}",),
                ).fetchone()
            if deleting:
                log_lines.append(f"[worker]   {fname} → import différé (suppression locale à publier)\n")
                continue
            # Une modification locale sauvegardée pendant ce pull attend son
            # propre push. Ne pas l'écraser avec une version GitHub plus vieille.
            try:
                with open(path, encoding="utf-8") as source:
                    imported_name = json.load(source).get("name")
                with db_connect() as db:
                    local = db.execute(
                        "SELECT * FROM presets WHERE name = ?", (imported_name,)
                    ).fetchone()
                    pending_push = local and db.execute(
                        "SELECT 1 FROM init_jobs WHERE job_type = ? "
                        "AND status IN ('pending', 'running') LIMIT 1",
                        (f"preset:push_github:{local['id']}",),
                    ).fetchone()
                    unpublished = local and _preset_has_unpublished_changes(db, local)
                if pending_push:
                    log_lines.append(f"[worker]   {fname} → import différé (modification locale à publier)\n")
                    continue
                if unpublished:
                    skipped += 1
                    log_lines.append(
                        f"[worker]   {fname} → import ignoré : le modèle a été modifié localement "
                        "sans être publié sur GitHub (l'enregistrer dans l'interface le publie)\n")
                    continue
            except (OSError, ValueError, AttributeError):
                pass  # Le CLI d'import remontera l'erreur du fichier invalide.
            r = subprocess.run(
                ["php", cli_script, f"--json-file={path}"],
                capture_output=True, text=True, timeout=30,
            )
            if r.returncode == 0:
                try:
                    out = json.loads(r.stdout.strip())
                    name = out.get("name", fname)
                    action = out.get("action", "?")
                    log_lines.append(f"[worker]   {fname} → {action} (name={name!r}, id={out.get('id')})\n")
                    if action == "created":
                        created += 1
                    elif action == "updated":
                        updated += 1
                    elif action == "unchanged":
                        unchanged += 1
                    with db_connect() as db:
                        synced = db.execute("SELECT * FROM presets WHERE name = ?", (name,)).fetchone()
                        if synced:
                            _remember_preset_sync(db, synced["id"], _preset_sync_payload(synced))
                except Exception as parse_err:
                    log_lines.append(f"[worker]   {fname} → success mais stdout illisible : {parse_err}\n")
            else:
                failed += 1
                err = (r.stderr or "").strip() or f"exit {r.returncode}"
                log_lines.append(f"[worker]   {fname} → FAILED : {err}\n")

            pct = 25 + int(70 * idx / len(files))
            update_init_job(job_id, progress_percent=pct, updated_at=now())
            flush_log()

        summary = f"{created} créé(s), {updated} mis à jour, {unchanged} inchangé(s), {failed} échec(s)"
        if skipped:
            summary += f", {skipped} ignoré(s) (modifications locales non publiées)"
        log_lines.append(f"[worker] terminé : {summary}\n")

        if is_autoseed and (created + updated) > 0:
            with db_connect() as db:
                db.execute(
                    "INSERT INTO settings (key, value) VALUES ('presets_seeded', '1') "
                    "ON CONFLICT(key) DO UPDATE SET value = excluded.value"
                )
            log_lines.append("[worker] flag settings.presets_seeded=1 posé\n")

        flush_log()
        final = "failed" if failed > 0 else "success"
        update_init_job(job_id, status=final, ended_at=now(), updated_at=now(),
                        progress_percent=100, message=summary)
        print(f"[worker] {job_type} → {final} ({summary})", flush=True)

    except Exception as e:
        log_lines.append(f"[worker] ERREUR : {e}\n")
        flush_log()
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"Erreur pull GitHub : {e}")
        print(f"[worker] {job_type} → failed: {e}", flush=True)


def enqueue_auto_preset_pull() -> None:
    """Relance les publications échouées, puis actualise depuis le dépôt."""
    if not is_ansible_enabled():
        return
    if not db_setting("ansible_repo_url", "") or not os.path.isdir(os.path.join(WORKDIR, ".git")):
        return
    with db_connect() as db:
        busy = db.execute(
            "SELECT 1 FROM init_jobs WHERE status IN ('pending', 'running') "
            "AND (job_type LIKE 'preset:push_github:%' "
            "OR job_type LIKE 'preset:delete_github:%' "
            "OR job_type LIKE 'preset:pull_github%') LIMIT 1"
        ).fetchone()
        if busy:
            return
        failed = db.execute(
            "SELECT id, job_type FROM init_jobs AS j WHERE status = 'failed' "
            "AND (job_type LIKE 'preset:push_github:%' OR job_type LIKE 'preset:delete_github:%') "
            "AND id = (SELECT MAX(id) FROM init_jobs WHERE job_type = j.job_type) "
            "AND ended_at <= datetime('now', '-5 minutes') ORDER BY id LIMIT 1"
        ).fetchone()
        if failed:
            job_type = failed["job_type"]
            if job_type.startswith("preset:push_github:"):
                preset_id = job_type.removeprefix("preset:push_github:")
                relevant = preset_id.isdigit() and db.execute(
                    "SELECT 1 FROM presets WHERE id=?", (int(preset_id),)
                ).fetchone()
            else:
                slug = job_type.removeprefix("preset:delete_github:")
                names = [row[0] for row in db.execute("SELECT name FROM presets")]
                relevant = not any(
                    re.sub(r"[^a-z0-9-]+", "-", name.lower()).strip("-") == slug
                    for name in names
                )
            if relevant:
                db.execute(
                    "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)",
                    (job_type, "Nouvelle tentative de synchronisation GitHub"),
                )
                return
        db.execute(
            "INSERT INTO init_jobs (job_type, status, message) "
            "VALUES ('preset:pull_github', 'pending', 'Actualisation automatique des modèles depuis GitHub')"
        )


def run_cleanup_per_mac_files(job: dict) -> None:
    with menu_lock(os.environ.get('TFTP_ROOT', '/srv/tftp')):
        _run_cleanup_per_mac_files_locked(job)


def _run_cleanup_per_mac_files_locked(job: dict) -> None:
    """Supprime les fichiers pxelinux.cfg/01-* du tftp root après un reset
    machines. Sans ça, le booting d'une nouvelle machine avec une MAC qui a
    déjà été enregistrée trouverait l'ancien menu localboot."""
    job_id = job["id"]
    tftp_root = os.environ.get("TFTP_ROOT", "/srv/tftp")
    cfg_dir = os.path.join(tftp_root, "pxelinux.cfg")

    update_init_job(job_id, status="running", started_at=now(), updated_at=now(),
                    progress_percent=10)
    log_lines = [f"[worker] cleanup_per_mac_files in {cfg_dir}\n"]
    removed = 0
    failures = 0
    try:
        if not os.path.isdir(cfg_dir):
            log_lines.append(f"[worker] {cfg_dir} introuvable, rien à nettoyer\n")
        else:
            for name in sorted(os.listdir(cfg_dir)):
                if not name.startswith("01-"):
                    continue
                path = os.path.join(cfg_dir, name)
                try:
                    os.unlink(path)
                    removed += 1
                    log_lines.append(f"[worker] rm {path}\n")
                except Exception as e:
                    failures += 1
                    log_lines.append(f"[worker] rm {path} échec : {e}\n")
            log_lines.append(f"[worker] {removed} fichier(s) per-MAC supprimé(s)\n")
        if failures:
            raise RuntimeError(f'{failures} menu(s) non supprimé(s)')
        append_init_log(job_id, "".join(log_lines))
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                        progress_percent=100,
                        message=f"{removed} fichier(s) PXE per-MAC nettoyé(s)")
        print(f"[worker] cleanup_per_mac_files → success ({removed} files)", flush=True)
    except Exception as e:
        log_lines.append(f"[worker] ERREUR : {e}\n")
        append_init_log(job_id, "".join(log_lines))
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"Erreur cleanup per-MAC : {e}")
        print(f"[worker] cleanup_per_mac_files → failed: {e}", flush=True)


def run_factory_reset_wipe_job(job: dict) -> None:
    with menu_lock(TFTP_ROOT):
        _run_factory_reset_wipe_job_locked(job)


def _run_factory_reset_wipe_job_locked(job: dict) -> None:
    """Factory reset (artefacts root). La DB a déjà été vidée côté PHP
    (settings/users/machines/... → retour wizard /init). Ici on efface les
    artefacts disque que PHP (www-data) ne peut pas supprimer car root-owned :
    images netboot, bootloaders TFTP, MOK. Puis on relance le bootstrap (MOK +
    bootloaders régénérés) comme à un boot frais via enqueue_initial_bootstrap()."""
    job_id = job["id"]
    update_init_job(job_id, status="running", started_at=now(), updated_at=now(),
                    progress_percent=10)
    log_lines = ["[worker] factory_reset: wipe des artefacts PXE\n"]
    # Contenu des dossiers (on garde les points de montage des volumes).
    targets = [os.path.join(HTTP_ROOT, "netboot"), TFTP_ROOT, MOK_DIR]
    try:
        for base in targets:
            if not os.path.isdir(base):
                continue
            for name in os.listdir(base):
                if base == TFTP_ROOT and name == '.pxe-menu.lock':
                    continue
                path = os.path.join(base, name)
                subprocess.run(["rm", "-rf", path], check=False)
                log_lines.append(f"[worker] rm {path}\n")
        update_init_job(job_id, status="running", updated_at=now(), progress_percent=60)
        # Bootloaders + MOK effacés → needs_bootstrap() vrai → ré-enqueue le
        # bootstrap (install_pxe_bins, generate_mok, build_grub, render_templates).
        enqueue_initial_bootstrap()
        log_lines.append("[worker] bootstrap relancé (install_pxe_bins/generate_mok/build_grub/render_templates)\n")
        append_init_log(job_id, "".join(log_lines))
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                        progress_percent=100,
                        message="Réinitialisation : artefacts effacés, bootstrap relancé")
        print("[worker] factory_reset_wipe → success", flush=True)
    except Exception as e:
        log_lines.append(f"[worker] ERREUR : {e}\n")
        append_init_log(job_id, "".join(log_lines))
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"Erreur factory reset : {e}")
        print(f"[worker] factory_reset_wipe → failed: {e}", flush=True)


# ============================================================================
# Image downloads (table os_images — gérée depuis /settings/images, pas init)
# ============================================================================

IMAGE_DOWNLOAD_SCRIPT = os.path.join(SCRIPTS_DIR, "bootstrap-download-image.sh")
IMAGE_DOWNLOAD_ISO_SCRIPT = os.path.join(SCRIPTS_DIR, "bootstrap-download-image-iso.sh")


def should_enqueue_auto_catalog_refresh(
    *,
    enabled: bool,
    interval_hours: int,
    last_at: int,
    now: int,
    refresh_running: bool,
    machine_active: bool,
    pxe_action_pending: bool,
) -> bool:
    """Décide si le refresh planifié peut démarrer sans perturber un boot PXE."""
    return (
        enabled
        and not refresh_running
        and not machine_active
        and not pxe_action_pending
        and (last_at <= 0 or now >= last_at + (interval_hours * 3600))
    )


def script_for_image(image: dict) -> str:
    """Choisit le script DL selon os_images.extract_method (v2).
    Défaut 'direct' = script v1 (URL kernel+initrd direct).
    'iso_extract' = nouveau script v2 (download ISO + extract via 7z)."""
    method = (image.get("extract_method") or "direct").lower()
    if method == "iso_extract":
        return IMAGE_DOWNLOAD_ISO_SCRIPT
    return IMAGE_DOWNLOAD_SCRIPT


def reap_image_zombies() -> int:
    """Reclasse en 'pending' les rows os_images.status='downloading' laissées
    orphelines par un crash worker. Sans ça, le fetch (status='pending') les
    exclut → invisible et bloqué."""
    with db_connect() as db:
        cur = db.execute(
            "UPDATE os_images "
            "SET status='pending', updated_at=?, "
            "    error_msg=COALESCE(error_msg,'') || ' [reaper: worker redémarré]' "
            "WHERE status='downloading'",
            (now(),),
        )
        return cur.rowcount


def fetch_pending_image_download() -> dict | None:
    """Prochaine image à télécharger : enabled=1 AND status='pending', par ordre
    d'id (priorité aux versions seedées en premier).

    'failed' est TERMINAL : on ne le re-pioche PAS automatiquement. Sinon un échec
    dur (URL 404 d'une version retirée du miroir, hôte injoignable) bouclait à
    l'infini — re-download → échec → re-pick → … — avec une barre de progression
    qui montait puis revenait à zéro sans jamais aboutir. wget gère déjà les
    aléas réseau transitoires via --tries. Une row 'failed' attend une relance
    EXPLICITE : bouton « Relancer le téléchargement » / toggle (resetToPending) ou
    bootstrap-download-images.sh (reset failed→pending à l'init), qui la
    repassent 'pending'."""
    with db_connect() as db:
        row = db.execute(
            "SELECT * FROM os_images "
            "WHERE enabled = 1 AND status = 'pending' "
            "ORDER BY id LIMIT 1"
        ).fetchone()
        return dict(row) if row else None


def update_os_image(image_id: int, **fields) -> None:
    if not fields:
        return
    cols = ", ".join(f"{k} = ?" for k in fields)
    values = list(fields.values()) + [image_id]
    with db_connect() as db:
        db.execute(f"UPDATE os_images SET {cols} WHERE id = ?", values)


def run_image_download(image: dict) -> None:
    """Délègue au script bootstrap-download-image.sh (single-image).
    Le script publie son own progress dans os_images.message + progress_percent
    + updated_at via side-channel SQLite."""
    image_id = image["id"]
    label = f"{image['distro']} {image['version']} {image['arch']}"
    script = script_for_image(image)

    if not os.path.isfile(script):
        update_os_image(image_id, status="failed", error_msg=f"Script introuvable : {script}", updated_at=now())
        return

    update_os_image(
        image_id,
        status="downloading",
        message=f"{label} · initialisation…",
        progress_percent=0,
        error_msg=None,
        updated_at=now(),
    )

    proc_env = {**os.environ, "IMAGE_ID": str(image_id)}
    try:
        proc = subprocess.Popen(
            ["/bin/bash", script],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            bufsize=1,
            env=proc_env,
        )
    except Exception as e:
        update_os_image(image_id, status="failed", error_msg=f"Échec lancement : {e}", updated_at=now())
        return

    # Consomme stdout (utile pour les logs container) mais ne le persiste pas
    # dans os_images — le live progress est publié par le script via side-channel.
    assert proc.stdout is not None
    for line in proc.stdout:
        print(f"[worker:image:{image_id}] {line.rstrip()}", flush=True)
    rc = proc.wait()

    if rc == 0:
        # Le script a déjà set status='downloaded' + size_bytes + downloaded_at.
        # On bumpe updated_at pour cohérence.
        update_os_image(image_id, updated_at=now())
        print(f"[worker:image:{image_id}] {label} → downloaded", flush=True)
    else:
        # Le script a déjà set status='failed' + error_msg en cas d'échec.
        # Filet de sécurité : si non set (crash inattendu), on force failed.
        with db_connect() as db:
            row = db.execute("SELECT status FROM os_images WHERE id = ?", (image_id,)).fetchone()
        if row and row["status"] != "failed":
            update_os_image(image_id, status="failed", error_msg=f"Script exit code {rc}", updated_at=now())
        print(f"[worker:image:{image_id}] {label} → failed (rc={rc})", flush=True)


# ============================================================================
# Handler init_job 'action:ansible_retry:N' — relance isolée de la phase ansible
# ============================================================================

def run_ansible_retry_job(job: dict) -> None:
    """Handler init_job 'action:ansible_retry:N' — réinitialise ansible_status='pending'
    sur l'installation liée à l'action N pour que fetch_pending_ansible() la reprenne
    au prochain poll.

    POURQUOI reset vers 'pending' plutôt qu'invoquer run_playbook directement :
    fetch_pending_ansible() fait un JOIN machines/actions pour enrichir le job
    (ansible_role, extra_vars depuis install_config_json). Ce JOIN est nécessaire
    pour que run_playbook dispose de toutes les données. Plutôt que dupliquer la
    logique, on laisse la boucle principale piloter l'exécution.
    """
    job_id = job["id"]
    job_type = job["job_type"]
    try:
        action_id = int(job_type.rsplit(":", 1)[-1])
    except (IndexError, ValueError):
        update_init_job(job_id, status="failed",
                        message=f"action_id invalide dans {job_type}",
                        ended_at=now(), updated_at=now())
        return

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    # Vérifie que l'action existe et est non-terminale (pas cancelled/success)
    with db_connect() as db:
        action_row = db.execute(
            "SELECT id, machine_id, status FROM actions WHERE id = ?",
            (action_id,),
        ).fetchone()

    if not action_row:
        update_init_job(job_id, status="failed",
                        message=f"action {action_id} introuvable",
                        ended_at=now(), updated_at=now())
        return

    action_status = str(action_row["status"])
    if action_status in ("success", "cancelled"):
        update_init_job(job_id, status="failed",
                        message=f"action {action_id} terminale (status={action_status}) — retry annulé",
                        ended_at=now(), updated_at=now())
        return

    machine_id = int(action_row["machine_id"])

    # Cherche l'installation liée à cette action avec ansible_status terminal
    # (failed ou aborted). On filtre dans la query SQL pour éviter la race
    # constatée 2026-05-19 : un GET /preseed.php?mac=... (ex: curl debug, ou
    # un client PXE qui re-fetch entre 2 essais) déclenche Installation::startForMachine
    # qui crée une nouvelle row in_progress avec ansible_status NULL. Sans filtre
    # SQL, ORDER BY id DESC LIMIT 1 retourne cette row fraîche au lieu de
    # l'install failed visée par le retry → "ansible_status='' — retry refusé"
    # alors que l'install à retry existe juste en-dessous.
    with db_connect() as db:
        inst_row = db.execute(
            "SELECT id, ansible_status FROM installations "
            "WHERE action_id = ? AND ansible_status IN ('failed', 'aborted') "
            "ORDER BY id DESC LIMIT 1",
            (action_id,),
        ).fetchone()

    if not inst_row:
        update_init_job(job_id, status="failed",
                        message=f"Aucune installation failed/aborted liée à action {action_id} — rien à retry",
                        ended_at=now(), updated_at=now())
        return

    install_id = int(inst_row["id"])

    # Remet l'action en 'running' si elle était 'failed' pour que le UI polling
    # redevienne actif et que les prochains checkpoints soient acceptés.
    with db_connect() as db:
        db.execute(
            "UPDATE actions SET status='running', ended_at=NULL WHERE id=? AND status='failed'",
            (action_id,),
        )
        # Reset ansible_status='pending' pour que fetch_pending_ansible() reprenne
        # cette installation au prochain poll.
        db.execute(
            "UPDATE installations SET ansible_status='pending', ended_at=NULL WHERE id=?",
            (install_id,),
        )
        # Remet la machine en 'installed', le statut du déroulement normal pendant Ansible (l'OS
        # est déjà installé). Surtout pas 'installing' : grub.php.j2 sert alors le menu Discovery,
        # donc tout redémarrage du playbook (ifupdown2 en exige un) bouclerait sur Discovery
        # au lieu de revenir sur le disque local.
        db.execute(
            "UPDATE machines SET status='installed' WHERE id=? AND status='failed'",
            (machine_id,),
        )

    _log_machine_event(machine_id, action_id, "action",
                       f"Réessai de la phase Ansible déclenché par admin (install_id={install_id})")

    update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                    message=f"Ansible retry enqueued — installation {install_id} remise en pending")
    print(f"[worker] action:ansible_retry:{action_id} → success (install_id={install_id})", flush=True)


def run_subprocess_streaming(
    cmd: list[str],
    cwd: str,
    env: Optional[dict],
    timeout_sec: int,
    on_line: Callable[[str], None],
    on_flush: Callable[[], None],
    flush_interval_sec: float = 1.0,
    should_abort: Optional[Callable[[], bool]] = None,
    process_kwargs: Optional[dict] = None,
) -> int:
    """Lance un subprocess avec capture stdout line-by-line. Appelle `on_line`
    pour chaque ligne (avec son `\\n`) et `on_flush` toutes les
    `flush_interval_sec` secondes (pour persister incrémentalement le log en
    DB). Timeout via threading.Timer qui kill le process si dépassé.

    stdout + stderr sont fusionnés (stderr=STDOUT) pour préserver l'ordre
    temporel — ansible-playbook écrit progressivement sur les deux flux, les
    intercaler garantit un log lisible.

    `should_abort` (optionnel) : interrogé au fil des lignes (throttlé à
    flush_interval_sec). S'il répond True → SIGTERM (déclenche le trap de
    nettoyage du script enfant : les scripts de téléchargement tuent leurs processus et
    ses curls, rc 130) puis SIGKILL après 10 s de grâce si le process
    l'ignore. ⚠️ vérifié seulement quand le process ÉMET (readline bloquant) —
    suffisant pour la sync miroir dont le monitor bat toutes les 4 s.

    Pattern utilisé par run_ansible_redeploy_job pour permettre le suivi en
    direct côté UI (page /jobs/{id} polling 2s).
    """
    proc = subprocess.Popen(
        cmd, cwd=cwd, env=env,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        text=True, bufsize=1,
        **(process_kwargs or {}),
    )

    killed = {"value": False}
    def _kill_on_timeout() -> None:
        killed["value"] = True
        try:
            proc.kill()
        except OSError:
            pass
    def _hard_kill() -> None:
        # SIGKILL de grâce après un abort ignoré — sans poser le flag timeout
        # (le message « Timeout dépassé » serait mensonger).
        try:
            proc.kill()
        except OSError:
            pass
    timer = threading.Timer(float(timeout_sec), _kill_on_timeout)
    timer.start()
    grace_timer: Optional[threading.Timer] = None
    aborted = False

    last_flush = time.monotonic()
    last_abort_check = time.monotonic()
    try:
        for line in iter(proc.stdout.readline, ""):
            if not line:
                break
            on_line(line)
            now_t = time.monotonic()
            if (should_abort is not None and not aborted
                    and now_t - last_abort_check >= flush_interval_sec):
                last_abort_check = now_t
                try:
                    abort_now = bool(should_abort())
                except Exception:
                    abort_now = False
                if abort_now:
                    aborted = True
                    on_line("\n[worker] Arrêt demandé — SIGTERM envoyé, le script nettoie ses téléchargements en cours.\n")
                    try:
                        proc.terminate()
                    except OSError:
                        pass
                    grace_timer = threading.Timer(10.0, _hard_kill)
                    grace_timer.start()
            if now_t - last_flush >= flush_interval_sec:
                try:
                    on_flush()
                except Exception:
                    # Un échec de flush ne doit jamais tuer le job.
                    pass
                last_flush = now_t
    finally:
        try:
            proc.stdout.close()
        except OSError:
            pass
        proc.wait()
        timer.cancel()
        if grace_timer is not None:
            grace_timer.cancel()

    if killed["value"]:
        on_line(f"\n[worker] Timeout {timeout_sec}s dépassé, process killé.\n")

    # Flush final (au cas où la dernière fenêtre serait < flush_interval).
    try:
        on_flush()
    except Exception:
        pass

    return proc.returncode


def run_ansible_redeploy_job(job: dict) -> None:
    """Handler init_job 'machine:ansible_redeploy:M' — redéploie la config
    Ansible sur la machine M sans dépendre d'une action en `failed`. Use case :
    bouton manuel "Redéployer la configuration Ansible" ou webhook GitHub.

    Pipeline : git pull dépôt Ansible → ansible-playbook playbooks/{role}.yml
    --limit {hostname}. Subprocess synchrone, stdout/stderr capturé et stocké
    dans init_jobs.log. Pas de mise à jour des tables installations/actions —
    c'est un job autonome.

    Refus :
      - machine introuvable
      - hostname vide (machine non encore inventoriée)
      - ansible_role manquant dans install_config_json (machine pas encore
        configurée pour Ansible)
      - playbook fichier introuvable
    """
    job_id = job["id"]
    job_type = job["job_type"]
    try:
        machine_id = int(job_type.rsplit(":", 1)[-1])
    except (IndexError, ValueError):
        update_init_job(job_id, status="failed", message=f"machine_id invalide dans {job_type}",
                        ended_at=now(), updated_at=now())
        return

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    with db_connect() as db:
        row = db.execute(
            "SELECT hostname, install_config_json FROM machines WHERE id = ?",
            (machine_id,),
        ).fetchone()
    if not row:
        update_init_job(job_id, status="failed",
                        message=f"machine {machine_id} introuvable",
                        ended_at=now(), updated_at=now())
        return

    hostname = (row["hostname"] or "").strip()
    if hostname == "":
        update_init_job(job_id, status="failed",
                        message=f"Hostname manquant pour machine {machine_id}",
                        ended_at=now(), updated_at=now())
        return

    cfg = {}
    try:
        cfg = json.loads(row["install_config_json"] or "") or {}
    except (json.JSONDecodeError, TypeError):
        cfg = {}
    role = (cfg.get("ansible_role") or "").strip() if isinstance(cfg, dict) else ""
    if role == "proxmox_ve" or (isinstance(cfg, dict) and cfg.get("variant") == "proxmox-ve"):
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message="Cette configuration n'est pas disponible dans cette édition.")
        return
    if role == "":
        update_init_job(job_id, status="failed",
                        message=f"ansible_role manquant pour machine {machine_id} ({hostname})",
                        ended_at=now(), updated_at=now())
        _log_machine_event(machine_id, None, "warning",
                           "Redéploiement Ansible refusé : aucun rôle configuré pour cette machine.")
        return

    try:
        ansible_executable, ansible_uid, ansible_gid, ansible_options = resolve_ansible_runtime()
    except RuntimeError as error:
        update_init_job(job_id, status='failed', message=str(error), ended_at=now(), updated_at=now())
        return

    log_buffer: list[str] = []

    def _log(msg: str) -> None:
        log_buffer.append(msg)

    _log(f"[worker] Redéploiement Ansible : machine={hostname} role={role}\n")

    if not ensure_ansible_repository_current(_log):
        update_init_job(job_id, status="failed", message="Actualisation du dépôt Ansible impossible",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        return
    try:
        playbook_relpath = _repository_playbook(role)
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        update_init_job(job_id, status="failed", message=str(error),
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        return
    playbook_full = os.path.join(WORKDIR, playbook_relpath)
    if not os.path.isfile(playbook_full):
        update_init_job(job_id, status="failed", message=f"Playbook non trouvé : {playbook_full}",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        return

    cmd = [ansible_executable, playbook_relpath, "--limit", hostname]
    ansible_key = os.path.join(ansible_home(), ".ssh", "ansible_automation")
    if os.path.exists(ansible_key):
        cmd += ["--private-key", ansible_key]
    _log(f"[worker] $ {' '.join(cmd)}\n[worker] cwd={WORKDIR}\n\n")

    # Flush incrémental du log en DB toutes les ~1s pour permettre le suivi
    # live côté UI (page /jobs/{id} qui poll toutes les 2s).
    def _flush_log() -> None:
        update_init_job(job_id, log="".join(log_buffer), updated_at=now())

    try:
        with ansible_vault_password_fd(ansible_uid, ansible_gid) as (vault_path, vault_fd):
            returncode = run_subprocess_streaming(
                cmd, cwd=WORKDIR, env=ansible_subprocess_env(vault_path),
                timeout_sec=30 * 60,  # 30 min hard cap
                on_line=_log,
                on_flush=_flush_log,
                flush_interval_sec=1.0,
                process_kwargs={**ansible_options, 'pass_fds': (vault_fd,)},
            )
    except VaultLocked:
        update_init_job(job_id, status="pending", updated_at=now())
        return
    except Exception as e:
        _log(f"[worker] Exception lancement ansible-playbook : {e}\n")
        update_init_job(job_id, status="failed",
                        message=f"Redéploiement Ansible : exception {type(e).__name__}",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        _log_machine_event(machine_id, None, "warning",
                           f"Redéploiement Ansible : exception {e}")
        return
    _log(f"\n[worker] ansible-playbook exit code = {returncode}\n")

    if returncode == 0:
        update_init_job(job_id, status="success",
                        message=f"Redéploiement Ansible OK ({hostname}/{role})",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        _log_machine_event(machine_id, None, "action",
                           f"Redéploiement Ansible terminé avec succès ({hostname}, playbook {role}.yml)")
    else:
        update_init_job(job_id, status="failed",
                        message=f"Redéploiement Ansible KO rc={returncode} ({hostname}/{role})",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        _log_machine_event(machine_id, None, "warning",
                           f"Redéploiement Ansible en échec (rc={returncode}, playbook {role}.yml)")


def run_ansible_repo_pull_job(job: dict) -> None:
    """Handler init_job 'ansible_repo:pull' — git pull --ff-only du repo
    Ansible. Capture SHA before/after et résume "X commits récupérés"
    ou "À jour". Permet à l'admin de rafraîchir le code local depuis l'UI
    avant un redéploiement manuel.
    """
    job_id = job["id"]
    log_buffer: list[str] = []

    def _log(msg: str) -> None:
        log_buffer.append(msg)

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())
    _log(f"[worker] Pull dépôt Ansible (cwd={WORKDIR})\n\n")

    def _head_sha() -> str:
        try:
            r = subprocess.run(
                ["git", "-c", "safe.directory=*", "-C", WORKDIR, "rev-parse", "HEAD"],
                capture_output=True, text=True, timeout=10,
            )
            return r.stdout.strip() if r.returncode == 0 else ""
        except (subprocess.TimeoutExpired, OSError):
            return ""

    sha_before = _head_sha()
    if sha_before:
        _log(f"[worker] HEAD avant pull : {sha_before[:7]}\n")

    ok, _ = git_pull_workdir(_log)

    sha_after = _head_sha()
    if sha_after:
        _log(f"[worker] HEAD après pull : {sha_after[:7]}\n")

    if not ok:
        update_init_job(job_id, status="failed",
                        message="Pull dépôt Ansible : échec (voir log)",
                        log="".join(log_buffer), ended_at=now(), updated_at=now())
        return

    if sha_before and sha_after and sha_before == sha_after:
        summary = f"À jour (commit {sha_after[:7]})"
    elif sha_before and sha_after:
        # Compte les commits récupérés via git log --oneline
        try:
            r = subprocess.run(
                ["git", "-c", "safe.directory=*", "-C", WORKDIR, "log", "--oneline",
                 f"{sha_before}..{sha_after}"],
                capture_output=True, text=True, timeout=10,
            )
            n_commits = len([l for l in r.stdout.splitlines() if l.strip()]) if r.returncode == 0 else 0
        except (subprocess.TimeoutExpired, OSError):
            n_commits = 0
        if n_commits > 0:
            summary = f"{n_commits} commit(s) récupéré(s) ({sha_before[:7]} → {sha_after[:7]})"
            _log(f"\n[worker] {summary}\n{r.stdout}")
        else:
            summary = f"Mise à jour : {sha_before[:7]} → {sha_after[:7]}"
    else:
        summary = "Pull terminé (SHA indisponible)"

    update_init_job(job_id, status="success",
                    message=summary,
                    log="".join(log_buffer), ended_at=now(), updated_at=now())


# ============================================================================
# Ansible jobs (provisioning machines clientes)
# ============================================================================

def is_ansible_enabled() -> bool:
    """Spec F : si la setting ansible_enabled = off, le worker skip tous les
    jobs Ansible (laisse les machines en status='installed' direct)."""
    try:
        with db_connect() as db:
            row = db.execute("SELECT value FROM settings WHERE key='ansible_enabled'").fetchone()
            return row is not None and row[0] == 'on'
    except Exception:
        return False  # DB neuve / setting absente → pas d'ansible


def fetch_pending_ansible() -> dict | None:
    # Même si Ansible est désactivé, les installations terminées doivent être
    # consommées pour sortir de ansible_status='pending' et clôturer l'action.
    # run_playbook les marque alors 'no_role' sans lancer de playbook.
    # LEFT JOIN actions pour ramener token + type de l'action parente. Le LEFT
    # vs INNER : les installs legacy (créées avant le refactor actions) ont
    # i.action_id NULL → action_token/action_type seront NULL côté Python et
    # run_playbook skip les checkpoints (cf. log warning).
    # POURQUOI lire ansible_role/extra_vars depuis le snapshot
    # machines.install_config_json (et plus depuis presets) :
    # - L'install via wizard per-machine (/machines/{id}/install) laisse
    #   m.preset_id = NULL → p.ansible_role retournait NULL → 'no_role'
    #   → playbook skip alors que le snapshot contient bien le role.
    # - bulkInstall depuis preset copie aussi le role dans le snapshot via
    #   Preset::toInstallConfig, donc cette source est cohérente dans les 2 cas.
    # Le snapshot reste la source de vérité par machine (peut diverger du
    # preset après modifications ultérieures).
    sql = """
        SELECT i.id AS install_id, i.machine_id, i.action_id,
               m.hostname, m.mac, m.install_config_json,
               a.token AS action_token, a.type AS action_type
        FROM installations i
        JOIN machines m ON i.machine_id = m.id
        LEFT JOIN actions a ON i.action_id = a.id
        WHERE i.ansible_status = 'pending'
        ORDER BY i.created_at LIMIT 1
    """
    with db_connect() as db:
        row = db.execute(sql).fetchone()
        if not row:
            return None
        job = dict(row)
        # Extrait role + extra_vars du snapshot. JSON malformé / clé absente
        # → None (process_ansible_job traitera comme 'no_role' propre).
        cfg = {}
        try:
            cfg = json.loads(job.get("install_config_json") or "") or {}
        except (json.JSONDecodeError, TypeError):
            pass
        job["ansible_role"] = cfg.get("ansible_role") if isinstance(cfg, dict) else None
        job["ansible_extra_vars"] = cfg.get("ansible_extra_vars") if isinstance(cfg, dict) else None
        return job


# ============================================================================
# Helpers ansible-playbook partagés entre run_playbook et run_ansible_redeploy_job
# ============================================================================

def _verify_repository_remote(*, push: bool = False) -> str:
    """Require the effective origin and current branch selected by the operator.

    Git expands insteadOf/pushInsteadOf in get-url. Do not log URLs or stderr:
    an old checkout may contain credentials. No checkout or config is modified.
    """
    expected = db_setting('ansible_repo_url', '').strip()
    branch = db_setting('ansible_repo_branch', 'main').strip() or 'main'
    if not expected:
        raise ValueError('Aucun dépôt Git configuré pour cette opération')
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/-]{0,254}', branch):
        raise ValueError('Branche Git configurée invalide')
    def git(*args):
        try:
            result = subprocess.run(['git', '-c', 'safe.directory=*', '-C', WORKDIR, *args],
                                    capture_output=True, text=True, timeout=10, check=True)
            return result.stdout.splitlines()
        except (OSError, subprocess.SubprocessError, UnicodeError) as error:
            raise ValueError('Dépôt Git, branche ou origine impossible à vérifier') from error
    if git('check-ref-format', '--branch', branch) != [branch]:
        raise ValueError('Branche Git configurée invalide')
    if git('symbolic-ref', '--quiet', '--short', 'HEAD') != [branch]:
        raise ValueError('La branche du clone diffère de la branche configurée ; clone conservé')
    if git('remote', 'get-url', '--all', 'origin') != [expected]:
        raise ValueError('L’origine du clone diffère de l’URL configurée ; clone conservé')
    if push and git('remote', 'get-url', '--push', '--all', 'origin') != [expected]:
        raise ValueError('La destination de publication diffère de l’URL configurée ; clone conservé')
    return branch


def git_pull_workdir(log_callback) -> tuple[bool, str]:
    """Update the configured checkout; callers refuse execution after failure.

    safe.directory=* contourne git>=2.35 "dubious ownership" (worker root +
    WORKDIR owned by ansible uid 1000).
    """
    try:
        branch = _verify_repository_remote()
    except ValueError as error:
        message = f"[worker] Synchronisation du dépôt refusée : {error}\n"
        log_callback(message)
        return False, message
    profile_path = db_setting("ansible_repo_profile", "").strip()
    if profile_path:
        try:
            profile = _repository_scope_module().pull_scoped(
                WORKDIR, branch=branch,
                profile_path=profile_path,
                env={**os.environ, "GIT_SSH_COMMAND": _git_ssh_cmd()})
            message = (f"[worker] Profil {profile['name']} actualisé : "
                       f"{len(profile['paths'])} chemins, modèles dans {profile['presets_path']}\n")
            log_callback(message)
            return True, message
        except (OSError, ValueError, subprocess.SubprocessError) as error:
            message = f"[worker] Actualisation du profil PXE refusée : {error}\n"
            log_callback(message)
            return False, message
    try:
        _legacy_repository_guard()
    except ValueError as error:
        message = f"[worker] Actualisation du dépôt refusée : {error}\n"
        log_callback(message)
        return False, message
    git_ssh_cmd = _git_ssh_cmd()
    pull = subprocess.run(
        ["git", "-c", "safe.directory=*", "-C", WORKDIR, "pull", "--ff-only", "origin", f"refs/heads/{branch}"],
        env={**os.environ, "GIT_SSH_COMMAND": git_ssh_cmd},
        capture_output=True,
        text=True,
    )
    if pull.returncode == 0:
        log_callback(f"[worker] git pull OK\n{pull.stdout}\n")
        return (True, pull.stdout)
    message = "[worker] Échec de synchronisation Git ; le clone est conservé.\n"
    log_callback(message)
    return (False, message)


def ensure_ansible_repository_current(log_callback) -> bool:
    """Actualise le dépôt configuré ; accepte un workspace local sans dépôt."""
    if not db_setting("ansible_repo_url", ""):
        return True
    return git_pull_workdir(log_callback)[0]


def ansible_home() -> str:
    return '/var/lib/pxe-tools-ansible/home' if os.environ.get('PXE_ANSIBLE_MODE') == 'system' else '/home/ansible'


def ansible_execution_identity() -> tuple[int, int, dict]:
    """The optional runtime and playbooks run as the non-root appliance account."""
    name = 'pxe-tools-ansible' if os.environ.get('PXE_ANSIBLE_MODE') == 'system' else 'ansible'
    try:
        account = pwd.getpwnam(name)
        dedicated_group = grp.getgrnam(name).gr_gid
        groups = set(os.getgrouplist(name, account.pw_gid))
    except (KeyError, OSError) as error:
        raise RuntimeError('Compte Ansible local indisponible') from error
    if (account.pw_uid == 0 or account.pw_gid == 0 or account.pw_gid != dedicated_group
            or groups != {account.pw_gid}):
        raise RuntimeError('Le compte Ansible possède des groupes inattendus ou privilégiés')
    if os.geteuid() == 0:
        options = {'user': account.pw_uid, 'group': account.pw_gid, 'extra_groups': [], 'umask': 0o077}
    elif os.geteuid() == account.pw_uid and os.getegid() == account.pw_gid:
        options = {'umask': 0o077}
    else:
        raise RuntimeError('Impossible de prendre l’identité Ansible non privilégiée')
    return account.pw_uid, account.pw_gid, options


def resolve_ansible_runtime() -> tuple[str, int, int, dict]:
    """Validate the selected optional environment as its owner, never as root."""
    mode = os.environ.get('PXE_ANSIBLE_MODE', 'volume')
    if mode == 'system':
        if os.environ.get('PXE_ANSIBLE_RUNTIME', ''):
            raise RuntimeError('Configuration Ansible ambiguë : choisir un seul runtime')
        uid, gid, options = ansible_execution_identity()
        executable = '/usr/bin/ansible-playbook'
        try:
            for path in ('/usr', '/usr/bin', executable):
                item = os.lstat(path)
                if item.st_uid != 0 or item.st_mode & 0o022:
                    raise ValueError('unsafe runtime ownership')
                if path == executable and (not stat.S_ISREG(item.st_mode) or not os.access(path, os.X_OK)):
                    raise ValueError('unsafe runtime executable')
                if path != executable and not stat.S_ISDIR(item.st_mode):
                    raise ValueError('unsafe runtime directory')
            package = subprocess.run(['/usr/bin/dpkg-query', '-W', '-f=${Status}', 'ansible-core'],
                                     check=True, capture_output=True, text=True, timeout=10)
            owner = subprocess.run(['/usr/bin/dpkg-query', '-S', executable],
                                   check=True, capture_output=True, text=True, timeout=10)
            if package.stdout.strip() != 'install ok installed' or owner.stdout.strip() != 'ansible-core: ' + executable:
                raise ValueError('runtime package not installed or not owning executable')
            key_path = os.environ.get('PXE_MASTER_KEY_PATH') or '/etc/pxe-tools/master.key'
            # A previously installed SQLite database may predate the private
            # creation umask. Test the actual effective account permissions,
            # including ACLs and searchable directories, rather than chmod data.
            guard = (
                'import os,sys; files=sys.argv[1:]; '
                'bad=any(os.access(p,os.R_OK) or os.access(p,os.W_OK) for p in files); '
                'bad=bad or any(os.access(os.path.dirname(p),os.W_OK) for p in files); '
                'sys.exit(1 if bad else 0)'
            )
            subprocess.run(['/usr/bin/python3', '-c', guard, DB_PATH, DB_PATH+'-wal', DB_PATH+'-shm', key_path],
                           cwd='/', env={'PATH':'/usr/bin:/bin', 'LANG':'C.UTF-8', 'PYTHONNOUSERSITE':'1'},
                           stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=True, timeout=10, **options)
            subprocess.run([executable, '--version'], cwd='/',
                           env={'HOME':ansible_home(), 'PATH':'/usr/bin:/bin', 'LANG':'C.UTF-8',
                                'PYTHONNOUSERSITE':'1'},
                           stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, check=True, timeout=15, **options)
        except (OSError, ValueError, subprocess.SubprocessError) as error:
            raise RuntimeError('Ansible système absent, incompatible ou non vérifiable') from error
        return executable, uid, gid, options
    if mode != 'volume':
        raise RuntimeError('Mode Ansible non pris en charge')
    runtime = os.environ.get('PXE_ANSIBLE_RUNTIME', '')
    if runtime != '/var/lib/pxe-ansible':
        raise RuntimeError('Runtime Ansible non installé ou volume non configuré')
    uid, gid, options = ansible_execution_identity()
    helper = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ansible_runtime.py')
    try:
        result = subprocess.run([sys.executable, helper, '--runtime', runtime, 'status'],
                                env={'HOME':'/home/ansible','PATH':'/usr/bin:/bin',
                                     'LANG':'C.UTF-8','PYTHONDONTWRITEBYTECODE':'1'},
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, text=True, timeout=15,
                                check=True, **options)
        status = json.loads(result.stdout)
        if not isinstance(status, dict):
            raise ValueError('invalid runtime status')
        identifier = status.get('runtime')
        if status.get('state') != 'ready' or not isinstance(identifier, str) or not re.fullmatch(r'env-[a-z0-9_]{8}', identifier):
            raise ValueError('runtime not ready')
    except (OSError, ValueError, TypeError, subprocess.SubprocessError) as error:
        raise RuntimeError('Runtime Ansible absent, incompatible ou non vérifiable') from error
    return os.path.join(runtime, 'runtimes', identifier, 'bin', 'ansible-playbook'), uid, gid, options


def ansible_subprocess_env(vault_password_file: str) -> dict:
    # Do not pass the worker's unrelated environment and credentials to the
    # playbook; explicit Ansible options are still available to the operator.
    env = {key: value for key, value in os.environ.items() if key.startswith('ANSIBLE_')}
    env.update({'HOME':ansible_home(), 'PATH':'/usr/local/bin:/usr/bin:/bin', 'LANG':'C.UTF-8',
                'PYTHONNOUSERSITE':'1',
                'ANSIBLE_HOST_KEY_CHECKING':'True', 'ANSIBLE_VAULT_PASSWORD_FILE':vault_password_file})
    return env


def publish_ansible_runtime_status() -> None:
    """Publish a time-limited observation; execution always revalidates it."""
    if os.environ.get('PXE_ANSIBLE_MODE', 'volume') == 'volume' and not os.environ.get('PXE_ANSIBLE_RUNTIME', ''):
        state = 'not_configured'
    else:
        try:
            resolve_ansible_runtime()
            state = 'ready'
        except RuntimeError:
            state = 'unavailable'
    db_set_setting('ansible_runtime_status', json.dumps({'state':state, 'checked_at':int(time.time())}))


def ansible_needs_vault(job: dict) -> bool:
    """Même garde que run_playbook : sans Ansible, rôle ou hôte, aucun playbook ne part."""
    return (
        is_ansible_enabled()
        and bool((job.get("ansible_role") or "").strip())
        and bool((job.get("hostname") or "").strip())
    )


# Pipeline event-driven post-install : 2 checkpoints attendus successivement
# avant de lancer ansible-playbook.
#
# 1. install_reboot_done — émis par le sidecar dnsmasq-tail quand la machine
#    effectue un DHCP request POST-install (boot PXE suivant le reboot d-i).
#    Confirme que la machine a physiquement redémarré et contacté le réseau.
#    Cf. REINSTCKPT01 Fix R (pivot depuis l'ancien signal late_command).
#
# 2. os_first_boot — émis par postboot.sh (systemd unit pxe-postboot.service)
#    qui POST `/api/internal/checkpoint?code=os_first_boot` au premier boot
#    local. Confirme OS bootée + network up + sshd up (systemd ordonne sshd
#    avant pxe-postboot.service). Push-based, signal exact = moment idéal
#    pour lancer ansible. Évite tout poll TCP:22.
#
# Fallback : si l'un des signaux n'arrive pas dans le délai imparti, un
# checkpoint synthétique *_assumed est posé en log (via Bearer ; absent du
# catalogue PHP donc rejeté silencieusement = trace uniquement dans error_log)
# et le pipeline continue. Ansible aura son propre verdict de connexion SSH.
_INSTALL_REBOOT_DONE_CP = "install_reboot_done"
_INSTALL_REBOOT_ASSUMED_CP = "install_reboot_assumed"
# Timeouts généreux (10 min) pour couvrir le pire cas hardware : serveurs avec
# POST + vérif RAID/RAM/disques qui peuvent boot pendant 5-10 minutes avant le
# 1er DHCP post-install ou avant l'exécution de postboot.sh. Sur hardware
# rapide (laptops type board), les checkpoints arrivent en <2 min cumulés.
_INSTALL_REBOOT_TIMEOUT_S = 600
_OS_FIRST_BOOT_CP = "os_first_boot"
_OS_FIRST_BOOT_ASSUMED_CP = "os_first_boot_assumed"
_OS_FIRST_BOOT_TIMEOUT_S = 600


def _wait_for_checkpoint(
    action_id: int,
    checkpoint_code: str,
    timeout_s: int,
    interval_s: float = 5.0,
) -> bool:
    """Poll machine_events jusqu'à réception du checkpoint pour cette action.

    Retourne True si le checkpoint est reçu dans le délai imparti, False sinon
    (timeout). Le poll est local à la DB SQLite (loopback, pas de HTTP).
    """
    if not action_id or action_id <= 0:
        return False
    deadline = time.time() + timeout_s
    while time.time() < deadline:
        try:
            with db_connect() as db:
                row = db.execute(
                    "SELECT 1 FROM machine_events "
                    "WHERE action_id = ? AND checkpoint = ? LIMIT 1",
                    (action_id, checkpoint_code),
                ).fetchone()
            if row:
                return True
        except Exception as e:
            logging.warning("_wait_for_checkpoint(%s) DB poll failed: %s", checkpoint_code, e)
        time.sleep(interval_s)
    return False


def wait_for_install_reboot_done(
    action_id: int,
    action_token: str,
    mac: str,
    timeout_s: int = _INSTALL_REBOOT_TIMEOUT_S,
    interval_s: float = 5.0,
) -> bool:
    """Cf. _wait_for_checkpoint(install_reboot_done). Signature préserve
    action_token/mac pour cohérence avec les call sites legacy (provenance
    HTTP côté machine), même si le poll DB ne les utilise pas directement."""
    if not action_token or not mac:
        return False
    return _wait_for_checkpoint(action_id, _INSTALL_REBOOT_DONE_CP, timeout_s, interval_s)


def wait_for_os_first_boot(
    action_id: int,
    timeout_s: int = _OS_FIRST_BOOT_TIMEOUT_S,
    interval_s: float = 5.0,
) -> bool:
    """Cf. _wait_for_checkpoint(os_first_boot)."""
    return _wait_for_checkpoint(action_id, _OS_FIRST_BOOT_CP, timeout_s, interval_s)


def update_install(install_id: int, **fields) -> None:
    if not fields:
        return
    cols = ", ".join(f"{k} = ?" for k in fields)
    values = list(fields.values()) + [install_id]
    with db_connect() as db:
        db.execute(f"UPDATE installations SET {cols} WHERE id = ?", values)

    # Hook : si on touche un état terminal d'install ou ansible, déclencher
    # Action::recomputeEndState côté PHP. update_install court-circuite
    # Installation::setAnsibleStatus (qui invoquerait normalement le hook),
    # donc on rejoue l'effet manuellement via l'endpoint internal pour que
    # l'action parente passe à success/failed.
    terminal = (
        fields.get("ansible_status") in ("success", "failed", "no_role")
        or fields.get("install_status") in ("success", "failed")
    )
    if terminal:
        with db_connect() as db:
            row = db.execute(
                "SELECT action_id FROM installations WHERE id = ?",
                (install_id,),
            ).fetchone()
        if row and row[0]:
            trigger_recompute_action(int(row[0]))


def append_install_log(install_id: int, line: str) -> None:
    with db_connect() as db:
        db.execute(
            "UPDATE installations SET ansible_log = COALESCE(ansible_log, '') || ? WHERE id = ?",
            (line, install_id),
        )


def update_machine_status(machine_id: int, status: str) -> None:
    with db_connect() as db:
        db.execute("UPDATE machines SET status = ? WHERE id = ?", (status, machine_id))


def trigger_recompute_action(action_id: int) -> bool:
    """POST /api/internal/action/{id}/recompute pour forcer un Action::recomputeEndState.

    POURQUOI : le worker met à jour installations.ansible_status directement en SQL
    (via update_install) pour les états terminaux ('success', 'failed', 'no_role'),
    court-circuitant les hooks PHP (Installation::setAnsibleStatus) qui appellent
    normalement recomputeEndState. Sans cet appel HTTP, l'action parente reste
    'running' indéfiniment alors que tous les sous-jobs sont terminaux.

    Best-effort : si l'appel échoue, l'action reste running et sera recomputée
    plus tard manuellement ou via un autre hook.
    """
    if not action_id or action_id <= 0:
        return False
    url = f"http://{CHECKPOINT_API_HOST}:80/api/internal/action/{action_id}/recompute"
    req = urllib.request.Request(url, data=b"", method="POST")
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status == 200
    except Exception as e:
        logging.warning("trigger_recompute_action failed (action_id=%s): %s", action_id, e)
        return False


def send_checkpoint(token: str, mac: str, code: str, payload: dict | None = None, level: str | None = None) -> bool:
    """Émet un ping checkpoint vers /api/internal/checkpoint (best-effort).

    POURQUOI urllib (stdlib) plutôt que requests : la lib `requests` n'est pas
    garantie présente dans le container worker (image debian:trixie-slim
    + python3 + python3-jinja2 + python3-nacl uniquement). urllib couvre le
    POST simple sans dépendance supplémentaire.

    `level` optionnel ('error') pour marquer un checkpoint en erreur côté UI.
    Transmis en query string (pxe_subnet filter côté controller accepte).

    Retourne True si HTTP 200 reçu, False sinon. Pas d'exception remontée :
    le checkpoint est best-effort, ne doit jamais bloquer le flow appelant.
    """
    if not token or not mac or not code:
        return False
    # SEC-02 : token passé via header Authorization (plus en query string)
    # pour éviter la journalisation par nginx access log / php-fpm log.
    qs_params: dict = {"mac": mac, "code": code}
    if level:
        qs_params["level"] = level
    qs = urllib.parse.urlencode(qs_params)
    url = f"{CHECKPOINT_API_URL}?{qs}"
    body_bytes: bytes | None = None
    headers = {"Authorization": f"Bearer {token}"}
    if payload is not None:
        body_bytes = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=body_bytes or b"", method="POST", headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            return resp.status == 200
    except Exception as e:
        # Log discret — le ping checkpoint est best-effort.
        logging.warning("send_checkpoint failed (code=%s): %s", code, e)
        return False


def run_render_postboot_job(job: dict) -> None:
    """Handler init_job 'action:render_postboot:N' — rend postboot.sh + service
    pour l'action N. Enqueueé par PHP au moment de la création de l'action
    (installSubmit / bulkInstall), bien AVANT que la machine boote en install
    et tente le wget /pxe/postboot/{N}/postboot.sh depuis son late_command.

    POURQUOI éager (init_job) au lieu de lazy (process_ansible_job) : le rendu
    lazy se faisait après la phase ansible, donc le late_command du preseed
    obtenait 404 sur le wget postboot.sh → service systemd jamais installé →
    pas de ping os_first_boot côté API → checkpoint manquant dans la checklist.
    """
    job_id = job["id"]
    job_type = job["job_type"]
    try:
        action_id = int(job_type.rsplit(":", 1)[-1])
    except (IndexError, ValueError):
        update_init_job(job_id, status="failed", message=f"action_id invalide dans {job_type}", ended_at=now(), updated_at=now())
        return

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    with db_connect() as db:
        row = db.execute(
            "SELECT a.token, m.mac FROM actions a JOIN machines m ON a.machine_id = m.id WHERE a.id = ?",
            (action_id,),
        ).fetchone()
    if not row:
        update_init_job(job_id, status="failed", message=f"action {action_id} introuvable", ended_at=now(), updated_at=now())
        return

    pxe_ip = db_setting("pxe_ip", "")
    if not pxe_ip:
        update_init_job(job_id, status="failed", message="setting pxe_ip non configuré", ended_at=now(), updated_at=now())
        return

    out_dir = render_postboot_for_action(action_id, str(row["token"]), str(row["mac"]), pxe_ip)
    if out_dir:
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(), message=f"Postboot rendu dans {out_dir}")
    else:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(), message="render_postboot_for_action a échoué (cf. stderr worker)")


# ============================================================================
# Helpers reboot SSH+WoL (init_jobs 'action:reboot:N', 'machine:reboot:M',
# 'machine:shutdown:M')
# ============================================================================
#
# Toutes les opérations SSH sur les machines installées passent par worker.py
# (et non par PHP-FPM) par design : la clé `ansible_automation` est en 0600
# owner `ansible`, et www-data n'y a délibérément PAS accès. Le bit `x` sur le
# répertoire 0701 permet seulement `file_exists()` côté PHP (status display
# /settings/ansible), pas la lecture du contenu. Le secret reste cantonné aux
# process root/ansible. Cf. compose/.env.example § WORKER_SSH_DIR.

_ANSIBLE_SSH_KEY = os.path.join(ansible_home(), ".ssh", "ansible_automation")
_SSH_USER = "ansible"
_IPV4_RE = re.compile(r"^\d{1,3}(\.\d{1,3}){3}$")
_MAC_RE = re.compile(r"^([0-9a-f]{2}:){5}[0-9a-f]{2}$")
_DHCP_IP_RE = re.compile(r'"dhcp_ip"\s*:\s*"([0-9.]+)"')
_DHCP_CHECKPOINTS = ("dhcp_request_install", "dhcp_request_wipe", "dhcp_request_disktest")


def _json_dict(raw) -> dict:
    try:
        value = json.loads(raw or "")
    except (json.JSONDecodeError, TypeError):
        return {}
    return value if isinstance(value, dict) else {}


def _snapshot_static_ip(install_config: dict) -> str | None:
    """IP statique de l'interface primaire du snapshot d'install (plan
    `network`, sinon colonnes legacy `network_mode`/`static_ip`)."""
    network = install_config.get("network")
    interfaces = network.get("interfaces") if isinstance(network, dict) else None
    if isinstance(interfaces, list) and interfaces:
        try:
            primary = interfaces[int(network.get("primary") or 0)]
        except (IndexError, TypeError, ValueError):
            return None
        if isinstance(primary, dict) and isinstance(primary.get("bond"), dict):
            name = primary["bond"].get("name")
            master = next((iface for iface in interfaces if isinstance(iface, dict)
                           and isinstance(iface.get("bond"), dict)
                           and iface["bond"].get("name") == name
                           and iface["bond"].get("role") == "master"), None)
            if master is not None:
                primary = master
        if not isinstance(primary, dict) or primary.get("mode") != "static":
            return None
        static = primary.get("static")
        return str(static.get("ip") or "").strip() if isinstance(static, dict) else None
    if install_config.get("network_mode") == "static":
        return str(install_config.get("static_ip") or "").strip()
    return None


def _last_known_ip(machine_id: int) -> str | None:
    """IP à cibler en SSH avant repli WoL (best-effort), par ordre de
    priorité : dernier event DHCP ; IP statique du snapshot d'install (une
    machine installée en statique n'émet plus d'event DHCP). Chaque candidate
    doit être une IPv4 valide, sinon on passe à la suivante. None si aucune.
    inventory_json.discovery_ip est exclue volontairement : cette IP peut avoir
    été réattribuée à une autre machine, et le reboot SSH y enverrait la clé
    Ansible."""
    with db_connect() as db:
        placeholders = ",".join("?" * len(_DHCP_CHECKPOINTS))
        event = db.execute(
            f"SELECT message FROM machine_events "
            f"WHERE machine_id = ? AND checkpoint IN ({placeholders}) "
            f"ORDER BY id DESC LIMIT 1",
            (machine_id, *_DHCP_CHECKPOINTS),
        ).fetchone()
        machine = db.execute(
            "SELECT install_config_json FROM machines WHERE id = ?",
            (machine_id,),
        ).fetchone()

    dhcp_match = _DHCP_IP_RE.search(str(event["message"])) if event and event["message"] else None
    candidates = [
        dhcp_match.group(1) if dhcp_match else None,
        _snapshot_static_ip(_json_dict(machine["install_config_json"])) if machine else None,
    ]
    return next((ip for ip in candidates if ip and _IPV4_RE.match(ip)), None)


def _ssh_remote_disconnect(output: str) -> bool:
    """Recognize an SSH session cut by the remote host during a power action.

    Exit code 255 alone also covers connection timeouts and routing errors.
    Those must reach the WoL fallback instead of being reported as a reboot.
    """
    lowered = output.lower()
    return any(signature in lowered for signature in (
        "closed by remote host",
        "connection reset by peer",
        "broken pipe",
    ))


def _ssh_reboot(ip: str) -> tuple[bool, str | None]:
    """Tente `sudo systemctl reboot` via SSH sur la machine cible.

    Une déconnexion avant réception du statut est signalée comme non
    confirmée ; elle ne démontre pas que la commande a été exécutée.

    Retourne (True, None) si succès, (False, reason) sinon.
    """
    if not _IPV4_RE.match(ip):
        return False, f"ip invalide: {ip}"
    cmd = [
        "ssh",
        "-i", _ANSIBLE_SSH_KEY,
        "-o", "StrictHostKeyChecking=yes",
        "-o", "ConnectTimeout=3",
        "-o", "BatchMode=yes",
        "-o", "LogLevel=ERROR",
        f"{_SSH_USER}@{ip}",
        "sudo systemctl reboot",
    ]
    try:
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=10,
        )
    except subprocess.TimeoutExpired:
        return False, "ssh timeout"
    except Exception as e:
        return False, f"ssh exception: {e}"

    rc = proc.returncode
    stderr_raw = (proc.stdout or "") + "\n" + (proc.stderr or "")
    # Strip debug lines (qui peuvent leaker IPs/users/versions).
    stderr_clean = re.sub(r"^debug\d:.*$", "", stderr_raw, flags=re.MULTILINE)
    stderr_clean = re.sub(r"\n+", "\n", stderr_clean).strip()
    # Sanitize path clé SSH (cf. SEC-01).
    stderr_clean = stderr_clean.replace(_ANSIBLE_SSH_KEY, "<key>")

    if rc == 0:
        return True, None
    if rc == 255 and _ssh_remote_disconnect(stderr_clean):
        return False, "session SSH interrompue : résultat non confirmé"
    reason = stderr_clean if stderr_clean else f"ssh rc={rc}"
    return False, reason


def _wol_send_single(mac: str, broadcast: str, port: int = 9) -> bool:
    """Envoie un magic packet WoL sur un broadcast. Retourne True si sendto OK."""
    hex_mac = re.sub(r"[^0-9a-f]", "", mac.lower())
    if len(hex_mac) != 12:
        return False
    try:
        mac_bytes = bytes.fromhex(hex_mac)
    except ValueError:
        return False
    packet = b"\xff" * 6 + mac_bytes * 16
    sock = None
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        sock.sendto(packet, (broadcast, port))
        return True
    except OSError:
        return False
    finally:
        if sock is not None:
            sock.close()


def _wol_send(mac: str) -> bool:
    """Send a magic packet only to the explicitly configured subnet broadcast."""
    mac_norm = mac.lower()
    if not _MAC_RE.match(mac_norm):
        return False
    cidr = db_setting("pxe_subnet", "").strip()
    parts = cidr.split("/")
    if len(parts) != 2 or not re.fullmatch(r"(?:0|[1-9]|[12][0-9]|3[0-2])", parts[1]):
        return False
    try:
        network = ipaddress.IPv4Network(cidr, strict=False)
    except ValueError:
        return False
    if network.prefixlen >= 31:
        return False
    return _wol_send_single(mac_norm, str(network.broadcast_address))


def _log_machine_event(machine_id: int, action_id: int | None, level: str, message: str) -> None:
    """Équivalent MachineEvent::log() PHP — INSERT direct dans machine_events.
    Best-effort : on log une warning Python si l'INSERT échoue mais on ne
    propage pas (le worker continue son job)."""
    try:
        with db_connect() as db:
            db.execute(
                "INSERT INTO machine_events (machine_id, level, message, checkpoint, action_id) "
                "VALUES (?, ?, ?, NULL, ?)",
                (machine_id, level, message, action_id),
            )
    except Exception as e:
        logging.warning("_log_machine_event failed (machine=%s): %s", machine_id, e)


def run_reboot_job(job: dict) -> None:
    """Handler init_job 'action:reboot:N' — reboote la machine associée à
    l'action N via SSH (si UP) avec fallback WoL (si SSH KO). Pour les reboots
    machine non liés à une action, voir `run_machine_power_job`.
    """
    job_id = job["id"]
    job_type = job["job_type"]
    try:
        action_id = int(job_type.rsplit(":", 1)[-1])
    except (IndexError, ValueError):
        update_init_job(job_id, status="failed", message=f"action_id invalide dans {job_type}", ended_at=now(), updated_at=now())
        return

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    # Récupère mac + machine_id via JOIN (action → machine).
    with db_connect() as db:
        row = db.execute(
            "SELECT a.machine_id, m.mac FROM actions a "
            "JOIN machines m ON a.machine_id = m.id WHERE a.id = ?",
            (action_id,),
        ).fetchone()
    if not row:
        update_init_job(job_id, status="failed", message=f"action {action_id} introuvable", ended_at=now(), updated_at=now())
        return

    machine_id = int(row["machine_id"])
    mac = str(row["mac"])
    ip = _last_known_ip(machine_id)

    # Tentative SSH si IP connue.
    ssh_ok = False
    ssh_error: str | None = None
    if ip:
        ssh_ok, ssh_error = _ssh_reboot(ip)

    if ssh_ok:
        update_init_job(
            job_id,
            status="success",
            ended_at=now(),
            updated_at=now(),
            message=f"Reboot SSH déclenché vers {ip}",
        )
        _log_machine_event(
            machine_id, action_id, "action",
            f"Reboot SSH déclenché vers {ip} via worker — la machine va redémarrer",
        )
        return

    # Fallback WoL (broadcast subnet + global).
    wol_ok = _wol_send(mac)
    if wol_ok:
        suffix = f" (SSH KO: {ssh_error})" if ssh_error else " (IP inconnue)"
        update_init_job(
            job_id,
            status="success",
            ended_at=now(),
            updated_at=now(),
            message=f"WoL envoyé à {mac}" + suffix,
        )
        _log_machine_event(
            machine_id, action_id, "action",
            "Magic packet WoL envoyé via worker",
        )
    else:
        update_init_job(
            job_id,
            status="failed",
            ended_at=now(),
            updated_at=now(),
            message=f"SSH KO ({ssh_error}) et WoL échec",
        )
        _log_machine_event(
            machine_id, action_id, "warning",
            "Échec reboot SSH + WoL via worker",
        )


def _ssh_systemctl(ip: str, verb: str) -> tuple[bool, str | None]:
    """Variante de `_ssh_reboot` paramétrée — exécute `sudo systemctl <verb>`
    via SSH. Une déconnexion ne permet pas de confirmer le résultat de
    `reboot` ou `poweroff` sans accusé authentifié.
    """
    if not _IPV4_RE.match(ip):
        return False, f"ip invalide: {ip}"
    cmd = [
        "ssh",
        "-i", _ANSIBLE_SSH_KEY,
        "-o", "StrictHostKeyChecking=yes",
        "-o", "ConnectTimeout=3",
        "-o", "BatchMode=yes",
        "-o", "LogLevel=ERROR",
        f"{_SSH_USER}@{ip}",
        f"sudo systemctl {verb}",
    ]
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=10)
    except subprocess.TimeoutExpired:
        return False, "ssh timeout"
    except Exception as e:
        return False, f"ssh exception: {e}"

    rc = proc.returncode
    stderr_raw = (proc.stdout or "") + "\n" + (proc.stderr or "")
    stderr_clean = re.sub(r"^debug\d:.*$", "", stderr_raw, flags=re.MULTILINE)
    stderr_clean = re.sub(r"\n+", "\n", stderr_clean).strip()
    stderr_clean = stderr_clean.replace(_ANSIBLE_SSH_KEY, "<key>")

    if rc == 0:
        return True, None
    if rc == 255 and _ssh_remote_disconnect(stderr_clean):
        return False, "session SSH interrompue : résultat non confirmé"
    reason = stderr_clean if stderr_clean else f"ssh rc={rc}"
    return False, reason


def run_machine_power_job(job: dict, verb: str) -> None:
    """Handler init_job 'machine:reboot:M' / 'machine:shutdown:M' — pilote
    l'alimentation de la machine M via SSH (worker tourne root → clé ansible
    lisible). Reboot : fallback WoL si SSH KO. Shutdown : pas de fallback
    (WoL allume, ne peut pas éteindre — déjà éteinte = succès implicite).
    """
    job_id = job["id"]
    job_type = job["job_type"]
    try:
        machine_id = int(job_type.rsplit(":", 1)[-1])
    except (IndexError, ValueError):
        update_init_job(job_id, status="failed", message=f"machine_id invalide dans {job_type}", ended_at=now(), updated_at=now())
        return

    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    with db_connect() as db:
        row = db.execute("SELECT mac FROM machines WHERE id = ?", (machine_id,)).fetchone()
    if not row:
        update_init_job(job_id, status="failed", message=f"machine {machine_id} introuvable", ended_at=now(), updated_at=now())
        return

    mac = str(row["mac"])
    ip = _last_known_ip(machine_id)

    ssh_ok = False
    ssh_error: str | None = None
    if ip:
        ssh_ok, ssh_error = _ssh_systemctl(ip, verb)

    if ssh_ok:
        action_fr = "Redémarrage" if verb == "reboot" else "Arrêt"
        update_init_job(job_id, status="success", ended_at=now(), updated_at=now(), message=f"{action_fr} SSH déclenché vers {ip}")
        _log_machine_event(machine_id, None, "action", f"{action_fr} SSH déclenché vers {ip} via worker")
        return

    # Reboot : fallback WoL (réveille machine éteinte). Shutdown : pas de fallback.
    if verb == "reboot":
        wol_ok = _wol_send(mac)
        if wol_ok:
            suffix = f" (SSH KO: {ssh_error})" if ssh_error else " (IP inconnue)"
            update_init_job(job_id, status="success", ended_at=now(), updated_at=now(), message=f"WoL envoyé à {mac}" + suffix)
            _log_machine_event(machine_id, None, "action", "Magic packet WoL envoyé via worker")
            return
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(), message=f"SSH KO ({ssh_error}) et WoL échec")
        _log_machine_event(machine_id, None, "warning", "Échec redémarrage SSH + WoL via worker")
    else:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(), message=f"SSH KO ({ssh_error or 'IP inconnue'})")
        _log_machine_event(machine_id, None, "warning", f"Échec arrêt SSH via worker ({ssh_error or 'IP inconnue'})")


def run_nginx_reload_job(job: dict) -> None:
    """Handler init_job 'system:nginx_reload' — hot-reload de la conf nginx
    admin après changement de la setting `https_enabled` côté UI.

    Pipeline :
      1. Re-exécute /opt/pxe-tools/init/render-nginx-conf.sh (re-render des
         fichiers admin-https.conf + admin-http.conf selon la setting actuelle)
      2. `nginx -t` valide la conf rendue
      3. `nginx -s reload` applique sans downtime (sockets actifs conservés)
      4. Si validation échoue, on ne reload pas — la conf précédente reste
         active (état nginx non touché). Le job est marqué failed.

    Le worker tourne root → accès direct aux scripts shell et signaux nginx,
    pas besoin de docker exec.
    """
    job_id = job["id"]
    update_init_job(job_id, status="running", started_at=now(), updated_at=now())

    log_buffer: list[str] = []

    def _log(msg: str) -> None:
        log_buffer.append(msg)

    # Native installation can use a separate nginx master and configuration.
    # The default remains the appliance's existing nginx process.
    nginx_config = os.environ.get("PXE_NGINX_CONFIG", "").strip()
    if nginx_config and not os.path.isabs(nginx_config):
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message="Chemin de configuration nginx absolu requis.")
        return
    nginx_config_args = ["-c", nginx_config] if nginx_config else []

    # 1. Re-render
    try:
        rr = subprocess.run(
            ["/opt/pxe-tools/init/render-nginx-conf.sh"],
            capture_output=True, text=True, timeout=10,
        )
    except Exception as e:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"render-nginx-conf exception : {e}",
                        log="".join(log_buffer))
        return
    _log(f"[render-nginx-conf rc={rr.returncode}]\n{rr.stdout}{rr.stderr}\n")
    if rr.returncode != 0:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"render-nginx-conf KO (rc={rr.returncode})",
                        log="".join(log_buffer))
        return

    # 2. Validation conf
    try:
        nt = subprocess.run(
            ["nginx", "-t", *nginx_config_args],
            capture_output=True, text=True, timeout=10,
        )
    except Exception as e:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"nginx -t exception : {e}",
                        log="".join(log_buffer))
        return
    _log(f"[nginx -t rc={nt.returncode}]\n{nt.stdout}{nt.stderr}\n")
    if nt.returncode != 0:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"nginx -t KO (rc={nt.returncode}) — reload annulé, conf précédente conservée",
                        log="".join(log_buffer))
        return

    # 3. Reload (SIGHUP-like, zero downtime)
    try:
        rl = subprocess.run(
            ["nginx", "-s", "reload", *nginx_config_args],
            capture_output=True, text=True, timeout=10,
        )
    except Exception as e:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"nginx -s reload exception : {e}",
                        log="".join(log_buffer))
        return
    _log(f"[nginx -s reload rc={rl.returncode}]\n{rl.stdout}{rl.stderr}\n")
    if rl.returncode != 0:
        update_init_job(job_id, status="failed", ended_at=now(), updated_at=now(),
                        message=f"nginx -s reload KO (rc={rl.returncode})",
                        log="".join(log_buffer))
        return

    update_init_job(job_id, status="success", ended_at=now(), updated_at=now(),
                    message="Conf nginx admin re-rendue et reload appliqué (zero downtime)",
                    log="".join(log_buffer))


def render_postboot_for_action(action_id: int, token: str, mac: str, pxe_ip: str) -> str | None:
    """Rend postboot.sh + pxe-postboot.service pour une action donnée.

    SEC-01 : dépose les fichiers dans POSTBOOT_OUTPUT_DIR/{action_id}/
    (par défaut /var/lib/pxe-tools/postboot, HORS document root nginx).
    Servis par la route PHP /postboot/{id}/postboot.sh qui authentifie
    REMOTE_ADDR vs dernière IP DHCP loggée pour la MAC de l'action.

    Perms 0640 www-data:www-data : php-fpm tourne en www-data, doit pouvoir
    lire ; personne d'autre n'a besoin (hors root). Si l'uid www-data
    n'existe pas (cas tests hors container), on retombe sur 0640 default
    sans chown.

    Retourne le chemin du dossier rendu ou None en cas d'échec.
    """
    if not action_id or not token or not mac or not pxe_ip:
        logging.warning(
            "render_postboot_for_action skip: missing args "
            "(action_id=%s token=%s mac=%s pxe_ip=%s)",
            action_id, bool(token), bool(mac), bool(pxe_ip),
        )
        return None
    try:
        # Import lazy : Jinja2 est dispo dans le container (cf. Dockerfile),
        # mais on évite de l'importer au top-level pour ne pas crasher le worker
        # si quelqu'un lance un script avec un PYTHONPATH cassé.
        from jinja2 import Environment, FileSystemLoader, StrictUndefined
    except ImportError as e:
        logging.error("Jinja2 indisponible: %s", e)
        return None

    out_dir = os.path.join(POSTBOOT_OUTPUT_DIR, str(action_id))
    try:
        os.makedirs(out_dir, mode=0o750, exist_ok=True)
        env = Environment(
            loader=FileSystemLoader(POSTBOOT_TEMPLATES_DIR),
            undefined=StrictUndefined,
            keep_trailing_newline=True,
        )
        ctx = {"pxe_ip": pxe_ip, "token": token, "mac": mac}

        sh_tpl = env.get_template("postboot.sh.j2")
        sh_path = os.path.join(out_dir, "postboot.sh")
        with open(sh_path, "w", encoding="utf-8") as f:
            f.write(sh_tpl.render(**ctx))
        # SEC-01 : 0640 — le script n'est plus exécuté par nginx, il est
        # lu (readfile) par PHP-FPM qui le sert via Content-Type shellscript.
        # Pas besoin du bit x ; readable owner+group (www-data) suffit.
        os.chmod(sh_path, 0o640)

        svc_tpl = env.get_template("pxe-postboot.service.j2")
        svc_path = os.path.join(out_dir, "pxe-postboot.service")
        with open(svc_path, "w", encoding="utf-8") as f:
            f.write(svc_tpl.render(**ctx))
        os.chmod(svc_path, 0o640)

        # Best-effort chown www-data:www-data (worker tourne typiquement en
        # root dans le container). Skip silencieux hors container/tests.
        try:
            import pwd, grp
            uid = pwd.getpwnam("www-data").pw_uid
            gid = grp.getgrnam("www-data").gr_gid
            os.chown(out_dir, uid, gid)
            os.chown(sh_path, uid, gid)
            os.chown(svc_path, uid, gid)
        except (KeyError, PermissionError, OSError):
            pass

        logging.info("postboot rendered for action_id=%s → %s", action_id, out_dir)
        return out_dir
    except Exception as e:
        logging.error("render_postboot_for_action failed for action_id=%s: %s", action_id, e)
        return None


def run_playbook(job: dict) -> None:
    install_id = job["install_id"]
    role = (job.get("ansible_role") or "").strip()
    hostname = (job.get("hostname") or "").strip()
    action_id = job.get("action_id")
    action_token = job.get("action_token")
    mac = job.get("mac")

    config = _json_dict(job.get("install_config_json"))
    if role == "proxmox_ve" or config.get("variant") == "proxmox-ve":
        append_install_log(install_id, "[worker] Cette configuration n'est pas disponible dans cette édition.\n")
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        return

    ansible_enabled = is_ansible_enabled()
    if not ansible_enabled or not role:
        # ansible_status='no_role' (au lieu de 'success' trompeur) pour
        # distinguer dans l'UI une install sans configuration vs une install
        # configurée avec succès. L'OS est installé, mais la machine n'est pas
        # provisionnée. Le log est écrit avant la transition terminale.
        reason = "Ansible désactivé" if not ansible_enabled else "Aucun rôle Ansible défini"
        append_install_log(install_id, f"[worker] {reason} — configuration ignorée. OS installé, machine non provisionnée.\n")
        update_machine_status(job["machine_id"], "installed")
        update_install(install_id, ansible_status="no_role")
        return

    if not hostname:
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        append_install_log(install_id, "[worker] Hostname manquant, abort.\n")
        return

    try:
        ansible_executable, ansible_uid, ansible_gid, ansible_options = resolve_ansible_runtime()
    except RuntimeError as error:
        append_install_log(install_id, f"[worker] {error}.\n")
        update_install(install_id, ansible_status='failed')
        update_machine_status(job['machine_id'], 'failed')
        return

    update_install(install_id, ansible_status="running", started_at=now())

    # Le rôle peut avoir été ajouté au dépôt depuis la dernière installation.
    # On synchronise avant de vérifier la présence du playbook.
    if not ensure_ansible_repository_current(lambda msg: append_install_log(install_id, msg)):
        append_install_log(install_id, "[worker] Actualisation du dépôt Ansible impossible.\n")
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        return

    try:
        playbook = _repository_playbook(role)
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        append_install_log(install_id, f"[worker] Profil de provisionnement refusé : {error}\n")
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        return
    full_path = os.path.join(WORKDIR, playbook)
    if not os.path.isfile(full_path):
        msg = f"[worker] Playbook non trouvé : {full_path}\n"
        append_install_log(install_id, msg)
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        return

    # Pipeline event-driven : 2 checkpoints successifs avant ansible-playbook.
    # 1. install_reboot_done — sidecar dnsmasq-tail détecte le DHCP post-install
    #    (machine a physiquement redémarré et contacté le réseau).
    # 2. os_first_boot — postboot.sh confirme OS bootée + network up + sshd up
    #    (push-based, signal exact = moment idéal pour lancer ansible).
    # Cf. block « Pipeline event-driven post-install » plus haut.
    if action_id and action_token and mac:
        append_install_log(
            install_id,
            f"[worker] Attente signal DHCP post-install (install_reboot_done) "
            f"— timeout {_INSTALL_REBOOT_TIMEOUT_S}s...\n",
        )
        if wait_for_install_reboot_done(
            int(action_id), str(action_token), str(mac),
            timeout_s=_INSTALL_REBOOT_TIMEOUT_S,
        ):
            append_install_log(
                install_id,
                "[worker] Signal install_reboot_done reçu — machine rebootée en PXE post-install.\n",
            )
        else:
            logging.warning(
                "[worker] Signal install_reboot_done non reçu après %ds "
                "(action_id=%s) — fallback install_reboot_assumed.",
                _INSTALL_REBOOT_TIMEOUT_S, action_id,
            )
            append_install_log(
                install_id,
                f"[worker] ⚠ Signal DHCP post-install non reçu après {_INSTALL_REBOOT_TIMEOUT_S}s "
                "(machine en IP statique, PXE désactivé au 2e boot, ou réseau instable). "
                "Checkpoint synthétique install_reboot_assumed posé — on continue.\n",
            )
            send_checkpoint(str(action_token), str(mac), _INSTALL_REBOOT_ASSUMED_CP)

        append_install_log(
            install_id,
            f"[worker] Attente signal os_first_boot (postboot.sh) — timeout {_OS_FIRST_BOOT_TIMEOUT_S}s...\n",
        )
        if wait_for_os_first_boot(int(action_id), timeout_s=_OS_FIRST_BOOT_TIMEOUT_S):
            append_install_log(
                install_id,
                "[worker] Signal os_first_boot reçu — OS bootée et postboot.sh a contacté l'orchestrateur, lancement ansible-playbook.\n",
            )
        else:
            # Fallback : postboot.sh peut être cassé/désactivé/network ko.
            # On tente ansible quand même — il aura son propre verdict SSH.
            logging.warning(
                "[worker] Signal os_first_boot non reçu après %ds (action_id=%s) "
                "— fallback os_first_boot_assumed, lancement ansible direct.",
                _OS_FIRST_BOOT_TIMEOUT_S, action_id,
            )
            append_install_log(
                install_id,
                f"[worker] ⚠ Signal os_first_boot non reçu après {_OS_FIRST_BOOT_TIMEOUT_S}s "
                "(postboot.sh cassé/désactivé, network ko ou DNS interne KO). "
                "Checkpoint synthétique os_first_boot_assumed posé — lancement ansible (verdict SSH côté ansible).\n",
            )
            send_checkpoint(str(action_token), str(mac), _OS_FIRST_BOOT_ASSUMED_CP)
    else:
        # Action legacy (sans token) : skip les 2 polls, ansible direct.
        append_install_log(
            install_id,
            "[worker] Skip attente checkpoints post-install (action legacy sans token) — ansible direct.\n",
        )

    cmd = [ansible_executable, playbook, "--limit", hostname]

    # --private-key : surcharge ansible_ssh_private_key_file (~/.ssh/ansible_automation
    # qui s'expanse en /root/.ssh/... car le worker tourne en root, alors que la clé
    # est dans /home/ansible/.ssh/...). La CLI ansible-playbook a priorité sur les
    # group_vars/host_vars, contrairement à la var d'env ANSIBLE_PRIVATE_KEY_FILE.
    ansible_key = os.path.join(ansible_home(), ".ssh", "ansible_automation")
    if os.path.exists(ansible_key):
        cmd += ["--private-key", ansible_key]

    extra = (job.get("ansible_extra_vars") or "").strip()
    display_cmd = list(cmd)
    if extra:
        cmd += ["-e", extra]
        display_cmd += ["-e", "[valeurs masquées]"]
    # La préparation des variables fait partie de la configuration. Émettre
    # ce jalon avant les contrôles Proxmox permet de rattacher leur erreur à
    # une tentative démarrée, sans cocher une étape jamais lancée.
    if action_id and action_token and mac:
        send_checkpoint(action_token, mac, "ansible_started")
    else:
        logging.warning(
            "Skip checkpoint ansible_started (legacy install_id=%s, action_id=%s)",
            install_id, action_id,
        )

    append_install_log(install_id, f"[worker] $ {' '.join(display_cmd)}\n[worker] cwd={WORKDIR}\n\n")

    # Rendu postboot.sh + service pour cette action (idempotent).
    # POURQUOI ici aussi : le rendu primaire est fait par Worker_CTRL à la
    # création de l'action (preseed/late_command wget /postboot/{action_id}/).
    # On re-render en best-effort pour couvrir le cas où l'install a été créée
    # avant le déploiement de Worker_CTRL ou si le rendu initial a échoué.
    if action_id and action_token and mac:
        pxe_ip_setting = db_setting("pxe_ip", "")
        if pxe_ip_setting:
            render_postboot_for_action(int(action_id), str(action_token), str(mac), pxe_ip_setting)

    try:
        with ansible_vault_password_fd(ansible_uid, ansible_gid) as (vault_path, vault_fd):
            proc = subprocess.Popen(
                cmd,
                cwd=WORKDIR,
                env=ansible_subprocess_env(vault_path),
                pass_fds=(vault_fd,),
                **ansible_options,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                bufsize=1,
            )
            assert proc.stdout is not None
            for line in proc.stdout:
                append_install_log(install_id, line)
            rc = proc.wait()
    except VaultLocked:
        append_install_log(install_id, "[worker] Mot de passe Vault absent : exécution reportée.\n")
        return
    except Exception as e:
        append_install_log(install_id, f"[worker] Échec lancement : {e}\n")
        # Émettre AVANT update_install (qui déclenche trigger_recompute_action →
        # action.status='failed' → rejet action_terminal côté InternalApiController:128).
        # Cf. Decision_ANSRETRY01 Option A : self-block déterministe sinon, badge phase
        # Post-installation reste pending gris au lieu de failed rouge.
        if action_id and action_token and mac:
            send_checkpoint(action_token, mac, "ansible_finished", {"returncode": -1, "error": str(e)}, level="error")
        update_install(install_id, ansible_status="failed")
        update_machine_status(job["machine_id"], "failed")
        return
    final = "success" if rc == 0 else "failed"
    machine_final = "provisioned" if rc == 0 else "failed"
    append_install_log(install_id, f"\n[worker] ansible-playbook exit code = {rc}\n")

    # Checkpoint ansible_finished émis AVANT update_install (qui déclenche
    # trigger_recompute_action → action.status='failed' sur rc!=0 → rejet
    # action_terminal côté InternalApiController:128). Cf. Decision_ANSRETRY01
    # Option A : self-block déterministe sinon, badge phase Post-installation
    # reste pending gris au lieu de failed rouge. Payload returncode distingue
    # succès/échec, level=error si rc!=0.
    if action_id and action_token and mac:
        cp_level = "error" if rc != 0 else None
        send_checkpoint(action_token, mac, "ansible_finished", {"returncode": rc}, level=cp_level)

    update_install(install_id, ansible_status=final)
    update_machine_status(job["machine_id"], machine_final)


# ============================================================================
# Ansible workspace bootstrap (git clone + vault password)
# ============================================================================

def db_setting(key: str, default: str = "") -> str:
    """Helper minimaliste pour lire un setting (worker-side, sans import circulaire)."""
    try:
        with db_connect() as db:
            row = db.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
            return row["value"] if row and row["value"] else default
    except Exception:
        return default


def db_set_setting(key: str, value: str) -> None:
    """Écrit un setting côté worker (upsert). Pour remonter des données structurées
    à l'UI (JSON) — même table que Setting::set côté PHP."""
    try:
        with db_connect() as db:
            db.execute(
                "INSERT INTO settings (key, value) VALUES (?, ?) "
                "ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP",
                (key, str(value)),
            )
    except Exception as e:
        print(f"[worker] db_set_setting({key}) échec : {e}", flush=True)


def start_auto_catalog_refresh(job_id: int) -> bool:
    """Passe le job auto à running seulement si aucune action PXE ne peut démarrer."""
    with db_connect() as db:
        job = db.execute(
            "UPDATE init_jobs SET status='running', started_at=?, updated_at=?, progress_percent=10 "
            "WHERE id=? AND status='pending' "
            "AND NOT EXISTS (SELECT 1 FROM machines WHERE status IN ('installing', 'wiping', 'testing')) "
            "AND NOT EXISTS (SELECT 1 FROM actions WHERE status IN ('pending', 'running'))",
            (now(), now(), job_id),
        )
        return job.rowcount == 1


def enqueue_auto_catalog_refresh() -> None:
    """Enfile un refresh périodique uniquement quand aucune machine ne boote."""
    now_epoch = int(time.time())
    try:
        with db_connect() as db:
            values = {
                row["key"]: row["value"]
                for row in db.execute(
                    "SELECT key, value FROM settings WHERE key IN (?, ?, ?)",
                    (
                        "catalog_auto_refresh_enabled",
                        "catalog_auto_refresh_interval_hours",
                        "catalog_auto_refresh_last_at",
                    ),
                )
            }
            try:
                interval_hours = int(values.get("catalog_auto_refresh_interval_hours", "168"))
            except (TypeError, ValueError):
                interval_hours = 168
            if interval_hours not in (24, 168, 720):
                interval_hours = 168
            try:
                last_at = int(values.get("catalog_auto_refresh_last_at", "0"))
            except (TypeError, ValueError):
                last_at = 0

            refresh_running = db.execute(
                "SELECT 1 FROM init_jobs WHERE job_type IN (?, ?, ?) "
                "AND status IN ('pending', 'running') LIMIT 1",
                (*MANUAL_REFRESH_JOB_TYPES, AUTO_REFRESH_JOB_TYPE),
            ).fetchone() is not None
            machine_active = db.execute(
                "SELECT 1 FROM machines WHERE status IN ('installing', 'wiping', 'testing') LIMIT 1"
            ).fetchone() is not None
            action_pending = db.execute(
                "SELECT 1 FROM actions WHERE status IN ('pending', 'running') LIMIT 1"
            ).fetchone() is not None
            if not should_enqueue_auto_catalog_refresh(
                enabled=values.get("catalog_auto_refresh_enabled", "off") == "on",
                interval_hours=interval_hours,
                last_at=last_at,
                now=now_epoch,
                refresh_running=refresh_running,
                machine_active=machine_active,
                pxe_action_pending=action_pending,
            ):
                return

            job = db.execute(
                "INSERT INTO init_jobs (job_type, status, message) "
                "SELECT ?, 'pending', ? WHERE NOT EXISTS ("
                "SELECT 1 FROM init_jobs WHERE job_type IN (?, ?, ?) "
                "AND status IN ('pending', 'running'))",
                (
                    AUTO_REFRESH_JOB_TYPE,
                    "Actualisation automatique du catalogue",
                    *MANUAL_REFRESH_JOB_TYPES,
                    AUTO_REFRESH_JOB_TYPE,
                ),
            )
            if job.rowcount:
                print("[worker] auto refresh catalogue enqueued", flush=True)
    except Exception as e:
        print(f"[worker] auto refresh catalogue failed: {e}", file=sys.stderr, flush=True)


def bootstrap_ansible_workspace() -> None:
    """
    Au démarrage du worker :
      1. Si WORKDIR ne contient pas .git, clone le repo (setting ansible_repo_url)
         avec la configuration SSH explicite et les clés d’hôte vérifiées.

    Idempotent et fail-soft : log les erreurs sans crash le worker.
    """
    if not is_ansible_enabled():
        return
    deploy_key = os.environ.get("PXE_GIT_SSH_KEY", "").strip()
    git_ssh_cmd = _git_ssh_cmd()

    # 1. Clone initial si nécessaire
    if not os.path.isdir(os.path.join(WORKDIR, ".git")):
        repo_url = db_setting("ansible_repo_url", "")
        if not repo_url:
            print(f"[worker] ansible_repo_url absent et {WORKDIR} pas de .git → skip clone initial", flush=True)
        elif deploy_key and not os.path.isfile(deploy_key):
            print("[worker] Clé Git configurée absente : clonage initial refusé.", flush=True)
        else:
            os.makedirs(WORKDIR, exist_ok=True)
            print(f"[worker] Clonage du dépôt Ansible → {WORKDIR}", flush=True)
            profile_path = db_setting("ansible_repo_profile", "").strip()
            if profile_path:
                _repository_scope_module().clone_scoped(
                    repo_url, WORKDIR, branch=db_setting("ansible_repo_branch", "main").strip(),
                    profile_path=profile_path,
                    env={**os.environ, "GIT_SSH_COMMAND": git_ssh_cmd})
                rc = None
            else:
                rc = subprocess.run(
                    ["git", "clone", repo_url, WORKDIR],
                    env={**os.environ, "GIT_SSH_COMMAND": git_ssh_cmd},
                    capture_output=True, text=True,
                )
            if rc is None or rc.returncode == 0:
                print("[worker] git clone OK", flush=True)
            else:
                print(f"[worker] git clone failed (rc={rc.returncode})", flush=True)

    # 2. Autoseed disaster-recovery : si la table presets est vide ET le flag
    #    presets_seeded n'est pas posé, enqueue un job pull_github:autoseed.
    #    Le handler vérifiera à nouveau la double garde avant d'agir (anti-race
    #    avec un éventuel premier import manuel concurrent).
    try:
        with db_connect() as db:
            n = db.execute("SELECT COUNT(*) AS n FROM presets").fetchone()
            seeded = db.execute(
                "SELECT value FROM settings WHERE key='presets_seeded'"
            ).fetchone()
            repo = db.execute(
                "SELECT value FROM settings WHERE key='ansible_repo_url'"
            ).fetchone()
        if (
            (n is None or n["n"] == 0)
            and not (seeded and seeded["value"] == "1")
            and (repo and repo["value"])
            and os.path.isdir(os.path.join(WORKDIR, ".git"))
        ):
            with db_connect() as db:
                existing_job = db.execute(
                    "SELECT id FROM init_jobs WHERE job_type='preset:pull_github:autoseed' "
                    "AND status IN ('pending','running') LIMIT 1"
                ).fetchone()
                if not existing_job:
                    db.execute(
                        "INSERT INTO init_jobs (job_type, status, message) "
                        "VALUES ('preset:pull_github:autoseed', 'pending', ?)",
                        ("Seed initial des modèles depuis GitHub (disaster-recovery)",),
                    )
                    print("[worker] autoseed enqueued (preset:pull_github:autoseed)", flush=True)
                else:
                    print("[worker] autoseed déjà en attente, skip enqueue", flush=True)
        else:
            print("[worker] autoseed inutile (presets présents, flag posé, repo absent ou pas cloné)", flush=True)
    except Exception as e:
        print(f"[worker] autoseed enqueue failed: {e}", flush=True)


# ============================================================================
# Boucle principale
# ============================================================================

def configured_worker_database() -> tuple[str, str]:
    """Match PHP's database source; never consume a leftover SQLite by accident."""
    return select_database(DB_CONFIG_PATH, DB_FALLBACK_PATH, DB_LEGACY_PATH)


def schema_ready() -> bool:
    """True si la DB existe, est non-vide ET le schéma est appliqué (table
    init_jobs présente). Connexion en lecture seule, et seulement une fois le
    fichier non-vide : une connexion en écriture créerait sinon un fichier/-wal
    en root, bloquant l'apply schema par www-data.

    Sans ce check sur le schéma, le worker sortait de l'attente dès que le
    fichier DB existait (créé par /init/db) mais AVANT que les tables soient
    posées → l'enqueue one-shot du bootstrap tombait sur `no such table:
    init_jobs` et n'était jamais rejoué (TFTP/MOK jamais initialisés)."""
    if not (os.path.exists(DB_PATH) and os.path.getsize(DB_PATH) > 0):
        return False
    try:
        conn = sqlite3.connect(f"file:{urllib.parse.quote(DB_PATH, safe='/')}?mode=ro", uri=True, timeout=5)
        try:
            row = conn.execute(
                "SELECT 1 FROM sqlite_master "
                "WHERE type='table' AND name='init_jobs'"
            ).fetchone()
            return row is not None
        finally:
            conn.close()
    except Exception:
        return False


def main() -> None:
    global DB_PATH
    print(f"[worker] start — SQLite par défaut={DB_PATH}, WORKDIR={WORKDIR}, poll={POLL_INTERVAL}s", flush=True)
    purge_unlock_dir()
    # Les anciens fichiers Vault sont conservés ; leur traitement demande une
    # action distincte de l'administrateur.

    # Le worker tourne en root et sqlite3.connect() CRÉE le fichier vide si
    # absent. Sur un fresh install (pas encore passé par /init/db), créer la DB
    # en root bloquerait ensuite l'apply schema par www-data via PDO (readonly).
    # On attend donc que le wizard ait créé la DB ET appliqué le schéma (table
    # init_jobs présente) — sinon l'enqueue bootstrap échoue à vide sans rejeu.
    waited = 0
    last_reason = None
    while True:
        try:
            candidate, database_source = configured_worker_database()
            DB_PATH = candidate
            if schema_ready():
                active_database = (candidate, database_source)
                break
            reason = 'En attente de la base SQLite et de son schéma (assistant initial).'
        except RuntimeError as error:
            reason = str(error)
        if reason != last_reason or (waited >= 60 and waited % 60 == 0):
            print(f"[worker] {reason}", flush=True)
            last_reason = reason
        time.sleep(POLL_INTERVAL)
        waited += POLL_INTERVAL

    try:
        bootstrap_ansible_workspace()
    except Exception as e:
        print(f"[worker] bootstrap_ansible_workspace failed: {e}", file=sys.stderr, flush=True)

    # Reaper en premier : un précédent crash/restart laisse des rows status='running'
    # (init_jobs) ou 'downloading' (os_images). Sans reset elles bloquent la file
    # de façon invisible (fetch ne les voit pas).
    try:
        reaped = reap_running_zombies()
        if reaped > 0:
            print(f"[worker] reaped {reaped} init_jobs zombie(s) status=running → failed", flush=True)
    except Exception as e:
        print(f"[worker] reaper init_jobs failed: {e}", file=sys.stderr, flush=True)

    try:
        reaped_img = reap_image_zombies()
        if reaped_img > 0:
            print(f"[worker] reaped {reaped_img} os_images zombie(s) status=downloading → pending", flush=True)
    except Exception as e:
        print(f"[worker] reaper os_images failed: {e}", file=sys.stderr, flush=True)

    # Enqueue les jobs bootstrap si nécessaire (au premier démarrage du container)
    try:
        if needs_bootstrap():
            print("[worker] volumes incomplets → enqueue jobs bootstrap", flush=True)
            enqueue_initial_bootstrap()
        else:
            print("[worker] artefacts PXE déjà présents, skip enqueue bootstrap", flush=True)
    except Exception as e:
        print(f"[worker] error enqueue bootstrap: {e}", file=sys.stderr, flush=True)

    last_ansible_runtime_check = -60
    last_auto_refresh_check = 0
    last_preset_sync_check = 0
    database_wait_reason = None
    database_wait_logged = 0.0
    while True:
        try:
            try:
                current_database = configured_worker_database()
                if current_database != active_database:
                    raise RuntimeError('Configuration de base modifiée ; redémarrez le worker après vérification.')
            except RuntimeError as error:
                reason = str(error)
                if reason != database_wait_reason or time.monotonic() - database_wait_logged >= 60:
                    print(f"[worker] {reason}", file=sys.stderr, flush=True)
                    database_wait_reason = reason
                    database_wait_logged = time.monotonic()
                time.sleep(POLL_INTERVAL)
                continue
            database_wait_reason = None
            if time.monotonic() - last_ansible_runtime_check >= 60:
                last_ansible_runtime_check = time.monotonic()
                publish_ansible_runtime_status()
            if time.monotonic() - last_preset_sync_check >= 300:
                last_preset_sync_check = time.monotonic()
                enqueue_auto_preset_pull()
            if time.monotonic() - last_auto_refresh_check >= 60:
                last_auto_refresh_check = time.monotonic()
                enqueue_auto_catalog_refresh()

            # Priorité absolue aux init jobs (bootstrap)
            init_job = fetch_pending_init_job(vault_locked=not unlock_pending())
            if init_job:
                print(f"[worker] picked init {init_job['id']} ({init_job['job_type']})", flush=True)
                try:
                    run_bootstrap_job(init_job)
                except Exception as e:
                    # Marque le job en failed si run_bootstrap_job a explosé,
                    # sinon il restera 'running' éternellement (zombie).
                    print(f"[worker] bootstrap job {init_job['id']} crashed: {e}", file=sys.stderr, flush=True)
                    try:
                        update_init_job(
                            init_job["id"],
                            status="failed",
                            ended_at=now(),
                            updated_at=now(),
                        )
                        append_init_log(init_job["id"], f"\n[worker] CRASH: {e}\n")
                    except Exception as ee:
                        print(f"[worker] failed to mark job failed: {ee}", file=sys.stderr, flush=True)
                continue

            # Puis les image_downloads (table os_images — gérés depuis /settings/images)
            img = fetch_pending_image_download()
            if img:
                label = f"{img['distro']}/{img['version']}/{img['arch']}"
                print(f"[worker] picked image download id={img['id']} ({label})", flush=True)
                try:
                    run_image_download(img)
                except Exception as e:
                    print(f"[worker] image download id={img['id']} crashed: {e}", file=sys.stderr, flush=True)
                    try:
                        update_os_image(img["id"], status="failed", error_msg=f"CRASH: {e}", updated_at=now())
                    except Exception as ee:
                        print(f"[worker] failed to mark image failed: {ee}", file=sys.stderr, flush=True)
                continue

            # Sinon traiter les ansible jobs
            ans_job = fetch_pending_ansible()
            if ans_job and ansible_needs_vault(ans_job) and not unlock_pending():
                time.sleep(POLL_INTERVAL)
                continue
            if ans_job:
                print(f"[worker] picked install {ans_job['install_id']} ({ans_job['hostname']})", flush=True)
                run_playbook(ans_job)
                continue

            time.sleep(POLL_INTERVAL)
        except KeyboardInterrupt:
            print("[worker] stop", flush=True)
            sys.exit(0)
        except Exception as e:
            print(f"[worker] error: {e}", file=sys.stderr, flush=True)
            time.sleep(POLL_INTERVAL)


if __name__ == "__main__":
    main()
