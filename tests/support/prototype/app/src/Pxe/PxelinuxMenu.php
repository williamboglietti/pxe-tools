<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Pxe;

use InvalidArgumentException;

/** Render a BIOS HTTP-boot menu for lpxelinux.0; never writes or starts anything. */
final class PxelinuxMenu
{
    public static function renderManualInstaller(string $serverIp, string $kernelPath, string $initrdPath): string
    {
        if (!filter_var($serverIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new InvalidArgumentException('invalid_server_ip');
        foreach ([$kernelPath, $initrdPath] as $path) {
            if (preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*$~D', $path) !== 1
                || in_array('..', explode('/', $path), true)) throw new InvalidArgumentException('invalid_boot_path');
        }
        $base = 'http://' . $serverIp;
        return implode("\n", [
            'UI menu.c32',
            'PROMPT 0',
            'TIMEOUT 100',
            'DEFAULT local',
            'MENU TITLE PXE Tools',
            '',
            'LABEL installer',
            '  MENU LABEL Debian installer (manual)',
            '  KERNEL ' . $base . $kernelPath,
            '  APPEND initrd=' . $base . $initrdPath,
            '  IPAPPEND 2',
            '',
            'LABEL local',
            '  MENU LABEL Boot from local disk',
            '  LOCALBOOT 0',
            '',
        ]);
    }

    /** Experimental discovery is explicitly selected and never installs a system. */
    public static function renderDiscovery(string $serverIp): string
    {
        $menu = self::renderManualInstaller($serverIp, '/netboot/debian/linux', '/netboot/debian/initrd.gz');
        $base = 'http://' . $serverIp;
        $entry = implode("\n", [
            'LABEL discovery',
            '  MENU LABEL Hardware discovery (experimental)',
            '  KERNEL ' . $base . '/netboot/debian/linux',
            '  APPEND initrd=' . $base . '/netboot/debian/discovery-initrd.gz auto=true priority=critical url=' . $base . '/discovery/preseed.cfg',
            '  IPAPPEND 2',
            '',
        ]);
        return str_replace('LABEL local', $entry . 'LABEL local', $menu);
    }
}
