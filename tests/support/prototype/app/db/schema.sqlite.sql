-- SPDX-License-Identifier: LGPL-3.0-only
-- Initial development schema; not a migration from any existing installation.
CREATE TABLE IF NOT EXISTS machines (
    id INTEGER PRIMARY KEY,
    mac TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'discovered',
    inventory_json TEXT NOT NULL,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Initial administrator bootstrap. Additional account management is not implemented.
CREATE TABLE IF NOT EXISTS administrators (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Desired state only. A separate privileged service must validate and apply it.
CREATE TABLE IF NOT EXISTS pxe_network_settings (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    mode TEXT NOT NULL CHECK (mode IN ('disabled', 'tftp', 'proxy')),
    settings_json TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO pxe_network_settings (id, mode, settings_json) VALUES (1, 'disabled', '{}');

-- Every accepted discovery keeps a snapshot; no automatic retention deletion.
CREATE TABLE IF NOT EXISTS discovery_reports (
    id INTEGER PRIMARY KEY,
    machine_id INTEGER NOT NULL REFERENCES machines(id) ON DELETE RESTRICT,
    inventory_json TEXT NOT NULL,
    received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS discovery_reports_machine ON discovery_reports(machine_id, id);
-- Preserve snapshots even if an unrelated SQLite connection omitted its PRAGMA.
CREATE TRIGGER IF NOT EXISTS prevent_machine_delete_with_reports
BEFORE DELETE ON machines
WHEN EXISTS (SELECT 1 FROM discovery_reports WHERE machine_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'machine has retained discovery reports');
END;

-- Review-only installation intent; no executor may treat it as permission to write.
CREATE TABLE IF NOT EXISTS installation_plans (
    id INTEGER PRIMARY KEY,
    machine_id INTEGER NOT NULL REFERENCES machines(id) ON DELETE RESTRICT,
    discovery_report_id INTEGER NOT NULL REFERENCES discovery_reports(id) ON DELETE RESTRICT,
    discovery_sha256 TEXT NOT NULL,
    disk_path TEXT NOT NULL,
    disk_serial TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('draft', 'reviewed', 'cancelled')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS installation_plans_one_open
    ON installation_plans(machine_id) WHERE status IN ('draft', 'reviewed');
CREATE TRIGGER IF NOT EXISTS installation_plan_identity_immutable
BEFORE UPDATE OF machine_id, discovery_report_id, discovery_sha256, disk_path, disk_serial ON installation_plans
BEGIN
    SELECT RAISE(ABORT, 'installation plan identity is immutable');
END;
CREATE TRIGGER IF NOT EXISTS installation_plan_valid_transition
BEFORE UPDATE OF status ON installation_plans
WHEN NOT (NEW.status = OLD.status OR (OLD.status = 'draft' AND NEW.status IN ('reviewed', 'cancelled'))
          OR (OLD.status = 'reviewed' AND NEW.status = 'cancelled'))
BEGIN
    SELECT RAISE(ABORT, 'invalid installation plan transition');
END;
-- End review-only installation intent.

-- Retained partition recipes; never an authorization to install.
CREATE TABLE IF NOT EXISTS partition_recipes (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL UNIQUE CHECK (length(name) BETWEEN 1 AND 120),
    recipe_json TEXT NOT NULL CHECK (length(CAST(recipe_json AS BLOB)) BETWEEN 1 AND 262144),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
-- End retained partition recipes.

-- Snapshot for a review-only plan; never an authorization to install.
CREATE TABLE IF NOT EXISTS installation_plan_recipes (
    plan_id INTEGER PRIMARY KEY REFERENCES installation_plans(id) ON DELETE CASCADE,
    recipe_id INTEGER NOT NULL REFERENCES partition_recipes(id) ON DELETE RESTRICT,
    recipe_name TEXT NOT NULL,
    recipe_json TEXT NOT NULL CHECK (length(CAST(recipe_json AS BLOB)) BETWEEN 1 AND 262144),
    recipe_sha256 TEXT NOT NULL CHECK (length(recipe_sha256) = 64),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TRIGGER IF NOT EXISTS installation_plan_recipe_immutable
BEFORE UPDATE ON installation_plan_recipes
BEGIN
    SELECT RAISE(ABORT, 'installation plan recipe is immutable');
END;
-- End snapshot for a review-only plan.

-- Short-lived enrollment grants; only hashes are stored, never bearer tokens.
CREATE TABLE IF NOT EXISTS discovery_enrollment_tokens (
    id INTEGER PRIMARY KEY,
    token_hash TEXT NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    used_at INTEGER
);

-- Acquisition is requested explicitly; completing it does not activate PXE.
CREATE TABLE IF NOT EXISTS netboot_jobs (
    id INTEGER PRIMARY KEY,
    kind TEXT NOT NULL CHECK (kind = 'debian13-amd64'),
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'succeeded', 'failed')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TEXT,
    ended_at TEXT,
    artifact_path TEXT,
    server_ip TEXT,
    message TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS netboot_jobs_one_active
    ON netboot_jobs(kind) WHERE status IN ('pending', 'running');

-- Desired boot bundle only. Selection never starts or configures a service.
CREATE TABLE IF NOT EXISTS pxe_boot_selection (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    job_id INTEGER REFERENCES netboot_jobs(id) ON DELETE RESTRICT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT OR IGNORE INTO pxe_boot_selection(id, job_id) VALUES (1, NULL);

CREATE TRIGGER IF NOT EXISTS require_successful_boot_selection
BEFORE UPDATE OF job_id ON pxe_boot_selection
WHEN NEW.job_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM netboot_jobs WHERE id=NEW.job_id AND kind='debian13-amd64'
      AND status='succeeded' AND artifact_path IS NOT NULL AND server_ip IS NOT NULL
)
BEGIN
    SELECT RAISE(ABORT, 'boot bundle is not ready');
END;

-- Keep a selected result stable even when an unrelated connection omits FKs.
CREATE TRIGGER IF NOT EXISTS prevent_selected_job_delete
BEFORE DELETE ON netboot_jobs
WHEN EXISTS (SELECT 1 FROM pxe_boot_selection WHERE job_id = OLD.id)
BEGIN
    SELECT RAISE(ABORT, 'boot bundle is selected');
END;
CREATE TRIGGER IF NOT EXISTS prevent_selected_job_change
BEFORE UPDATE OF status, artifact_path, server_ip ON netboot_jobs
WHEN EXISTS (SELECT 1 FROM pxe_boot_selection WHERE job_id = OLD.id)
    AND (NEW.status != 'succeeded' OR NEW.artifact_path IS NOT OLD.artifact_path OR NEW.server_ip IS NOT OLD.server_ip)
BEGIN
    SELECT RAISE(ABORT, 'boot bundle is selected');
END;
