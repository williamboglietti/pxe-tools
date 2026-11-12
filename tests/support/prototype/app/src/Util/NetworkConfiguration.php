<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Util;

use InvalidArgumentException;

/** Validate explicit network choices without changing the host or starting services. */
final class NetworkConfiguration
{
    /**
     * @param array{pxe_interface?:mixed,pxe_ip?:mixed,dhcp_proxy_enabled?:mixed,dhcp_proxy_cidr?:mixed} $input
     * @param list<array{name:string,ipv4:list<string>}> $interfaces Detected interfaces and their IPv4 CIDRs.
     * @return array<string,string>
     */
    public static function validate(array $input, array $interfaces): array
    {
        $interface = $input['pxe_interface'] ?? null;
        $ip = $input['pxe_ip'] ?? null;
        if (!is_string($interface) || !is_string($ip)) throw new InvalidArgumentException('missing');
        $interface = trim($interface);
        $ip = trim($ip);
        if ($interface === '' || $ip === '') throw new InvalidArgumentException('missing');
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $interface)) throw new InvalidArgumentException('invalid_interface');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new InvalidArgumentException('invalid_ip');
        $matches = array_values(array_filter($interfaces, static fn(array $entry): bool => ($entry['name'] ?? null) === $interface));
        if (count($matches) !== 1) throw new InvalidArgumentException('unknown_or_ambiguous_interface');
        $addresses = array_map(static fn(string $cidr): string => explode('/', $cidr, 2)[0], $matches[0]['ipv4'] ?? []);
        if (!in_array($ip, $addresses, true)) throw new InvalidArgumentException('interface_ip_mismatch');
        $enabled = $input['dhcp_proxy_enabled'] ?? false;
        if (!is_bool($enabled)) throw new InvalidArgumentException('invalid_proxy_selection');
        $values = ['pxe_interface' => $interface, 'pxe_ip' => $ip, 'dhcp_proxy_enabled' => $enabled ? 'on' : 'off'];
        if ($enabled) {
            $cidr = $input['dhcp_proxy_cidr'] ?? null;
            if (!is_string($cidr) || trim($cidr) === '') throw new InvalidArgumentException('missing_proxy');
            $parsed = self::parseCidr($cidr);
            if ($parsed === null || $parsed['prefix'] === 0) throw new InvalidArgumentException('invalid_cidr');
            if ((ip2long($ip) & ip2long($parsed['netmask'])) !== ip2long($parsed['network'])) {
                throw new InvalidArgumentException('network_mismatch');
            }
            $values['dhcp_proxy_network'] = $parsed['network'];
            $values['dhcp_proxy_netmask'] = $parsed['netmask'];
        }
        return $values;
    }

    /** @return array{network:string,netmask:string,prefix:int}|null */
    public static function parseCidr(string $input): ?array
    {
        $parts = explode('/', trim($input));
        if (count($parts) !== 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !ctype_digit($parts[1]) || strlen($parts[1]) > 2) return null;
        $prefix = (int)$parts[1];
        if ($prefix > 32) return null;
        $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1)) & 0xFFFFFFFF;
        return ['network' => long2ip(ip2long($parts[0]) & $mask), 'netmask' => long2ip($mask), 'prefix' => $prefix];
    }
}
