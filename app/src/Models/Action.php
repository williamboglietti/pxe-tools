<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use InvalidArgumentException;
use RuntimeException;

/**
 * Action — action utilisateur parente (1 reinstall = 1 action chapeau).
 *
 * Une action regroupe 1 ou plusieurs sous-jobs (wipes, installations,
 * discoveries, disk_tests). Elle porte un token hex 64 chars injecté dans
 * les templates preseed/wipe.sh/postboot.sh et validé à chaque ping checkpoint.
 *
 * POURQUOI status stocké et non dérivé : évite des jointures sur toutes les
 * sous-tables à chaque affichage de timeline. recomputeEndState() est appelé
 * explicitement par les hooks Wipe::end(), Installation::endAnsible(), etc.
 */
final class Action
{
    // Types d'action — détermine le catalogue de checkpoints applicable
    public const TYPE_REINSTALL    = 'reinstall';
    public const TYPE_INSTALL_ONLY = 'install_only';
    public const TYPE_WIPE_ONLY    = 'wipe_only';
    public const TYPE_DISCOVERY    = 'discovery';
    public const TYPE_DISKTEST     = 'disktest';

    public const TYPES = [
        self::TYPE_REINSTALL,
        self::TYPE_INSTALL_ONLY,
        self::TYPE_WIPE_ONLY,
        self::TYPE_DISCOVERY,
        self::TYPE_DISKTEST,
    ];

    // Statuts — reflètent le status agrégé des sous-jobs
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCESS   = 'success';
    public const STATUS_FAILED    = 'failed';
    // STATUS_CANCELLED : action close manuellement par un admin via le bouton
    // "Marquer comme terminée" de la fiche machine (cas action stuck en
    // 'running' alors que l'OS est physiquement up — ex: checkpoint manquant,
    // worker zombie). N'est jamais posé par recomputeEndState : c'est une
    // décision admin, pas un état dérivé des sous-jobs.
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES_TERMINAL = [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /**
     * Crée une nouvelle action et retourne la row avec son token.
     *
     * Le token est généré via bin2hex(random_bytes(32)) — 64 chars hex.
     * Ne pas logger le token en clair : c'est un secret d'authentification machine.
     *
     * @throws InvalidArgumentException si $type n'est pas dans TYPES
     */
    public static function create(int $machineId, string $type, ?string $createdBy = null): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(
                "Type d'action invalide : '$type'. Types valides : " . implode(', ', self::TYPES)
            );
        }

        // POURQUOI random_bytes(32) + bin2hex : 256 bits d'entropie, représentation
        // hex pure sans padding (contrairement à base64), facile à transmettre en
        // query string et dans les scripts shell. Format imposé par la spec.
        $token = bin2hex(random_bytes(32));

        $pdo = Db::get();
        if (Db::columnExists('init_jobs', 'id')) {
            $stmt = $pdo->prepare(
                "INSERT INTO actions (machine_id, type, status, token, created_by) "
              . "SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS ("
              . "SELECT 1 FROM init_jobs WHERE job_type='bootstrap:auto_refresh_catalog_v2' AND status='running')"
            );
            $stmt->execute([$machineId, $type, self::STATUS_PENDING, $token, $createdBy]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Actualisation automatique du catalogue en cours.', 409);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO actions (machine_id, type, status, token, created_by) VALUES (?, ?, ?, ?, ?)'
            )->execute([$machineId, $type, self::STATUS_PENDING, $token, $createdBy]);
        }

        $id = (int)$pdo->lastInsertId();

        // Enqueue rendu postboot.sh dès la création — le worker prendra ce
        // job au prochain poll (~5s), bien avant que la machine boote en
        // install et tente le wget /pxe/postboot/{id}/postboot.sh depuis son
        // late_command. Sans cet enqueue, le rendu se faisait LAZY au moment
        // de process_ansible_job → wget retournait 404 → service systemd
        // jamais installé → pas de checkpoint os_first_boot. Skip discovery
        // (pas de reboot OS final, donc pas de postboot pertinent).
        if ($type !== self::TYPE_DISCOVERY) {
            try {
                $pdo->prepare(
                    "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
                )->execute([
                    'action:render_postboot:' . $id,
                    "Rendu postboot.sh + pxe-postboot.service pour action $id",
                ]);
            } catch (\Throwable) {
                // Best-effort : si la table init_jobs n'existe pas (DB fraîche
                // pré-migration) ou contention, on ne bloque pas la création
                // de l'action. Le worker tentera un rendu lazy en fallback.
            }
        }

