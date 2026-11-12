-- Migration 2026-05-16 — Actions parent + colonnes checkpoints
--
-- Idempotente : ce fichier est joué par app/db/migrate.php. Les ALTER TABLE
-- sont protégés par Db::columnExists() dans migrate.php. Ce SQL sert de
-- documentation de l'intent ; les exécutions réelles sont dans migrate.php.
--
-- Note : la table `actions` et les index sont créés via CREATE TABLE/INDEX IF NOT EXISTS
-- directement dans migrate.php pour le pattern dual-driver (SQLite vs MySQL).
-- Ce fichier est conservé comme traçabilité de la migration, dans la convention
-- du projet (cf. 2026-05-09-add-users-and-wizard.sql).

-- Table actions (SQLite form — parité dans schema.sqlite.sql)
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

-- FK action_id sur les 4 tables sous-job
ALTER TABLE wipes         ADD COLUMN action_id INTEGER NULL REFERENCES actions(id);
ALTER TABLE installations ADD COLUMN action_id INTEGER NULL REFERENCES actions(id);
ALTER TABLE discoveries   ADD COLUMN action_id INTEGER NULL REFERENCES actions(id);
ALTER TABLE disk_tests    ADD COLUMN action_id INTEGER NULL REFERENCES actions(id);

CREATE INDEX IF NOT EXISTS idx_wipes_action         ON wipes(action_id);
CREATE INDEX IF NOT EXISTS idx_installations_action ON installations(action_id);
CREATE INDEX IF NOT EXISTS idx_discoveries_action   ON discoveries(action_id);
CREATE INDEX IF NOT EXISTS idx_disk_tests_action    ON disk_tests(action_id);

-- Colonnes checkpoint sur machine_events
-- IMPORTANT : PAS de contrainte UNIQUE (action_id, checkpoint) — la dedup est UI-side (spec ligne 158)
ALTER TABLE machine_events ADD COLUMN checkpoint TEXT NULL;
ALTER TABLE machine_events ADD COLUMN action_id  INTEGER NULL REFERENCES actions(id);

CREATE INDEX IF NOT EXISTS idx_machine_events_action ON machine_events(action_id);
