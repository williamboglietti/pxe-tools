#!/usr/bin/env python3
"""Render Jinja2 templates with environment variables as context.

Used by bootstrap-*.sh scripts to render PXE config files (grub-early.cfg,
grub.cfg, pxelinux-default) from the original Ansible templates without
requiring Ansible at runtime.

Mapping :
  ORCHESTRATOR_IP        → pxe_ip
  os_images table        → pxe_debian_versions (Spec E — query DB)
  DEBIAN_DEFAULT_VERSION → pxe_debian_default_version (fallback dernière version)
  KERNEL_OPTS            → pxe_kernel_opts
  BIOS_BOOTLOADER        → pxe_bios_bootloader
  UEFI_BOOTLOADER        → pxe_uefi_bootloader
  UEFI_ENABLED           → pxe_uefi_enabled (bool)

Spec E : la liste des versions Debian disponibles est lue depuis la table
sqlite `os_images` (rows enabled=1 AND status='downloaded'). Fallback sur
DEBIAN_VERSIONS env si la DB n'est pas accessible (cas premier render avant
migration), ou si la table os_images n'existe pas encore.

Usage : render_template.py <template_path> <output_path>
"""
import json
from atomic_publish import publish
import os
import sqlite3
import sys
import urllib.parse
from contextlib import closing
from jinja2 import Environment, FileSystemLoader, StrictUndefined

sys.path.insert(0, os.environ.get('PXE_APP_DIR') or '/app')
from database_selection import select_database

try:
    DB_PATH, DB_SOURCE = select_database()
except RuntimeError as error:
    print(f'[render] {error}', file=sys.stderr)
    sys.exit(1)


def db_setting(key: str, fallback: str = "") -> str:
    """Lit une setting depuis sqlite3 ; retourne fallback si absente / DB indispo.
    Lot 3 Spec B : permet à render_template de prendre les settings DB en
    priorité sur les env vars (qui restent en fallback compat premier boot)."""
    if not os.path.isfile(DB_PATH):
        return fallback
    try:
        conn = sqlite3.connect(DB_PATH, timeout=5)
        cur = conn.execute("SELECT value FROM settings WHERE key = ?", (key,))
        row = cur.fetchone()
        conn.close()
        return str(row[0]) if row and row[0] is not None else fallback
    except sqlite3.Error:
        return fallback


def fetch_debian_versions_from_db() -> list[str] | None:
    """Lit les CODENAMES Debian downloaded (trixie, bookworm) depuis os_images.
    Retourne None si la DB/table est absente (le caller fallback sur DEBIAN_VERSIONS).

    POURQUOI codename et pas version : ces valeurs alimentent allowed_distros
    (select OS du wizard/preset, qui stocke un codename) ET les paths netboot
    /netboot/<codename>/<arch>/ (boot.ipxe.j2, grub.php.j2) résolus via le symlink
    codename. En v2 os_images.version = version_full (ex 13.4.0) → l'utiliser ici
    faisait afficher « 13.4.0 » comme OS et cassait les paths netboot par codename.
    """
    if not os.path.isfile(DB_PATH):
        return None
    try:
        conn = sqlite3.connect(DB_PATH, timeout=5)
        cur = conn.execute(
            "SELECT DISTINCT codename FROM os_images "
            "WHERE distro = 'debian' AND enabled = 1 AND status = 'downloaded' "
            "  AND codename IS NOT NULL AND codename != '' "
            "ORDER BY codename"
        )
        rows = [r[0] for r in cur.fetchall()]
        conn.close()
        return rows
    except sqlite3.Error:
        return None


def fetch_debian_versions_with_full_from_db(arch: str) -> list[dict] | None:
    """Lit os_images downloaded en retournant chaque couple (codename, version_full)
    avec le distro associé. Utilisé par pxelinux-default.j2 + grub.cfg.j2 pour
    construire les paths /netboot/<distro>/<version_full>/<arch>/ (layout v2).

    Une entry par codename (le latest version_full DESC est retenu si plusieurs
    point releases d'un même codename sont téléchargées).
    """
    if not os.path.isfile(DB_PATH):
        return None
    try:
        conn = sqlite3.connect(DB_PATH, timeout=5)
        # POURQUOI codename et pas version : os_images.version = version_full en v2
        # (ex 13.4.0). Le champ "codename" du dict alimente le titre des menuentries
        # ET surtout l'URL preseed.php?distro=<codename> (grub.cfg.j2 / pxelinux).
        # Y mettre la version produisait ?distro=13.4.0 (codename invalide). Les
        # paths netboot, eux, n'utilisent que distro/version_full → inchangés.
        # Tri par version_full DESC : la dédup par codename retient le latest point-release.
        cur = conn.execute(
            "SELECT codename, version_full, distro FROM os_images "
            "WHERE distro = 'debian' AND arch = ? AND enabled = 1 AND status = 'downloaded' "
            "  AND version_full IS NOT NULL AND version_full != '' "
            "  AND codename IS NOT NULL AND codename != '' "
            "ORDER BY version_full DESC", (arch,)
        )
        rows = cur.fetchall()
        conn.close()
        seen = set()
        out = []
        for codename, vf, distro in rows:
            if codename in seen:
                continue
            seen.add(codename)
            out.append({"codename": codename, "version_full": vf, "distro": distro})
        return out
    except sqlite3.Error:
        return None


