<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Pxe;

use Admin\Util\NetworkConfiguration;
use InvalidArgumentException;

/** Renders a dedicated configuration; never writes files or controls services. */
final class DnsmasqConfiguration
{
    /**
     * @param array<string,mixed> $input
     * @param list<array{name:string,ipv4:list<string>}> $interfaces
     */
    public static function render(string $mode, array $input, array $interfaces): string
    {
        if ($mode === 'disabled') return "port=0\nno-dhcp-interface=*\n";
        if (!in_array($mode, ['tftp', 'proxy'], true)) throw new InvalidArgumentException('invalid_mode');
        // Mode is the only source of truth for proxy enablement.
        $input['dhcp_proxy_enabled'] = $mode === 'proxy';
        $network = NetworkConfiguration::validate($input, $interfaces);
        $root = $input['tftp_root'] ?? null;
        if (!is_string($root) || !preg_match('~^/(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*/)*[A-Za-z0-9_-][A-Za-z0-9_.-]*/*$~D', $root)
            || in_array('..', explode('/', $root), true)) throw new InvalidArgumentException('invalid_tftp_root');
        $root = rtrim($root, '/');
        $lines = ['port=0', 'interface=' . $network['pxe_interface'], 'bind-interfaces', 'enable-tftp=' . $network['pxe_interface'], 'tftp-root=' . $root, 'no-dhcpv6-interface=*'];
        if ($mode === 'tftp') {
            $lines[] = 'no-dhcpv4-interface=*';
        } else {
            $bios = self::bootFile($input['bios_bootfile'] ?? null);
            $uefi = self::bootFile($input['uefi_bootfile'] ?? null);
            $ip = $network['pxe_ip'];
            $lines[] = 'dhcp-range=' . $network['dhcp_proxy_network'] . ',proxy,' . $network['dhcp_proxy_netmask'];
            $lines[] = 'dhcp-match=set:bios,option:client-arch,0';
            $lines[] = 'dhcp-match=set:efi-x86,option:client-arch,7';
            $lines[] = 'dhcp-match=set:efi-x86-ebc,option:client-arch,9';
            $lines[] = 'dhcp-boot=tag:bios,' . $bios . ',,' . $ip;
            $lines[] = 'dhcp-boot=tag:efi-x86,' . $uefi . ',,' . $ip;
            $lines[] = 'dhcp-boot=tag:efi-x86-ebc,' . $uefi . ',,' . $ip;
            $lines[] = 'pxe-service=x86PC,"Network boot (BIOS)",' . $bios . ',' . $ip;
            $lines[] = 'pxe-service=x86-64_EFI,"Network boot (UEFI)",' . $uefi . ',' . $ip;
            $lines[] = 'pxe-service=BC_EFI,"Network boot (UEFI BC)",' . $uefi . ',' . $ip;
        }
        return implode("\n", $lines) . "\n";
    }

    private static function bootFile(mixed $name): string
    {
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,127}$/D', $name) !== 1) {
            throw new InvalidArgumentException('invalid_boot_file');
        }
        return $name;
    }
}
