<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use Admin\Util\DiskIdentity;

final class DiskTest
{
    // Menu volontairement court : un rôle distinct par mode. badblocks_long_ro est
    // retiré (redondant avec la Vérification lecture seule, qui a le compteur live).
    // smart_long est de retour (2026-10-04) : c'est le seul test de surface
    // non destructif et sans I/O du système, utile pour auditer des disques en service.
    public const MODES = [
        'smart_short',
        'smart_long',
        'badblocks_quick_ro',
        'badblocks_fixed_rw',
        'badblocks_quick_rw',
        'badblocks_long_rw',
    ];

    // Libellés grand public COURTS (select fermé, badges, tableaux) : le nom dit
    // l'intention — vérifier ou RÉPARER. Les clés ne changent jamais (DB).
    // Les entrées legacy restent pour l'affichage des résultats historiques.
    public const MODE_LABELS = [
        'smart_short'        => 'Autodiagnostic SMART rapide',
        'smart_long'         => 'Autodiagnostic SMART complet',
        'badblocks_quick_ro' => 'Vérification (lecture seule)',
        'badblocks_fixed_rw' => 'Qualification HDD — motif fixe (EFFACEMENT !)',
        'badblocks_quick_rw' => 'Réparation (EFFACEMENT !)',
        'badblocks_long_rw'  => 'Réparation approfondie (EFFACEMENT !)',
        // LEGACY — mode retiré du menu, affichage des anciens résultats only :
        'badblocks_long_ro'  => 'Vérification approfondie (lecture seule)',
    ];

    // Détail chiffré affiché dans le menu de sélection : ce que le mode fait
    // concrètement (combien de lectures/écritures) et s'il répare ou non.
    // fixed_rw = 1 motif fixe 0xaa (qualification des HDD, équivalent write1fixed).
    // quick_rw = 1 motif aléatoire (l'écriture suffit à déclencher la
    // réallocation firmware ; l'aléatoire déjoue la compression des contrôleurs
    // SSD) ; long_rw = motifs complémentaires 0xaa/0x55 (chaque bit testé à 0
    // ET à 1 — attrape les bits collés sans l'usure des 4 motifs d'usine).
    public const MODE_DETAILS = [
        'smart_short'        => 'le disque se teste lui-même sur un échantillon · ne répare pas',
        'smart_long'         => 'le disque se teste lui-même sur toute sa surface · lecture seule, ne répare pas',
        'badblocks_quick_ro' => '1 lecture complète du disque · ne répare pas',
        'badblocks_fixed_rw' => '1 écriture complète + 1 relecture (motif 0xaa) · recommandé pour les HDD',
        'badblocks_quick_rw' => '1 écriture complète + 1 relecture · répare les secteurs défectueux',
        'badblocks_long_rw'  => '2 écritures + 2 relectures (chaque bit testé à 0 et à 1) · SSD ou disque suspect',
    ];

    public const MODE_DESTRUCTIVE = [
        'badblocks_fixed_rw',
        'badblocks_quick_rw',
        'badblocks_long_rw',
    ];

    public const MODE_DURATION_HINT = [
        'smart_short'        => '~2 min',
        'smart_long'         => '~2 h par To',
        'badblocks_quick_ro' => '~2 h par To',
        'badblocks_fixed_rw' => '~4 h par To',
        'badblocks_quick_rw' => '~4 h par To',
        'badblocks_long_rw'  => '~8 h par To',
    ];

    // 'wait' = test in-session : l'agent revient à l'écran d'attente discovery
    // au lieu d'éteindre/rebooter (la fenêtre d'attente est réarmée par le serveur).
    public const POST_ACTIONS = ['reboot', 'poweroff', 'wait'];

    /** Nombre total de motifs/passes par mode — pour afficher « motif 1/2 »
     *  (UI + pré-calcul du libellé de phase). Lecture seule = passes de lecture. */
    public const MODE_PASS_TOTAL = [
        'badblocks_quick_ro' => 1,
        'badblocks_long_ro'  => 4,
        'badblocks_fixed_rw' => 1,
        'badblocks_quick_rw' => 1,
        'badblocks_long_rw'  => 2,
    ];

