<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

use Admin\Security\Administrator;

require dirname(__DIR__) . '/src/Security/Administrator.php';

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: provide password on stdin; php app/cli/init.php /absolute/new-database username\n");
    exit(2);
}
[$script, $path, $username] = $argv;
if (!str_starts_with($path, '/') || str_contains($path, "\0") || !is_dir(dirname($path))) {
    fwrite(STDERR, "An absolute path in an existing directory is required.\n");
    exit(2);
}
$parent = realpath(dirname($path));
$parentStat = $parent === false ? false : stat($parent);
if ($parentStat === false || !function_exists('posix_geteuid') || ($parentStat['mode'] & 0022) !== 0
    || $parentStat['uid'] !== posix_geteuid()) {
    fwrite(STDERR, "Database directory must be owned by the current user and not writable by others.\n");
    exit(2);
}
$basename = basename($path);
if ($basename === '.' || $basename === '..' || str_ends_with($path, '/')) {
    fwrite(STDERR, "A new database filename is required.\n");
    exit(2);
}
// Use the already-checked physical parent rather than a mutable parent symlink.
$path = rtrim($parent, '/') . '/' . $basename;
if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
    fwrite(STDERR, "Provide the password through a protected stdin pipe; do not type it into an echoed terminal.\n");
    exit(2);
}
$password = stream_get_contents(STDIN, 1025);
if (!is_string($password) || strlen($password) > 74) {
    fwrite(STDERR, "Invalid password input.\n");
    exit(2);
}
$password = preg_replace('/\r?\n\z/', '', $password);
if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")
    || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $username) !== 1) {
    fwrite(STDERR, "Invalid username or password.\n");
    exit(2);
}
umask(0077);
// Exclusive creation avoids replacing an existing database, symlink or other file.
$created = @fopen($path, 'x+b');
if ($created === false) {
    fwrite(STDERR, "Database already exists or cannot be created.\n");
    exit(1);
}
fclose($created);
try {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents(dirname(__DIR__) . '/db/schema.sqlite.sql');
    if ($schema === false) throw new RuntimeException('Cannot read schema');
    $db->exec($schema);
    (new Administrator($db))->createInitial($username, $password);
    fwrite(STDOUT, "Database and initial administrator created.\n");
} catch (Throwable $error) {
    // The exclusive file is deliberately left for inspection, never deleted implicitly.
    fwrite(STDERR, "Initialization failed; inspect the newly created file before retrying.\n");
    exit(1);
} finally {
    $password = '';
}
