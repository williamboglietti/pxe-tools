<?php
declare(strict_types=1);
// SQLite trigger simulates a competing request inserting after the initial
// lookup but before this request's INSERT. No appliance state is accessed.
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Admin\\')) {
        require_once $root . '/app/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    }
});

use Admin\Controllers\WizardController;
use Admin\Db;
use Admin\Models\OsImage;

$database = tempnam(sys_get_temp_dir(), 'pxe-image-race-');
putenv('PXE_DB_DRIVER=sqlite');
putenv('PXE_DB_PATH=' . $database);
register_shutdown_function(static function () use ($database): void { @unlink($database); });
if (Db::config()['path'] !== $database) throw new RuntimeException('Expected isolated database');
$pdo = Db::get();
$pdo->exec(file_get_contents($root . '/app/db/schema.sqlite.sql'));
$pdo->exec("INSERT INTO catalog_entries(distro,version_full,arch,channel,extract_method) VALUES ('ubuntu','24.04','amd64','stable','direct')");
$pdo->exec("CREATE TRIGGER race_image BEFORE INSERT ON os_images WHEN NEW.status='pending' BEGIN
  INSERT INTO os_images(distro,version,arch,version_full,status,enabled,error_msg)
  VALUES(NEW.distro,NEW.version,NEW.arch,NEW.version_full,'downloading',0,'fixture preserved');
END");

if (getenv('PXE_SKIP_ENSURE_RACE') !== '1') {
    if (!OsImage::ensureForDistroVersion('ubuntu', '24.04', 'amd64')) {
        throw new RuntimeException('Competing image should count as already present');
    }
    $row = $pdo->query("SELECT status,enabled,error_msg FROM os_images WHERE distro='ubuntu'")->fetch(PDO::FETCH_ASSOC);
    if ($row !== ['status'=>'downloading', 'enabled'=>0, 'error_msg'=>'fixture preserved']) {
        throw new RuntimeException('Competing image was overwritten or missing');
    }
}

// Use the real catalog's documented offline fallback, without network access.
class OfflineHttps {
    public $context;
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool { return false; }
}
stream_wrapper_unregister('https');
stream_wrapper_register('https', OfflineHttps::class);
$method = new ReflectionMethod(WizardController::class, 'seedLatestImageForDistro');
$method->invoke(new WizardController(), $pdo, 'debian');
$row = $pdo->query("SELECT status,enabled,error_msg FROM os_images WHERE distro='debian'")->fetch(PDO::FETCH_ASSOC);
if ($row !== ['status'=>'downloading', 'enabled'=>1, 'error_msg'=>'fixture preserved']) {
    throw new RuntimeException('Concurrent discovery image did not retain active download');
}
$pdo->exec('DROP TRIGGER race_image');
$pdo->exec("UPDATE os_images SET status='downloaded',enabled=0,error_msg='completed fixture' WHERE distro='debian'");
$method->invoke(new WizardController(), $pdo, 'debian');
$row = $pdo->query("SELECT status,enabled,error_msg FROM os_images WHERE distro='debian'")->fetch(PDO::FETCH_ASSOC);
if ($row !== ['status'=>'downloaded', 'enabled'=>1, 'error_msg'=>'completed fixture']) {
    throw new RuntimeException('Completed image was unexpectedly reset');
}
$pdo->exec("UPDATE os_images SET status='failed',enabled=0,error_msg='retry fixture' WHERE distro='debian'");
$method->invoke(new WizardController(), $pdo, 'debian');
$row = $pdo->query("SELECT status,enabled,error_msg FROM os_images WHERE distro='debian'")->fetch(PDO::FETCH_ASSOC);
if ($row !== ['status'=>'pending', 'enabled'=>1, 'error_msg'=>null]) {
    throw new RuntimeException('Failed image was not prepared for retry');
}
stream_wrapper_restore('https');
$pdo->exec("CREATE TRIGGER fail_seed BEFORE INSERT ON os_images WHEN NEW.distro='debian' BEGIN SELECT RAISE(ABORT,'fixture unrelated failure'); END");
$pdo->exec("DELETE FROM os_images WHERE distro='debian'");
try {
    $method->invoke(new WizardController(), $pdo, 'debian');
    throw new RuntimeException('Unrelated database errors must not be swallowed');
} catch (PDOException $error) {
    if (!str_contains($error->getMessage(), 'fixture unrelated failure')) throw $error;
}
$pdo->exec("INSERT INTO catalog_entries(distro,version_full,arch,channel,extract_method) VALUES ('debian','1.0','amd64','stable','direct')");
try {
    OsImage::ensureForDistroVersion('debian', '1.0', 'amd64');
    throw new RuntimeException('Unrelated picker database errors must not be swallowed');
} catch (PDOException $error) {
    if (!str_contains($error->getMessage(), 'fixture unrelated failure')) throw $error;
}
echo "OK concurrent image creation preserves state and rejects unrelated errors\n";
