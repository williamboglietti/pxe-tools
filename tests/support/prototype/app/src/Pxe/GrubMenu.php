<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Pxe;

use InvalidArgumentException;

/** Render an explicit manual UEFI boot menu; never writes or starts anything. */
final class GrubMenu
{
    public static function renderManualInstaller(string $serverIp, string $kernelPath, string $initrdPath): string
    {
        if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new InvalidArgumentException('invalid_server_ip');
        foreach ([$kernelPath, $initrdPath] as $path) {
            if (preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D', $path) !== 1
                || in_array('..', explode('/', $path), true)) throw new InvalidArgumentException('invalid_boot_path');
        }
        $base = '(tftp,' . $serverIp . ')';
        return implode("\n", [
            'set default=1',
            'set timeout=10',
            '',
            "menuentry 'Debian installer (manual)' {",
            '  linux ' . $base . $kernelPath,
            '  initrd ' . $base . $initrdPath,
            '}',
            '',
            "menuentry 'Return to firmware' {",
            '  exit',
            '}',
            '',
        ]);
    }
}
