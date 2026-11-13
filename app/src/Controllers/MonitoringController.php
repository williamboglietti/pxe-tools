<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Models\Setting;
use Admin\Util\InstallMonitor;
use Admin\Util\NetworkDetector;

/**
 * Endpoints de supervision en lecture seule. Le préfixe /api/ est public : chaque endpoint
 * se protège lui-même (loopback ou sous-réseau PXE), sans session.
 */
final class MonitoringController
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** IPv4 uniquement : le serveur PXE désactive IPv6 (ipv6.disable=1). */
    public static function sourceAllowed(string $ip, string $pxeSubnet): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        if (str_starts_with($ip, '127.')) return true;
        $cidr = NetworkDetector::parseCidr($pxeSubnet);
        if ($cidr === null) return false;
        $mask = ip2long($cidr['netmask']);
        return (ip2long($ip) & $mask) === (ip2long($cidr['network']) & $mask);
    }

    public function installs(array $params): void
    {
        $subnet = Setting::get(Setting::KEY_PXE_SUBNET, '') ?? '';
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!self::sourceAllowed((string)($_SERVER['REMOTE_ADDR'] ?? ''), $subnet)) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            return;
        }
        echo json_encode(InstallMonitor::snapshot(time()), self::JSON_FLAGS);
    }
}
