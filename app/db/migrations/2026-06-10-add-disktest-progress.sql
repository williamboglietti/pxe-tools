-- 2026-06-10 — Tests de disque temps réel (documentaire : l'ALTER idempotent
-- réel vit dans migrate.php, exécuté au boot du conteneur).
--
-- progress_json : progression structurée PAR DISQUE, écrite ~toutes les 10 s par
-- l'agent disktest.sh via /api/disktest-disk-progress. Objet indexé par chemin :
--   {"/dev/sda": {"mode":"badblocks_long_rw","phase":"write2","pass":1,
--                 "pct":37,"bad":12,"elapsed_s":1840,"eta_s":3130,
--                 "updated_at":"2026-06-10T14:02:11+00:00"}}
-- pct/eta = PHASE COURANTE (en -wsv le % badblocks repart à 0 par motif) ;
-- updated_at = horloge SERVEUR (l'agent d-i n'a pas d'heure fiable).
-- NULL = aucun agent n'a posté (vieux script) → l'UI retombe sur le log brut.
--
-- in_session : 1 si le test est enchaîné depuis l'écran d'attente discovery
-- (sans cycle reboot PXE) — posé par diskTestStart quand la machine est en
-- awaiting_action_until ; avec post_action='wait' l'agent revient à l'attente
-- et disktest-end réarme la fenêtre.

ALTER TABLE disk_tests ADD COLUMN progress_json TEXT;
ALTER TABLE disk_tests ADD COLUMN in_session INTEGER NOT NULL DEFAULT 0;
