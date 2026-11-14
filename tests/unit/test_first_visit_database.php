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
    echo "OK first visit preserves absent database\n";
} finally {
    if (file_exists($db)) unlink($db);
    rmdir($dir);
}
