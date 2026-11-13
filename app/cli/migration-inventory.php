<?php
declare(strict_types=1);

/**
 * Read-only inventory of a stopped SQLite instance. This does not create a
 * backup, stop a service, transfer data or authorize a migration.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Util/MigrationInventory.php';

use Admin\Util\MigrationInventory;

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function hasSidecar(string $path): bool
{
    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        $sidecar = $path . $suffix;
        clearstatcache(true, $sidecar);
        if (file_exists($sidecar) || is_link($sidecar)) {
            return true;
        }
    }
    return false;
}

$usage = 'Usage : php migration-inventory.php --confirm-stopped --db /chemin/absolu/base.sqlite';
if ($argc === 2 && $argv[1] === '--help') {
    fwrite(STDOUT, $usage . "\n");
    exit(0);
}
if ($argc !== 4 || $argv[1] !== '--confirm-stopped' || $argv[2] !== '--db') {
    fail($usage);
}
$input = $argv[3];
if ($input === '' || $input[0] !== '/' || str_contains($input, "\0") || is_link($input)) {
    fail('Une base SQLite existante, absolue et sans lien symbolique est requise.');
}
$path = realpath($input);
if ($path === false || !is_file($path) || !is_readable($path)) {
    fail('La base SQLite est absente ou illisible ; aucun fichier n’a été créé.');
}
if (hasSidecar($path)) {
    fail('Un journal SQLite existe encore ; arrêter proprement la source avant inspection.');
}
if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
    fail('L’ouverture SQLite en lecture seule n’est pas disponible.');
}

try {
    // After the operator confirms a stopped source and sidecars are refused,
    // immutable mode avoids creating WAL/SHM files for a WAL-mode database.
    $uri = 'file:' . str_replace('%2F', '/', rawurlencode($path)) . '?mode=ro&immutable=1';
    $pdo = new PDO('sqlite:' . $uri, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);
    $pdo->exec('PRAGMA query_only = ON');
    $pdo->beginTransaction();
    if ($pdo->query('PRAGMA quick_check(1)')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
        throw new RuntimeException('Contrôle SQLite non concluant.');
    }
    $counts = MigrationInventory::inspect($pdo);
    if (hasSidecar($path)) {
        throw new RuntimeException('Un journal SQLite est apparu.');
    }
    $pdo->commit();
    $pdo = null;
    if (hasSidecar($path)) {
        fail('Un journal SQLite est apparu pendant l’inspection ; résultat refusé.');
    }
    fwrite(STDOUT, json_encode([
        'format' => 'pxe-tools-migration-inventory',
        'version' => 1,
        'counts' => $counts,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    // Paths, database values and raw database errors must not enter logs.
    fail('Inspection impossible : base non conforme, illisible ou intégrité non vérifiée.');
}
