<?php
declare(strict_types=1);

namespace Admin\Util;

/**
 * Deterministic disk reliability classification.
 * No database access, disk access, subprocesses or network calls.
 */
final class DiskReliability
{
    private const ATA_ATTRIBUTES = [5 => 'reallocated', 187 => 'reported_uncorrect',
        188 => 'command_timeout', 196 => 'reallocation_events', 197 => 'pending',
        198 => 'offline_uncorrectable', 199 => 'crc_errors', 10 => 'spin_retry',
        194 => 'temperature_attr'];
    private const SSD_LIFE_ATTRIBUTES = [231, 233, 202, 169, 177];
    private const HOURS_FIVE_YEARS = 43800;
    private const RECENT_UNREADABLE_HOURS = 1000;
    private const SPIN_RETRY_DEGRADED = 10;
    private const REALLOCATED_TOLERATED = 8;
    private const REALLOCATED_REJECTED = 100;
    private const PLANS = ['smart' => 'smart', 'read' => 'read', 'write1' => 'write',
        'write2' => 'write', 'write1fixed' => 'write'];
    public const PLACEMENT = [
        'A' => 'Données de production, y compris sans redondance (une sauvegarde reste obligatoire).',
        'B' => 'Uniquement en redondance (miroir ou RAIDZ) ou pour des données reproductibles ou sauvegardées ailleurs.',
        'C' => 'Données jetables (cache, essais), jamais seul ni pour des disques de VM utiles.',
        'D' => "À remplacer : n'y mettre aucune donnée.",
        '?' => 'Test incomplet : relancer avant de décider.',
    ];

    /**
     * Application entry point. SMART inputs are decoded smartctl -x JSON or JSON
     * strings, never snapshot database rows. Result evidence must be explicit;
     * unknown/missing/interrupted evidence cannot produce a healthy grade.
     *
     * @param array|string|null $smartBefore
     * @param array|string|null $smartAfter
     * @param array{status:string,plan:string,kind:string,selftests:array,surface:?array} $test
     * @return array{grade:string,reasons:array<int,string>,placement:string}
     */
    public static function evaluate(array|string|null $smartBefore, array|string|null $smartAfter, array $test): array
    {
        $before = self::smartSummary($smartBefore);
        $after = self::smartSummary($smartAfter);
        if (!$before['available'] || !$after['available']
            || !is_bool($before['health_passed']) || !is_bool($after['health_passed'])) {
            return self::unknown('SMART illisible : aucun verdict possible');
        }
        if (($test['status'] ?? null) !== 'completed') {
            return self::unknown('test incomplet ou interrompu');
        }
        $plan = $test['plan'] ?? null;
        $kind = $test['kind'] ?? null;
        if (!is_string($plan) || !isset(self::PLANS[$plan])
            || !in_array($kind, ['hdd', 'ssd', 'nvme'], true)) {
            return self::unknown('plan de test ou type de disque inconnu');
        }
        $selftests = $test['selftests'] ?? null;
        if (!is_array($selftests) || !array_is_list($selftests)) {
            return self::unknown('autodiagnostic interrompu ou absent');
        }
        foreach ($selftests as $selftest) {
            if (!is_array($selftest)
                || !in_array($selftest['status'] ?? null, ['completed', 'unsupported'], true)) {
                return self::unknown('autodiagnostic interrompu ou absent');
            }
            if ($selftest['status'] === 'completed' && !is_bool($selftest['passed'] ?? null)) {
                return self::unknown('résultat de l’autodiagnostic absent');
            }
            if (isset($selftest['type']) && !is_string($selftest['type'])) {
                return self::unknown('résultat de l’autodiagnostic illisible');
            }
        }
        if (!array_key_exists('surface', $test)) {
            return self::unknown('résultat du test de surface absent');
        }
        $surface = $test['surface'];
        if ($surface === null && self::PLANS[$plan] !== 'smart') {
            return self::unknown('résultat du test de surface absent');
        }
        if ($surface !== null) {
            if (!is_array($surface) || !is_string($surface['status'] ?? null)
                || !self::counter($surface['errors'] ?? null)
                || !self::counter($surface['bad_blocks'] ?? null)
                || !array_key_exists('stalls', $surface)
                || ($surface['stalls'] !== null && !is_array($surface['stalls']))) {
                return self::unknown('résultat du test de surface illisible');
            }
        }
        return self::classify($before, $after, $selftests, $surface, $plan, $kind);
    }

