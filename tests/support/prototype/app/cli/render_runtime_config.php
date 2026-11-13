<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

use Admin\Pxe\DnsmasqConfiguration;

require dirname(__DIR__) . '/src/Util/NetworkConfiguration.php';
require dirname(__DIR__) . '/src/Pxe/DnsmasqConfiguration.php';

// Only an internal caller should supply this JSON. The service supervisor
// separately checks that the address is currently on the chosen interface.
if (PHP_SAPI !== 'cli' || $argc !== 1) exit(2);
try {
    $raw = stream_get_contents(STDIN, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) throw new InvalidArgumentException('input');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('input');
    $mode = $input['mode'] ?? null;
    $interface = $input['pxe_interface'] ?? null;
    $ip = $input['pxe_ip'] ?? null;
    if (!is_string($mode) || !in_array($mode, ['tftp', 'proxy'], true)
        || !is_string($interface) || !is_string($ip)) throw new InvalidArgumentException('input');
    $input['bios_bootfile'] = 'lpxelinux.0';
    $input['uefi_bootfile'] = 'bootx64.efi';
    echo DnsmasqConfiguration::render($mode, $input, [['name'=>$interface, 'ipv4'=>[$ip . '/32']]]);
} catch (Throwable $error) {
    fwrite(STDERR, "Invalid runtime configuration.\n");
    exit(1);
}
