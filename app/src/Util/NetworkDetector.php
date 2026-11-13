<?php
declare(strict_types=1);

namespace Admin\Util;

/**
 * Détection automatique des interfaces réseau de l'host (vu depuis le container
 * en network_mode: host). Utilisé par le wizard d'init pour pré-remplir
 * `pxe_interface` et `pxe_ip`.
 *
 * Sources :
 *   - /sys/class/net/<iface>/address   → MAC
 *   - /sys/class/net/<iface>/operstate → up/down
 *   - /sys/class/net/<iface>/wireless/ → wifi (présence du dossier)
 *   - `ip -j -o addr show`             → liste IPv4 par interface (paquet iproute2)
 *
 * Filtres :
 *   - skip `lo`
 *   - skip `docker*`, `br-*`, `veth*` (Docker virtual bridges)
 */
final class NetworkDetector
{
    /**
     * @return array<int, array{name:string,mac:string,state:string,wireless:bool,ipv4:array<int,string>}>
     */
    public static function listInterfaces(): array
    {
        $sysClassNet = '/sys/class/net';
        if (!is_dir($sysClassNet)) {
            return [];
        }

        $ipv4ByIface = self::ipv4Map();
        $result = [];

        foreach (scandir($sysClassNet) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === 'lo') continue;
            if (str_starts_with($name, 'docker') ||
                str_starts_with($name, 'br-') ||
                str_starts_with($name, 'veth')) {
                continue;
            }
            $base = $sysClassNet . '/' . $name;
            if (!is_dir($base)) continue;

            $mac   = trim(@file_get_contents($base . '/address') ?: '');
            $state = trim(@file_get_contents($base . '/operstate') ?: 'unknown');
            $wireless = is_dir($base . '/wireless');

            $result[] = [
                'name'     => $name,
                'mac'      => $mac,
                'state'    => $state,
                'wireless' => $wireless,
                'ipv4'     => $ipv4ByIface[$name] ?? [],
            ];
        }

        // Tri : interfaces "up" en premier, puis ethernet avant wifi, puis alpha
        usort($result, function (array $a, array $b): int {
            if (($a['state'] === 'up') !== ($b['state'] === 'up')) {
                return $a['state'] === 'up' ? -1 : 1;
            }
            if ($a['wireless'] !== $b['wireless']) {
                return $a['wireless'] ? 1 : -1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * Retourne ['eth0' => ['192.0.2.10/24', ...], ...] via `ip -j addr show`.
     * On utilise `ip -j` (sans `-o`) car `-o` aplat le JSON et perd le `ifname`
     * au top level (chaque entry n'a alors que `addr_info` avec `dev` à
     * l'intérieur). Fallback : map vide si `ip` indisponible.
     *
     * @return array<string, array<int, string>>
     */
    private static function ipv4Map(): array
    {
        $out = @shell_exec('ip -j -4 addr show 2>/dev/null');
        if (!is_string($out) || $out === '') return [];

        $data = json_decode($out, true);
        if (!is_array($data)) return [];

        $map = [];
        foreach ($data as $iface) {
            // Avec -j -o : dev est dans addr_info ; avec -j seul : ifname top-level.
            // On supporte les deux pour robustesse.
            $name = (string)($iface['ifname'] ?? '');
            $ips  = [];
            foreach (($iface['addr_info'] ?? []) as $a) {
                if (($a['family'] ?? '') !== 'inet') continue;
                if ($name === '' && isset($a['dev'])) $name = (string)$a['dev'];
                $local = (string)($a['local'] ?? '');
                $plen  = (int)($a['prefixlen'] ?? 0);
                if ($local !== '') {
                    $ips[] = $local . '/' . $plen;
                }
            }
            if ($name !== '' && $ips !== []) $map[$name] = $ips;
        }
        return $map;
    }

    /**
     * Suggère l'pxe_ip depuis l'IP de l'interface choisie.
     * Prend la première IPv4 (sans le /CIDR).
     */
    public static function suggestOrchestratorIp(string $ifaceName): ?string
    {
        foreach (self::listInterfaces() as $iface) {
            if ($iface['name'] !== $ifaceName) continue;
            foreach ($iface['ipv4'] as $cidr) {
                return explode('/', $cidr, 2)[0];
            }
        }
        return null;
    }

    /**
     * Choisit la meilleure interface candidate pour PXE selon une priorité claire :
     *   1. Première Ethernet UP (idéal — câblée et active)
     *   2. Première UP (peut être Wi-Fi — fonctionnera mais Wi-Fi non recommandé pour PXE)
     *   3. Première Ethernet (down — sera signalée par un warning UI)
     *   4. Première de la liste (fallback ultime)
     *
     * Garantit que l'utilisateur tombe par défaut sur l'option la plus saine,
     * tout en laissant un fallback explicite si rien n'est UP.
     */
    public static function pickBestInterface(): ?string
    {
        $ifs = self::listInterfaces();
        if ($ifs === []) return null;

        foreach ($ifs as $i) {
            if ($i['state'] === 'up' && !$i['wireless']) return $i['name'];
        }
        foreach ($ifs as $i) {
            if ($i['state'] === 'up') return $i['name'];
        }
        foreach ($ifs as $i) {
            if (!$i['wireless']) return $i['name'];
        }
        return $ifs[0]['name'];
    }

    /**
     * Calcule l'adresse réseau + netmask + CIDR depuis le subnet de l'interface.
     * Ex: enp1s0 = 192.0.2.17/23 → [
     *   'network' => '192.0.2.0',
     *   'netmask' => '255.255.254.0',
     *   'prefix'  => 23,
     *   'cidr'    => '192.0.2.0/23',
     * ].
     *
     * @return array{network:string, netmask:string, prefix:int, cidr:string}|null
     */
    public static function suggestProxyNetwork(string $ifaceName): ?array
    {
        foreach (self::listInterfaces() as $iface) {
            if ($iface['name'] !== $ifaceName) continue;
            foreach ($iface['ipv4'] as $cidr) {
                $parts = explode('/', $cidr, 2);
                $ip   = $parts[0];
                $plen = isset($parts[1]) ? (int)$parts[1] : 24;
                if ($plen < 0 || $plen > 32) continue;
                $maskLong = $plen === 0 ? 0 : (~((1 << (32 - $plen)) - 1)) & 0xFFFFFFFF;
                $netLong  = ip2long($ip) & $maskLong;
                $network  = long2ip($netLong);
                $netmask  = long2ip($maskLong);
                return [
                    'network' => $network,
                    'netmask' => $netmask,
                    'prefix'  => $plen,
                    'cidr'    => $network . '/' . $plen,
                ];
            }
        }
        return null;
    }

    /**
     * Parse une notation CIDR ("192.0.2.0/23") en network + netmask + prefix.
     * Retourne null si le format est invalide.
     *
     * @return array{network:string, netmask:string, prefix:int}|null
     */
    public static function parseCidr(string $input): ?array
    {
        $input = trim($input);
        if (!str_contains($input, '/')) return null;
        [$ip, $plenStr] = explode('/', $input, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
        if (!preg_match('/^(?:0|[1-9]|[12][0-9]|3[0-2])$/D', $plenStr)) return null;
        $plen = (int)$plenStr;
        $maskLong = $plen === 0 ? 0 : (~((1 << (32 - $plen)) - 1)) & 0xFFFFFFFF;
        $netLong  = ip2long($ip) & $maskLong;
        return [
            'network' => long2ip($netLong),
            'netmask' => long2ip($maskLong),
            'prefix'  => $plen,
        ];
    }
}