    /** Codes de phase envoyés par l'agent (whitelist côté endpoint) → libellés UI.
     *  En écriture badblocks repart à 0 % à chaque motif : la phase situe le %
     *  affiché. Le nombre total de motifs dépend du mode (1 ou 2) — pas de « /N »
     *  en dur dans les libellés. */
    public const PHASE_LABELS = [
        'init'    => 'Préparation',
        'smart'   => 'Autodiagnostic SMART',
        'read'    => 'Lecture du disque',
        'write1'  => 'Écriture (motif 1)',
        'verify1' => 'Vérification (motif 1)',
        'write2'  => 'Écriture (motif 2)',
        'verify2' => 'Vérification (motif 2)',
        'write3'  => 'Écriture (motif 3)',
        'verify3' => 'Vérification (motif 3)',
        'write4'  => 'Écriture (motif 4)',
        'verify4' => 'Vérification (motif 4)',
        'done'    => 'Terminé',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM disk_tests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Latest test for a machine (any status). */
    public static function latestForMachine(int $machineId): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT * FROM disk_tests WHERE machine_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$machineId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Active (pending|running) test job for a given MAC.
     * Utilisé par disktest.sh pour récupérer son job. Passe par
     * Machine::findByAnyMac pour gérer le cas multi-NIC : disktest.sh peut
     * tourner sur une interface ≠ MAC primaire DB.
     */
    public static function activeByMac(string $mac): ?array
    {
        $machine = Machine::findByAnyMac($mac);
        if (!$machine) return null;
        $stmt = Db::get()->prepare(
            'SELECT t.*, m.mac, m.hostname '
          . 'FROM disk_tests t JOIN machines m ON t.machine_id = m.id '
          . "WHERE t.machine_id = ? AND t.job_status IN ('pending','running') "
          . 'ORDER BY t.id DESC LIMIT 1'
        );
        $stmt->execute([(int)$machine['id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Lie chaque disque demandé à son numéro de série courant (discovery) et le
     * compare à celui que l'opérateur a vu à l'écran. Un test n'a de valeur que
     * rattaché au bon disque : une lecture seule sur un mauvais disque produirait
     * un rapport de fiabilité faux, une écriture sur un mauvais disque détruirait
     * des données. Tout disque sans identité valide, ambigu ou dont la série a
     * changé depuis l'affichage annule l'ensemble de la demande.
     *
     * @param array<string,mixed> $inventory inventory_json décodé de la machine
     * @param array<int,array{disk:string,mode:string}> $jobs
     * @param array<string,mixed> $confirmedSerials série affichée par disque (POST)
     * @return array<int,array{disk:string,mode:string,identity:array<string,string>}>
     * @throws \RuntimeException code 409
     */
    public static function bindJobs(array $inventory, array $jobs, array $confirmedSerials): array
    {
        $bound = [];
        $seen = [];
        foreach ($jobs as $job) {
            $disk = (string)($job['disk'] ?? '');
            $found = DiskIdentity::forPath($inventory, $disk);
            if ($found['identity'] === null) {
                throw new \RuntimeException($found['error'] ?? ('Numéro de série requis pour tester ' . $disk), 409);
            }
            $confirmed = DiskIdentity::normalize($confirmedSerials[$disk] ?? null);
            if ($confirmed === null || $confirmed !== $found['identity']['value']) {
                throw new \RuntimeException('Le numéro de série de ' . $disk . ' a changé depuis l’affichage. Relancez un discovery et confirmez de nouveau le disque.', 409);
            }
            if (isset($seen[$found['identity']['value']])) {
                throw new \RuntimeException('Le même disque physique est demandé deux fois : ' . $disk, 409);
            }
            $seen[$found['identity']['value']] = true;
            $job['identity'] = $found['identity'];
            $bound[] = $job;
        }
        return $bound;
    }

    /**
     * Jobs tels que le script du test les reçoit. Il ne teste un disque que si la
     * série relue sur la machine égale serial_b64 ; un job ancien, sans identité,
     * part avec '' et sera refusé par le script.
     *
     * @param array<int,mixed> $jobs jobs_json décodé
     * @return array<int,array{disk:string,mode:string,serial_b64:string}>
     */
    public static function agentJobs(array $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            $job = is_array($job) ? $job : [];
            $serial = self::jobSerial($job);
            $out[] = [
                'disk'       => (string)($job['disk'] ?? ''),
                'mode'       => (string)($job['mode'] ?? ''),
                'serial_b64' => $serial === null ? '' : base64_encode($serial),
            ];
        }
        return $out;
    }

    /** Série normalisée d'un job, ou null si le job n'a pas d'identité exploitable. */
    public static function jobSerial(array $job): ?string
    {
        $identity = $job['identity'] ?? null;
        if (!is_array($identity) || ($identity['kind'] ?? '') !== 'serial' || ($identity['status'] ?? 'valid') !== 'valid') return null;
        return DiskIdentity::normalize($identity['value'] ?? null);
    }

    /**
     * @param array<int,array{disk:string,mode:string,identity:array<string,string>}> $jobs Liste de {disk, mode, identity} (voir bindJobs)
     * @param bool $parallel true = tous les disques en parallèle
     * @param string $postAction 'reboot' ou 'poweroff' après fin
     */
    public static function start(int $machineId, array $jobs, bool $parallel, string $postAction = 'poweroff', ?int $actionId = null): int
    {
        foreach ($jobs as $j) {
            if (!isset($j['disk'], $j['mode'])) {
                throw new \InvalidArgumentException('Each job needs disk + mode');
            }
            if (!in_array($j['mode'], self::MODES, true)) {
                throw new \InvalidArgumentException("Invalid disktest mode: {$j['mode']}");
            }
            if (self::jobSerial($j) === null) {
                throw new \InvalidArgumentException("Disktest job without a valid serial identity: {$j['disk']}");
            }
        }
        if (!in_array($postAction, self::POST_ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid post_action: $postAction");
        }
        $jobsJson = json_encode(array_values($jobs), JSON_UNESCAPED_SLASHES);
        Db::get()->prepare(
            "INSERT INTO disk_tests (machine_id, jobs_json, parallel, job_status, post_action, action_id, started_at) "
          . "VALUES (?, ?, ?, 'pending', ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$machineId, $jobsJson, $parallel ? 1 : 0, $postAction, $actionId]);
        return (int) Db::get()->lastInsertId();
    }

    public static function setStatus(int $id, string $status): void
    {
        Db::get()->prepare('UPDATE disk_tests SET job_status = ? WHERE id = ?')
                  ->execute([$status, $id]);
    }

    public static function appendLog(int $id, string $line): void
    {
        $concat = Db::concat('log');
        Db::get()->prepare(
            "UPDATE disk_tests SET log = $concat WHERE id = ?"
        )->execute([$line, $id]);
    }

    /**
     * Append (ou remplace si déjà présent) le résultat pour un (disque, mode).
     * results_json est une liste d'objets ; un nouveau résultat écrase une entrée
     * existante sur la même paire (disk, mode).
     *
     * @param array{verdict:string,bad_blocks:?int,duration_s:?int,serial?:?string,device?:?string} $result
     */
    public static function setResultForDisk(int $id, string $disk, string $mode, array $result): void
    {
        $row = self::find($id);
        if ($row === null) return;
        $current = [];
        if (!empty($row['results_json'])) {
            $decoded = json_decode((string)$row['results_json'], true);
            if (is_array($decoded)) $current = $decoded;
        }
        $entry = [
            'disk'         => $disk,
            'mode'         => $mode,
            'verdict'      => (string)($result['verdict'] ?? 'unknown'),
            'bad_blocks'   => isset($result['bad_blocks']) ? (int)$result['bad_blocks'] : null,
            'duration_s'   => isset($result['duration_s']) ? (int)$result['duration_s'] : null,
            'serial'       => isset($result['serial']) ? (string)$result['serial'] : null,
            'device'       => isset($result['device']) ? (string)$result['device'] : null,
            'completed_at' => date('c'),
        ];
        $evidence = self::resultEvidence($result['evidence'] ?? null);
        if ($evidence !== null) $entry['evidence'] = $evidence;
        $found = false;
        foreach ($current as $i => $existing) {
            if (($existing['disk'] ?? null) === $disk && ($existing['mode'] ?? null) === $mode) {
                $current[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (!$found) $current[] = $entry;
        $json = json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Db::get()->prepare('UPDATE disk_tests SET results_json = ? WHERE id = ?')
                  ->execute([$json, $id]);
    }

    /** Preserve bounded diagnostic evidence; its semantics are checked when grading. */
    public static function resultEvidence(mixed $value): ?array
    {
        if (!is_array($value) || ($value['schema'] ?? null) !== 1) return null;
        if (!in_array($value['tool'] ?? null, ['badblocks', 'smartctl'], true)) return null;
        $json = json_encode($value);
        return is_string($json) && strlen($json) <= 16384 ? $value : null;
    }

    /** A terminal result needs coherent evidence for precisely the selected mode. */
    public static function hasCompletedResult(string $mode, array $result): bool
    {
        $verdict = $result['verdict'] ?? '';
        if (!in_array($verdict, ['passed', 'failed'], true)) return false;
        $evidence = self::resultEvidence($result['evidence'] ?? null);
        if ($evidence === null || ($evidence['status'] ?? '') !== 'completed') return false;
        if (in_array($mode, ['smart_short', 'smart_long'], true)) {
            $rc = $evidence['start_exit_code'] ?? null;
            return ($evidence['tool'] ?? '') === 'smartctl'
                && ($evidence['type'] ?? '') === ($mode === 'smart_long' ? 'long' : 'short')
                && is_int($rc) && $rc >= 0 && ($rc & 7) === 0
                && (($evidence['new_entry'] ?? false) === true || ($evidence['seen_running'] ?? false) === true)
                && is_bool($evidence['passed'] ?? null)
                && $evidence['passed'] === ($verdict === 'passed');
        }
        $expected = match ($mode) {
            'badblocks_fixed_rw' => ['0xaa'], 'badblocks_quick_rw' => ['random'],
            'badblocks_long_rw' => ['0xaa', '0x55'],
            'badblocks_quick_ro', 'badblocks_long_ro' => [], default => null,
        };
        if ($expected === null || ($evidence['tool'] ?? '') !== 'badblocks'
            || ($evidence['exit_code'] ?? null) !== 0 || ($evidence['pass_completed'] ?? null) !== true
            || !is_int($evidence['passes_completed'] ?? null)
            || $evidence['passes_completed'] < ($mode === 'badblocks_long_ro' ? 4 : 1)
            || ($evidence['patterns'] ?? null) !== $expected
            || ($evidence['write_phases'] ?? null) !== count($expected)
            || ($evidence['verify_phases'] ?? null) !== count($expected)) return false;
        foreach (['errors', 'bad_blocks'] as $key) {
            if (!is_int($evidence[$key] ?? null) || $evidence[$key] < 0) return false;
        }
        return array_key_exists('stalls', $evidence)
            && ($evidence['stalls'] === null || is_array($evidence['stalls']))
            && (($evidence['errors'] === 0 && $evidence['bad_blocks'] === 0) === ($verdict === 'passed'));
    }

    /** All expected disks must explicitly pass before an installation can follow. */
    public static function completionOutcome(array $test, string $reportedStatus): array
    {
        $jobs = json_decode((string)($test['jobs_json'] ?? ''), true);
        $results = json_decode((string)($test['results_json'] ?? ''), true);
        if (!is_array($jobs) || $jobs === [] || !is_array($results)) {
            return ['status' => 'failed', 'incomplete' => true];
        }
        $incomplete = false;
        $failed = false;
        foreach ($jobs as $job) {
            if (!is_array($job) || empty($job['disk']) || empty($job['mode'])) {
                $incomplete = true;
                continue;
            }
            $matches = array_values(array_filter($results, static fn($row): bool => is_array($row)
                && ($row['disk'] ?? null) === $job['disk'] && ($row['mode'] ?? null) === $job['mode']));
            $serial = self::jobSerial($job);
            if (count($matches) !== 1 || $serial === null
                || DiskIdentity::normalize($matches[0]['serial'] ?? null) !== $serial
                || !self::hasCompletedResult($job['mode'], $matches[0])) {
                $incomplete = true;
            } elseif ($matches[0]['verdict'] === 'failed') {
                $failed = true;
            }
        }
        return [
            'status' => $failed || $incomplete || $reportedStatus !== 'success' ? 'failed' : 'success',
            'incomplete' => !$failed && ($incomplete || $reportedStatus !== 'success'),
        ];
    }

    /**
     * Écrit la progression live d'UN disque dans progress_json (objet indexé par
     * chemin disque). Read-modify-write sous transaction : en mode parallèle,
     * deux disques peuvent poster dans la même seconde via deux workers PHP-FPM
     * — sans verrou, le dernier écrirait sur une lecture périmée (lost update).
     *
     * @param array{mode:string,phase:string,pass:int,pct:int,bad:int,elapsed_s:int,eta_s:?int} $fields
     */
    public static function setDiskProgress(int $id, string $disk, array $fields): void
    {
        $pdo = Db::get();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT progress_json FROM disk_tests WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row === false) {
                if ($own) $pdo->rollBack();
                return;
            }
            $progress = json_decode((string)($row['progress_json'] ?? ''), true);
            if (!is_array($progress)) $progress = [];
            $progress[$disk] = $fields + ['updated_at' => date('c')];
            $pdo->prepare('UPDATE disk_tests SET progress_json = ? WHERE id = ?')
                ->execute([json_encode($progress, JSON_UNESCAPED_SLASHES), $id]);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Fusionne jobs_json + progress_json + results_json en lignes d'affichage,
     * une par (disque, mode) planifié. Source unique pour le partial _progress,
     * la fiche machine et le calcul du % global.
     *
     * @param array<string,mixed> $test Row disk_tests
     * @return array<int,array{disk:string,mode:string,state:string,pct:int,phase:string,
     *                         bad:?int,eta_s:?int,verdict:?string,updated_at:?string}>
     */
    public static function computeProgressRows(array $test): array
    {
        $jobs     = json_decode((string)($test['jobs_json'] ?? ''), true) ?: [];
        $progress = json_decode((string)($test['progress_json'] ?? ''), true) ?: [];
        $results  = json_decode((string)($test['results_json'] ?? ''), true) ?: [];

        $resultByDiskMode = [];
        foreach ($results as $r) {
            if (isset($r['disk'], $r['mode'])) {
                $resultByDiskMode[$r['disk'] . '|' . $r['mode']] = $r;
            }
        }

        $rows = [];
        foreach ($jobs as $j) {
            $disk = (string)($j['disk'] ?? '');
            $mode = (string)($j['mode'] ?? '');
            if ($disk === '') continue;
            $res  = $resultByDiskMode[$disk . '|' . $mode] ?? null;
            $prog = is_array($progress[$disk] ?? null) ? $progress[$disk] : null;
            if ($res !== null) {
                $rows[] = [
                    'disk' => $disk, 'mode' => $mode, 'state' => 'done', 'pct' => 100,
                    'phase' => 'done',
                    'bad' => isset($res['bad_blocks']) ? (int)$res['bad_blocks'] : null,
                    'eta_s' => null,
                    'verdict' => (string)($res['verdict'] ?? 'unknown'),
                    'updated_at' => $res['completed_at'] ?? null,
                ];
            } elseif ($prog !== null) {
                $rows[] = [
                    'disk' => $disk, 'mode' => $mode, 'state' => 'running',
                    'pct' => max(0, min(100, (int)($prog['pct'] ?? 0))),
                    'phase' => (string)($prog['phase'] ?? ''),
                    'bad' => isset($prog['bad']) ? (int)$prog['bad'] : null,
                    'eta_s' => isset($prog['eta_s']) && $prog['eta_s'] !== null ? (int)$prog['eta_s'] : null,
                    'verdict' => null,
                    'updated_at' => $prog['updated_at'] ?? null,
                ];
            } else {
                $rows[] = [
                    'disk' => $disk, 'mode' => $mode, 'state' => 'waiting', 'pct' => 0,
                    'phase' => '', 'bad' => null, 'eta_s' => null,
                    'verdict' => null, 'updated_at' => null,
                ];
            }
        }
        return $rows;
    }

    /** % global d'un test = moyenne des % par job (résultat final = 100). */
    public static function computeOverallPct(array $test): int
    {
        $rows = self::computeProgressRows($test);
        if ($rows === []) return 0;
        $sum = 0;
        foreach ($rows as $r) $sum += (int)$r['pct'];
        return (int)floor($sum / count($rows));
    }

    /**
     * Tests actifs avec % global, indexés par machine — UNE requête pour le badge
     * « Tests en cours · N % » de la liste des machines (pas de N+1 sur le poll 5 s).
     *
     * @return array<int,array{test_id:int,pct:int}>
     */
    public static function activeProgressByMachine(): array
    {
        $rows = Db::get()->query(
            "SELECT machine_id, id, action_id, jobs_json, progress_json, results_json
             FROM disk_tests WHERE job_status IN ('pending','running') ORDER BY id"
        )->fetchAll();
        $out = [];
        foreach ($rows as $t) {
            $out[(int)$t['machine_id']] = [
                'test_id'   => (int)$t['id'],
                'action_id' => isset($t['action_id']) ? (int)$t['action_id'] : 0,
                'pct'       => self::computeOverallPct($t),
            ];
        }
        return $out;
    }

    public static function end(int $id, string $status): void
    {
        Db::get()->prepare(
            'UPDATE disk_tests SET job_status = ?, ended_at = CURRENT_TIMESTAMP WHERE id = ?'
        )->execute([$status, $id]);

        // Hook : recompute l'état agrégé de l'action parente si ce disk_test lui est rattaché.
        // Guard null : les sous-jobs legacy (action_id IS NULL) sont silencieusement ignorés.
        $row = self::find($id);
        if ($row && !empty($row['action_id'])) {
            Action::recomputeEndState((int)$row['action_id']);
        }
    }

    /**
     * Renvoie le dernier résultat connu par disque pour une machine, en parcourant
     * tous les disk_tests de la machine du plus récent au plus ancien.
     *
     * @return array<string,array{mode:string,verdict:string,bad_blocks:?int,completed_at:?string,test_id:int}>
     */
    public static function latestResultPerDisk(int $machineId): array
    {
        $stmt = Db::get()->prepare(
            'SELECT id, results_json FROM disk_tests '
          . 'WHERE machine_id = ? AND results_json IS NOT NULL '
          . 'ORDER BY id DESC'
        );
        $stmt->execute([$machineId]);
        $rows = $stmt->fetchAll();
        $latest = [];
        foreach ($rows as $r) {
            $results = json_decode((string)$r['results_json'], true);
            if (!is_array($results)) continue;
            foreach ($results as $entry) {
                $disk = $entry['disk'] ?? null;
                if ($disk === null || isset($latest[$disk])) continue;
                $latest[$disk] = [
                    'mode'         => $entry['mode'] ?? '',
                    'verdict'      => $entry['verdict'] ?? 'unknown',
                    'bad_blocks'   => $entry['bad_blocks'] ?? null,
                    'completed_at' => $entry['completed_at'] ?? null,
                    'test_id'      => (int)$r['id'],
                ];
            }
        }
        return $latest;
    }
}
