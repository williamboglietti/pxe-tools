<?php
declare(strict_types=1);
namespace Admin\Util;

use InvalidArgumentException;

final class NetworkConfiguration
{
    /** @return array<string,string> Validated settings; does not mutate state. */
    public static function validate(array $post, array $interfaces): array
    {
        $iface = trim((string)($post['pxe_interface'] ?? ''));
        $ip = trim((string)($post['pxe_ip'] ?? ''));
        $proxy = isset($post['dhcp_proxy_enabled']);
        $override = isset($post['network_override']);
        if ($iface === '' || $ip === '') throw new InvalidArgumentException('missing');
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $iface)) throw new InvalidArgumentException('invalid_interface');
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new InvalidArgumentException('invalid_ip');
        $selected = null;
        foreach ($interfaces as $detected) if ($detected['name'] === $iface) $selected = $detected;
        if ($selected === null && !$override) throw new InvalidArgumentException('unknown_interface');
        $addresses = array_map(static fn(string $cidr): string => explode('/', $cidr)[0], $selected['ipv4'] ?? []);
        if (!$override && !in_array($ip, $addresses, true)) throw new InvalidArgumentException('interface_ip_mismatch');
        $subnet = $post['pxe_subnet'] ?? '';
        if (!is_string($subnet) || trim($subnet) === '') throw new InvalidArgumentException('missing_subnet');
        $accessNetwork = NetworkDetector::parseCidr(trim($subnet));
        if ($accessNetwork === null) throw new InvalidArgumentException('invalid_subnet');
        if (!$override && (ip2long($ip) & ip2long($accessNetwork['netmask'])) !== ip2long($accessNetwork['network'])) {
            throw new InvalidArgumentException('subnet_mismatch');
        }
        $values = ['pxe_interface' => $iface, 'pxe_ip' => $ip, 'dhcp_proxy_enabled' => $proxy ? 'on' : 'off',
            'pxe_subnet' => $accessNetwork['network'] . '/' . $accessNetwork['prefix'],
            'https_enabled' => isset($post['https_enabled']) ? 'on' : 'off', 'network_override' => $override ? 'on' : 'off'];
        if ($proxy) {
            $cidr = trim((string)($post['dhcp_proxy_cidr'] ?? ''));
            if ($cidr === '') throw new InvalidArgumentException('missing_proxy');
            $parsed = NetworkDetector::parseCidr($cidr);
            if ($parsed === null) throw new InvalidArgumentException('invalid_cidr');
            if (!$override && (ip2long($ip) & ip2long($parsed['netmask'])) !== ip2long($parsed['network'])) {
                throw new InvalidArgumentException('network_mismatch');
            }
            $values['dhcp_proxy_network'] = $parsed['network'];
            $values['dhcp_proxy_netmask'] = $parsed['netmask'];
        }
        return $values;
    }
}
