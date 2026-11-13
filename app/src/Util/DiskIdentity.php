<?php
declare(strict_types=1);

namespace Admin\Util;

/** Serial based identity shared by the install wizard and preseed renderer. */
final class DiskIdentity
{
    private const ABSENT = ['', '0', 'UNKNOWN', 'N/A', 'NA', 'NONE', 'NULL', 'NOT AVAILABLE', 'DEFAULT'];

    /** Trim and case-fold without stripping punctuation or internal whitespace. */
    public static function normalize(mixed $serial): ?string
    {
        if (!is_string($serial)) return null;
        $value = strtoupper(trim($serial));
        return $value === '' || in_array($value, self::ABSENT, true) ? null : $value;
    }

    /** Read a serial, or a strictly typed Hyper-V SCSI WWID. */
    public static function fromDisk(array $disk): ?array
    {
        $identity = is_array($disk['identity'] ?? null) ? $disk['identity'] : [];
        $kind = (string)($identity['kind'] ?? 'serial');
        if ($kind === 'wwid') {
            $value = self::normalize($identity['value'] ?? null);
            if (($identity['source'] ?? '') !== 'sysfs-wwid'
                || trim((string)($disk['vendor'] ?? '')) !== 'Msft'
                || trim((string)($disk['model'] ?? '')) !== 'Virtual Disk'
                || $value === null || !preg_match('/^NAA\.[0-9A-F]{32}$/D', $value)
                || $value === 'NAA.' . str_repeat('0', 32)) return null;
            return ['kind' => 'wwid', 'value' => $value, 'source' => 'sysfs-wwid',
                'status' => (string)($identity['status'] ?? 'valid')];
        }
        if ($kind !== 'serial') return null;
        $value = self::normalize($identity['value'] ?? $disk['serial'] ?? null);
        if ($value === null) return null;
        return [
            'kind' => 'serial',
            'value' => $value,
            'source' => (string)($identity['source'] ?? (!empty($disk['serial']) ? 'legacy' : 'unknown')),
            'status' => (string)($identity['status'] ?? 'valid'),
        ];
    }

    /** Add explicit, versioned identity status to newly received discovery data. */
    public static function normalizeInventory(mixed $rawDisks): array
    {
        if (!is_array($rawDisks)) return [];
        $disks = [];
        $counts = [];
        foreach ($rawDisks as $disk) {
            if (!is_array($disk)) continue;
            $identity = self::fromDisk($disk);
            $value = $identity['value'] ?? null;
            if ($value !== null) $counts[$value] = ($counts[$value] ?? 0) + 1;
            $disk['_normalized_identity'] = $identity;
            $disks[] = $disk;
        }
        foreach ($disks as &$disk) {
            $identity = $disk['_normalized_identity'];
            unset($disk['_normalized_identity']);
            $value = $identity['value'] ?? null;
            $source = $identity['source'] ?? 'none';
            $kind = $identity['kind'] ?? 'serial';
            $status = $value === null ? 'missing' : (($counts[$value] ?? 0) > 1 ? 'ambiguous' : 'valid');
            $disk['identity'] = ['kind' => $kind, 'value' => $value ?? '', 'source' => $source, 'status' => $status];
            $disk = array_replace($disk, self::storageMetadata($disk));
        }
        unset($disk);
        return array_values($disks);
    }

