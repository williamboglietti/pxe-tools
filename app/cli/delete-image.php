<?php
declare(strict_types=1);
// The worker runs this reservation process as the unprivileged web account.
if (PHP_SAPI !== 'cli' || $argc !== 4 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])
    || (int)$argv[1] <= 0 || (int)$argv[2] <= 0
    || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    fwrite(STDERR, "Invalid image deletion request.\n"); exit(1);
}
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) require_once $file;
});
try {
    \Admin\Db::useWorkerSqlite($argv[3]);
    \Admin\Models\OsImage::finishQueuedDelete((int)$argv[1], (int)$argv[2], static function (): void {
        if (fwrite(STDOUT, "READY\n") === false || !fflush(STDOUT) || trim((string)fgets(STDIN)) !== 'COMMIT') {
            throw new RuntimeException('Worker did not confirm staging.');
        }
    });
    fwrite(STDOUT, "COMMITTED\n"); fflush(STDOUT);
} catch (\Throwable $error) {
    fwrite(STDERR, "Image deletion refused; data kept for inspection.\n"); exit(1);
}
