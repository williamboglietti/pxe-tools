<?php
declare(strict_types=1);

require __DIR__ . '/../../app/src/Db.php';
require __DIR__ . '/../../app/src/Auth.php';
require __DIR__ . '/../../app/src/InitGuard.php';
require __DIR__ . '/../../app/src/Models/Setting.php';

$dir = sys_get_temp_dir() . '/pxe-first-visit-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create fixture');
$db = $dir . '/absent.sqlite';
putenv('PXE_DB_DRIVER=sqlite');
putenv('PXE_DB_PATH=' . $db);
putenv('AUTH_MODE=local');
try {
    if (\Admin\InitGuard::state() !== \Admin\InitGuard::STATE_DB_PENDING || file_exists($db)) {
        throw new RuntimeException('First visit created a database or skipped setup');
    }
    \Admin\Models\Setting::get('db_init_done');
    if (file_exists($db)) throw new RuntimeException('Setup page created a database');
    if (!\Admin\Db::testConnection(['driver' => 'sqlite', 'path' => $db])['ok'] || file_exists($db)) {
        throw new RuntimeException('Connection test created a database or failed');
    }
    touch($db);
    putenv('PXE_DB_DRIVER');
    \Admin\Db::resetForTests();
    $before = hash_file('sha256', $db);
    $result = \Admin\Db::testConnection(['driver' => 'sqlite', 'path' => $db]);
    if (empty($result['deferred']) || hash_file('sha256', $db) !== $before || file_exists($db . '-wal')) {
        throw new RuntimeException('Connection check modified the unconfigured database');
    }
    try {
        \Admin\Db::get();
        throw new RuntimeException('Unconfigured database was opened');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Base SQLite indisponible') throw $error;
    }
    \Admin\Db::resetForTests();
    \Admin\Db::useWorkerSqlite($db);
    if ((int)\Admin\Db::get()->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('Explicit worker database unavailable');
    }
    echo "OK first visit preserves absent database\n";
} finally {
    \Admin\Db::resetForTests();
    if (file_exists($db)) unlink($db);
    rmdir($dir);
}
