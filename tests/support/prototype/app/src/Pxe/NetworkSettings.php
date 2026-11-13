<?php
// SPDX-License-Identifier: LGPL-3.0-only
declare(strict_types=1);

namespace Admin\Pxe;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Store desired network state. Saving never starts, stops or configures services. */
final class NetworkSettings
{
    public function __construct(private readonly PDO $db) {}

    /** @return array{mode:string,settings:array} */
    public function read(): array
    {
        $row = $this->db->query('SELECT mode, settings_json FROM pxe_network_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('network settings are not initialized');
        $settings = json_decode($row['settings_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) throw new RuntimeException('invalid network settings');
        return ['mode'=>$row['mode'], 'settings'=>$settings];
    }

    /** Save a manually entered desired configuration; actual interfaces are checked at activation. */
    public function saveDesired(string $mode, array $input): void
    {
        if ($mode === 'disabled') {
            $this->save($mode, [], []);
            return;
        }
        $interface = $input['pxe_interface'] ?? null;
        $ip = $input['pxe_ip'] ?? null;
        $cidr = $input['dhcp_proxy_cidr'] ?? '';
        if (!is_string($interface) || !is_string($ip) || !is_string($cidr)
            || strlen($interface) > 64 || strlen($ip) > 15 || strlen($cidr) > 18) {
            throw new InvalidArgumentException('invalid_network_input');
        }
        $interface = trim($interface);
        $ip = trim($ip);
        $cidr = trim($cidr);
        if (str_starts_with($interface, '-')) throw new InvalidArgumentException('invalid_interface');
        // These paths and filenames are managed by the runtime, not chosen by
        // the browser. The temporary TFTP root is rebuilt at activation.
        $render = ['pxe_interface'=>$interface, 'pxe_ip'=>$ip, 'tftp_root'=>'/srv/pxe-tools/tftp',
                   'bios_bootfile'=>'lpxelinux.0', 'uefi_bootfile'=>'bootx64.efi', 'dhcp_proxy_cidr'=>$cidr];
        $this->save($mode, $render, [['name'=>$interface, 'ipv4'=>[$ip . '/32']]]);
    }

    /** Validate current interface data and persist only recognized settings. */
    public function save(string $mode, array $input, array $interfaces): void
    {
        // Revalidation at actual activation is mandatory: interfaces may change.
        DnsmasqConfiguration::render($mode, $input, $interfaces);
        if ($mode === 'disabled') {
            // Atomic mode change retains settings even if another writer updated them.
            $stmt = $this->db->prepare("UPDATE pxe_network_settings SET mode = 'disabled', updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->execute();
            if ($stmt->rowCount() !== 1) throw new RuntimeException('network settings are not initialized');
            return;
        }
        // Keep fields belonging to other modes when switching, and fail rather
        // than lose a concurrent update if SQLite detects a write conflict.
        $this->db->beginTransaction();
        try {
            $stored = $this->read()['settings'];
            $allowed = ['pxe_interface', 'pxe_ip', 'tftp_root'];
            if ($mode === 'proxy') $allowed = array_merge($allowed, ['dhcp_proxy_cidr', 'bios_bootfile', 'uefi_bootfile']);
            foreach ($allowed as $key) {
                if (isset($input[$key]) && is_string($input[$key])) $stored[$key] = $input[$key];
            }
            $json = json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $stmt = $this->db->prepare('UPDATE pxe_network_settings SET mode = ?, settings_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1');
            $stmt->execute([$mode, $json]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('network settings are not initialized');
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
