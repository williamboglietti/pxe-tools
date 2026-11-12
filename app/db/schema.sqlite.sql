-- Schema initial — PXE Tools admin DB
-- Idempotent : utilisable en CREATE TABLE IF NOT EXISTS via wrapper Python/PHP au déploiement

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS presets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE,
    description     TEXT,
    distro          TEXT NOT NULL,                          -- NOM d'OS ('debian'), cohérent avec catalog_entries.distro / os_images.distro
    codename        TEXT,                                   -- release ('trixie'). Source du codename apt-suite + path netboot (modèle distro-name)
    version_full    TEXT,                                   -- point release de l'OS (ex 13.4.0). NULL = fallback latest os_images downloaded du distro.
    network_mode    TEXT NOT NULL DEFAULT 'dhcp',
    static_ip       TEXT,
    static_netmask  TEXT,                                   -- legacy: notation pointée, conservé en lecture pour rollback
    static_cidr     INTEGER,                                -- préfixe CIDR (1-32), source de vérité depuis migration
    static_gateway  TEXT,
    static_dns      TEXT,  -- JSON array d'adresses DNS explicitement configurées
    partition_mode          TEXT NOT NULL DEFAULT 'default',
    partition_custom_json   TEXT,                                   -- plan arborescent (target_disks, disks, raid_arrays, vgs, logical_volumes)
    admin_user_login        TEXT NOT NULL,
    admin_user_pwhash       TEXT,
    admin_user_password_cipher TEXT,                            -- Crypto::encrypt(clair) via master.key — affichage en clair dans l'aperçu
    admin_user_groups       TEXT DEFAULT 'sudo',
    admin_user_pubkeys      TEXT,
    ansible_role            TEXT,
    ansible_extra_vars      TEXT,
    -- Champs avancés (preseed Debian étendu) : exposés dans l'UI sous forme
    -- de fieldsets repliables. NULL = utiliser les valeurs hardcodées
    -- actuelles dans le template preseed-base.cfg.j2 (compat descendante).
    advanced_config_json    TEXT,                                   -- groupes optionnels : localization, mirror, timezone, packages, apt, bootloader, finish, post_install
    root_login_mode         TEXT NOT NULL DEFAULT 'disabled',       -- 'enabled' (root + late_command useradd) | 'disabled' (passwd/make-user natif d-i)
    admin_user_fullname     TEXT,                                   -- passwd/user-fullname (requis si root_login_mode='disabled')
    domain                  TEXT,
    variant                  TEXT NOT NULL DEFAULT 'debian',          -- 'debian' | 'proxmox-ve' (PVE 9 = Debian 13 + couche Ansible proxmox_ve posée au 1er boot)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS machines (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    mac             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    hostname        TEXT,
    domain          TEXT,
    status          TEXT NOT NULL DEFAULT 'unknown',
    preset_id       INTEGER REFERENCES presets(id) ON DELETE SET NULL,        -- legacy : conservé pour rollback, plus utilisé par preseed.php depuis le snapshot
    install_config_json TEXT,                                                 -- snapshot complet de la config d'install (source de vérité depuis 2026-05-07)
    inventory_json  TEXT,
    notes           TEXT,
    last_seen_at    DATETIME,
    boot_mode       TEXT NOT NULL DEFAULT 'http',  -- 'http' (rapide) ou 'tftp' (fiable, pour VMs/firmwares lents)
    local_boot_source TEXT NOT NULL DEFAULT 'none', -- none | pxe (install réussie) | manual (admin)
    rescue_mode     INTEGER NOT NULL DEFAULT 0,    -- 1 = prochain boot servira le menuentry rescue (Debian d-i en récupération)
    next_action     TEXT,                          -- consigne lue par discover.sh en boucle de poll : NULL=wait (rester allumée), 'poweroff', 'reboot'
    awaiting_action_until DATETIME,                -- timestamp UTC d'expiration de la fenêtre d'attente post-discovery (NOW+1h). NULL = pas en attente.
    boot_arch       TEXT,                          -- Spec D.4 : arch détectée au discovery (uname -m → mapping amd64/arm64) — sert /netboot/<distro>/<boot_arch>/
    root_password_hash TEXT,                       -- Spec F.2 : hash root pwd per-machine (override global setting)
    root_password_cipher TEXT,                     -- Crypto::encrypt(clair) via master.key — affichage en clair dans l'aperçu
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS machine_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id      INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    level           TEXT NOT NULL DEFAULT 'info',  -- info | action | warning | error
    message         TEXT NOT NULL,
    checkpoint      TEXT NULL,    -- code structuré du catalogue ActionCheckpoints (NULL = log libre)
    action_id       INTEGER NULL REFERENCES actions(id),  -- lie l'event à l'action en cours
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_machine_events_machine_ts ON machine_events(machine_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_machine_events_action ON machine_events(action_id);

CREATE TABLE IF NOT EXISTS installations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id      INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    started_at      DATETIME,
    ended_at        DATETIME,
    install_status  TEXT,
    ansible_status  TEXT,
    ansible_log     TEXT,
    install_log     TEXT,                                 -- log d-i capturé batch POST late_command (LOGCAP01 — limite applicative 5 MB)
    action_id       INTEGER NULL REFERENCES actions(id),  -- action parent (2026-05-16)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wipes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id      INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    disks_json      TEXT NOT NULL,                                  -- ex: ["/dev/sda","/dev/nvme0n1"]
    wipe_mode       TEXT NOT NULL,                                  -- 'partial' | 'full'
    wipe_status     TEXT NOT NULL DEFAULT 'pending',                -- pending | running | success | failed
    post_action     TEXT NOT NULL DEFAULT 'poweroff',               -- 'reboot' | 'poweroff' (action après fin)
    started_at      DATETIME,
    ended_at        DATETIME,
    log             TEXT,
    chain_install_config_json TEXT DEFAULT NULL,                    -- snapshot install_config + hostname + domain à appliquer post-wipe (feature wipe→install enchaîné)
    action_id       INTEGER NULL REFERENCES actions(id),            -- action parent (2026-05-16)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS disk_tests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id      INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    jobs_json       TEXT NOT NULL,                                  -- [{"disk":"/dev/sda","mode":"smart_short"},...]
    parallel        INTEGER NOT NULL DEFAULT 0,                     -- 0 = séquentiel, 1 = parallèle
    job_status      TEXT NOT NULL DEFAULT 'pending',                -- pending | running | success | failed
    post_action     TEXT NOT NULL DEFAULT 'poweroff',               -- 'reboot' | 'poweroff'
    started_at      DATETIME,
    ended_at        DATETIME,
    log             TEXT,
    results_json    TEXT,                                           -- [{"disk":"/dev/sda","mode":"smart_short","verdict":"passed","bad_blocks":0,"duration_s":120,"completed_at":"..."},...]
    progress_json   TEXT,                                           -- {"/dev/sda":{"mode","phase","pass","pct","bad","elapsed_s","eta_s","updated_at"}} — progression live par disque (agent, ~10 s)
    in_session      INTEGER NOT NULL DEFAULT 0,                     -- 1 = test enchaîné depuis l'attente discovery (sans reboot), post_action 'wait'
    action_id       INTEGER NULL REFERENCES actions(id),            -- action parent (2026-05-16)
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS disk_smart_snapshots (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id      INTEGER NOT NULL,
    test_id         INTEGER NOT NULL,
    serial          TEXT NOT NULL,
    device          TEXT,
    phase           TEXT NOT NULL,
    smart_json      TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_disk_smart_snapshots_test_serial_phase UNIQUE (test_id, serial, phase),
    CONSTRAINT fk_disk_smart_snapshots_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_smart_snapshots_test FOREIGN KEY (test_id) REFERENCES disk_tests(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_disk_smart_snapshots_serial_created
    ON disk_smart_snapshots(serial, created_at);

CREATE INDEX IF NOT EXISTS idx_machines_status      ON machines(status);
CREATE INDEX IF NOT EXISTS idx_installations_status ON installations(ansible_status, machine_id);
CREATE INDEX IF NOT EXISTS idx_wipes_pending        ON wipes(wipe_status, machine_id);
CREATE INDEX IF NOT EXISTS idx_disk_tests_pending   ON disk_tests(job_status, machine_id);
CREATE INDEX IF NOT EXISTS idx_wipes_action         ON wipes(action_id);
CREATE INDEX IF NOT EXISTS idx_installations_action ON installations(action_id);
CREATE INDEX IF NOT EXISTS idx_disk_tests_action    ON disk_tests(action_id);

CREATE TRIGGER IF NOT EXISTS machines_updated AFTER UPDATE ON machines BEGIN
    UPDATE machines SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS presets_updated AFTER UPDATE ON presets BEGIN
    UPDATE presets SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
END;

-- Paramètres globaux (key-value). Modifiables à chaud depuis l'UI /settings.
-- Source de vérité runtime ; data.json reste figé au déploiement Ansible.
-- Les clés exposées sont déclarées en const Admin\Models\Setting::KNOWN.
CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TRIGGER IF NOT EXISTS settings_updated AFTER UPDATE ON settings BEGIN
    UPDATE settings SET updated_at = CURRENT_TIMESTAMP WHERE key = NEW.key;
END;

-- Init jobs : bootstrap initial du serveur PXE (download Debian, MOK, GRUB, etc.)
-- Distinct de `installations` (qui concerne les machines clientes Ansible-déployées).
-- Les rows sont créées par worker.py au premier démarrage si les volumes sont vides,
-- puis traitées séquentiellement. L'UI /init poll cette table pour afficher le progress.
CREATE TABLE IF NOT EXISTS init_jobs (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type            TEXT NOT NULL,                 -- ex: bootstrap:download_debian
    status              TEXT NOT NULL DEFAULT 'pending',  -- pending | running | success | failed | skipped
    progress_percent    INTEGER NOT NULL DEFAULT 0,    -- 0..100 (informatif, peut rester 0/100 si non instrumenté)
    message             TEXT,                          -- description courte affichée dans l'UI
    log                 TEXT,                          -- stdout/stderr du script bootstrap
    started_at          DATETIME,
    ended_at            DATETIME,
    updated_at          DATETIME,                      -- heartbeat worker (stale watchdog côté UI)
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_init_jobs_pending ON init_jobs(status, id);

-- ---------------------------------------------------------------------------
-- Tables historiquement créées par migrate.php (data-migration) — déplacées
-- ici pour parité avec schema.mysql.sql + bootstrap fresh propre.
-- ---------------------------------------------------------------------------

-- users (Spec B) — auth locale bcrypt
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME
);

-- os_images — catalogue distros téléchargeables (Spec E)
-- v2 : 1 row par (distro, version_full, arch). `version` legacy = codename
-- Debian (ex "trixie") ; `version_full` (ex "13.4.0") est la source de vérité
-- pour la mineure téléchargée. Une row existe ⇒ admin a explicitement
-- demandé ce téléchargement (pas de pré-seed).
CREATE TABLE IF NOT EXISTS os_images (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    distro              TEXT NOT NULL,
    version             TEXT NOT NULL,                                          -- legacy v1 : codename Debian (trixie). Cohabite avec version_full pour back-compat workers.
    arch                TEXT NOT NULL DEFAULT 'amd64',
    kernel_url          TEXT,                                                   -- nullable : NULL en extract_method='iso_extract' (kernel/initrd extraits de l'ISO ; le chemin netboot est calculé par les templates, ces colonnes sont vestigiales)
    initrd_url          TEXT,                                                   -- nullable : idem kernel_url
    firmware_url        TEXT,
    iso_url             TEXT,                                                   -- v2 : URL ISO si extract_method='iso_extract'
    extract_method      TEXT NOT NULL DEFAULT 'direct',                         -- 'direct' | 'iso_extract'
    enabled             INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL DEFAULT 'pending',
    progress_percent    INTEGER NOT NULL DEFAULT 0,
    message             TEXT,
    downloaded_at       DATETIME,
    size_bytes          INTEGER,
    error_msg           TEXT,
    minor_version       TEXT,                                                   -- v1 : "13.4" (legacy, encore renseigné par bootstrap:refresh_catalog v1)
    channel             TEXT,                                                   -- 'stable' | 'oldstable' | 'testing' | 'unstable'
    codename            TEXT,                                                   -- v2 : Debian/Ubuntu (trixie, noble). NULL pour Fedora/Alma/etc.
    major               INTEGER,                                                -- v2 : 13, 24, 41 — groupement tableaux picker
    version_full        TEXT,                                                   -- v2 : canonique "13.4.0", "24.04.2", "41" (NULL temporairement après ALTER legacy, NOT NULL en pratique fresh)
    updated_at          DATETIME,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(distro, version, arch)
);
-- v2 : index UNIQUE additionnel sur version_full (pas UNIQUE direct dans la
-- table pour éviter la contrainte NOT NULL implicite et garder ALTER simple).
CREATE UNIQUE INDEX IF NOT EXISTS idx_os_images_full ON os_images(distro, version_full, arch)
    WHERE version_full IS NOT NULL;

-- catalog_entries — cache des versions disponibles à télécharger (alimenté par
-- bootstrap:refresh_catalog_v2 qui itère sur DistroCatalog::all()). Distinct de
-- `os_images` qui ne contient que ce que l'admin a commandé.
CREATE TABLE IF NOT EXISTS catalog_entries (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    distro              TEXT NOT NULL,
    codename            TEXT,
    major               INTEGER,
    version_full        TEXT NOT NULL,
    channel             TEXT,
    arch                TEXT NOT NULL,
    kernel_url          TEXT,
    initrd_url          TEXT,
    firmware_url        TEXT,
    iso_url             TEXT,
    extract_method      TEXT NOT NULL DEFAULT 'direct',
    discovered_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(distro, version_full, arch)
);
CREATE INDEX IF NOT EXISTS idx_catalog_entries_distro ON catalog_entries(distro, major DESC, version_full DESC);

-- discoveries — cycles hardware discovery (Spec C)
CREATE TABLE IF NOT EXISTS discoveries (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id               INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    started_at               DATETIME,
    ended_at                 DATETIME,
    discovery_status         TEXT NOT NULL DEFAULT 'running',
    last_phase               TEXT,
    ip_addr                  TEXT,
    inventory_snapshot_json  TEXT,
    action_id                INTEGER NULL REFERENCES actions(id),   -- action parent (2026-05-16)
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_discoveries_machine  ON discoveries(machine_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_discoveries_running  ON discoveries(discovery_status, machine_id);
CREATE INDEX IF NOT EXISTS idx_discoveries_action   ON discoveries(action_id);

-- task_phases — phases fines des tâches longues (install Debian, wipe, ansible).
-- Pingées en live depuis les preseeds (early/late_command) et le worker python
-- via GET /api/task-phase pour permettre un suivi temps réel UI (pattern GH Actions).
-- Le couple (task_type, task_id) joue le rôle de FK logique vers installations/wipes
-- (pas de vraie FK car les types pointent vers tables différentes).
CREATE TABLE IF NOT EXISTS task_phases (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    task_type    TEXT NOT NULL,                  -- 'install' | 'wipe' | 'ansible'
    task_id      INTEGER NOT NULL,               -- installations.id ou wipes.id
    phase_key    TEXT NOT NULL,                  -- 'preseed-loaded', 'partman-done', ...
    status       TEXT NOT NULL DEFAULT 'pending',-- pending | running | success | failed | skipped
    started_at   DATETIME,
    ended_at     DATETIME,
    message      TEXT,                           -- ligne descriptive optionnelle
    log_excerpt  TEXT,                           -- dernières lignes log si pertinent
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(task_type, task_id, phase_key)
);
CREATE INDEX IF NOT EXISTS idx_task_phases_lookup ON task_phases(task_type, task_id);

-- actions — action utilisateur parente (2026-05-16)
-- Regroupe 1 ou plusieurs sous-jobs (wipe, install, discovery, disk_test) sous
-- une entité unique. Le token (hex 64 chars) est injecté dans les templates
-- preseed/wipe.sh/postboot.sh et validé à chaque ping checkpoint.
-- IMPORTANT : PAS de UNIQUE sur (action_id, checkpoint) dans machine_events —
-- la dedup checkpoints est UI-side (spec § Idempotence).
CREATE TABLE IF NOT EXISTS actions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id  INTEGER NOT NULL,
    type        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pending',
    token       TEXT NOT NULL UNIQUE,
    created_by  TEXT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at    TIMESTAMP NULL,
    FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_actions_machine ON actions(machine_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_actions_token ON actions(token);

-- Chaque configuration GRUB servie crée un cycle de boot. selected_menu reste
-- NULL tant que l'entrée GRUB n'a pas signalé son exécution au serveur.
CREATE TABLE IF NOT EXISTS boot_sessions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    machine_id    INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
    action_id     INTEGER NULL REFERENCES actions(id) ON DELETE SET NULL,
    mac           TEXT NOT NULL,
    default_menu  TEXT NOT NULL,
    selected_menu TEXT,
    token         TEXT NOT NULL UNIQUE,
    client_ip     TEXT,
    served_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    selected_at   DATETIME
);
CREATE INDEX IF NOT EXISTS idx_boot_sessions_machine ON boot_sessions(machine_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_boot_sessions_action ON boot_sessions(action_id);
