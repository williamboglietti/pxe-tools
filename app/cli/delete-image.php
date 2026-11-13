<?php
declare(strict_types=1);
// Invoked only by the worker after validating the protected root and job.
if (PHP_SAPI !== 'cli' || $argc !== 3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])
    || (int)$argv[1] <= 0 || (int)$argv[2] <= 0) {
    fwrite(STDERR, "Invalid image deletion request.\n"); exit(1);
}
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) require_once $file;
});
try {
    \Admin\Models\OsImage::deleteWithFiles((int)$argv[1], (int)$argv[2]);
} catch (\Throwable $error) {
    fwrite(STDERR, "Image deletion refused; data kept for inspection.\n"); exit(1);
}
