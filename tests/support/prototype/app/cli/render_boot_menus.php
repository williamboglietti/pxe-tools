<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

use Admin\Pxe\GrubMenu;
use Admin\Pxe\PxelinuxMenu;

require dirname(__DIR__) . '/src/Pxe/GrubMenu.php';
require dirname(__DIR__) . '/src/Pxe/PxelinuxMenu.php';

if (PHP_SAPI !== 'cli' || !($argc === 2 || ($argc === 3 && $argv[2] === '--discovery'))) {
    fwrite(STDERR, "Usage: php render_boot_menus.php SERVER_IPV4 [--discovery]\n");
    exit(2);
}
try {
    $kernel = '/netboot/debian/linux';
    $initrd = '/netboot/debian/initrd.gz';
    echo json_encode([
        'bios' => $argc === 3 ? PxelinuxMenu::renderDiscovery($argv[1]) : PxelinuxMenu::renderManualInstaller($argv[1], $kernel, $initrd),
        'uefi' => GrubMenu::renderManualInstaller($argv[1], $kernel, $initrd),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Could not render boot menus.\n");
    exit(1);
}
