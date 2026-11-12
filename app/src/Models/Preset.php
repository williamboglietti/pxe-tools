<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

final class Preset
{
    public const NETWORK_MODES   = ['dhcp', 'static'];
    public const PARTITION_MODES = ['default', 'custom'];

    // Mode de gestion du compte root + user admin.
    //  'enabled'  : root activé + user admin créé en late_command (compat historique).
    //  'disabled' : root désactivé, user admin créé via passwd/make-user natif d-i (pattern preseed-board).
    public const ROOT_LOGIN_MODES = ['enabled', 'disabled'];

    // Services APT activables via apt-setup/services-select.
    public const ALLOWED_APT_SERVICES = ['security', 'updates', 'backports'];

    // FS valides pour partitions standards (sur disque physique)
    public const ALLOWED_FS_STANDARD = ['ext2', 'ext3', 'ext4', 'xfs', 'swap', 'efi'];
    // FS valides pour LV (LVM Logical Volume) — pas d'efi (réservé partition standard)
    public const ALLOWED_FS_LV       = ['ext2', 'ext3', 'ext4', 'xfs', 'swap'];
    // FS spéciaux pour partitions standards qui sont des "containers" (PV LVM ou membre RAID).
    public const ALLOWED_FS_CONTAINER = ['lvm-pv', 'raid-member'];
    // Partition spéciale BIOS GRUB : 1 MiB sans système de fichiers, sert
    // uniquement à embarquer GRUB legacy sur disque GPT (équivalent flag
    // bios_grub de parted). Une seule par disque, en première position.
    public const ALLOWED_FS_SPECIAL = ['biosgrub'];
    // Union complète des fs valides côté partition standard (pour <select>)
    public const ALLOWED_FS = ['ext2', 'ext3', 'ext4', 'xfs', 'swap', 'efi', 'lvm-pv', 'raid-member', 'biosgrub'];

    // Niveaux RAID supportés (Phase B)
    public const RAID_LEVELS = [0, 1, 5, 6, 10];

    private const COLS = [
        'name','description','distro','codename','version_full',
        'network_mode','static_ip','static_netmask','static_cidr','static_gateway','static_dns',
        'partition_mode','partition_custom_json',
        'admin_user_login','admin_user_pwhash','admin_user_password_cipher','admin_user_groups','admin_user_pubkeys',
        'ansible_role','ansible_extra_vars',
        'advanced_config_json','root_login_mode','admin_user_fullname','domain','variant',
        'pre_wipe_mode',
        // Chantier hardware-aware (Phase 1 schema + Phase 2 wiring) :
        // based_on_machine_id   : FK soft vers machines(id) = inventaire de référence
        // hostname_pattern      : pattern de génération hostname (ex: srv-{mac_last4})
        // pre_wipe_mode_json    : structure stable per-disque (le scalar pre_wipe_mode
        //                         reste lisible pour rétro-compat)
        'based_on_machine_id','hostname_pattern','hostname_random','pre_wipe_mode_json',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::get()->query('SELECT * FROM presets ORDER BY name')->fetchAll();
    }