    /**
     * Informational discovery metadata, separate from destructive target identity.
     * A null collection status distinguishes an old inventory from a recent
     * collection which explicitly could not read a field. No CID/UUID is ever
     * substituted for the raw serial used by installation and disk tests.
     */
    public static function storageMetadata(array $disk): array
    {
        $filesystem = self::filesystemMetadata($disk['filesystem'] ?? null);
        $partitions = [];
        foreach (is_array($disk['partitions'] ?? null) ? $disk['partitions'] : [] as $partition) {
            if (!is_array($partition)) continue;
            $number = self::metadataInteger($partition['number'] ?? null);
            $partitions[] = array_merge([
                'name' => self::metadataString($partition['name'] ?? null),
                'path' => self::metadataString($partition['path'] ?? null),
                'number' => $number !== null && $number > 0 ? $number : null,
                'size_bytes' => self::metadataInteger($partition['size_bytes'] ?? null),
            ], self::filesystemMetadata($partition));
        }

        $diskName = is_string($disk['name'] ?? null) ? $disk['name'] : '';
        $nativeMmc = preg_match('~^(?:/dev/)?mmcblk[0-9]+$~D', $diskName) === 1;
        $mmc = null;
        if ($nativeMmc && is_array($disk['mmc'] ?? null)) {
            $rawMmc = $disk['mmc'];
            $mmc = [];
            foreach (['type', 'name', 'cid', 'csd', 'manfid', 'oemid', 'date', 'fwrev', 'life_time', 'pre_eol_info'] as $field) {
                $mmc[$field] = self::metadataString($rawMmc[$field] ?? null);
            }
            foreach (['cid', 'csd'] as $field) {
                if ($mmc[$field] !== null && preg_match('/^[a-fA-F0-9]{32}$/D', $mmc[$field]) !== 1) $mmc[$field] = null;
            }
            $mmc['hardware_regions'] = [];
            $baseName = basename($diskName);
            $parentPath = '/dev/' . $baseName;
            foreach (is_array($rawMmc['hardware_regions'] ?? null) ? $rawMmc['hardware_regions'] : [] as $region) {
                if (!is_array($region)) continue;
                $name = self::metadataString($region['name'] ?? null);
                $path = self::metadataString($region['path'] ?? null);
                $parent = self::metadataString($region['parent_path'] ?? null);
                if ($name === null || $path !== '/dev/' . $name
                    || ($parent !== null && $parent !== $parentPath)
                    || (array_key_exists('parent_path', $region) && $region['parent_path'] !== null && $parent === null)) continue;
                $samePrefix = preg_match('/^' . preg_quote($baseName, '/') . '(?:boot[01]|rpmb|gp[0-9]+)$/D', $name) === 1;
                // The RPMB character-device index need not match mmcblkN.
                // Keep it only when discovery proved its physical parent.
                $independentRpmb = preg_match('/^(?:rpmb[0-9]+|mmcblk[0-9]+rpmb)$/D', $name) === 1;
                if (!$samePrefix && (!$independentRpmb || $parent !== $parentPath)) continue;
                $forceRo = self::metadataInteger($region['force_ro'] ?? null);
                $mmc['hardware_regions'][] = [
                    'name' => $name,
                    'path' => $path,
                    'parent_path' => $parent,
                    'size_bytes' => self::metadataInteger($region['size_bytes'] ?? null),
                    'force_ro' => in_array($forceRo, [0, 1], true) ? $forceRo : null,
                    'protected' => true,
                ];
            }
        }

        $collection = null;
        if (is_array($disk['metadata_collection'] ?? null)) {
            $rawCollection = $disk['metadata_collection'];
            $fsStatus = $rawCollection['filesystem_status'] ?? null;
            $mmcStatus = $rawCollection['mmc_status'] ?? null;
            $collection = [
                'filesystem_status' => in_array($fsStatus, ['available', 'unavailable', 'error'], true) ? $fsStatus : 'unavailable',
                'mmc_status' => in_array($mmcStatus, ['available', 'unavailable', 'not_applicable'], true) ? $mmcStatus : 'unavailable',
            ];
            if ($collection['filesystem_status'] === 'available'
                && (!is_array($disk['partitions'] ?? null) || !is_array($disk['filesystem'] ?? null))) {
                $collection['filesystem_status'] = 'unavailable';
            }
            if (!$nativeMmc) $collection['mmc_status'] = 'not_applicable';
            elseif ($mmc === null || $collection['mmc_status'] === 'not_applicable') $collection['mmc_status'] = 'unavailable';
        }
        return ['mmc' => $mmc, 'partitions' => $partitions, 'filesystem' => $filesystem, 'metadata_collection' => $collection];
    }

    private static function filesystemMetadata(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $result = [];
        foreach (['fstype', 'uuid', 'partuuid', 'label'] as $field) $result[$field] = self::metadataString($raw[$field] ?? null);
        return $result;
    }

    private static function metadataString(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        return trim($value) !== '' ? $value : null;
    }

