<?php
declare(strict_types=1);

namespace Admin\Util;

use Admin\Models\Machine;

/**
 * Wrapper unifié au-dessus de l'inventaire matériel d'une machine.
 *
 * Source de vérité unique pour les deux UI (wizard Install + édition Preset) :
 * elles doivent voir le même hardware quand elles parlent de la même machine.
 * En Phase 1 du chantier preset-hardware-aware, ce helper alimentera le form
 * preset une fois la `based_on_machine_id` câblée.
 *
 * Stateless : pas de cache, pas de DB roundtrip (le caller passe la row
 * `machines.*` déjà chargée).
 *
 * Contrat de sortie : ['disks' => [...], 'nics' => [...], 'ram_kb' => int|null,
 *                      'is_uefi' => bool, 'cpu' => array]
 */
final class HardwareContext
{
    /**
     * Construit le contexte hardware depuis une row `machines.*`.
     *
     * Tolère inventory_json NULL/vide/JSON invalide → retourne self::empty()
     * avec les arrays vides et les scalars null/false. Aucune exception.
     *
     * @param array<string,mixed> $machine row SQL machines (avec inventory_json brut)
     * @return array{disks: list<array<string,mixed>>, nics: list<array<string,mixed>>, boot_mac: string, ram_kb: int|null, is_uefi: bool, cpu: array<string,mixed>}
     */
    public static function fromMachine(array $machine): array
    {
        $raw = $machine['inventory_json'] ?? null;
        if (!is_string($raw) || trim($raw) === '') return self::empty();
        $inv = json_decode($raw, true);
        if (!is_array($inv)) return self::empty();

        $hw = is_array($inv['inventory'] ?? null) ? $inv['inventory'] : [];

        // Disques : taille MB SI alignée partman expert_recipe.
        $caps = Machine::diskCapacitiesMbFromInventory($machine);
        $disks = [];
        $identityCounts = [];
        foreach (($inv['disks'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            $candidateIdentity = DiskIdentity::fromDisk($candidate);
            if ($candidateIdentity !== null) $identityCounts[$candidateIdentity['value']] = ($identityCounts[$candidateIdentity['value']] ?? 0) + 1;
        }
        foreach (($inv['disks'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $name = (string)($d['name'] ?? '');
            if ($name === '') continue;
            $identity = DiskIdentity::fromDisk($d);
            $identityStatus = $identity === null ? 'missing'
                : (($identityCounts[$identity['value']] ?? 0) > 1 ? 'ambiguous'
                : (!is_array($d['identity'] ?? null) ? 'legacy' : 'valid'));
            $disks[] = [
                'name'       => $name,
                'size_bytes' => isset($d['size_bytes']) ? (int)$d['size_bytes'] : null,
                'size_mb'    => $caps[$name] ?? null,
                'is_ssd'     => isset($d['rota']) ? ($d['rota'] === false) : null,
                'vendor'     => (string)($d['vendor'] ?? ''),
                'model'      => (string)($d['model'] ?? ''),
                'serial'     => (string)($d['serial'] ?? ''),
                'identity'   => $identity,
                'identity_status' => $identityStatus,
                'identity_source' => (string)((is_array($d['identity'] ?? null) ? ($d['identity']['source'] ?? null) : null) ?? (!empty($d['serial']) ? 'legacy' : 'unknown')),
                'transport'  => (string)($d['transport'] ?? ''),
            ];
        }

        $nics = [];
        foreach (($inv['interfaces'] ?? []) as $i) {
            if (!is_array($i)) continue;
            $mac = (string)($i['mac'] ?? '');
            if ($mac === '') continue;
            $nics[] = [
                'mac'        => $mac,
                'name'       => (string)($i['name'] ?? ''),
                'speed_mbps' => isset($i['speed_mbps']) ? (int)$i['speed_mbps'] : null,
                'type'       => (string)($i['type'] ?? ''),
                'ipv4'       => is_array($i['ipv4'] ?? null) ? implode(', ', array_map('strval', $i['ipv4'])) : (string)($i['ipv4'] ?? ''),
            ];
        }

        $ramKb = null;
        if (isset($hw['memory_kb']) && is_numeric($hw['memory_kb'])) {
            $ramKb = (int)$hw['memory_kb'];
            if ($ramKb <= 0) $ramKb = null;
        }

        return [
            'disks'   => $disks,
            'nics'    => $nics,
            // Les inventaires antérieurs à boot_mac utilisent la MAC de la
            // machine. On ne sélectionne une NIC que si elle est inventoriée.
            'boot_mac' => Machine::normalizeMac((string)($inv['boot_mac'] ?? $machine['mac'] ?? '')),
            'ram_kb'  => $ramKb,
            'is_uefi' => (($hw['boot_mode'] ?? null) === 'UEFI'),
            'cpu'     => [
                'model'       => trim((string)($hw['cpu_model'] ?? '')),
                'cores'       => isset($hw['cpu_cores']) ? (int)$hw['cpu_cores'] : null,
                'max_mhz'     => isset($hw['cpu_max_mhz']) ? (int)$hw['cpu_max_mhz'] : null,
                'current_mhz' => isset($hw['cpu_current_mhz']) ? (int)$hw['cpu_current_mhz'] : null,
            ],
        ];
    }

    /**
     * Squelette vide — utilisé en mode "modèle nu" (preset sans machine de
     * référence) et en fallback quand inventory_json est absent/invalide.
     *
     * @return array{disks: list<array<string,mixed>>, nics: list<array<string,mixed>>, boot_mac: string, ram_kb: int|null, is_uefi: bool, cpu: array<string,mixed>}
     */
    public static function empty(): array
    {
        return [
            'disks'   => [],
            'nics'    => [],
            'boot_mac' => '',
            'ram_kb'  => null,
            'is_uefi' => false,
            'cpu'     => [
                'model'       => '',
                'cores'       => null,
                'max_mhz'     => null,
                'current_mhz' => null,
            ],
        ];
    }

    /** Index de la NIC primaire dans l'inventaire courant. */
    public static function primaryNicIndex(array $nics, array $plan, string $bootMac, bool $preferBoot): int
    {
        $findMac = static function (string $mac) use ($nics): ?int {
            $mac = Machine::normalizeMac($mac);
            if ($mac === '') return null;
            foreach (array_values($nics) as $index => $nic) {
                if (Machine::normalizeMac((string)($nic['mac'] ?? '')) === $mac) return $index;
            }
            return null;
        };

        if ($preferBoot) {
            $bootIndex = $findMac($bootMac);
            if ($bootIndex !== null) return $bootIndex;
        }

        $primary = isset($plan['primary']) && is_numeric($plan['primary']) ? (int)$plan['primary'] : 0;
        $savedMac = (string)($plan['interfaces'][$primary]['match']['mac'] ?? '');
        $savedIndex = $findMac($savedMac);
        if ($savedIndex !== null) return $savedIndex;
        return $primary >= 0 && $primary < count($nics) ? $primary : 0;
    }
}