def fetch_default_netboot_path_from_db(arch_default: str) -> str | None:
    """Path /netboot/<distro>/<version_full>/<arch>/ de la version Debian COURANTE
    téléchargée (extract_method='direct'), pour le default netboot du discovery /
    rescue / fallback.

    POURQUOI restreindre à 'direct' : seules ces images ont le firmware.cpio.gz
    aligné sur le netboot installer du miroir (zéro skew anna). Les archives
    'iso_extract' figées peuvent désynchroniser le discovery → on les EXCLUT du
    default path (mais PAS du picker d'install versionné, cf. versions_full).
    Tri version_full DESC → la point release courante la plus récente gagne.
    Retourne None si DB/table absente ou aucune image direct → caller fallback
    sur debian/<arch>.
    """
    if not os.path.isfile(DB_PATH):
        return None
    try:
        conn = sqlite3.connect(DB_PATH, timeout=5)
        cur = conn.execute(
            "SELECT distro, version_full FROM os_images "
            "WHERE distro = 'debian' AND arch = ? AND enabled = 1 AND status = 'downloaded' "
            "  AND COALESCE(extract_method, 'direct') = 'direct' "
            "  AND version_full IS NOT NULL AND version_full != '' "
            "ORDER BY version_full DESC LIMIT 1", (arch_default,)
        )
        row = cur.fetchone()
        conn.close()
        if not row:
            return None
        return f"{row[0]}/{row[1]}/{arch_default}"
    except sqlite3.Error:
        return None


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: render_template.py <template> <output>", file=sys.stderr)
        return 1

    template_path, output_path = sys.argv[1], sys.argv[2]

    if DB_SOURCE == 'config':
        if not os.path.isfile(DB_PATH):
            print('[render] Base SQLite configurée absente ; rendu refusé.', file=sys.stderr)
            return 1
        try:
            with closing(sqlite3.connect(f"file:{urllib.parse.quote(DB_PATH, safe='/')}?mode=ro", uri=True, timeout=5)) as db:
                db.execute('SELECT key FROM settings LIMIT 1').fetchone()
        except sqlite3.Error:
            print('[render] Base SQLite configurée illisible ; rendu refusé.', file=sys.stderr)
            return 1

    env = Environment(
        loader=FileSystemLoader(os.path.dirname(template_path) or "."),
        undefined=StrictUndefined,
        keep_trailing_newline=True,
    )
    # Alias `to_json` (Ansible) → `tojson` (Jinja2 standard) pour compat templates
    env.filters["to_json"] = lambda v: json.dumps(v, ensure_ascii=False)
    template = env.get_template(os.path.basename(template_path))

    # Spec E : DB d'abord, env var en fallback (compat premier boot).
    versions = fetch_debian_versions_from_db()
    if not versions:
        versions = [v.strip() for v in os.environ.get("DEBIAN_VERSIONS", "bookworm").split(",") if v.strip()]
    # Layout v2 : chemin /netboot/<distro>/<version_full>/<arch>/ pour les
    # templates GRUB/PXE fallback. Si la DB n'a pas encore d'image v2 (premier
    # boot, catalogue pas refresh), on retombe sur des dicts vides → les boucles
    # Jinja produisent un menu vide (cas légitime : aucune install Debian
    # déclenchable tant que /settings/images n'est pas peuplé).
    arch_default = os.environ.get("DEBIAN_ARCH", "amd64")
    versions_full = fetch_debian_versions_with_full_from_db(arch_default) or []
    # Default netboot path = version COURANTE (extract_method='direct') uniquement.
    # versions_full ci-dessus, lui, garde TOUTES les versions downloaded (direct +
    # iso_extract) → le picker d'install versionné les voit toutes ; seul le
    # discovery/rescue/fallback est verrouillé sur la version direct alignée miroir.
    default_netboot_path = (
        fetch_default_netboot_path_from_db(arch_default) or f"debian/{arch_default}"
    )
    # Lot 3 Spec B : settings DB en priorité (avec env vars en fallback compat).
    orch_ip = db_setting("pxe_ip", os.environ.get("ORCHESTRATOR_IP", ""))
    root_pwd_hash = db_setting("root_password_hash", os.environ.get("ROOT_PASSWORD_HASH", ""))
    ansible_pubkey = db_setting("ansible_ssh_pubkey", "") if db_setting("ansible_enabled", "off") == "on" else ""
    # Spec H : toggle iPXE chainload (off par défaut). Quand on, pxelinux et grub
    # exposent un menuentry "Chainload iPXE" qui charge ipxe.lkrn / ipxe.efi.
    ipxe_enabled = db_setting("ipxe_enabled", "off") == "on"

    ctx = {
        "ansible_managed": "Géré par pxe-tools-worker bootstrap (NE PAS éditer)",
        "pxe_ip": orch_ip,
        "pxe_debian_versions": versions,
        # Layout v2 : liste d'objets {codename, version_full, distro} consommée
        # par les boucles Jinja des templates GRUB/PXE fallback (pxelinux-default,
        # grub.cfg) pour construire des paths /netboot/<distro>/<version_full>/<arch>/.
        "pxe_debian_versions_full": versions_full,
        # Path netboot par défaut (latest version_full downloaded pour debian)
        # pour les menus discovery / rescue / fallback qui n'ont pas de couple
        # codename-version explicite.
        "pxe_default_netboot_path": default_netboot_path,
        "pxe_debian_default_version": os.environ.get(
            "DEBIAN_DEFAULT_VERSION", versions[-1] if versions else "bookworm"
        ),
        "pxe_debian_mirror_url": os.environ.get(
            "DEBIAN_MIRROR", "http://deb.debian.org/debian"
        ),
        "pxe_kernel_opts": os.environ.get(
            "KERNEL_OPTS",
            "language=fr country=FR locale=fr_FR.UTF-8 keymap=fr ipv6.disable=1",
        ),
        "pxe_bios_bootloader": os.environ.get("BIOS_BOOTLOADER", "lpxelinux.0"),
        "pxe_uefi_bootloader": os.environ.get("UEFI_BOOTLOADER", "grubnetx64.efi"),
        "pxe_uefi_enabled": os.environ.get("UEFI_ENABLED", "true").lower() == "true",
        # Paths internes container (côté worker = côté php-fpm pour les volumes partagés)
        "pxe_data_dir": os.environ.get("PXE_TOOLS_DATA_DIR", "/var/lib/pxe-tools"),
        "pxe_tftp_root": os.environ.get("TFTP_ROOT", "/srv/tftp"),
        "pxe_http_root": os.environ.get("HTTP_ROOT", "/srv/http"),
        # Secrets : settings DB en priorité (Spec B Lot 3), env vars en fallback.
        # preseed.php.j2 lit ces clés depuis data.json (rendu une fois au boot).
        # Pour root_password_hash : si install_config_json.root_password_hash
        # est posé per-machine (Spec F.2), le preseed.php utilise celui-là en
        # priorité ; sinon fallback sur cette valeur globale.
        "pxe_root_password_hash": root_pwd_hash,
        "pxe_ansible_pubkey": ansible_pubkey,
        "ansible_ssh_pubkey": ansible_pubkey,
        # Port admin (Spec A.10) — utilisé dans grub.php.j2 pour afficher l'URL
        # admin aux machines en status=discovered sans preset.
        "pxe_admin_port": os.environ.get("ADMIN_PORT", "8443"),
        # Spec H : toggle iPXE chainload — pxelinux/grub conditionnent leurs
        # menuentries "Chainload iPXE" sur ce flag.
        "pxe_ipxe_enabled": ipxe_enabled,
        # Spec D : architecture par défaut pour les paths netboot (/<version>/<arch>/).
        # Utilisé par grub.cfg.j2, pxelinux-default.j2, grub.php.j2, boot.ipxe.j2.
        # En multi-arch full (Spec D.4), preseed.php fera un lookup machine.boot_arch
        # pour servir le bon path. Pour l'instant, default arch = env DEBIAN_ARCH.
        "pxe_default_arch": os.environ.get("DEBIAN_ARCH", "amd64"),
    }

    publish(output_path, template.render(**ctx).encode('utf-8'))

    print(f"[render] {template_path} → {output_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
