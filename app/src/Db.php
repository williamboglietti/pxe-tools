<?php
declare(strict_types=1);

namespace Admin;

use PDO;
use RuntimeException;

/**
 * Db — singleton PDO driver-aware (SQLite / MySQL / MariaDB).
 *
 * Chaîne de priorité config :
 *   1. /etc/pxe-tools/db.conf (JSON, source de vérité — wizard /init/db ou Ansible)
 *   2. Env vars PXE_DB_* (déploiement headless — PXE_DB_DRIVER + creds)
 *   3. Fallback legacy SQLite : PXE_DB_PATH (anciennement ORCHESTRATOR_DB) ou défaut
 *
 * Helpers driver-aware utilisés par les Models pour les patterns SQL non
 * portables entre SQLite et MySQL :
 *   - upsert($table, $keys, $values)
 *   - concat($col)         → COALESCE(col,'')||?  ou  CONCAT(IFNULL(col,''),?)
 *   - unixEpoch($expr)     → strftime('%s',expr)  ou  UNIX_TIMESTAMP(expr)
 *   - nowEpoch()
 *   - quoteIdent($name)    → "col"  ou  `col`  (pour mots réservés MySQL comme `key`)
 *   - columnExists($table, $col)
 */
final class Db
{
    public const CONFIG_PATH = '/etc/pxe-tools/db.conf';
    public const DEFAULT_SQLITE_PATH = '/var/lib/pxe-tools/pxe-tools.sqlite';
    public const LEGACY_SQLITE_PATH  = '/var/lib/pxe-tools/orchestrator.sqlite';

    private static ?PDO $instance = null;
    private static ?array $config = null;
    private static bool $workerSqlite = false;

    /** Pin a worker child to the SQLite database already selected by its parent. */
    public static function useWorkerSqlite(string $path): void
    {
        if (PHP_SAPI !== 'cli' || self::$instance !== null || self::$config !== null
            || $path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new RuntimeException('Configuration de base worker invalide.');
        }
        self::$config = self::normalizeConfig(['driver' => 'sqlite', 'path' => $path]);
        self::$workerSqlite = true;
    }

