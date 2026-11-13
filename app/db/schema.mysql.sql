-- Schema initial — PXE Tools admin DB (MySQL / MariaDB)
-- Idempotent : utilisable en CREATE TABLE IF NOT EXISTS via wrapper au déploiement.
-- Pendant de app/db/schema.sqlite.sql. Conserve les mêmes noms de colonnes et
-- contraintes ; les divergences (AUTO_INCREMENT, COLLATE, ENGINE, triggers
-- remplacés par ON UPDATE) sont commentées en regard.

CREATE TABLE IF NOT EXISTS presets (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(190) NOT NULL UNIQUE,
    description             TEXT,
    distro                  VARCHAR(64) NOT NULL,                          -- NOM d'OS ('debian'), cohérent avec catalog_entries.distro / os_images.distro
    codename                VARCHAR(64),                                   -- release ('trixie'). Source du codename apt-suite + path netboot (modèle distro-name)
    version_full            VARCHAR(64),                                   -- point release de l'OS (ex 13.4.0). NULL = fallback latest os_images downloaded.
    network_mode            VARCHAR(16) NOT NULL DEFAULT 'dhcp',
    static_ip               VARCHAR(45),
    static_netmask          VARCHAR(45),                                   -- legacy: notation pointée
    static_cidr             INT,                                           -- préfixe CIDR (1-32)
    static_gateway          VARCHAR(45),
    static_dns              TEXT,                                          -- JSON array
    partition_mode          VARCHAR(32) NOT NULL DEFAULT 'default',
    partition_custom_json   MEDIUMTEXT,
    admin_user_login        VARCHAR(190) NOT NULL,
    admin_user_pwhash       TEXT,
    admin_user_password_cipher TEXT,                                       -- Crypto::encrypt(clair) via master.key
    admin_user_groups       VARCHAR(190) DEFAULT 'sudo',
    admin_user_pubkeys      MEDIUMTEXT,
    ansible_role            VARCHAR(190),
    ansible_extra_vars      MEDIUMTEXT,
    -- Champs avancés (preseed Debian étendu). NULL = fallback hardcodé preseed-base.cfg.j2.
    advanced_config_json    MEDIUMTEXT,
    root_login_mode         VARCHAR(16) NOT NULL DEFAULT 'disabled',
    admin_user_fullname     VARCHAR(190),
    domain                  VARCHAR(253),
    variant                  VARCHAR(32) NOT NULL DEFAULT 'debian',          -- 'debian' | 'proxmox-ve' (PVE 9 = Debian 13 + couche Ansible proxmox_ve)
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS machines (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mac                     VARCHAR(190) NOT NULL UNIQUE COLLATE utf8mb4_general_ci,  -- _ci = case-insensitive (équivalent COLLATE NOCASE)
    hostname                VARCHAR(255),
    domain                  VARCHAR(255),
    status                  VARCHAR(32) NOT NULL DEFAULT 'unknown',
    preset_id               BIGINT UNSIGNED,
    install_config_json     MEDIUMTEXT,
    inventory_json          MEDIUMTEXT,
    notes                   TEXT,
    last_seen_at            DATETIME,
    boot_mode               VARCHAR(16) NOT NULL DEFAULT 'http',
    local_boot_source       VARCHAR(16) NOT NULL DEFAULT 'none',
    rescue_mode             TINYINT NOT NULL DEFAULT 0,
    next_action             VARCHAR(16),
    awaiting_action_until   DATETIME DEFAULT NULL,
    boot_arch               VARCHAR(16),
    root_password_hash      TEXT,
    root_password_cipher    TEXT,                                          -- Crypto::encrypt(clair) via master.key
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_machines_preset
        FOREIGN KEY (preset_id) REFERENCES presets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS machine_events (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id              BIGINT UNSIGNED NOT NULL,
    level                   VARCHAR(16) NOT NULL DEFAULT 'info',
    message                 TEXT NOT NULL,
    checkpoint              VARCHAR(64) NULL,
    action_id               BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_machine_events_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_machine_events_machine_ts (machine_id, created_at DESC),
    INDEX idx_machine_events_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installations (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id              BIGINT UNSIGNED NOT NULL,
    started_at              DATETIME,
    ended_at                DATETIME,
    install_status          VARCHAR(32),
    ansible_status          VARCHAR(32),
    ansible_log             MEDIUMTEXT,
    install_log             MEDIUMTEXT DEFAULT NULL,         -- LOGCAP01 — log d-i capturé batch POST late_command (limite applicative 5 MB)
    action_id               BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_installations_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_installations_status (ansible_status, machine_id),
    INDEX idx_installations_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wipes (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id                  BIGINT UNSIGNED NOT NULL,
    disks_json                  TEXT NOT NULL,
    wipe_mode                   VARCHAR(16) NOT NULL,
    wipe_status                 VARCHAR(16) NOT NULL DEFAULT 'pending',
    post_action                 VARCHAR(16) NOT NULL DEFAULT 'poweroff',
    started_at                  DATETIME,
    ended_at                    DATETIME,
    log                         MEDIUMTEXT,
    chain_install_config_json   MEDIUMTEXT DEFAULT NULL,
    action_id                   BIGINT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wipes_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_wipes_pending (wipe_status, machine_id),
    INDEX idx_wipes_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disk_tests (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id              BIGINT UNSIGNED NOT NULL,
    jobs_json               TEXT NOT NULL,
    parallel                TINYINT NOT NULL DEFAULT 0,
    job_status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    post_action             VARCHAR(16) NOT NULL DEFAULT 'poweroff',
    started_at              DATETIME,
    ended_at                DATETIME,
    log                     MEDIUMTEXT,
    results_json            MEDIUMTEXT,
    progress_json           MEDIUMTEXT,
    in_session              TINYINT NOT NULL DEFAULT 0,
    action_id               BIGINT UNSIGNED NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disk_tests_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_disk_tests_pending (job_status, machine_id),
    INDEX idx_disk_tests_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS disk_smart_snapshots (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id              BIGINT UNSIGNED NOT NULL,
    test_id                 BIGINT UNSIGNED NOT NULL,
    serial                  VARCHAR(128) NOT NULL,
    device                  VARCHAR(128) NULL,
    phase                   VARCHAR(8) NOT NULL,
    smart_json              LONGTEXT NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_disk_smart_snapshots_test_serial_phase UNIQUE (test_id, serial, phase),
    CONSTRAINT fk_disk_smart_snapshots_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_disk_smart_snapshots_test
        FOREIGN KEY (test_id) REFERENCES disk_tests(id) ON DELETE CASCADE,
    INDEX idx_disk_smart_snapshots_serial_created (serial, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Index supplémentaires hors-table (parité avec schema.sqlite.sql L97-100)
CREATE INDEX idx_machines_status ON machines(status);

-- Paramètres globaux (key-value). `key` est mot réservé MySQL → backticks.
-- L'auto-update du updated_at est porté par la colonne (ON UPDATE) plutôt
-- qu'un trigger SQLite.
CREATE TABLE IF NOT EXISTS settings (
    `key`           VARCHAR(190) NOT NULL PRIMARY KEY,
    value           MEDIUMTEXT NOT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Init jobs : bootstrap initial du serveur PXE
CREATE TABLE IF NOT EXISTS init_jobs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_type            VARCHAR(64) NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'pending',
    progress_percent    INT NOT NULL DEFAULT 0,
    message             TEXT,
    log                 MEDIUMTEXT,
    started_at          DATETIME,
    ended_at            DATETIME,
    updated_at          DATETIME,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_init_jobs_pending (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Tables historiquement crées par migrate.php (data-migration) — reportées
-- ici en partie statique pour les déploiements MySQL fresh où migrate.php
-- ne les crée pas s'il détecte schema.mysql.sql déjà appliqué.
-- ---------------------------------------------------------------------------

-- users (Spec B) — auth locale bcrypt
CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(190) NOT NULL UNIQUE COLLATE utf8mb4_general_ci,
    password_hash   TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- os_images — catalogue distros téléchargeables (v2 : multi-distros + point releases)
CREATE TABLE IF NOT EXISTS os_images (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    distro              VARCHAR(64) NOT NULL,
    version             VARCHAR(64) NOT NULL,
    arch                VARCHAR(16) NOT NULL,
    kernel_url          TEXT,
    initrd_url          TEXT,
    iso_url             TEXT,
    extract_method      VARCHAR(32) NOT NULL DEFAULT 'direct',
    enabled             TINYINT NOT NULL DEFAULT 0,
    status              VARCHAR(32),
    progress_percent    INT NOT NULL DEFAULT 0,
    message             TEXT,
    downloaded_at       DATETIME,
    minor_version       VARCHAR(32),
    channel             VARCHAR(16),
    codename            VARCHAR(64),
    major               INT,
    version_full        VARCHAR(64),
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_distro_version_arch (distro, version, arch),
    UNIQUE KEY uniq_distro_version_full_arch (distro, version_full, arch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- catalog_entries — cache des versions disponibles (alimenté par refresh_catalog_v2)
CREATE TABLE IF NOT EXISTS catalog_entries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    distro              VARCHAR(64) NOT NULL,
    codename            VARCHAR(64),
    major               INT,
    version_full        VARCHAR(64) NOT NULL,
    channel             VARCHAR(16),
    arch                VARCHAR(16) NOT NULL,
    kernel_url          TEXT,
    initrd_url          TEXT,
    firmware_url        TEXT,
    iso_url             TEXT,
    extract_method      VARCHAR(32) NOT NULL DEFAULT 'direct',
    discovered_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_catalog_distro_version_arch (distro, version_full, arch),
    INDEX idx_catalog_entries_distro (distro, major DESC, version_full DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- discoveries — cycles hardware discovery
CREATE TABLE IF NOT EXISTS discoveries (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id                  BIGINT UNSIGNED NOT NULL,
    started_at                  DATETIME,
    ended_at                    DATETIME,
    last_phase                  VARCHAR(32),
    ip_addr                     VARCHAR(45),
    discovery_status            VARCHAR(16) NOT NULL DEFAULT 'pending',
    log                         MEDIUMTEXT,
    inventory_snapshot_json     MEDIUMTEXT,
    action_id                   BIGINT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_discoveries_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    INDEX idx_discoveries_machine (machine_id, id DESC),
    INDEX idx_discoveries_running (discovery_status, machine_id),
    INDEX idx_discoveries_action (action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- actions — action utilisateur parente (2026-05-16)
-- Regroupe 1 ou plusieurs sous-jobs (wipe, install, discovery, disk_test).
-- Le token (hex 64 chars) est injecté dans les templates preseed/wipe.sh/postboot.sh
-- et validé à chaque ping checkpoint.
CREATE TABLE IF NOT EXISTS actions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id  BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(32) NOT NULL,
    status      VARCHAR(16) NOT NULL DEFAULT 'pending',
    token       VARCHAR(64) NOT NULL,
    created_by  VARCHAR(190) NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at    DATETIME NULL,
    UNIQUE KEY idx_actions_token (token),
    INDEX idx_actions_machine (machine_id),
    CONSTRAINT fk_actions_machine
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- boot_sessions — historique des menus GRUB servis puis sélectionnés.
CREATE TABLE IF NOT EXISTS boot_sessions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id    BIGINT UNSIGNED NOT NULL,
    action_id     BIGINT UNSIGNED NULL,
    mac           VARCHAR(17) NOT NULL,
    default_menu  VARCHAR(24) NOT NULL,
    selected_menu VARCHAR(24) NULL,
    token         CHAR(32) NOT NULL,
    client_ip     VARCHAR(45) NULL,
    served_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    selected_at   DATETIME NULL,
    UNIQUE KEY idx_boot_sessions_token (token),
    INDEX idx_boot_sessions_machine (machine_id, id DESC),
    INDEX idx_boot_sessions_action (action_id),
    CONSTRAINT fk_boot_sessions_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_boot_sessions_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- task_phases — phases fines des tâches longues (install, wipe, ansible)
CREATE TABLE IF NOT EXISTS task_phases (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    task_type    VARCHAR(16) NOT NULL,
    task_id      BIGINT UNSIGNED NOT NULL,
    phase_key    VARCHAR(64) NOT NULL,
    status       VARCHAR(16) NOT NULL DEFAULT 'pending',
    started_at   DATETIME,
    ended_at     DATETIME,
    message      VARCHAR(255),
    log_excerpt  MEDIUMTEXT,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_task_phase (task_type, task_id, phase_key),
    INDEX idx_task_phases_lookup (task_type, task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
