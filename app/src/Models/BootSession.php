<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;

/** Historique des menus GRUB fournis et des entrées réellement exécutées. */
final class BootSession
{
    public const MENUS = ['discovery', 'wipe', 'install', 'disktest', 'rescue', 'local', 'other'];

    public static function label(string $menu): string
    {
        return match ($menu) {
            'discovery' => 'Discovery',
            'wipe'      => 'Effacement des disques',
            'install'   => 'Installation',
            'disktest'  => 'Tests de disques',
            'rescue'    => 'Mode de récupération',
            'local'     => 'Démarrage sur le disque local',
            'other'     => 'Autres options de démarrage',
            default     => 'Menu inconnu',
        };
    }

    /** @return array{id:int,token:string}|null */
    public static function recordServed(int $machineId, string $mac, string $menu, string $clientIp): ?array
    {
        if ($machineId <= 0 || !in_array($menu, self::MENUS, true)) return null;
        $pdo = Db::get();
        $actionStmt = $pdo->prepare("SELECT id FROM actions WHERE machine_id = ? "
            . "AND status IN ('pending','running') ORDER BY id DESC LIMIT 1");
        $actionStmt->execute([$machineId]);
        $actionId = $actionStmt->fetchColumn() ?: null;
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO boot_sessions '
            . '(machine_id, action_id, mac, default_menu, token, client_ip) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$machineId, $actionId, $mac, $menu, $token, $clientIp]);
        $bootId = (int)$pdo->lastInsertId();
        MachineEvent::log($machineId, MachineEvent::LEVEL_INFO,
            'Menu GRUB servi par défaut : ' . self::label($menu), null,
            $actionId === null ? null : (int)$actionId);
        return ['id' => $bootId, 'token' => $token];
    }

    public static function recordSelected(int $id, string $token, string $menu): bool
    {
        if ($id <= 0 || !preg_match('/^[a-f0-9]{32}$/', $token)
            || !in_array($menu, self::MENUS, true)) return false;
        $pdo = Db::get();
        $stmt = $pdo->prepare('UPDATE boot_sessions SET selected_menu = ?, selected_at = CURRENT_TIMESTAMP '
            . 'WHERE id = ? AND token = ? AND selected_menu IS NULL');
        $stmt->execute([$menu, $id, $token]);
        if ($stmt->rowCount() === 1) {
            $row = $pdo->prepare('SELECT machine_id, action_id FROM boot_sessions WHERE id = ?');
            $row->execute([$id]);
            $boot = $row->fetch();
            if ($boot) {
                $actionId = $boot['action_id'] === null ? null : (int)$boot['action_id'];
                MachineEvent::log((int)$boot['machine_id'], MachineEvent::LEVEL_ACTION,
                    'Entrée GRUB lancée : ' . self::label($menu), null, $actionId);
                // "local" post-install est un signal fiable (hit HTTP direct de la
                // machine sur grub.php) que le reboot post-install a eu lieu. On
                // émet install_reboot_done ici plutôt que de dépendre uniquement du
                // sidecar dnsmasq-tail (parsing des logs proxy DHCP dont le format
                // réel ne matche pas toujours le pattern attendu) : sans ce
                // deuxième chemin, worker.py attendait jusqu'à 10 min avant son
                // fallback install_reboot_assumed, retardant ansible-playbook d'autant.
                if ($menu === 'local' && $actionId !== null) {
                    $action = Action::findById($actionId);
                    $alreadySeen = Db::get()->prepare("SELECT 1 FROM machine_events WHERE action_id = ? AND checkpoint = 'install_reboot_done' LIMIT 1");
                    $alreadySeen->execute([$actionId]);
                    if ($action && !$alreadySeen->fetchColumn() && ActionCheckpoints::validCode((string)$action['type'], 'install_reboot_done')) {
                        MachineEvent::log((int)$boot['machine_id'], MachineEvent::LEVEL_INFO,
                            'checkpoint:install_reboot_done', 'install_reboot_done', $actionId);
                    }
                }
            }
            return true;
        }
        // Requête GRUB répétée : même entrée déjà sélectionnée = succès.
        $stmt = $pdo->prepare('SELECT selected_menu FROM boot_sessions WHERE id = ? AND token = ?');
        $stmt->execute([$id, $token]);
        return $stmt->fetchColumn() === $menu;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentForMachine(int $machineId, int $limit = 8): array
    {
        if ($machineId <= 0) return [];
        $stmt = Db::get()->prepare('SELECT id, action_id, mac, default_menu, selected_menu, '
            . 'served_at, selected_at FROM boot_sessions WHERE machine_id = ? '
            . 'ORDER BY id DESC LIMIT ?');
        $stmt->execute([$machineId, max(1, min($limit, 30))]);
        return $stmt->fetchAll() ?: [];
    }
}