    /**
     * Lit la config DB selon la chaîne de priorité (cf. docblock de la classe).
     */
    public static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        // 1. db.conf (source de vérité posée par wizard /init/db ou Ansible)
        if (file_exists(self::CONFIG_PATH) || is_link(self::CONFIG_PATH)) {
            $raw = @file_get_contents(self::CONFIG_PATH);
            $cfg = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($cfg) || !isset($cfg['driver']) || !is_string($cfg['driver'])
                || !is_string($raw) || !self::hasSingleTopLevelDriver($raw)) {
                throw new RuntimeException('Configuration de base invalide ou ambiguë ; fichier et données conservés.');
            }
            self::$config = self::normalizeConfig($cfg);
            return self::$config;
        }

        // 2. Env vars PXE_DB_* (déploiement headless — Phase I)
        $envDriver = strtolower((string)(getenv('PXE_DB_DRIVER') ?: ''));
        if (in_array($envDriver, ['sqlite', 'mysql', 'mariadb'], true)) {
            if ($envDriver === 'sqlite') {
                self::$config = self::normalizeConfig([
                    'driver' => 'sqlite',
                    'path'   => getenv('PXE_DB_PATH') ?: self::DEFAULT_SQLITE_PATH,
                ]);
            } else {
                self::$config = self::normalizeConfig([
                    'driver'   => $envDriver,
                    'host'     => getenv('PXE_DB_HOST')     ?: 'localhost',
                    'port'     => (int)(getenv('PXE_DB_PORT') ?: 3306),
                    'database' => getenv('PXE_DB_DATABASE') ?: 'pxe_tools',
                    'username' => getenv('PXE_DB_USERNAME') ?: 'pxe_tools',
                    'password' => getenv('PXE_DB_PASSWORD') ?: '',
                    'prefix'   => getenv('PXE_DB_PREFIX')   ?: '',
                ]);
            }
            return self::$config;
        }

        // 3. Fallback legacy SQLite. PXE_DB_PATH > ORCHESTRATOR_DB > path historique > défaut.
        $legacyPath = getenv('PXE_DB_PATH')
            ?: getenv('ORCHESTRATOR_DB')   // legacy 2026-05 et avant
            ?: (is_file(self::LEGACY_SQLITE_PATH) ? self::LEGACY_SQLITE_PATH : self::DEFAULT_SQLITE_PATH);
        self::$config = [
            'driver' => 'sqlite',
            'path'   => $legacyPath,
        ];
        return self::$config;
    }

    /** Reject duplicate top-level driver keys, including JSON-escaped names. */
    private static function hasSingleTopLevelDriver(string $raw): bool
    {
        $depth = 0;
        $count = 0;
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '"') {
                $start = $i;
                for ($i++; $i < $length; $i++) {
                    if ($raw[$i] === '\\') { $i++; continue; }
                    if ($raw[$i] === '"') break;
                }
                if ($i >= $length) return false;
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $raw[$next])) $next++;
                if ($depth === 1 && $next < $length && $raw[$next] === ':'
                    && json_decode(substr($raw, $start, $i - $start + 1)) === 'driver') {
                    $count++;
                }
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            }
        }
        return $count === 1;
    }

    public static function driver(): string
    {
        return self::config()['driver'];
    }

    /** Fail closed for standalone PXE endpoints that require SQLite. */
    public static function sqliteRuntimePath(): string
    {
        if (file_exists(self::CONFIG_PATH) || is_link(self::CONFIG_PATH)) {
            $raw = @file_get_contents(self::CONFIG_PATH);
            $fileConfig = $raw === false ? null : json_decode($raw, true);
            if (!is_array($fileConfig) || !isset($fileConfig['driver']) || !is_string($fileConfig['driver'])
                || !is_string($raw) || !self::hasSingleTopLevelDriver($raw)
                || strtolower($fileConfig['driver']) !== 'sqlite') {
                throw new RuntimeException('Configuration SQLite indisponible');
            }
            $path = array_key_exists('path', $fileConfig) ? $fileConfig['path'] : self::DEFAULT_SQLITE_PATH;
        } else {
            $driver = strtolower((string)(getenv('PXE_DB_DRIVER') ?: ''));
            if ($driver !== '' && $driver !== 'sqlite') {
                throw new RuntimeException('Configuration SQLite indisponible');
            }
            $path = self::config()['path'] ?? null;
        }
        if (!is_string($path) || !str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Base SQLite indisponible');
        }
        return $path;
    }

    public static function get(bool $enableWal = true): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $cfg = self::config();
        // Routine reads must not create a database before setup. The wizard's
        // explicit connection test and schema initialization use connect().
        if ($cfg['driver'] === 'sqlite' && (!is_file($cfg['path']) ||
            ((!file_exists(self::CONFIG_PATH) && !is_link(self::CONFIG_PATH)) && !getenv('PXE_DB_DRIVER') && !self::$workerSqlite))) {
            throw new RuntimeException('Base SQLite indisponible');
        }
        $pdo = self::connect($cfg, $enableWal);
        self::$instance = $pdo;
        return $pdo;
    }

    /**
     * Test connexion sans toucher au singleton ni écrire db.conf.
     * Utilisé par le wizard /init/db pour le bouton "Tester la connexion".
     */
    public static function testConnection(array $cfg, bool $createSqlite = false): array
    {
        try {
            $cfg = self::normalizeConfig($cfg);
            if ($cfg['driver'] === 'sqlite' && !$createSqlite &&
                (!is_file($cfg['path']) || ((!file_exists(self::CONFIG_PATH) && !is_link(self::CONFIG_PATH)) && !getenv('PXE_DB_DRIVER')))) {
                if (!is_dir(dirname($cfg['path'])) || !is_writable(dirname($cfg['path']))) {
                    throw new RuntimeException('Répertoire SQLite inaccessible en écriture');
                }
                if (file_exists($cfg['path']) && !is_readable($cfg['path'])) {
                    throw new RuntimeException('Base SQLite illisible');
                }
                $pdo = new PDO('sqlite::memory:');
                $pdo->query('SELECT 1')->fetch();
                return ['ok' => true, 'error' => null, 'deferred' => true];
            }
            $pdo = self::connect($cfg);
            $pdo->query('SELECT 1')->fetch();
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Écriture atomique de db.conf (tmp + rename, chmod 0600).
     */
    public static function writeConfig(array $cfg): void
    {
        $cfg = self::normalizeConfig($cfg);
        $dir = dirname(self::CONFIG_PATH);
        if (!is_dir($dir)) {
            throw new RuntimeException("Répertoire $dir absent (créé au déploiement)");
        }
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp  = self::CONFIG_PATH . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("Échec écriture $tmp");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, self::CONFIG_PATH)) {
            @unlink($tmp);
            throw new RuntimeException('Échec rename atomique db.conf');
        }
        // Invalide le cache interne pour que les prochains appels relisent
        self::$config   = null;
        self::$workerSqlite = false;
        self::$instance = null;
    }

    /** Create an initial configuration atomically without replacing any file. */
    public static function writeInitialConfig(array $cfg): void
    {
        $cfg = self::normalizeConfig($cfg);
        $dir = dirname(self::CONFIG_PATH);
        if (!is_dir($dir)) throw new RuntimeException('Répertoire de configuration absent');
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = self::CONFIG_PATH . '.tmp.' . bin2hex(random_bytes(8));
        $stream = @fopen($tmp, 'xb');
        if ($stream === false) throw new RuntimeException('Impossible de créer la configuration temporaire');
        try {
            if (!chmod($tmp, 0600) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) {
                throw new RuntimeException('Impossible de préparer la configuration');
            }
            fclose($stream);
            $stream = null;
            // link() fails atomically if another process created the target.
            if (!@link($tmp, self::CONFIG_PATH)) {
                throw new RuntimeException('Configuration déjà présente ou impossible à créer ; elle est conservée');
            }
        } finally {
            if (is_resource($stream)) fclose($stream);
            @unlink($tmp);
        }
        self::$config = null;
        self::$instance = null;
    }

    // -------------------------------------------------------------------
    // Helpers SQL driver-aware
    // -------------------------------------------------------------------

    /**
     * Génère un UPSERT idempotent selon le driver.
     *   SQLite : INSERT INTO t (...) VALUES (...) ON CONFLICT(k) DO UPDATE SET ...
     *   MySQL  : INSERT INTO t (...) VALUES (...) ON DUPLICATE KEY UPDATE ...
     *
     * @param string   $table     Nom de la table
     * @param string[] $conflict  Colonnes formant la clé d'unicité (SQLite ON CONFLICT)
     * @param string[] $columns   Toutes les colonnes insérées (= placeholders ? dans le VALUES)
     */
    public static function upsert(string $table, array $conflict, array $columns): string
    {
        $tableQ = self::quoteIdent($table);
        $colsQ  = array_map([self::class, 'quoteIdent'], $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $cols   = implode(', ', $colsQ);

        // Colonnes à mettre à jour (toutes sauf les colonnes de conflit)
        $updateCols = array_diff($columns, $conflict);

        if (self::driver() === 'mysql') {
            $sets = [];
            foreach ($updateCols as $c) {
                $cQ = self::quoteIdent($c);
                $sets[] = "$cQ = VALUES($cQ)";
            }
            $setSql = $sets ? 'UPDATE ' . implode(', ', $sets) : 'IGNORE'; // fallback safe
            return "INSERT INTO $tableQ ($cols) VALUES ($placeholders) ON DUPLICATE KEY $setSql";
        }
        // SQLite
        $conflictQ = implode(', ', array_map([self::class, 'quoteIdent'], $conflict));
        $sets = [];
        foreach ($updateCols as $c) {
            $cQ = self::quoteIdent($c);
            $sets[] = "$cQ = excluded.$cQ";
        }
        $setSql = $sets ? 'UPDATE SET ' . implode(', ', $sets) : 'NOTHING';
        return "INSERT INTO $tableQ ($cols) VALUES ($placeholders) ON CONFLICT($conflictQ) DO $setSql";
    }

    /** Concat de log idempotent (utilisé pour append au log existant). */
    public static function concat(string $col): string
    {
        $colQ = self::quoteIdent($col);
        return self::driver() === 'mysql'
            ? "CONCAT(IFNULL($colQ, ''), ?)"
            : "COALESCE($colQ, '') || ?";
    }

    /** Expression SQL = epoch unix d'une expression date/datetime. */
    public static function unixEpoch(string $expr): string
    {
        return self::driver() === 'mysql'
            ? "UNIX_TIMESTAMP($expr)"
            : "strftime('%s', $expr)";
    }

    /** Expression SQL = epoch unix maintenant (now()). */
    public static function nowEpoch(): string
    {
        return self::driver() === 'mysql'
            ? "UNIX_TIMESTAMP()"
            : "strftime('%s', 'now')";
    }

    /** Quote un identifiant (table/colonne) selon le driver. */
    public static function quoteIdent(string $name): string
    {
        // Sécurité défensive : un identifiant légitime ne contient que [a-zA-Z0-9_]
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new RuntimeException("Identifiant SQL invalide : $name");
        }
        return self::driver() === 'mysql'
            ? "`$name`"
            : "\"$name\"";
    }

    /** Vérifie qu'une colonne existe dans une table (driver-aware). */
    public static function columnExists(string $table, string $column): bool
    {
        if (self::driver() === 'mysql') {
            $cfg = self::config();
            $stmt = self::get()->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ? AND column_name = ?
                 LIMIT 1'
            );
            $stmt->execute([$cfg['database'] ?? '', $table, $column]);
            return (bool)$stmt->fetchColumn();
        }
        // SQLite
        $stmt = self::get()->prepare("SELECT 1 FROM pragma_table_info(?) WHERE name = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------
    // Internes
    // -------------------------------------------------------------------

    private static function connect(array $cfg, bool $enableWal = true): PDO
    {
        if ($cfg['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $cfg['path']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
            // WAL : readers et writer ne se bloquent plus mutuellement (vital
            // ici car le worker python écrit en flot continu pendant un install
            // ansible et FPM/PHP doit pouvoir SELECT pour servir l'UI). Mode
            // persistant côté DB : ce PRAGMA est noop une fois posé, mais on
            // le re-applique systématiquement pour les DB fresh.
            if ($enableWal) {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }
            // 5s d'attente avant SQLITE_BUSY au lieu de l'erreur immédiate :
            // tolère un writer concurrent court (ex: append_install_log batch).
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return $pdo;
        }
        // External database support is not available in the public appliance.
        // Fail before opening any socket; retain the historical configuration
        // and data so that an administrator can plan a future migration.
        throw new RuntimeException('MySQL/MariaDB ne sont pas encore pris en charge ; configuration et données conservées.');


    }

    /** Normalise + valide la config (defaults port, driver mariadb → mysql, etc.). */
    private static function normalizeConfig(array $cfg): array
    {
        $driver = strtolower($cfg['driver'] ?? '');
        if ($driver === 'mariadb') {
            $driver = 'mysql';
        }
        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'path'   => $cfg['path'] ?? self::DEFAULT_SQLITE_PATH,
            ];
        }
        if ($driver === 'mysql') {
            return [
                'driver'   => 'mysql',
                'host'     => $cfg['host']     ?? 'localhost',
                'port'     => (int)($cfg['port'] ?? 3306),
                'database' => $cfg['database'] ?? '',
                'username' => $cfg['username'] ?? '',
                'password' => $cfg['password'] ?? '',
                'prefix'   => $cfg['prefix']   ?? '',
            ];
        }
        throw new RuntimeException("Driver DB inconnu : '{$cfg['driver']}'");
    }

    /** Pour tests : reset du cache interne. Ne pas utiliser en runtime normal. */
    public static function resetForTests(): void
    {
        self::$instance = null;
        self::$config   = null;
    }
}
