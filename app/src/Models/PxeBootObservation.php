<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

/** MAC/IP pairs reported by the internal DHCP hook or dnsmasq sidecar. */
final class PxeBootObservation
{
    private const CHECKPOINT = 'pxe_boot_observed';

    public static function record(int $machineId, string $mac, string $ip): void
    {
        if ($machineId <= 0 || Machine::normalizeMac($mac) !== $mac
            || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return;
        MachineEvent::log($machineId, MachineEvent::LEVEL_INFO,
            'PXE DHCP observed ' . json_encode(['mac' => $mac, 'ip' => $ip], JSON_UNESCAPED_SLASHES),
            self::CHECKPOINT);
    }

    public static function recentMatches(int $machineId, string $mac, string $ip): bool
    {
        if ($machineId <= 0 || Machine::normalizeMac($mac) !== $mac
            || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $message = 'PXE DHCP observed ' . json_encode(['mac' => $mac, 'ip' => $ip], JSON_UNESCAPED_SLASHES);
        $stmt = Db::get()->prepare('SELECT 1 FROM machine_events '
            . 'WHERE machine_id = ? AND checkpoint = ? AND message = ? '
            . "AND created_at >= datetime('now', '-2 hours') LIMIT 1");
        $stmt->execute([$machineId, self::CHECKPOINT, $message]);
        return (bool)$stmt->fetchColumn();
    }
}