    /**
     * Liste des presets groupés par compatibilité avec une machine donnée :
     *   - compatible : presets dont `based_on_machine_id` = $machineId, triés id DESC
     *     (le plus récent en premier → candidat de pré-sélection assistive).
     *   - others     : tous les autres presets, triés par nom.
     *
     * @return array{compatible: list<array<string,mixed>>, others: list<array<string,mixed>>}
     */
    public static function allGroupedForMachine(int $machineId): array
    {
        $compatible = [];
        $others = [];
        foreach (self::all() as $p) {
            if ((int)($p['based_on_machine_id'] ?? 0) === $machineId) {
                $compatible[] = $p;
            } else {
                $others[] = $p;
            }
        }
        usort($compatible, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return ['compatible' => $compatible, 'others' => $others];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM presets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        // Les anciennes bases peuvent conserver un DEFAULT SQL historique.
        $data['root_login_mode'] ??= 'disabled';
        $placeholders = implode(',', array_fill(0, count(self::COLS), '?'));
        $sql = 'INSERT INTO presets(' . implode(',', self::COLS) . ") VALUES ($placeholders)";
        $values = array_map(fn($c) => $data[$c] ?? null, self::COLS);
        Db::get()->prepare($sql)->execute($values);
        return (int) Db::get()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $set = implode(',', array_map(fn($c) => "$c = ?", self::COLS));
        // Protect historical unsupported data at the actual write boundary,
        // including imports or a concurrent change after form validation.
        $sql = "UPDATE presets SET $set WHERE id = ? AND COALESCE(variant, 'debian') <> 'proxmox-ve'";
        $values = array_map(fn($c) => $data[$c] ?? null, self::COLS);
        $values[] = $id;
        $statement = Db::get()->prepare($sql);
        $statement->execute($values);
        if ($statement->rowCount() === 0 && (self::find($id)['variant'] ?? null) === 'proxmox-ve') {
            throw new \RuntimeException('Ce modèle Proxmox VE historique est conservé en lecture seule pour le moment.');
        }
    }

    public static function findIdByName(string $name): ?int
    {
        $stmt = Db::get()->prepare('SELECT id FROM presets WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Import idempotent par `name` : UPDATE si le nom existe, INSERT sinon.
     * Préserve `presets.id` et les FK `machines.preset_id` quand un import GitHub
     * écrase un preset déjà présent en DB (disaster-recovery).
     *
     * @param array<string,mixed> $data colonnes Preset::COLS (au moins `name` non-vide)
     * @return array{action: 'updated'|'created'|'unchanged', id: int}
     */
    public static function upsertByName(array $data): array
    {
        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($name === '') {
            throw new \InvalidArgumentException('Preset name is required for upsert');
        }
        $existing = self::findIdByName($name);
        if ($existing !== null) {
            $current = self::find($existing);
            $changed = false;
            foreach (self::COLS as $column) {
                $before = $current[$column] ?? null;
                $after = $data[$column] ?? null;
                if ($before === null || $after === null ? $before !== $after : (string)$before !== (string)$after) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed) return ['action' => 'unchanged', 'id' => $existing];
            self::update($existing, $data);
            return ['action' => 'updated', 'id' => $existing];
        }
        return ['action' => 'created', 'id' => self::create($data)];
    }

    public static function delete(int $id): void
    {
        Db::get()->prepare('DELETE FROM presets WHERE id = ?')->execute([$id]);
    }

    /** Convertit un préfixe CIDR (1-32) en netmask pointé pour les directives preseed. */
    public static function cidrToNetmask(int $cidr): string
    {
        $cidr = max(1, min(32, $cidr));
        $long = $cidr === 0 ? 0 : (-1 << (32 - $cidr)) & 0xFFFFFFFF;
        return long2ip($long);
    }

    /**
     * Liste les choix CIDR pour le <select>, format `/24 (255.255.255.0)`.
     * @return array<int,string>
     */
    public static function cidrChoices(): array
    {
        $out = [];
        for ($i = 32; $i >= 1; $i--) {
            $out[$i] = "/$i (" . self::cidrToNetmask($i) . ')';
        }
        return $out;
    }

    /**
     * Décode le champ static_dns (JSON array). Tolère le format legacy "espace/CSV".
     * @return array<int,string>
     */
    public static function parseDnsList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded), fn($x) => $x !== ''));
        }
        $parts = preg_split('/[\s,]+/', $raw);
        return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    }

    /**
     * DNS proposés aux nouvelles installations et aux nouveaux modèles :
     * uniquement les valeurs explicitement enregistrées dans les paramètres.
     * @return array<int,string>
     */
    public static function defaultDns(): array
    {
        return self::parseDnsList(Setting::get(Setting::KEY_DEFAULT_DNS));
    }

    /** Format attendu par `d-i netcfg/get_nameservers` : IPs séparées par des espaces. */
    public static function formatDnsForPreseed(array $dns): string
    {
        return implode(' ', $dns);
    }

    /**
     * Décode le bloc réseau multi-NIC d'un preset (Phase 3 chantier preset-hardware-aware).
     *
     * Trois sources possibles :
     *   1. `advanced_config_json.network.interfaces[]` (nouveau schéma) → utilisé tel quel
     *   2. Colonnes flat legacy (`network_mode`, `static_ip`, ...) → wrap dans une
     *      interface primaire unique. Garantit la rétrocompat des presets antérieurs
     *      à ce chantier (pas de migration destructive : les colonnes restent en DB
     *      comme cache de la NIC primaire pour requêtes/tri sans parser le JSON).
     *   3. Aucune des deux → fallback DHCP single-NIC primaire.
     *
     * Structure retournée — identique à celle attendue par preseed.php.j2 et par
     * InstallConfigBuilder::validateNetworkPlan :
     *   [
     *     'interfaces' => [
     *       [
     *         'match_by' => 'primary|index|mac',
     *         'index'    => 0,
     *         'match'    => ['mac' => 'aa:bb:..'],   // optionnel
     *         'mode'     => 'dhcp|static|disabled',
     *         'static'   => ['ip', 'cidr', 'gateway', 'dns'[]] | null,
     *         'vlan'     => ['id' => int] | null,
     *         'bond'     => ['role' => 'master|slave', 'name', 'mode'] | null,
     *       ], ...
     *     ],
     *     'primary' => 0,
     *   ]
     *
     * @param array<string,mixed> $preset row SQL preset OU snapshot install_config_json
     * @return array{interfaces: list<array<string,mixed>>, primary: int}
     */
    public static function parseNetwork(array $preset): array
    {
        // Cas 1 : structure multi-NIC déjà présente (advanced_config_json.network ou
        // snapshot.network propagé par toInstallConfig)
        $adv = null;
        if (isset($preset['network']) && is_array($preset['network'])) {
            // Source directe (snapshot install_config_json après toInstallConfig)
            $adv = $preset['network'];
        } elseif (isset($preset['advanced_config_json']) && is_string($preset['advanced_config_json'])) {
            $decoded = json_decode($preset['advanced_config_json'], true);
            if (is_array($decoded) && isset($decoded['network']) && is_array($decoded['network'])) {
                $adv = $decoded['network'];
            }
        }

        if (is_array($adv) && isset($adv['interfaces']) && is_array($adv['interfaces'])) {
            $interfaces = [];
            foreach ($adv['interfaces'] as $idx => $iface) {
                if (!is_array($iface)) continue;
                $interfaces[] = self::normalizeInterface($iface, $idx);
            }
            if (!empty($interfaces)) {
                $primary = isset($adv['primary']) && is_numeric($adv['primary'])
                    ? max(0, min((int)$adv['primary'], count($interfaces) - 1))
                    : 0;
                return ['interfaces' => $interfaces, 'primary' => $primary];
            }
        }

        // Cas 2 : legacy flat → wrap en NIC primaire unique
        $mode = $preset['network_mode'] ?? 'dhcp';
        if (!in_array($mode, self::NETWORK_MODES, true)) $mode = 'dhcp';

        $iface = [
            'match_by' => 'primary',
            'index'    => 0,
            'match'    => null,
            'mode'     => $mode,
            'static'   => null,
            'vlan'     => null,
            'bond'     => null,
        ];

        if ($mode === 'static') {
            $cidr = isset($preset['static_cidr']) && is_numeric($preset['static_cidr'])
                ? (int)$preset['static_cidr']
                : 24;
            $iface['static'] = [
                'ip'      => (string)($preset['static_ip'] ?? ''),
                'cidr'    => $cidr,
                'gateway' => (string)($preset['static_gateway'] ?? ''),
                'dns'     => self::parseDnsList($preset['static_dns'] ?? null),
            ];
        }

        return ['interfaces' => [$iface], 'primary' => 0];
    }

    /**
     * Normalise une interface décodée depuis un JSON externe :
     *   - clé manquante → default safe
     *   - match_by inconnu → 'primary'
     *   - mode inconnu → 'dhcp'
     *   - static.dns string → array (via parseDnsList)
     *
     * @param array<string,mixed> $iface
     * @return array<string,mixed>
     */
    private static function normalizeInterface(array $iface, int $idx): array
    {
        $matchBy = in_array($iface['match_by'] ?? '', ['primary', 'index', 'mac'], true)
            ? $iface['match_by'] : 'primary';
        $mode = in_array($iface['mode'] ?? '', ['dhcp', 'static', 'disabled'], true)
            ? $iface['mode'] : 'dhcp';

        $match = null;
        if (isset($iface['match']) && is_array($iface['match']) && !empty($iface['match']['mac'])) {
            $mac = strtolower(trim((string)$iface['match']['mac']));
            $match = ['mac' => $mac];
        }

        $static = null;
        if ($mode === 'static' && isset($iface['static']) && is_array($iface['static'])) {
            $st = $iface['static'];
            $dns = $st['dns'] ?? null;
            if (is_string($dns)) {
                $dns = self::parseDnsList($dns);
            } elseif (is_array($dns)) {
                $dns = array_values(array_filter(array_map(fn($x) => trim((string)$x), $dns), fn($x) => $x !== ''));
            } else {
                $dns = [];
            }
            $static = [
                'ip'      => (string)($st['ip'] ?? ''),
                'cidr'    => is_numeric($st['cidr'] ?? null) ? (int)$st['cidr'] : 24,
                'gateway' => (string)($st['gateway'] ?? ''),
                'dns'     => $dns,
            ];
        }

        $vlan = null;
        if (isset($iface['vlan']) && is_array($iface['vlan']) && isset($iface['vlan']['id']) && is_numeric($iface['vlan']['id'])) {
            $vid = (int)$iface['vlan']['id'];
            if ($vid >= 1 && $vid <= 4094) $vlan = ['id' => $vid];
        }

        $bond = null;
        if (isset($iface['bond']) && is_array($iface['bond'])) {
            $role = in_array($iface['bond']['role'] ?? '', ['master', 'slave'], true)
                ? $iface['bond']['role'] : null;
            $bondName = trim((string)($iface['bond']['name'] ?? ''));
            $bondMode = trim((string)($iface['bond']['mode'] ?? 'active-backup'));
            if ($role !== null && $bondName !== '') {
                $bond = ['role' => $role, 'name' => $bondName, 'mode' => $bondMode];
            }
        }

        return [
            'match_by' => $matchBy,
            'index'    => isset($iface['index']) && is_numeric($iface['index'])
                ? (int)$iface['index']
                : $idx,
            'match'    => $match,
            'mode'     => $mode,
            'static'   => $static,
            'vlan'     => $vlan,
            'bond'     => $bond,
        ];
    }

    /**
     * Single source of truth des valeurs hardcodées dans preseed-base.cfg.j2.
     * Retournée pour tout preset qui n'a pas (encore) d'advanced_config_json.
     * Garantit la compatibilité descendante : si le code preseed.php applique
     * defaultAdvancedConfig() à un preset legacy, il produit le même preseed
     * qu'avant l'ajout de la colonne.
     */
    public static function defaultAdvancedConfig(): array
    {
        return [
            'localization' => [
                'locale'             => 'fr_FR.UTF-8',
                'language'           => null,
                'country'            => null,
                'supported_locales'  => [],
                'keymap'             => 'fr(latin9)',
                'keymap_toggle'      => null,
                'console_ask_detect' => null,
            ],
            'mirror' => [
                'country'   => 'manual',
                'hostname'  => 'deb.debian.org',
                'directory' => '/debian',
                'proxy'     => '',
            ],
            'timezone' => [
                'utc'        => true,
                'zone'       => 'Europe/Paris',
                'ntp'        => true,
                'ntp_server' => null,
            ],
            'packages' => [
                'tasksel'            => ['ssh-server', 'standard'],
                'pkgsel_include'     => ['sudo', 'openssh-server', 'python3', 'ca-certificates', 'curl'],
                'install_recommends' => false,
                'upgrade'            => 'full-upgrade',
                'update_policy'      => null,
                'popcon'             => false,
            ],
            'apt' => [
                'non_free_firmware'        => true,
                'contrib'                  => true,
                'non_free'                 => false,
                'services_select'          => null,
                'security_host'            => null,
                'hw_detect_load_firmware'  => true,
            ],
            'bootloader' => [
                'only_debian'   => true,
                'with_other_os' => false,
                'bootdev'       => 'default',
            ],
            'finish' => [
                'reboot'      => 'note',
                'eject_cdrom' => null,
            ],
            'post_install' => [
                // [{ "url": "https://sshid.io/USER", "label": "sshid.io USER" }, ...]
                'remote_pubkey_urls' => [],
            ],
        ];
    }

    /**
     * Décode advanced_config_json en fusionnant avec les defaults.
     * Garantit que toutes les clés attendues existent (defaults + override JSON).
     * Si $raw est NULL ou JSON invalide, retourne strictement defaultAdvancedConfig().
     */
    public static function parseAdvancedConfig(?string $raw): array
    {
        $defaults = self::defaultAdvancedConfig();
        if ($raw === null || trim($raw) === '') return $defaults;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $defaults;

        // Merge profond niveau 1 (sections) — niveau 2 = remplacement total
        // de la sous-section si présente (les listes sont remplacées, pas mergées).
        $out = $defaults;
        foreach ($defaults as $section => $sub) {
            if (!isset($decoded[$section]) || !is_array($decoded[$section])) continue;
            foreach (array_keys($sub) as $key) {
                if (array_key_exists($key, $decoded[$section])) {
                    $out[$section][$key] = $decoded[$section][$key];
                }
            }
        }
        return $out;
    }

    /**
     * Normalise un payload `adv[…]` (POST $_POST['adv'] ou snapshot équivalent)
     * en structure complète strictement alignée avec defaultAdvancedConfig().
     *
     * Mutualisée entre PresetController (qui re-encode JSON pour stockage SQL
     * dans presets.advanced_config_json) et InstallConfigBuilder (qui injecte
     * la sortie dans install_config['advanced']).
     *
     * @param mixed $adv tableau (ou non-array → defaults retournés)
     * @return array<string,mixed>
     */
    public static function normalizeAdvancedPayload(mixed $adv): array
    {
        if (!is_array($adv)) return self::defaultAdvancedConfig();

        $csvToList = static function (mixed $v): array {
            if (is_array($v)) {
                return array_values(array_filter(array_map('trim', array_map('strval', $v)), fn($x) => $x !== ''));
            }
            if ($v === null || trim((string)$v) === '') return [];
            $parts = preg_split('/[\s,]+/', (string)$v);
            return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
        };
        $strOrNull = static fn(mixed $v): ?string => (is_string($v) && trim($v) !== '') ? trim($v) : null;
        $boolFromCheckbox = static fn(mixed $v): bool => !empty($v) && (string)$v !== '0';

        $loc   = is_array($adv['localization'] ?? null) ? $adv['localization'] : [];
        $mir   = is_array($adv['mirror'] ?? null)       ? $adv['mirror']       : [];
        $tz    = is_array($adv['timezone'] ?? null)     ? $adv['timezone']     : [];
        $pkg   = is_array($adv['packages'] ?? null)     ? $adv['packages']     : [];
        $aptIn = is_array($adv['apt'] ?? null)          ? $adv['apt']          : [];
        $bl    = is_array($adv['bootloader'] ?? null)   ? $adv['bootloader']   : [];
        $fin   = is_array($adv['finish'] ?? null)       ? $adv['finish']       : [];
        $pi    = is_array($adv['post_install'] ?? null) ? $adv['post_install'] : [];

        $urlsRaw = is_array($pi['remote_pubkey_urls'] ?? null) ? array_values($pi['remote_pubkey_urls']) : [];
        $urls = [];
        foreach ($urlsRaw as $row) {
            if (!is_array($row)) continue;
            $url = trim((string)($row['url'] ?? ''));
            if ($url === '') continue;
            if (!preg_match('#^https://[A-Za-z0-9._~/:?=&@\-+%]+$#', $url)) continue;
            $label = trim((string)($row['label'] ?? ''));
            $urls[] = ['url' => $url, 'label' => $label !== '' ? $label : null];
        }

        $servicesAll = $csvToList($aptIn['services_select'] ?? null);
        $services = array_values(array_filter($servicesAll, fn($s) => in_array($s, self::ALLOWED_APT_SERVICES, true)));

        return [
            'localization' => [
                'locale'             => $strOrNull($loc['locale'] ?? null) ?? 'fr_FR.UTF-8',
                'language'           => $strOrNull($loc['language'] ?? null),
                'country'            => $strOrNull($loc['country'] ?? null),
                'supported_locales'  => $csvToList($loc['supported_locales'] ?? null),
                'keymap'             => $strOrNull($loc['keymap'] ?? null) ?? 'fr(latin9)',
                'keymap_toggle'      => $strOrNull($loc['keymap_toggle'] ?? null),
                'console_ask_detect' => array_key_exists('console_ask_detect', $loc) ? $boolFromCheckbox($loc['console_ask_detect']) : null,
            ],
            'mirror' => [
                'country'   => $strOrNull($mir['country'] ?? null) ?? 'manual',
                'hostname'  => $strOrNull($mir['hostname'] ?? null) ?? 'deb.debian.org',
                'directory' => $strOrNull($mir['directory'] ?? null) ?? '/debian',
                'proxy'     => is_string($mir['proxy'] ?? null) ? trim($mir['proxy']) : '',
            ],
            'timezone' => [
                'utc'        => $boolFromCheckbox($tz['utc'] ?? null),
                'zone'       => $strOrNull($tz['zone'] ?? null) ?? 'Europe/Paris',
                'ntp'        => $boolFromCheckbox($tz['ntp'] ?? null),
                'ntp_server' => $strOrNull($tz['ntp_server'] ?? null),
            ],
            'packages' => [
                'tasksel'            => $csvToList($pkg['tasksel'] ?? null),
                'pkgsel_include'     => $csvToList($pkg['pkgsel_include'] ?? null),
                'install_recommends' => $boolFromCheckbox($pkg['install_recommends'] ?? null),
                'upgrade'            => $strOrNull($pkg['upgrade'] ?? null) ?? 'full-upgrade',
                'update_policy'      => $strOrNull($pkg['update_policy'] ?? null),
                'popcon'             => $boolFromCheckbox($pkg['popcon'] ?? null),
            ],
            'apt' => [
                'non_free_firmware'       => $boolFromCheckbox($aptIn['non_free_firmware'] ?? null),
                'contrib'                 => $boolFromCheckbox($aptIn['contrib'] ?? null),
                'non_free'                => $boolFromCheckbox($aptIn['non_free'] ?? null),
                // A blank CSV retains the installer default. A separate
                // explicit choice represents an intentionally empty list.
                'services_select'         => $boolFromCheckbox($aptIn['disable_services'] ?? null)
                    ? [] : (!empty($services) ? $services : null),
                'security_host'           => $strOrNull($aptIn['security_host'] ?? null),
                'hw_detect_load_firmware' => $boolFromCheckbox($aptIn['hw_detect_load_firmware'] ?? null),
            ],
            'bootloader' => [
                'only_debian'   => $boolFromCheckbox($bl['only_debian'] ?? null),
                'with_other_os' => $boolFromCheckbox($bl['with_other_os'] ?? null),
                'bootdev'       => $strOrNull($bl['bootdev'] ?? null) ?? 'default',
            ],
            'finish' => [
                'reboot'      => $strOrNull($fin['reboot'] ?? null) ?? 'note',
                'eject_cdrom' => array_key_exists('eject_cdrom', $fin) ? $boolFromCheckbox($fin['eject_cdrom']) : null,
            ],
            'post_install' => [
                'remote_pubkey_urls' => $urls,
            ],
        ];
    }

    /**
     * Construit le subset de la ligne preset utile à une install (= snapshot).
     * Utilisé par le wizard d'install (chargement template) et par bulkInstall (copy preset → machines.install_config_json).
     *
     * @param array<string,mixed> $preset ligne SQL d'un preset
     * @return array<string,mixed>
     */
    public static function toInstallConfig(array $preset): array
    {
        return [
            'name'                  => $preset['name'] ?? null,
            'domain'                => $preset['domain'] ?? null,
            'distro'                => $preset['distro'] ?? 'debian',
            'codename'              => $preset['codename'] ?? null,
            'version_full'          => $preset['version_full'] ?? null,
            'network_mode'          => $preset['network_mode'] ?? 'dhcp',
            'static_ip'             => $preset['static_ip'] ?? null,
            'static_cidr'           => isset($preset['static_cidr']) ? (int)$preset['static_cidr'] : null,
            'static_gateway'        => $preset['static_gateway'] ?? null,
            'static_dns'            => self::parseDnsList($preset['static_dns'] ?? null),
            'partition_mode'        => $preset['partition_mode'] ?? 'default',
            // Presets are hardware agnostic. Even a manually imported/legacy JSON
            // blob must not carry a physical disk serial to another machine.
            'partition_custom_json' => ($preset['partition_mode'] ?? 'default') === 'custom'
                ? json_encode(self::parsePartitioningPlan($preset['partition_custom_json'] ?? null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'admin_user_login'      => $preset['admin_user_login'] ?? '',
            'admin_user_pwhash'     => $preset['admin_user_pwhash'] ?? null,
            'admin_user_password_cipher' => $preset['admin_user_password_cipher'] ?? null,
            'admin_user_groups'     => $preset['admin_user_groups'] ?? 'sudo',
            'admin_user_pubkeys'    => $preset['admin_user_pubkeys'] ?? null,
            'admin_user_fullname'   => $preset['admin_user_fullname'] ?? null,
            'root_login_mode'       => $preset['root_login_mode'] ?? 'enabled',
            'advanced'              => self::parseAdvancedConfig($preset['advanced_config_json'] ?? null),
            // Phase 3 chantier preset-hardware-aware : snapshot multi-NIC normalisé.
            // Source priorité : advanced_config_json.network > colonnes flat legacy.
            // Le snapshot porte les deux pour rétrocompat preseed.php.j2 (qui peut
            // continuer à lire network_mode/static_* le temps de la transition).
            'network'               => self::parseNetwork($preset),
            // Preserve historical variants and roles without inventing a role.
            'variant'                => $preset['variant'] ?? 'debian',
            'ansible_role'          => $preset['ansible_role'] ?? null,
            'ansible_extra_vars'    => $preset['ansible_extra_vars'] ?? null,
            // pre_wipe_mode : transmis tel quel (scalar ou JSON dict) — le wizard
            // le décodera via parseWipeMode() ; MachineController::installSubmit
            // le consomme via extractPreWipeModes().
            'pre_wipe_mode'         => $preset['pre_wipe_mode'] ?? 'aucun',
            // Chantier hardware-aware (Phase 2) : propagés dans le snapshot pour
            // audit + restitution. Le pre_wipe_mode_json est la source moderne
            // per-disque (le scalar reste pour rétro-compat). based_on_machine_id
            // = trace de provenance hardware (la machine peut avoir été supprimée
            // entre-temps, c'est non-bloquant).
            'based_on_machine_id'   => isset($preset['based_on_machine_id']) ? (int)$preset['based_on_machine_id'] : null,
            'disk_target_constraints' => \Admin\Util\DiskIdentity::constraintsForPreset($preset),
            // hostname_pattern : nom statique (cf. migrate.php — la colonne garde son
            // nom historique, mais ne contient plus de placeholders).
            'hostname_pattern'      => $preset['hostname_pattern'] ?? null,
            'hostname_random'       => !empty($preset['hostname_random']) ? 1 : 0,
            'pre_wipe_mode_json'    => $preset['pre_wipe_mode_json'] ?? null,
        ];
    }

    /**
     * Décode le champ pre_wipe_mode en dict {disk_name: mode}.
     *
     * Rétrocompat Option A : si la valeur est un scalar ('aucun'|'partial'|'full'),
     * elle est appliquée à tous les disques fournis.
     * Si c'est un JSON dict, il est décodé tel quel.
     * Default per-disk = 'aucun' (safe).
     *
     * @param mixed         $raw        Valeur brute (scalar ou JSON string ou array)
     * @param array<string> $diskNames  Noms de disques connus (pour expansion scalar)
     * @return array<string,string>     {disk_name => mode}
     */
    public static function parseWipeMode(mixed $raw, array $diskNames = []): array
    {
        $validModes = ['aucun', 'none', 'partial', 'full'];
        $normalizeMode = static function (string $m): string {
            // 'none' est un alias DB legacy de 'aucun' (wipe.sh utilise 'none')
            return $m === 'none' ? 'aucun' : $m;
        };

        // Cas 1 : array PHP déjà décodé (dict {disk: mode})
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $disk => $mode) {
                $m = $normalizeMode((string)$mode);
                $out[(string)$disk] = in_array($m, $validModes, true) ? $m : 'aucun';
            }
            return $out;
        }

        if (!is_string($raw) || trim($raw) === '') {
            // Pas de config → tous disques 'aucun'
            return array_fill_keys($diskNames, 'aucun');
        }

        // Cas 2 : JSON dict (format nouveau)
        if (str_starts_with(trim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $out = [];
                foreach ($decoded as $disk => $mode) {
                    $m = $normalizeMode((string)$mode);
                    $out[(string)$disk] = in_array($m, $validModes, true) ? $m : 'aucun';
                }
                return $out;
            }
        }

        // Cas 3 : scalar legacy ('aucun'|'partial'|'full') → applique à tous les disques
        $m = $normalizeMode(trim($raw));
        if (!in_array($m, $validModes, true)) $m = 'aucun';
        return array_fill_keys($diskNames, $m);
    }

    /**
     * Plan de partitionnement vide (squelette par défaut quand aucun JSON valide).
     * @return array{target_disks:array<int,string>,disks:array<int,array<string,mixed>>,raid_arrays:array<int,array<string,mixed>>,vgs:array<int,array<string,mixed>>,logical_volumes:array<int,array<string,mixed>>}
     */
    public static function emptyPartitioningPlan(): array
    {
        return [
            'target_disks'    => [],
            'disks'           => [],
            'raid_arrays'     => [],
            'vgs'             => [],
            'logical_volumes' => [],
        ];
    }

    /**
     * Décode le plan arborescent de partitionnement (multi-disques + LVM multi-VG + RAID).
     * Structure attendue :
     *   {
     *     target_disks:    ["/dev/sda", ...],
     *     disks:           [{path, partitions: [{id, fs, mount?, size_mb, fill, reserved_pct?, mount_options?}]}],
     *     raid_arrays:     [{name, level, members[], spares[], is_pv}],            // Phase B
     *     vgs:             [{name, pvs[]}],
     *     logical_volumes: [{vg, name, fs, mount?, size_mb, fill, reserved_pct?, mount_options?}],
     *   }
     *
     * Tolère les snapshots legacy (ancien format plat `[{fs,mount,...}]`) en renvoyant un plan vide
     * — l'utilisateur doit recréer le preset (compat cassée volontairement).
     */
    public static function parsePartitioningPlan(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') return self::emptyPartitioningPlan();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return self::emptyPartitioningPlan();

        // Détection format legacy : array indexée numérique avec des objets {fs,...}
        // → plan vide (compat cassée, l'utilisateur doit recréer le preset).
        if (array_is_list($decoded)) return self::emptyPartitioningPlan();

        $cleanStr = static fn($v): ?string => (is_string($v) && trim($v) !== '') ? trim($v) : null;
        $cleanInt = static fn($v): int => is_numeric($v) ? (int)$v : 0;
        $cleanReserved = static function ($v): ?int {
            if ($v === null || $v === '' || !is_numeric($v)) return null;
            return max(0, min(50, (int)$v));
        };
        $cleanOpts = static function ($v): array {
            if (!is_array($v)) return [];
            return array_values(array_filter(
                array_map(static fn($x) => trim((string)$x), $v),
                static fn($x) => $x !== ''
            ));
        };

        // target_disks
        $targetDisks = [];
        if (isset($decoded['target_disks']) && is_array($decoded['target_disks'])) {
            foreach ($decoded['target_disks'] as $d) {
                $s = $cleanStr($d);
                if ($s !== null) $targetDisks[] = $s;
            }
            $targetDisks = array_values(array_unique($targetDisks));
        }

        // disks[].partitions[]
        $disks = [];
        if (isset($decoded['disks']) && is_array($decoded['disks'])) {
            foreach ($decoded['disks'] as $d) {
                if (!is_array($d)) continue;
                $path = $cleanStr($d['path'] ?? null);
                if ($path === null) continue;
                $parts = [];
                if (isset($d['partitions']) && is_array($d['partitions'])) {
                    foreach ($d['partitions'] as $p) {
                        if (!is_array($p)) continue;
                        $fs = $cleanStr($p['fs'] ?? null);
                        if ($fs === null) continue;
                        $parts[] = [
                            'id'            => $cleanStr($p['id'] ?? null),
                            'fs'            => $fs,
                            'mount'         => $cleanStr($p['mount'] ?? null),
                            'size_mb'       => $cleanInt($p['size_mb'] ?? 0),
                            'fill'          => (bool)($p['fill'] ?? false),
                            'reserved_pct'  => $cleanReserved($p['reserved_pct'] ?? null),
                            'mount_options' => $cleanOpts($p['mount_options'] ?? []),
                        ];
                    }
                }
                $disks[] = ['path' => $path, 'partitions' => $parts];
            }
        }

        // raid_arrays (Phase B — décodé mais pas encore utilisé en preseed)
        $raidArrays = [];
        if (isset($decoded['raid_arrays']) && is_array($decoded['raid_arrays'])) {
            foreach ($decoded['raid_arrays'] as $r) {
                if (!is_array($r)) continue;
                $name = $cleanStr($r['name'] ?? null);
                if ($name === null) continue;
                $level = is_numeric($r['level'] ?? null) ? (int)$r['level'] : null;
                if ($level === null || !in_array($level, self::RAID_LEVELS, true)) continue;
                $members = is_array($r['members'] ?? null)
                    ? array_values(array_filter(array_map($cleanStr, $r['members']), fn($x) => $x !== null))
                    : [];
                $spares = is_array($r['spares'] ?? null)
                    ? array_values(array_filter(array_map($cleanStr, $r['spares']), fn($x) => $x !== null))
                    : [];
                $raidArrays[] = [
                    'name'    => $name,
                    'level'   => $level,
                    'members' => $members,
                    'spares'  => $spares,
                    'is_pv'   => (bool)($r['is_pv'] ?? false),
                ];
            }
        }

        // vgs
        $vgs = [];
        if (isset($decoded['vgs']) && is_array($decoded['vgs'])) {
            foreach ($decoded['vgs'] as $v) {
                if (!is_array($v)) continue;
                $name = $cleanStr($v['name'] ?? null);
                if ($name === null) continue;
                $pvs = is_array($v['pvs'] ?? null)
                    ? array_values(array_filter(array_map($cleanStr, $v['pvs']), fn($x) => $x !== null))
                    : [];
                $vgs[] = ['name' => $name, 'pvs' => $pvs];
            }
        }

        // logical_volumes
        $lvs = [];
        if (isset($decoded['logical_volumes']) && is_array($decoded['logical_volumes'])) {
            foreach ($decoded['logical_volumes'] as $lv) {
                if (!is_array($lv)) continue;
                $vg = $cleanStr($lv['vg'] ?? null);
                $name = $cleanStr($lv['name'] ?? null);
                $fs = $cleanStr($lv['fs'] ?? null);
                if ($vg === null || $name === null || $fs === null) continue;
                $lvs[] = [
                    'vg'            => $vg,
                    'name'          => $name,
                    'fs'            => $fs,
                    'mount'         => $cleanStr($lv['mount'] ?? null),
                    'size_mb'       => $cleanInt($lv['size_mb'] ?? 0),
                    'fill'          => (bool)($lv['fill'] ?? false),
                    'reserved_pct'  => $cleanReserved($lv['reserved_pct'] ?? null),
                    'mount_options' => $cleanOpts($lv['mount_options'] ?? []),
                ];
            }
        }

        return [
            'target_disks'    => $targetDisks,
            'disks'           => $disks,
            'raid_arrays'     => $raidArrays,
            'vgs'             => $vgs,
            'logical_volumes' => $lvs,
        ];
    }
}
