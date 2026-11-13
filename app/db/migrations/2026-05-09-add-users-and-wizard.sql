-- Migration Spec B Lot 1 — auth locale + sentinel wizard
--
-- Idempotent : appliqué par app/db/migrate.php au démarrage du container.
-- Ce fichier est conservé pour traçabilité ; la logique est dupliquée dans
-- migrate.php (CREATE TABLE IF NOT EXISTS + INSERT OR IGNORE).
--
-- Décision Q1=B : `wizard_completed=0` même sur installations existantes →
-- le wizard se lance au prochain redémarrage et l'admin peut le compléter
-- (le wizard détectera s'il faut juste créer l'admin user et finaliser, ou
-- tout reconfigurer).

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT NOT NULL,                                  -- bcrypt via PHP password_hash()
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME
);

-- Sentinel : tant que wizard_completed != '1', InitGuard redirige vers /init/welcome.
-- Spec B Lot 2 ajoutera les autres clés du wizard (pxe_interface, orchestrator_ip, etc.).
INSERT OR IGNORE INTO settings (key, value) VALUES ('wizard_completed', '0');