        return self::findById($id) ?? [];
    }

    /**
     * Enqueue un init_job pour reboot SSH+WoL asynchrone de la machine
     * associée à l'action. Exécuté par worker.py (root) qui peut lire la
     * clé SSH ansible (contrairement à www-data côté PHP-FPM).
     *
     * Best-effort : si init_jobs absente (DB fraîche) ou contention, on
     * ne bloque pas — le fallback est l'absence de reboot auto (admin
     * doit relancer manuellement). Cas considéré non-critique vu le
     * contexte (sites post-action, machine déjà configurée pour booter
     * en PXE au prochain reboot manuel).
     */
    public static function enqueueReboot(int $actionId, int $machineId): void
    {
        try {
            Db::get()->prepare(
                "INSERT INTO init_jobs (job_type, status, message) VALUES (?, 'pending', ?)"
            )->execute([
                'action:reboot:' . $actionId,
                "Reboot SSH+WoL pour machine $machineId (action $actionId)",
            ]);
        } catch (\Throwable) {
            // Cf. docblock : non-bloquant.
        }
    }

    /**
     * Trouve une action par son ID interne.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM actions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Trouve une action par son token (lookup fréquent : validation checkpoint).
     *
     * SEC-03 :
     *   - Pré-validation `strlen===64 && ctype_xdigit` avant le SELECT : évite
     *     d'envoyer du garbage à PDO et supprime les variations de timing
     *     entre tokens malformés et tokens 64-hex inexistants.
     *   - hash_equals() défense en profondeur après le SELECT : garantit une
     *     comparaison constant-time même si PDO/SQLite remontait un row par
     *     erreur de matching (théorique mais coût quasi-nul).
     *
     * @return array<string,mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $stmt = Db::get()->prepare('SELECT * FROM actions WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) return null;
        if (!hash_equals((string)$row['token'], $token)) return null;
        return $row;
    }

    /**
     * Charge tous les sous-jobs liés à une action, groupés par type.
     * Utilisé par TaskController::showAction pour le rendu de la page action.
     *
     * @return array{wipes:array,installations:array,discoveries:array,disk_tests:array}
     */
    public static function findSubjobsByActionId(int $actionId): array
    {
        $pdo = Db::get();
        $fetch = static function (string $table) use ($pdo, $actionId): array {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE action_id = ? ORDER BY id ASC");
            $stmt->execute([$actionId]);
            return $stmt->fetchAll() ?: [];
        };
        return [
            'wipes'         => $fetch('wipes'),
            'installations' => $fetch('installations'),
            'discoveries'   => $fetch('discoveries'),
            'disk_tests'    => $fetch('disk_tests'),
        ];
    }

    /**
     * Journal d'une action, tel qu'affiché et téléchargé : 'install' (debian-installer) ou 'ansible'
     * (post-installation). Plusieurs installations sur une même action sont séparées par un en-tête.
     */
    public static function logFromSubjobs(array $subjobs, string $kind): string
    {
        $column = match ($kind) {
            'install' => 'install_log',
            'ansible' => 'ansible_log',
            default   => throw new \InvalidArgumentException("Type de journal inconnu : $kind"),
        };
        $installations = $subjobs['installations'] ?? [];
        $log = '';
        foreach ($installations as $installation) {
            if (empty($installation[$column])) continue;
            if (count($installations) > 1) $log .= "\n=== Installation #" . (int)$installation['id'] . " ===\n";
            $log .= $installation[$column];
        }
        return $log;
    }

    /**
     * Met à jour le status de l'action et, si terminal, pose ended_at.
     * Méthode interne utilisée par recomputeEndState().
     */
    public static function setStatus(int $id, string $status): void
    {
        if (in_array($status, self::STATUSES_TERMINAL, true)) {
            Db::get()->prepare(
                'UPDATE actions SET status = ?, ended_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$status, $id]);
        } else {
            Db::get()->prepare(
                'UPDATE actions SET status = ?, ended_at = NULL WHERE id = ?'
            )->execute([$status, $id]);
        }
    }

    /**
     * Clôt manuellement une action (admin force-complete depuis l'UI).
     *
     * Cas d'usage : action stuck en 'running' alors que l'OS est physiquement
     * up — checkpoint manquant ou worker zombie. L'admin sait que l'install est
     * terminée mais l'UI ne le voit pas → ce helper débloque la fiche machine
     * en posant l'action à 'cancelled' et en clôturant les sous-jobs encore
     * actifs pour qu'un recomputeEndState futur ne ré-écrase pas l'état.
     *
     * Transactionnel (3 UPDATEs minimum : actions + wipes + installations +
     * discoveries + disk_tests). Rollback complet en cas d'échec.
     */
    public static function forceComplete(int $actionId): void
    {
        $pdo = Db::get();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE actions SET id=id WHERE id=?')->execute([$actionId]);
            $current = self::findById($actionId);
            if (!$current || in_array($current['status'], self::STATUSES_TERMINAL, true)) {
                if ($ownsTransaction) $pdo->commit();
                return;
            }

            // 1) Clôt les sous-jobs non-terminaux pour bloquer un futur
            //    recomputeEndState d'un hook tardif. 'aborted' = état terminal
            //    cohérent avec la sémantique cancel admin.
            $pdo->prepare(
                "UPDATE wipes SET wipe_status='aborted', ended_at=CURRENT_TIMESTAMP
                 WHERE action_id=? AND wipe_status IN ('pending','running')"
            )->execute([$actionId]);

            $pdo->prepare(
                "UPDATE installations SET install_status='aborted', ended_at=CURRENT_TIMESTAMP
                 WHERE action_id=? AND install_status IN ('pending','running','installing','in_progress')"
            )->execute([$actionId]);

            $pdo->prepare(
                "UPDATE installations SET ansible_status='aborted'
                 WHERE action_id=? AND ansible_status IN ('pending','running','in_progress')"
            )->execute([$actionId]);

            $pdo->prepare(
                "UPDATE discoveries SET discovery_status='aborted', ended_at=CURRENT_TIMESTAMP
                 WHERE action_id=? AND discovery_status IN ('pending','running','in_progress')"
            )->execute([$actionId]);

            $pdo->prepare(
                "UPDATE disk_tests SET job_status='aborted', ended_at=CURRENT_TIMESTAMP
                 WHERE action_id=? AND job_status IN ('pending','running')"
            )->execute([$actionId]);

            // 2) Action elle-même → cancelled (terminal, ended_at posé)
            $pdo->prepare(
                'UPDATE actions SET status=?, ended_at=CURRENT_TIMESTAMP WHERE id=?'
            )->execute([self::STATUS_CANCELLED, $actionId]);

            $pdo->prepare("UPDATE init_jobs SET status='failed', message=? WHERE job_type IN (?, ?, ?) AND status='pending'")->execute([
                'Clôture administrative : demande en attente annulée.',
                'action:reboot:' . $actionId, 'action:ansible_retry:' . $actionId, 'action:render_postboot:' . $actionId,
            ]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Recalcule et persiste le status agrégé de l'action depuis ses sous-jobs.
     *
     * Règle (spec §Hooks ligne 93-101) :
     *   - Aucun sous-job          → pending
     *   - Au moins 1 running/pending en cours → running
     *   - Au moins 1 failed       → failed
     *   - Tous success            → success
     *
     * Appelé depuis Wipe::end(), Installation::endAnsible(), Discovery::end(),
     * DiskTest::end() à chaque transition de status d'un sous-job.
     */
    public static function recomputeEndState(int $actionId): void
    {
        $pdo = Db::get();

        // POURQUOI transaction englobante : sans elle, 2 hooks recomputeEndState concurrents
        // (ex. wipe.end + setAnsibleStatus pour la même action reinstall) peuvent lire des
        // snapshots partiels et écrire dans le mauvais ordre, laissant status='running'
        // alors que tous les sous-jobs ont terminé. Sous SQLite WAL + busy_timeout=5000
        // le risque est atténué (UPDATEs sérialisés), mais BEGIN IMMEDIATE force l'écriture
        // atomique. Sous MySQL/MariaDB autocommit + READ COMMITTED, la race est reproductible
        // → SELECT ... FOR UPDATE obligatoire avant la collecte.
        // Branche inTransaction() : si le caller a déjà ouvert une transaction, on s'y greffe
        // (l'atomicité est déjà garantie), pas de transaction imbriquée (PDO SQLite/MySQL
        // lèverait une exception sur un BEGIN imbriqué).
        $alreadyInTransaction = $pdo->inTransaction();
        // POURQUOI ce flag séparé : pour SQLite on utilise exec('BEGIN IMMEDIATE')
        // car PDO::beginTransaction() envoie BEGIN deferred (ne supporte pas le
        // mode IMMEDIATE). Conséquence : $pdo->inTransaction() reste false et
        // $pdo->commit()/rollBack() lèvent "no active transaction". On track
        // donc manuellement pour pouvoir terminer la transaction symétriquement
        // via exec('COMMIT'/'ROLLBACK').
        $sqliteTxStarted = false;
        if (!$alreadyInTransaction) {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
                $sqliteTxStarted = true;
            } else {
                // MySQL/MariaDB : beginTransaction() + row lock avant les SELECTs
                $pdo->beginTransaction();
                $pdo->prepare('SELECT id FROM actions WHERE id = ? FOR UPDATE')->execute([$actionId]);
            }
        }

        try {
            // Collecte les statuts de tous les sous-jobs liés à cette action.
            // Les 4 tables partagent la colonne action_id (FK nullable).
            // POURQUOI COALESCE(ansible_status, install_status) pour installations :
            // l'install a 2 phases (preseed d-i → install_status, puis ansible → ansible_status).
            // Le statut final pertinent est ansible_status quand il existe ; sinon install_status
            // reflète la phase preseed (in_progress, success avant ansible, failed).
            $statuses = [];

            // Tables simples avec une colonne status unique
            foreach ([
                'wipes'       => 'wipe_status',
                'discoveries' => 'discovery_status',
                'disk_tests'  => 'job_status',
            ] as $table => $statusCol) {
                $stmt = $pdo->prepare("SELECT $statusCol FROM $table WHERE action_id = ?");
                $stmt->execute([$actionId]);
                foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $s) {
                    if ($s !== null && $s !== '') {
                        $statuses[] = $s;
                    }
                }
            }

            // Installations : statut agrégé = ansible_status s'il existe, sinon install_status
            $stmt = $pdo->prepare(
                "SELECT COALESCE(ansible_status, install_status) FROM installations WHERE action_id = ?"
            );
            $stmt->execute([$actionId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $s) {
                if ($s !== null && $s !== '') {
                    $statuses[] = $s;
                }
            }

            // Aucun sous-job → rester pending
            if (empty($statuses)) {
                $computed = self::STATUS_PENDING;
            } elseif (in_array('failed', $statuses, true)) {
                // Au moins 1 failed → failed
                $computed = self::STATUS_FAILED;
            } else {
                // Tous success ? 'no_role' est traité comme success : worker.py
                // pose ansible_status='no_role' quand aucun rôle Ansible n'est défini
                // pour la machine — l'install OS est complète, il n'y a juste rien
                // à provisionner en post-install. Du point de vue de l'action c'est
                // terminal (pas d'attente résiduelle).
                $terminalOk = ['success', 'no_role'];
                $allSuccess = true;
                foreach ($statuses as $s) {
                    if (!in_array($s, $terminalOk, true)) {
                        $allSuccess = false;
                        break;
                    }
                }
                // Au moins 1 en cours (running, pending, ou autre état non-terminal)
                $computed = $allSuccess ? self::STATUS_SUCCESS : self::STATUS_RUNNING;
            }

            // Garde TYPE_REINSTALL : l'action attend wipe + install. La row
            // installations n'est créée qu'au boot d-i post-wipe (par
            // preseed.php → Installation::startForMachine). Entre wipe.end et
            // ce boot s'écoule une fenêtre de ~20s pendant laquelle seul le
            // wipe.success est observé. Sans ce garde, l'action passe success
            // dans cet intervalle, devient terminale, et tous les checkpoints
            // suivants (phase install) sont rejetés avec reason=action_terminal,
            // laissant le dashboard vide pour la phase install.
            if ($computed === self::STATUS_SUCCESS) {
                $stmt = $pdo->prepare('SELECT type FROM actions WHERE id = ?');
                $stmt->execute([$actionId]);
                $actionType = (string)$stmt->fetchColumn();
                if ($actionType === self::TYPE_REINSTALL) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM installations WHERE action_id = ?');
                    $stmt->execute([$actionId]);
                    if ((int)$stmt->fetchColumn() === 0) {
                        $computed = self::STATUS_RUNNING;
                    }
                }
            }

            // Administrative closure is final, even when a late worker callback arrives.
            $stmt=$pdo->prepare('SELECT status FROM actions WHERE id=?');
            $stmt->execute([$actionId]);
            if ($stmt->fetchColumn()!==self::STATUS_CANCELLED) self::setStatus($actionId, $computed);

            if ($sqliteTxStarted) {
                $pdo->exec('COMMIT');
            } elseif (!$alreadyInTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($sqliteTxStarted) {
                $pdo->exec('ROLLBACK');
            } elseif (!$alreadyInTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