    /** Stable summary of smartctl -x --json, matching Python smart_summary. */
    public static function smartSummary(array|string|null $data): array
    {
        $summary = ['available' => false, 'health_passed' => null, 'power_on_hours' => null,
            'temperature' => null, 'attrs' => [], 'nvme' => [], 'ata_error_count' => null,
            'ata_errors' => [], 'selftest_count' => null, 'selftest_head' => [],
            'selftest_latest' => null, 'ssd_life_left' => null];
        if (is_string($data)) {
            $data = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) return $summary;
        }
        if (!is_array($data) || !isset($data['smart_status']) || !is_array($data['smart_status'])) {
            return $summary;
        }
        try {
            $summary['health_passed'] = self::boolean($data['smart_status']['passed'] ?? null);
            $summary['power_on_hours'] = self::nonnegativeNumber(self::object($data, 'power_on_time')['hours'] ?? null);
            $summary['temperature'] = self::number(self::object($data, 'temperature')['current'] ?? null);
            $table = self::rows(self::object($data, 'ata_smart_attributes'));
            foreach ($table as $row) {
                $id = $row['id'] ?? null;
                if (!is_int($id)) throw new \InvalidArgumentException('Invalid attribute id');
                if (isset(self::ATA_ATTRIBUTES[$id])) {
                    $value = self::object($row, 'raw')['value'] ?? null;
                    $summary['attrs'][self::ATA_ATTRIBUTES[$id]] = $id === 194
                        ? self::number($value) : self::nonnegativeNumber($value);
                }
            }
            foreach (self::SSD_LIFE_ATTRIBUTES as $id) {
                foreach ($table as $row) {
                    if ($row['id'] === $id) {
                        $summary['ssd_life_left'] = self::nonnegativeNumber($row['value'] ?? null);
                        break 2;
                    }
                }
            }
            $errorLog = self::object($data, 'ata_smart_error_log');
            $errors = self::object($errorLog, 'summary') ?: self::object($errorLog, 'extended');
            $summary['ata_error_count'] = self::nonnegativeNumber($errors['count'] ?? null);
            foreach (self::rows(self::object($errorLog, 'extended')) as $entry) {
                $description = $entry['error_description'] ?? '';
                if (!is_string($description)) throw new \InvalidArgumentException('Invalid ATA error');
                preg_match('/Error: (\w+)/', $description, $match);
                $summary['ata_errors'][] = ['hours' => self::nonnegativeNumber($entry['lifetime_hours'] ?? null),
                    'kind' => $match[1] ?? 'other'];
            }
            $testLog = self::object($data, 'ata_smart_self_test_log');
            $log = self::object($testLog, 'extended') ?: self::object($testLog, 'standard');
            $entries = self::rows($log);
            $summary['selftest_count'] = $log
                ? self::nonnegativeNumber(array_key_exists('count', $log) ? $log['count'] : count($entries)) : null;
            foreach (array_slice($entries, 0, 3) as $entry) {
                $summary['selftest_head'][] = [self::string(self::object($entry, 'type')['string'] ?? null),
                    self::nonnegativeNumber($entry['lifetime_hours'] ?? null), self::string(self::object($entry, 'status')['string'] ?? null)];
            }
            if ($entries) {
                $top = $entries[0];
                $status = self::object($top, 'status');
                $summary['selftest_latest'] = ['type' => self::string(self::object($top, 'type')['string'] ?? null),
                    'passed' => self::boolean($status['passed'] ?? null), 'status' => self::string($status['string'] ?? null),
                    'lifetime_hours' => self::nonnegativeNumber($top['lifetime_hours'] ?? null)];
            }
            $health = self::object($data, 'nvme_smart_health_information_log');
            if ($health) {
                foreach (['critical_warning', 'available_spare', 'available_spare_threshold',
                    'percentage_used', 'media_errors', 'num_err_log_entries', 'unsafe_shutdowns', 'data_units_written'] as $key) {
                    $summary['nvme'][$key] = self::nonnegativeNumber($health[$key] ?? null);
                }
                $summary['power_on_hours'] = array_key_exists('power_on_hours', $health)
                    ? self::nonnegativeNumber($health['power_on_hours']) : $summary['power_on_hours'];
                $summary['temperature'] = array_key_exists('temperature', $health)
                    ? self::number($health['temperature']) : $summary['temperature'];
                $rows = self::rows(self::object($data, 'nvme_self_test_log'));
                $summary['selftest_count'] = count($rows);
                $summary['selftest_head'] = [];
                foreach (array_slice($rows, 0, 3) as $row) {
                    $summary['selftest_head'][] = [self::string(self::object($row, 'self_test_code')['string'] ?? null),
                        self::nonnegativeNumber($row['power_on_hours'] ?? null), self::string(self::object($row, 'self_test_result')['string'] ?? null)];
                }
                if ($rows) {
                    $result = self::object($rows[0], 'self_test_result');
                    $code = self::nonnegativeNumber($result['value'] ?? null);
                    $summary['selftest_latest'] = ['type' => self::string(self::object($rows[0], 'self_test_code')['string'] ?? null),
                        'passed' => $code !== null && $code == 0, 'status' => self::string($result['string'] ?? null),
                        'lifetime_hours' => self::nonnegativeNumber($rows[0]['power_on_hours'] ?? null)];
                }
            }
            $summary['available'] = true;
        } catch (\InvalidArgumentException $e) {
            $summary['available'] = false;
        }
        return $summary;
    }

    /**
     * Exact Python classify rules for normalized summaries and test evidence.
     * Call evaluate() for untrusted/missing inputs and overall completion checks.
     *
     * @return array{grade:string,reasons:array<int,string>,placement:string}
     */
    public static function classify(array $before, array $after, array $selftests, ?array $surface, string $plan, string $kind): array
    {
        if (!isset(self::PLANS[$plan])) throw new \InvalidArgumentException('Unknown test plan');
        $reasons = [];
        $rank = 0;
        $worsen = static function (int $severity, string $reason) use (&$rank, &$reasons): void {
            $rank = max($rank, $severity);
            $reasons[] = $reason;
        };
        if (empty($after['available']) || empty($before['available'])) {
            return self::unknown('SMART illisible : aucun verdict possible');
        }
        foreach ($selftests as $test) {
            if ($test === null || ($test['status'] ?? null) === 'interrupted') {
                return self::unknown('autodiagnostic interrompu ou absent');
            }
        }
        if ($surface !== null && ($surface['status'] ?? null) !== 'completed'
            && !($surface['errors'] ?? 0) && !($surface['bad_blocks'] ?? 0)) {
            return self::unknown('test de surface non terminé (' . self::pythonString($surface['status'] ?? null) . ')');
        }
        if (($after['health_passed'] ?? null) === false) $worsen(3, 'SMART global en échec');
        $supported = array_filter($selftests, static fn(array $t): bool => ($t['status'] ?? null) !== 'unsupported');
        if (!$supported) {
            if ($surface === null) {
                return self::unknown('autodiagnostic non pris en charge et aucun test de surface demandé');
            }
            $worsen(0, 'autodiagnostic non lancé ou non détecté : verdict fondé sur SMART et le test de surface');
            $latest = $after['selftest_latest'] ?? null;
            if ($latest && ($latest['passed'] ?? null) === false) {
                $worsen(3, 'le dernier autodiagnostic du journal du disque est en échec : ' . self::pythonString($latest['status'] ?? null));
            }
        }
        foreach ($supported as $test) {
            if (($test['passed'] ?? null) !== true) {
                $worsen(3, 'autodiagnostic ' . self::pythonString($test['type'] ?? null)
                    . ' non réussi : ' . self::pythonString($test['status'] ?? null));
            }
        }
        if ($surface !== null && (($surface['errors'] ?? 0) > 0 || ($surface['bad_blocks'] ?? 0) > 0)) {
            $worsen(3, sprintf('test de surface : %d erreur(s), %d bloc(s) défectueux', $surface['errors'] ?? 0, $surface['bad_blocks'] ?? 0));
        }
        if ($surface !== null && !empty($surface['stalls'])) {
            $worsen(1, sprintf('ralentissement ou blocage détecté sur %d tranche(s) de 10 %%', count($surface['stalls'])));
        }
        $a = $after['attrs'];
        $b = $before['attrs'];
        $realloc = $a['reallocated'] ?? 0;
        $reallocBefore = $b['reallocated'] ?? 0;
        if ($realloc > $reallocBefore) {
            $worsen(3, sprintf('secteurs réalloués en hausse pendant le test : %d → %d', $reallocBefore, $realloc));
        } elseif ($realloc > self::REALLOCATED_REJECTED) {
            $worsen(3, sprintf('%d secteurs réalloués', $realloc));
        } elseif ($realloc > self::REALLOCATED_TOLERATED) {
            $worsen(2, sprintf('%d secteurs réalloués (stables)', $realloc));
        } elseif ($realloc > 0) {
            $worsen(1, sprintf('%d secteur(s) réalloué(s) (stables)', $realloc));
        }
        foreach (['pending' => 'secteurs en attente', 'offline_uncorrectable' => 'secteurs non corrigeables hors ligne'] as $key => $label) {
            if (($a[$key] ?? 0) > 0) $worsen(3, sprintf('%d %s', $a[$key], $label));
        }
        if (($a['reported_uncorrect'] ?? 0) > ($b['reported_uncorrect'] ?? 0)) {
            $worsen(3, 'erreurs non corrigées signalées en hausse');
        } elseif (($a['reported_uncorrect'] ?? 0) > 0) {
            $worsen(2, sprintf('%d erreur(s) non corrigée(s) signalée(s), stables', $a['reported_uncorrect']));
        }
        if (($a['spin_retry'] ?? 0) >= self::SPIN_RETRY_DEGRADED) {
            $worsen(2, sprintf('tentatives de démarrage du plateau : %d', $a['spin_retry']));
        } elseif (($a['spin_retry'] ?? 0) > 0) {
            $worsen(1, sprintf('tentatives de démarrage du plateau : %d', $a['spin_retry']));
        }
        $crc = $a['crc_errors'] ?? 0;
        $crcBefore = $b['crc_errors'] ?? 0;
        if ($crc > $crcBefore) {
            $worsen(2, sprintf("erreurs CRC d'interface en hausse pendant le test : %d → %d (câble ou backplane défaillant maintenant)", $crcBefore, $crc));
        } elseif ($crc > 0) {
            $worsen(0, sprintf("%d erreur(s) CRC d'interface, stables pendant le test (compteur cumulé à vie : un câble remplacé ne le remet pas à zéro)", $crc));
        }
        if (($a['command_timeout'] ?? 0) > 0) $worsen(1, sprintf('%d délai(s) de commande dépassé(s)', $a['command_timeout']));
        $unreadable = array_values(array_filter($after['ata_errors'] ?? [], static fn(array $e): bool => in_array($e['kind'], ['UNC', 'WP'], true)));
        if (($after['ata_error_count'] ?? 0) > ($before['ata_error_count'] ?? 0)) {
            $worsen(3, "journal d'erreurs ATA en hausse pendant le test");
        } elseif ($unreadable) {
            $latest = max(array_map(static fn(array $e) => $e['hours'] ?: 0, $unreadable));
            if ($after['power_on_hours'] && $after['power_on_hours'] - $latest <= self::RECENT_UNREADABLE_HOURS) {
                $worsen(3, sprintf('erreur de secteur (UNC/WP) il y a moins de %d h de fonctionnement', self::RECENT_UNREADABLE_HOURS));
            } else {
                $worsen(2, sprintf('%d erreur(s) de secteur (UNC/WP) dans le journal ATA, la dernière à %d h', count($unreadable), $latest));
            }
        } elseif (($after['ata_error_count'] ?? 0) > 0) {
            $hours = array_map(static fn(array $e) => $e['hours'], array_filter($after['ata_errors'] ?? [], static fn(array $e): bool => $e['hours'] !== null));
            if ($hours && $after['power_on_hours'] && $after['power_on_hours'] - max($hours) > self::RECENT_UNREADABLE_HOURS) {
                $worsen(0, sprintf('%d entrée(s) anciennes dans le journal ATA, la dernière à %d h de fonctionnement', $after['ata_error_count'], max($hours)));
            } else {
                $worsen(1, sprintf("%d entrée(s) dans le journal d'erreurs ATA, âge inconnu ou récent", $after['ata_error_count']));
            }
        }
        $n = $after['nvme'];
        $nb = $before['nvme'];
        if ($n) {
            if (($n['critical_warning'] ?? 0) != 0) $worsen(3, 'avertissement critique NVMe ' . self::pythonString($n['critical_warning']));
            if (($n['media_errors'] ?? 0) > ($nb['media_errors'] ?? 0)) {
                $worsen(3, 'erreurs de média NVMe en hausse');
            } elseif (($n['media_errors'] ?? 0) > 0) {
                $worsen(2, sprintf('%d erreur(s) de média NVMe (stables)', $n['media_errors']));
            }
            if (isset($n['available_spare'], $n['available_spare_threshold']) && $n['available_spare'] <= $n['available_spare_threshold']) {
                $worsen(3, 'réserve NVMe sous le seuil');
            }
            if (($n['percentage_used'] ?? 0) >= 80) {
                $worsen(2, 'usure NVMe : ' . self::pythonString($n['percentage_used']) . ' %');
            } elseif (($n['percentage_used'] ?? 0) >= 30) {
                $worsen(1, 'usure NVMe : ' . self::pythonString($n['percentage_used']) . ' %');
            }
        }
        $life = $after['ssd_life_left'];
        if ($kind === 'ssd' && $life !== null) {
            if ($life < 30) $worsen(2, 'durée de vie restante SSD : ' . self::pythonString($life) . ' %');
            elseif ($life < 70) $worsen(1, 'durée de vie restante SSD : ' . self::pythonString($life) . ' %');
        }
        if (($after['power_on_hours'] ?? 0) > self::HOURS_FIVE_YEARS) {
            $worsen(1, sprintf('%d h de fonctionnement (plus de 5 ans)', $after['power_on_hours']));
        }
        if ($rank === 0 && self::PLANS[$plan] === 'smart') {
            $worsen(1, "seul l'autodiagnostic du disque a tourné : aucune lecture ni écriture complète par l'hôte");
        }
        $grade = 'ABCD'[$rank];
        return ['grade' => $grade, 'reasons' => $reasons ?: ['aucun défaut relevé'], 'placement' => self::PLACEMENT[$grade]];
    }

    private static function unknown(string $reason): array
    {
        return ['grade' => '?', 'reasons' => [$reason], 'placement' => self::PLACEMENT['?']];
    }

    private static function pythonString(mixed $value): string
    {
        return $value === null ? 'None' : (is_bool($value) ? ($value ? 'True' : 'False') : (string)$value);
    }

    private static function counter(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || is_int($value) || (is_float($value) && is_finite($value))) return $value;
        throw new \InvalidArgumentException('Invalid SMART numeric value');
    }

    private static function nonnegativeNumber(mixed $value): int|float|null
    {
        $number = self::number($value);
        if ($number !== null && $number < 0) throw new \InvalidArgumentException('Invalid negative SMART counter');
        return $number;
    }

    private static function boolean(mixed $value): ?bool
    {
        if ($value === null || is_bool($value)) return $value;
        throw new \InvalidArgumentException('Invalid SMART boolean value');
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null || is_string($value)) return $value;
        throw new \InvalidArgumentException('Invalid SMART string value');
    }

    private static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) throw new \InvalidArgumentException('Invalid SMART object');
        return $value;
    }

    private static function rows(array $data): array
    {
        $rows = self::object($data, 'table');
        if (!array_is_list($rows)) throw new \InvalidArgumentException('Invalid SMART table');
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \InvalidArgumentException('Invalid SMART row');
        }
        return $rows;
    }
}
