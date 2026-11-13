<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

final class Installation
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM installations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Latest installation for a machine. */
    public static function latestForMachine(int $machineId): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT * FROM installations WHERE machine_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function startForMachine(int $machineId, ?int $actionId = null): int
    {
        // Idempotent : si une installation est déjà in_progress pour cette
        // machine, retourne son id sans en créer une nouvelle.
        // preseed.php appelle install-start à chaque fetch — or Debian
        // Installer fetch le preseed plusieurs fois pendant un install
        // (kernel cmdline + ré-évaluations d-i). Sans cette dédup, on
        // accumule N rows in_progress pour une seule install réelle.
        // Note : la dédup ignore action_id pour ne pas casser les appels
        // legacy depuis PxeController qui ne fournissent pas d'action.
        $stmt = Db::get()->prepare(
            "SELECT id FROM installations WHERE machine_id = ? AND install_status = 'in_progress' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch();
        if ($row) {
            // Backfill action_id si la row existante n'en a pas encore et qu'on en fournit un
            if ($actionId !== null && empty($row['action_id'] ?? null)) {
                Db::get()->prepare('UPDATE installations SET action_id = ? WHERE id = ?')
                          ->execute([$actionId, (int)$row['id']]);
            }
            return (int)$row['id'];
        }

        Db::get()->prepare(
            "INSERT INTO installations (machine_id, started_at, install_status, action_id) VALUES (?, CURRENT_TIMESTAMP, 'in_progress', ?)"
        )->execute([$machineId, $actionId]);
        return (int) Db::get()->lastInsertId();
    }

    public static function endLatest(int $machineId, string $installStatus): ?int
    {
        $row = self::latestForMachine($machineId);
        if (!$row) return null;
        $ansibleStatus = ($installStatus === 'success') ? 'pending' : null;
        // POURQUOI pas de hook recomputeEndState ici (asymétrie délibérée vs Wipe::end,
        // setAnsibleStatus, Discovery::complete, DiskTest::end) : cette méthode termine
        // la phase preseed (d-i success) mais PAS l'action. ansible_status est posé à
        // 'pending' juste après, et recomputeEndState retournerait 'running' via
        // COALESCE(ansible_status, install_status). Le hook qui calcule le verdict final
        // de l'action est dans setAnsibleStatus (phase finale Ansible).
        Db::get()->prepare(
            'UPDATE installations SET ended_at = CURRENT_TIMESTAMP, install_status = ?, ansible_status = ? WHERE id = ?'
        )->execute([$installStatus, $ansibleStatus, $row['id']]);
        return (int) $row['id'];
    }

    public static function appendLog(int $id, string $line): void
    {
        $concat = Db::concat('ansible_log');
        Db::get()->prepare(
            "UPDATE installations SET ansible_log = $concat WHERE id = ?"
        )->execute([$line, $id]);
    }

    /**
     * Append d'un chunk de log d-i (LOGCAP01) sur `installations.install_log`.
     *
     * Capture orchestrée par le late_command preseed via batch POST :
     * /api/internal/install-log reçoit des chunks de quelques KB toutes les
     * ~1s. Le modèle borne le log à 5 MB applicatif (truncate gracieux avec
     * marker visible) — protège contre le worst case (boucle de retry, log
     * partman verbose dégénéré).
     *
     * Stratégie :
     *   - SELECT length(install_log) actuelle (NULL = 0).
     *   - Si déjà ≥ maxBytes : skip total, return ['status'=>'truncated', appended=0].
     *   - Si length+chunk > maxBytes : tronque chunk à l'espace dispo - marker,
     *     append + marker, return ['status'=>'truncated'].
     *   - Sinon : append complet, return ['status'=>'ok'].
     *
     * SQLite acquiert le verrou d'écriture avant le SELECT pour éviter le
     * passage deferred SELECT→UPDATE qui peut échouer sous concurrence WAL.
     * MySQL verrouille la ligne avec FOR UPDATE avant le calcul de capacité.
     *
     * Logs : `error_log` 1 ligne info au moment du truncate (1ère fois où le
     * cap est atteint). Pas de log par chunk OK (spam).
     *
     * @return array{status:string,bytes:int,appended:int}
     */
    public static function appendInstallLog(int $installId, string $chunk, int $maxBytes = 5242880): array
    {
        $marker     = "\n[LOG TRUNCATED AT 5MB]\n";
        $markerLen  = strlen($marker);
        $chunkLen   = strlen($chunk);

        $pdo = Db::get();
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        // PDO::beginTransaction() uses a deferred SQLite transaction, whose
        // read snapshot cannot always be upgraded after a competing write.
        // Acquire the writer slot before reading; SQLite's busy timeout then
        // handles a short competing transaction without a stale snapshot.
        if ($sqlite) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        $transactionActive = true;
        $commit = static function () use ($pdo, $sqlite, &$transactionActive): void {
            if ($sqlite) $pdo->exec('COMMIT');
            else $pdo->commit();
            $transactionActive = false;
        };
        $rollback = static function () use ($pdo, $sqlite, &$transactionActive): void {
            if ($sqlite) $pdo->exec('ROLLBACK');
            else $pdo->rollBack();
            $transactionActive = false;
        };
        try {
            $sql = 'SELECT install_log FROM installations WHERE id = ?';
            $stmt = $pdo->prepare($sqlite ? $sql : $sql . ' FOR UPDATE');
            $stmt->execute([$installId]);
            $row = $stmt->fetch();
            if (!$row) {
                $rollback();
                return ['status' => 'not_found', 'bytes' => 0, 'appended' => 0];
            }
            $existing    = (string)($row['install_log'] ?? '');
            $existingLen = strlen($existing);

            // Cas A : déjà à la limite → skip total (marker éventuellement déjà
            // posé par le précédent append qui a fait basculer en truncated).
            if ($existingLen >= $maxBytes) {
                $commit();
                return ['status' => 'truncated', 'bytes' => $existingLen, 'appended' => 0];
            }

            // Cas B : chunk fait basculer au-delà → tronque + marker.
            if ($existingLen + $chunkLen > $maxBytes) {
                $available = $maxBytes - $existingLen;
                $reserve   = min($markerLen, $available);
                $writeLen  = max(0, $available - $reserve);
                $truncated = $writeLen > 0 ? substr($chunk, 0, $writeLen) : '';
                $payload   = $truncated . substr($marker, 0, $reserve);
                // MySQL n'a pas l'opérateur || pour concat — branche sur Db::concat
                // au lieu de hardcoder. Refait via Db::concat pour cross-driver.
                $concat = Db::concat('install_log');
                $upd = $pdo->prepare(
                    "UPDATE installations SET install_log = $concat WHERE id = ?"
                );
                $upd->execute([$payload, $installId]);
                $commit();
                error_log("[install-log] truncated install_id=$installId at maxBytes=$maxBytes (appended=$writeLen bytes + marker)");
                return [
                    'status'   => 'truncated',
                    'bytes'    => $existingLen + strlen($payload),
                    'appended' => $writeLen,
                ];
            }

            // Cas C : append complet.
            $concat = Db::concat('install_log');
            $upd = $pdo->prepare(
                "UPDATE installations SET install_log = $concat WHERE id = ?"
            );
            $upd->execute([$chunk, $installId]);
            $commit();
            return [
                'status'   => 'ok',
                'bytes'    => $existingLen + $chunkLen,
                'appended' => $chunkLen,
            ];
        } catch (\Throwable $e) {
            if ($transactionActive) {
                try { $rollback(); } catch (\Throwable $ignored) { /* Preserve the original error. */ }
            }
            throw $e;
        }
    }

    /**
     * Annule une installation en cours (admin click "Annuler" dans /installations/{id}).
     *
     * Délègue à Action::forceComplete() si l'install a un action_id (cas standard
     * post-2026-05 où chaque install est attachée à une Action) : cette méthode
     * marque en transaction tous les sous-jobs (installations + wipes + ansible)
     * en 'aborted' et l'action parent en 'cancelled'. Idempotent.
     *
     * Cas legacy (action_id IS NULL) : fallback sur un UPDATE direct gardé sur
     * install_status='in_progress' pour ne pas écraser un terminal state.
     *
     * @return bool true si au moins une row a été modifiée.
     */
    public static function abort(int $id): bool
    {
        $row = self::find($id);
        if (!$row) return false;
        if ($row['install_status'] !== 'in_progress') return false;

        $actionId = !empty($row['action_id']) ? (int)$row['action_id'] : null;
        if ($actionId !== null) {
            Action::forceComplete($actionId);
            return true;
        }
        $stmt = Db::get()->prepare(
            "UPDATE installations SET install_status='aborted', ended_at=CURRENT_TIMESTAMP "
          . "WHERE id = ? AND install_status='in_progress'"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function setAnsibleStatus(int $id, string $status): void
    {
        $endClause = in_array($status, ['success', 'failed'], true) ? ', ended_at = CURRENT_TIMESTAMP' : '';
        Db::get()->prepare(
            "UPDATE installations SET ansible_status = ?$endClause WHERE id = ?"
        )->execute([$status, $id]);

        // Hook : recompute l'état agrégé de l'action parente si cette installation lui est rattachée.
        // Guard null : les sous-jobs legacy (action_id IS NULL) sont silencieusement ignorés.
        $row = self::find($id);
        if ($row && !empty($row['action_id'])) {
            Action::recomputeEndState((int)$row['action_id']);
        }
    }

    /**
     * Returns the next pending installation row, augmenté du role + extra_vars
     * Ansible extraits du snapshot machines.install_config_json (source de
     * vérité par machine, indépendant du preset).
     *
     * POURQUOI snapshot et pas preset : quand l'install est créée via le wizard
     * per-machine (/machines/{id}/install), machines.preset_id reste NULL — le
     * snapshot install_config_json contient la config complète, y compris
     * ansible_role. Le JOIN sur presets retournait alors p.ansible_role=NULL,
     * worker.py interprétait comme "no_role" et skip le playbook. Le snapshot
     * étant aussi posé par bulkInstall (depuis le preset), il couvre les 2 cas.
     */
    public static function nextPending(): ?array
    {
        $sql = 'SELECT i.id AS install_id, i.machine_id, m.hostname, m.mac, m.install_config_json '
             . 'FROM installations i '
             . 'JOIN machines m ON i.machine_id = m.id '
             . "WHERE i.ansible_status = 'pending' "
             . 'ORDER BY i.created_at LIMIT 1';
        $row = Db::get()->query($sql)->fetch();
        if (!$row) return null;

        // Extrait ansible_role + ansible_extra_vars du snapshot, fallback null
        // si le json est malformé ou les clés absentes (worker.py traitera
        // role=null comme no_role).
        $cfg = json_decode((string)($row['install_config_json'] ?? ''), true);
        $row['ansible_role']       = is_array($cfg) ? ($cfg['ansible_role'] ?? null) : null;
        $row['ansible_extra_vars'] = is_array($cfg) ? ($cfg['ansible_extra_vars'] ?? null) : null;
        unset($row['install_config_json']);
        return $row;
    }
}