    private static function metadataInteger(mixed $value): ?int
    {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (!is_string($value) || !ctype_digit($value)) return null;
        $digits = ltrim($value, '0');
        $limit = (string)PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return null;
        return (int)$value;
    }

    /** @return array{identity:?array<string,string>,error:?string} */
    public static function forPath(array $inventory, string $path, bool $allowWwid = false): array
    {
        $path = self::devicePath($path);
        $matches = [];
        foreach (($inventory['disks'] ?? []) as $disk) {
            if (is_array($disk) && self::devicePath((string)($disk['name'] ?? '')) === $path) $matches[] = $disk;
        }
        if (count($matches) !== 1) return ['identity' => null, 'error' => 'Disque absent ou ambigu dans la découverte : ' . $path];
        if (!is_array($matches[0]['identity'] ?? null)) {
            return ['identity' => null, 'error' => 'Ancien inventaire sans statut d’identité : relancez le discovery avant de sélectionner ' . $path];
        }
        $identity = self::fromDisk($matches[0]);
        if ($identity === null || ($matches[0]['identity']['status'] ?? '') !== 'valid') {
            return ['identity' => null, 'error' => 'Numéro de série absent ou non vérifiable pour ' . $path];
        }
        if ($identity['kind'] !== 'serial' && !$allowWwid) {
            return ['identity' => null, 'error' => 'Cette opération exige un numéro de série vérifiable : ' . $path];
        }
        $count = 0;
        foreach (($inventory['disks'] ?? []) as $disk) {
            $other = is_array($disk) ? self::fromDisk($disk) : null;
            if (($other['value'] ?? null) === $identity['value']) $count++;
        }
        if ($count !== 1) return ['identity' => null, 'error' => 'Numéro de série ambigu dans la découverte pour ' . $path];
        return ['identity' => ['kind' => $identity['kind'], 'value' => $identity['value'], 'source' => $identity['source'], 'status' => 'valid'], 'error' => null];
    }

    /** Rebind a generic recipe to this machine's current discovery; preset identities are discarded. */
    public static function bindConfig(array $config, array $machine): array
    {
        $inventory = json_decode((string)($machine['inventory_json'] ?? ''), true);
        $selectionConfirmed = !array_key_exists('disk_identity_selection_confirmed', $config)
            || $config['disk_identity_selection_confirmed'] === true;
        if (($config['disk_target_constraints'] ?? []) !== []) {
            return self::bindConstrainedConfig($config, is_array($inventory) ? $inventory : [], $selectionConfirmed);
        }
        if (($config['partition_mode'] ?? 'default') !== 'custom') {
            if (!$selectionConfirmed) {
                $config['system_disk_path'] = null;
                $config['system_disk_identity'] = null;
                return $config;
            }
            $first = is_array($inventory) && is_array($inventory['disks'][0] ?? null) ? $inventory['disks'][0] : null;
            if ($first !== null) {
                $path = self::devicePath((string)($first['name'] ?? ''));
                $target = self::forPath($inventory, $path, true);
                $config['system_disk_path'] = $path;
                $config['system_disk_identity'] = $target['identity'];
            } else {
                $config['system_disk_path'] = null;
                $config['system_disk_identity'] = null;
            }
            return $config;
        }
        $plan = json_decode((string)($config['partition_custom_json'] ?? ''), true);
        if (!is_array($plan) || array_is_list($plan)) return $config;
        if (!$selectionConfirmed) {
            $plan['target_disk_identities'] = [];
            $config['partition_custom_json'] = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $config;
        }
        $inventoryPaths = [];
        foreach (($inventory['disks'] ?? []) as $disk) {
            if (!is_array($disk)) continue;
            $path = self::devicePath((string)($disk['name'] ?? ''));
            if ($path !== '') $inventoryPaths[$path] = true;
        }
        $plannedPaths = [];
        foreach (array_merge($plan['target_disks'] ?? [], array_map(static fn($d) => $d['path'] ?? '', $plan['disks'] ?? [])) as $path) {
            if (is_string($path) && self::devicePath($path) !== '') $plannedPaths[self::devicePath($path)] = true;
        }
        $remaining = array_values(array_diff(array_keys($inventoryPaths), array_keys(array_intersect_key($inventoryPaths, $plannedPaths))));
        usort($remaining, 'strnatcmp');
        $missing = array_values(array_diff(array_keys($plannedPaths), array_keys($inventoryPaths)));
        usort($missing, 'strnatcmp');
        $remap = [];
        // A generic one-disk recipe may adapt to the only disk of a target.
        // Several candidates require an explicit operator selection: ordering
        // /dev names cannot establish which physical disk should be erased.
        if (empty($plan['raid_arrays']) && count($plannedPaths) === 1
            && count($inventoryPaths) === 1 && count($missing) === 1 && count($remaining) === 1) {
            $remap[$missing[0]] = $remaining[0];
        }
        if ($remap !== []) {
            $apply = static fn($p) => $remap[self::devicePath((string)$p)] ?? self::devicePath((string)$p);
            $plan['target_disks'] = array_map($apply, $plan['target_disks'] ?? []);
            if (isset($plan['disks']) && is_array($plan['disks'])) {
                foreach ($plan['disks'] as &$disk) if (is_array($disk)) $disk['path'] = $apply($disk['path'] ?? '');
            }
            unset($disk);
            if (is_array($config['advanced']['bootloader'] ?? null)) {
                $bootdev = self::devicePath((string)($config['advanced']['bootloader']['bootdev'] ?? ''));
                if (isset($remap[$bootdev])) $config['advanced']['bootloader']['bootdev'] = $remap[$bootdev];
            }
        }
        $identityMap = [];
        foreach (($plan['target_disks'] ?? []) as $path) {
            if (!is_string($path)) continue;
            $result = self::forPath(is_array($inventory) ? $inventory : [], $path, true);
            if ($result['identity'] !== null) $identityMap[$path] = $result['identity'];
        }
        $plan['target_disk_identities'] = $identityMap;
        $config['partition_custom_json'] = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $config;
    }

