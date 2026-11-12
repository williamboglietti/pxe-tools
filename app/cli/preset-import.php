<?php
declare(strict_types=1);

/**
 * CLI utility — import d'un preset depuis un fichier JSON.
 * Réutilise la validation + upsert du PresetController (DRY).
 *
 * Usage :
 *   php /app/cli/preset-import.php --json-file=/path/to/preset.json
 *   cat preset.json | php /app/cli/preset-import.php --stdin
 *   php /app/cli/preset-import.php --json-file=/path/to/preset.json --validate-only
 *   --validate-only performs the same validation and prints a credential-free
 *   review, without writing a preset or scheduling an operation.
 *
 * Exit codes :
 *   0  succès — stdout = {"action":"updated"|"created","id":N,"name":"..."}
 *   1  erreur de validation ou erreur DB
 *   2  erreur d'usage (args manquants, fichier illisible, JSON invalide)
 *
 * Sécurité : refuse de tourner via SAPI fpm/cgi (CLI only) — au cas où ce
 * fichier finirait routé par nginx vers PHP-FPM.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, 6));
    $file = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($file)) require_once $file;
});

$opts = getopt('', ['json-file:', 'stdin', 'validate-only']);
$json = '';
if (isset($opts['json-file'])) {
    $path = (string)$opts['json-file'];
    if (!is_readable($path)) {
        fwrite(STDERR, "ERROR: file not readable: $path\n");
        exit(2);
    }
    $json = (string) file_get_contents($path);
} elseif (array_key_exists('stdin', $opts)) {
    $json = (string) stream_get_contents(STDIN);
} else {
    fwrite(STDERR, "Usage: php " . basename(__FILE__) . " --json-file=PATH | --stdin\n");
    exit(2);
}

if (trim($json) === '') {
    fwrite(STDERR, "ERROR: empty JSON input\n");
    exit(2);
}

$payload = json_decode($json, true);
if (!is_array($payload)) {
    fwrite(STDERR, "ERROR: invalid JSON: " . json_last_error_msg() . "\n");
    exit(2);
}

try {
    if (array_key_exists('validate-only', $opts)) {
        $review = \Admin\Controllers\PresetController::validateFromArray($payload);
        if ($review['errors'] !== []) throw new \RuntimeException('Validation failed: ' . implode(' ; ', $review['errors']));
        $result = ['action' => 'validated', 'name' => $review['name'], 'review' => $review['review']];
    } else {
        $result = \Admin\Controllers\PresetController::importFromArray($payload);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