    /** Optional audit intention is separate from a reusable partition recipe. */
    public static function constraintsForPreset(array $preset): mixed
    {
        if (array_key_exists('disk_target_constraints', $preset)) return $preset['disk_target_constraints'];
        $advanced = json_decode((string)($preset['advanced_config_json'] ?? ''), true);
        return is_array($advanced) ? ($advanced['disk_target_constraints'] ?? []) : [];
    }

    /** The first version supports precisely one system disk, identified by raw serial. */
    public static function validateConstraints(array $config): array
    {
        $constraints = $config['disk_target_constraints'] ?? [];
        if ($constraints === []) return [];
        if (!is_array($constraints) || array_is_list($constraints) || count($constraints) !== 1) {
            return ['Contrainte disque : objet {chemin: numéro de série brut} avec une seule cible requis.'];
        }
        $plan = json_decode((string)($config['partition_custom_json'] ?? ''), true);
        if (($config['partition_mode'] ?? 'default') !== 'custom' || !is_array($plan)
            || count($plan['target_disks'] ?? []) !== 1 || !is_array($plan['disks'] ?? null) || !empty($plan['raid_arrays'])) {
            return ['Contrainte disque : prise en charge uniquement pour une recette personnalisée à un seul disque, sans RAID.'];
        }
        $path = array_key_first($constraints);
        $errors = [];
        if (!is_string($path) || $path !== ($plan['target_disks'][0] ?? null)) {
            $errors[] = 'Contrainte disque : le chemin du relevé doit correspondre au seul disque cible de la recette.';
        }
        if (self::normalize($constraints[$path]) === null) $errors[] = 'Contrainte disque : numéro de série brut absent ou invalide (le suffixe by-id ne convient pas).';
        return $errors;
    }

    private static function bindConstrainedConfig(array $config, array $inventory, bool $selectionConfirmed): array
    {
        $errors = self::validateConstraints($config);
        if (!$selectionConfirmed) $errors[] = 'Contrainte disque : confirmation de la sélection requise.';
        $plan = json_decode((string)($config['partition_custom_json'] ?? ''), true);
        if (!is_array($plan)) $plan = [];
        $plan['target_disk_identities'] = [];
        $config['system_disk_identity'] = null;
        if ($errors === []) {
            $constraints = $config['disk_target_constraints'];
            $old = array_key_first($constraints);
            $want = self::normalize($constraints[$old]);
            $matches = [];
            foreach ($inventory['disks'] ?? [] as $disk) {
                if (is_array($disk) && (self::fromDisk($disk)['value'] ?? null) === $want) $matches[] = $disk;
            }
            if (count($matches) !== 1) {
                $errors[] = 'Contrainte disque : la série imposée par le relevé est absente ou ambiguë dans cette découverte ; aucun autre disque ne sera choisi.';
            } else {
                $path = self::devicePath((string)($matches[0]['name'] ?? ''));
                $target = self::forPath($inventory, $path);
                if ($target['identity'] === null) $errors[] = $target['error'];
                else {
                    $plan['target_disks'] = [$path];
                    foreach ($plan['disks'] as &$disk) if ($disk['path'] === $old) $disk['path'] = $path;
                    unset($disk);
                    if (($config['advanced']['bootloader']['bootdev'] ?? '') === $old) $config['advanced']['bootloader']['bootdev'] = $path;
                    $config['disk_target_constraints'] = [$path => $constraints[$old]];
                    $plan['target_disk_identities'] = [$path => $target['identity']];
                }
            }
        }
        $config['disk_target_constraint_errors'] = $errors;
        $config['partition_custom_json'] = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $config;
    }

    private static function devicePath(string $path): string
    {
        if ($path === '') return '';
        return str_starts_with($path, '/dev/') ? $path : '/dev/' . ltrim($path, '/');
    }

    /** @return list<string> */
    public static function validateTargets(array $config, array $machine): array
    {
        $constraintErrors = array_merge(self::validateConstraints($config), $config['disk_target_constraint_errors'] ?? []);
        if ($constraintErrors !== []) return array_values(array_unique($constraintErrors));
        if (($config['partition_mode'] ?? 'default') !== 'custom') {
            $path = (string)($config['system_disk_path'] ?? '');
            $expected = $config['system_disk_identity'] ?? null;
            if ($path === '' || !is_array($expected)) return ['Identité du disque système absente : relancez un discovery et sélectionnez un disque cible.'];
            $inventory = json_decode((string)($machine['inventory_json'] ?? ''), true);
            $current = self::forPath(is_array($inventory) ? $inventory : [], $path, true);
            if ($current['identity'] === null) return [$current['error'] ?? ('Identité disque invalide : ' . $path)];
            if (($expected['kind'] ?? '') !== $current['identity']['kind'] || self::normalize($expected['value'] ?? null) !== $current['identity']['value']) {
                return ['Identité du disque système absente ou périmée : relancez un discovery et sélectionnez explicitement le disque.'];
            }
            return [];
        }
        $plan = json_decode((string)($config['partition_custom_json'] ?? ''), true);
        if (!is_array($plan)) return [];
        $inventory = json_decode((string)($machine['inventory_json'] ?? ''), true);
        $errors = [];
        $saved = is_array($plan['target_disk_identities'] ?? null) ? $plan['target_disk_identities'] : [];
        foreach (($plan['target_disks'] ?? []) as $path) {
            if (!is_string($path)) continue;
            $current = self::forPath(is_array($inventory) ? $inventory : [], $path, true);
            $expected = $saved[$path] ?? null;
            if (($config['disk_target_constraints'] ?? []) !== []) {
                $intent = self::normalize($config['disk_target_constraints'][$path] ?? null);
                if ($intent === null || ($current['identity']['value'] ?? null) !== $intent || self::normalize($expected['value'] ?? null) !== $intent) {
                    $errors[] = 'Contrainte disque : identité du snapshot différente du numéro de série imposé par le relevé.';
                }
            }
            if ($current['identity'] === null) $errors[] = $current['error'] ?? ('Identité disque invalide : ' . $path);
            elseif (!is_array($expected) || ($expected['kind'] ?? '') !== $current['identity']['kind'] || self::normalize($expected['value'] ?? null) !== $current['identity']['value']) {
                $errors[] = 'Identité du disque ' . $path . ' absente ou périmée : appliquez un discovery récent puis sélectionnez explicitement le disque.';
            }
        }
        return $errors;
    }
}
