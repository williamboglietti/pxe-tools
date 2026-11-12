<?php
declare(strict_types=1);

namespace Admin\Util;

use Admin\Models\Preset;
use Admin\Models\Setting;

/**
 * Helpers de parsing + validation des données POST du form d'install.
 *
 * Source unique pour :
 *   - construction du dict install_config_json (machine wizard)
 *   - validation stricte des champs
 *   - hash sha512crypt du password admin
 *
 * Utilisé par MachineController (wizard d'install).
 * PresetController garde sa logique en propre tant qu'aucune raison forte de fusionner.
 */
final class InstallConfigBuilder
{
    /**
     * Overhead LVM/partman à déduire de la capacité brute du VG avant comparer
     * la somme des tailles de LVs. Sans ces marges, un plan dont la somme min
     * tient juste sous la capacité du PV (~1-30 MB SI) passe la validation
     * mais partman fait fallback atomic silencieux à l'exécution (LVs nommés
     * root/swap_1, pas de lv_var). Cf. lessons-session-2026-05-20-bis.
     * Valeurs en MB SI, conservatrices pour couvrir les defaults LVM2 +
     * marge interne partman.
     */
    public const LVM_PV_HEADER_MB     = 4;  // header PV (~1 MiB) + safety
    public const LVM_LV_ALIGNMENT_MB  = 4;  // 1 PE (4 MiB) max perdu par LV à l'alignement
    public const PARTMAN_VG_SAFETY_MB = 16; // marge interne calcul de contraintes partman

    /**
     * Hash le password si fourni en POST, sinon retourne le hash existant
     * (transmis via input hidden admin_user_pwhash_existing).
     */
    public static function hashPasswordFromPost(): ?string
    {
        $pw = trim((string)($_POST['admin_user_password'] ?? ''));
        if ($pw !== '') {
            $salt = '$6$rounds=656000$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 16);
            return crypt($pw, $salt);
        }
        $existing = trim((string)($_POST['admin_user_pwhash_existing'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * Chiffre le password admin user en clair via Crypto (master.key) si fourni
     * en POST. Sinon retourne le ciphertext existant (input hidden) ou null.
     * Stocké à côté du hash sha512crypt pour affichage dans l'aperçu machine.
     */
    public static function encryptPasswordFromPost(): ?string
    {
        $pw = (string)($_POST['admin_user_password'] ?? '');
        if (trim($pw) !== '') {
            return Crypto::encrypt($pw);
        }
        $existing = trim((string)($_POST['admin_user_password_cipher_existing'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * Spec F.2 — Hash le mot de passe root per-machine si fourni en POST,
     * sinon retourne le hash existant (transmis via input hidden) OU null
     * (auquel cas preseed.php.j2 fallback sur la valeur globale).
     */
    public static function hashRootPasswordFromPost(): ?string
    {
        $pw = trim((string)($_POST['root_password'] ?? ''));
        if ($pw !== '') {
            $salt = '$6$rounds=656000$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 16);
            return crypt($pw, $salt);
        }
        $existing = trim((string)($_POST['root_password_hash_existing'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * Chiffre le mdp root en clair via Crypto (master.key) si fourni en POST.
     * Sinon retourne le ciphertext existant (input hidden) ou null. Stocké dans
     * machines.root_password_cipher pour affichage dans l'aperçu.
     */
    public static function encryptRootPasswordFromPost(): ?string
    {
        $pw = (string)($_POST['root_password'] ?? '');
        if (trim($pw) !== '') {
            return Crypto::encrypt($pw);
        }
        $existing = trim((string)($_POST['root_password_cipher_existing'] ?? ''));
        return $existing !== '' ? $existing : null;
    }

    /**
     * Extrait les DNS effectivement soumis. Une valeur effacée reste vide :
     * la validation signalera un DNS manquant si l'adresse est statique.
     *
     * @return array<int,string>
     */
    public static function extractDnsList(): array
    {
        $raw = $_POST['dns'] ?? [];
        if (!is_array($raw)) return ['invalid'];
        $dns = [];
        foreach ($raw as $value) {
            // Keep malformed values invalid so static-network validation
            // refuses them instead of quietly dropping a submitted entry.
            if (!is_string($value)) { $dns[] = 'invalid'; continue; }
            $value = trim($value);
            if ($value !== '') $dns[] = $value;
        }
        return $dns;
    }

    /**
     * Extrait le plan arborescent de partitionnement depuis $_POST.
     *
     * Structure POST attendue :
     *   target_disks[]                      = ["/dev/sda", ...]
     *   disks[<i>][path]                    = "/dev/sda"
     *   disks[<i>][partitions][<j>][id]     = "sda1"     (auto-généré côté JS)
     *   disks[<i>][partitions][<j>][fs]     = "ext4"
     *   disks[<i>][partitions][<j>][mount]  = "/boot"
     *   disks[<i>][partitions][<j>][size_mb]= 1000
     *   disks[<i>][partitions][<j>][fill]   = "1"
     *   disks[<i>][partitions][<j>][reserved_pct]    = 5
     *   disks[<i>][partitions][<j>][mount_options][] = "noatime"
     *   disks[<i>][partitions][<j>][mount_options_other] = "discard,relatime"
     *
     *   raid_arrays[<i>][name]     = "md0"     (Phase B)
     *   raid_arrays[<i>][level]    = 1
     *   raid_arrays[<i>][members][]= "sda2"
     *   raid_arrays[<i>][spares][] = "sdc1"
     *   raid_arrays[<i>][is_pv]    = "1"
     *
     *   vgs[<i>][name]    = "system_VG"
     *   vgs[<i>][pvs][]   = "sda3"
     *
     *   logical_volumes[<i>][vg]      = "system_VG"
     *   logical_volumes[<i>][name]    = "root"
     *   logical_volumes[<i>][fs]      = "ext4"
     *   logical_volumes[<i>][mount]   = "/"
     *   logical_volumes[<i>][size_mb] = 0
     *   logical_volumes[<i>][fill]    = "1"
     *   logical_volumes[<i>][reserved_pct]    = 5
     *   logical_volumes[<i>][mount_options][] = "noatime"
     *
     * @return array{target_disks:array<int,string>,disks:array<int,array<string,mixed>>,raid_arrays:array<int,array<string,mixed>>,vgs:array<int,array<string,mixed>>,logical_volumes:array<int,array<string,mixed>>}
     */
    public static function extractPartitioningPlan(): array
    {
        $cleanStr = static fn($v): ?string => (is_string($v) && trim($v) !== '') ? trim($v) : null;
        $cleanInt = static fn($v): int => is_numeric($v) ? (int)$v : 0;
        $cleanReserved = static function ($v): ?int {
            if ($v === null || $v === '' || !is_numeric($v)) return null;
            return max(0, min(50, (int)$v));
        };
        $cleanOpts = static function ($v, ?string $other = null): array {
            $out = [];
            if (is_array($v)) {
                foreach ($v as $opt) {
                    $opt = trim((string)$opt);
                    if ($opt !== '') $out[] = $opt;
                }
            }
            if ($other !== null && trim($other) !== '') {
                foreach (preg_split('/[,\s]+/', trim($other)) as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') $out[] = $opt;
                }
            }
            return array_values(array_unique($out));
        };

        // target_disks
        $targetDisks = [];
        if (isset($_POST['target_disks']) && is_array($_POST['target_disks'])) {
            foreach ($_POST['target_disks'] as $d) {
                $s = $cleanStr($d);
                if ($s !== null) $targetDisks[] = $s;
            }
            $targetDisks = array_values(array_unique($targetDisks));
        }

        // Capacités assumées par disque (Go) — saisies depuis disk_capacity_gb[/dev/sda]
        // ou disk_capacity_gb[] indexé. Utilisée pour le calcul fill côté UI sur
        // preset générique ; côté Installation, c'est une preview (l'install
        // effective utilise les vraies capacités de la machine).
        $diskCapacityGb = [];
        if (isset($_POST['disk_capacity_gb']) && is_array($_POST['disk_capacity_gb'])) {
            foreach ($_POST['disk_capacity_gb'] as $k => $v) {
                if (!is_numeric($v) || (int)$v <= 0) continue;
                // Normalise clé (avec ou sans préfixe /dev/) ; ignore les clés numériques
                // (rows ajoutées dynamiquement sans path encore renseigné).
                if (!is_string($k)) continue;
                $key = str_starts_with($k, '/dev/') ? $k : '/dev/' . ltrim($k, '/');
                $diskCapacityGb[$key] = (int)$v;
            }
        }

        // disks[].partitions[]
        $disks = [];
        if (isset($_POST['disks']) && is_array($_POST['disks'])) {
            foreach ($_POST['disks'] as $d) {
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
                            'fill'          => !empty($p['fill']),
                            'reserved_pct'  => $cleanReserved($p['reserved_pct'] ?? null),
                            'mount_options' => $cleanOpts($p['mount_options'] ?? [], (string)($p['mount_options_other'] ?? '')),
                        ];
                    }
                }
                $diskEntry = ['path' => $path, 'partitions' => $parts];
                // Persiste la capacité assumée (preset générique : utilisée au reload
                // pour pré-remplir l'input ; Installation : preview seulement).
                if (isset($diskCapacityGb[$path])) {
                    $diskEntry['assumed_size_gb'] = $diskCapacityGb[$path];
                }
                $disks[] = $diskEntry;
            }
        }

        // raid_arrays (Phase B — extrait mais ignoré côté preseed jusqu'à Phase B)
        $raidArrays = [];
        if (isset($_POST['raid_arrays']) && is_array($_POST['raid_arrays'])) {
            foreach ($_POST['raid_arrays'] as $r) {
                if (!is_array($r)) continue;
                $name = $cleanStr($r['name'] ?? null);
                if ($name === null) continue;
                $level = is_numeric($r['level'] ?? null) ? (int)$r['level'] : null;
                if ($level === null || !in_array($level, Preset::RAID_LEVELS, true)) continue;
                $members = isset($r['members']) && is_array($r['members'])
                    ? array_values(array_filter(array_map($cleanStr, $r['members']), fn($x) => $x !== null))
                    : [];
                $spares = isset($r['spares']) && is_array($r['spares'])
                    ? array_values(array_filter(array_map($cleanStr, $r['spares']), fn($x) => $x !== null))
                    : [];
                $raidArrays[] = [
                    'name'    => $name,
                    'level'   => $level,
                    'members' => $members,
                    'spares'  => $spares,
                    'is_pv'   => !empty($r['is_pv']),
                ];
            }
        }

        // vgs
        $vgs = [];
        if (isset($_POST['vgs']) && is_array($_POST['vgs'])) {
            foreach ($_POST['vgs'] as $v) {
                if (!is_array($v)) continue;
                $name = $cleanStr($v['name'] ?? null);
                if ($name === null) continue;
                $pvs = isset($v['pvs']) && is_array($v['pvs'])
                    ? array_values(array_filter(array_map($cleanStr, $v['pvs']), fn($x) => $x !== null))
                    : [];
                $vgs[] = ['name' => $name, 'pvs' => $pvs];
            }
        }

        // logical_volumes
        $lvs = [];
        if (isset($_POST['logical_volumes']) && is_array($_POST['logical_volumes'])) {
            foreach ($_POST['logical_volumes'] as $lv) {
                if (!is_array($lv)) continue;
                $vg   = $cleanStr($lv['vg'] ?? null);
                $name = $cleanStr($lv['name'] ?? null);
                $fs   = $cleanStr($lv['fs'] ?? null);
                if ($vg === null || $name === null || $fs === null) continue;
                $lvs[] = [
                    'vg'            => $vg,
                    'name'          => $name,
                    'fs'            => $fs,
                    'mount'         => $cleanStr($lv['mount'] ?? null),
                    'size_mb'       => $cleanInt($lv['size_mb'] ?? 0),
                    'fill'          => !empty($lv['fill']),
                    'reserved_pct'  => $cleanReserved($lv['reserved_pct'] ?? null),
                    'mount_options' => $cleanOpts($lv['mount_options'] ?? [], (string)($lv['mount_options_other'] ?? '')),
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

    /**
     * Extrait le plan réseau multi-NIC depuis $_POST (Phase 3 chantier preset-hardware-aware).
     *
     * Structure POST attendue (1 entrée par NIC découverte) :
     *   network[interfaces][<i>][match_by]     = "primary|index|mac"
     *   network[interfaces][<i>][index]        = 0
     *   network[interfaces][<i>][match][mac]   = "aa:bb:cc:..."   (optionnel)
     *   network[interfaces][<i>][mode]         = "dhcp|static|disabled"
     *   network[interfaces][<i>][static][ip]   = "192.0.2.42"
     *   network[interfaces][<i>][static][cidr] = 23
     *   network[interfaces][<i>][static][gateway] = "192.0.2.1"
     *   network[interfaces][<i>][static][dns][] = "1.1.1.1"
     *   network[interfaces][<i>][vlan][id]     = 100              (optionnel)
     *   network[interfaces][<i>][bond][role]   = "master|slave"
     *   network[interfaces][<i>][bond][name]   = "bond0"
     *   network[interfaces][<i>][bond][mode]   = "active-backup"
     *   network[primary]                       = 0                (radio)
     *
     * Tolère POST vide → fallback structure DHCP single-NIC primaire (matche
     * la branche legacy de `Preset::parseNetwork()`).
     *
     * @return array{interfaces: list<array<string,mixed>>, primary: int}
     */
    public static function extractNetworkPlan(): array
    {
        $raw = $_POST['network'] ?? null;
        if (!is_array($raw) || !isset($raw['interfaces']) || !is_array($raw['interfaces'])) {
            return ['interfaces' => [], 'primary' => 0];
        }

        $interfaces = [];
        foreach ($raw['interfaces'] as $idx => $iface) {
            if (!is_array($iface)) continue;
            $mode = in_array($iface['mode'] ?? '', ['dhcp', 'static', 'disabled'], true)
                ? $iface['mode'] : 'dhcp';
            $matchBy = in_array($iface['match_by'] ?? '', ['primary', 'index', 'mac'], true)
                ? $iface['match_by'] : 'primary';

            $match = null;
            if (isset($iface['match']['mac']) && trim((string)$iface['match']['mac']) !== '') {
                $match = ['mac' => strtolower(trim((string)$iface['match']['mac']))];
            }

            $static = null;
            if ($mode === 'static' && isset($iface['static']) && is_array($iface['static'])) {
                $st = $iface['static'];
                $dnsRaw = $st['dns'] ?? [];
                $dns = is_array($dnsRaw)
                    ? array_values(array_filter(array_map(fn($x) => trim((string)$x), $dnsRaw), fn($x) => $x !== ''))
                    : [];
                $static = [
                    'ip'      => trim((string)($st['ip'] ?? '')),
                    'cidr'    => is_numeric($st['cidr'] ?? null) ? (int)$st['cidr'] : 24,
                    'gateway' => trim((string)($st['gateway'] ?? '')),
                    'dns'     => $dns,
                ];
            }

            $vlan = null;
            if (isset($iface['vlan']['id']) && is_numeric($iface['vlan']['id'])) {
                $vid = (int)$iface['vlan']['id'];
                if ($vid >= 1 && $vid <= 4094) $vlan = ['id' => $vid];
            }

            $bond = null;
            if (isset($iface['bond']) && is_array($iface['bond'])) {
                $role = in_array($iface['bond']['role'] ?? '', ['master', 'slave'], true)
                    ? $iface['bond']['role'] : null;
                $bondName = trim((string)($iface['bond']['name'] ?? ''));
                if ($role !== null && $bondName !== '') {
                    $bond = [
                        'role' => $role,
                        'name' => $bondName,
                        'mode' => trim((string)($iface['bond']['mode'] ?? 'active-backup')) ?: 'active-backup',
                    ];
                }
            }

            $interfaces[] = [
                'match_by' => $matchBy,
                'index'    => is_numeric($iface['index'] ?? null) ? (int)$iface['index'] : (int)$idx,
                'match'    => $match,
                'mode'     => $mode,
                'static'   => $static,
                'vlan'     => $vlan,
                'bond'     => $bond,
            ];
        }

        $primary = is_numeric($raw['primary'] ?? null) ? (int)$raw['primary'] : 0;
        if (!empty($interfaces)) {
            $primary = max(0, min($primary, count($interfaces) - 1));
        } else {
            $primary = 0;
        }

        return ['interfaces' => $interfaces, 'primary' => $primary];
    }

    /**
     * Garde les colonnes réseau historiques cohérentes avec la NIC primaire
     * d'un plan multi-NIC. Le preseed lit ce plan en priorité, mais ces colonnes
     * servent encore à l'API et aux formulaires legacy.
     *
     * @return array{network_mode:string,static_ip:?string,static_cidr:?int,static_gateway:?string,static_dns:array<int,string>}|null
     */
    public static function legacyNetworkFromPlan(array $plan): ?array
    {
        $interfaces = $plan['interfaces'] ?? [];
        if (!is_array($interfaces) || $interfaces === []) return null;
        $index = isset($plan['primary']) ? (int)$plan['primary'] : 0;
        $primary = $interfaces[$index] ?? null;
        if (is_array($primary) && !empty($primary['bond']['name'])) {
            foreach ($interfaces as $iface) {
                if (($iface['bond']['name'] ?? '') === $primary['bond']['name'] && ($iface['bond']['role'] ?? '') === 'master') {
                    $primary = $iface;
                    break;
                }
            }
        }
        if (!is_array($primary) || ($primary['mode'] ?? '') !== 'static') {
            return ['network_mode' => 'dhcp', 'static_ip' => null, 'static_cidr' => null,
                'static_gateway' => null, 'static_dns' => []];
        }
        $static = is_array($primary['static'] ?? null) ? $primary['static'] : [];
        return [
            'network_mode' => 'static',
            'static_ip' => trim((string)($static['ip'] ?? '')) ?: null,
            'static_cidr' => isset($static['cidr']) ? (int)$static['cidr'] : null,
            'static_gateway' => trim((string)($static['gateway'] ?? '')) ?: null,
            'static_dns' => is_array($static['dns'] ?? null) ? array_values($static['dns']) : [],
        ];
    }

    /**
     * Vérifie qu'une IP IPv4 est dans le subnet d'une IP+CIDR de référence.
     * Helper privé pour validateNetworkPlan (gateway dans le subnet).
     */
    private static function cidrContains(string $networkIp, int $cidr, string $candidateIp): bool
    {
        if ($cidr < 1 || $cidr > 32) return false;
        $a = ip2long($networkIp);
        $b = ip2long($candidateIp);
        if ($a === false || $b === false) return false;
        $mask = $cidr === 0 ? 0 : (-1 << (32 - $cidr)) & 0xFFFFFFFF;
        return ($a & $mask) === ($b & $mask);
    }

    /**
     * Valide un plan réseau multi-NIC (Phase 3 chantier preset-hardware-aware).
     *
     * Invariants vérifiés :
     *   - Mode static : IP/CIDR/gateway IPv4 valides + ≥1 DNS IPv4
     *   - Gateway DOIT être dans le subnet de l'IP statique
     *   - Si $availableNics fournis : refuse les `match.mac` inconnus
     *   - Au moins 1 NIC active (mode != 'disabled') comme primary
     *   - Une seule NIC primaire si match_by=primary
     *   - VLAN id entre 1 et 4094
     *
     * Tolère un plan vide : retourne array vide d'erreurs (legacy presets sans
     * structure multi-NIC sont autorisés ; ils tombent sur la NIC primaire DHCP
     * via `Preset::parseNetwork()`).
     *
     * @param array{interfaces: list<array<string,mixed>>, primary: int} $plan
     * @param array<int,array<string,mixed>> $availableNics inventaire HardwareContext::fromMachine()['nics']
     * @return array<int,string>
     */
    public static function validateNetworkPlan(array $plan, array $availableNics = []): array
    {
        $errors = [];
        $interfaces = $plan['interfaces'] ?? [];
        if (empty($interfaces)) return $errors;
        if (!is_array($interfaces) || !array_is_list($interfaces)) return ['Réseau : interfaces doit être une liste de cartes réseau.'];

        // Set des MACs connues (lowercase) pour rejet match.mac inconnu
        $knownMacs = [];
        foreach ($availableNics as $nic) {
            $mac = strtolower(trim((string)($nic['mac'] ?? '')));
            if ($mac !== '') $knownMacs[$mac] = true;
        }

        $activeCount = 0;
        $usedMacs = [];
        foreach ($interfaces as $i => $iface) {
            $label = 'NIC #' . ($i + 1);
            if (!is_array($iface)) { $errors[] = "$label : configuration invalide"; continue; }
            $mode = $iface['mode'] ?? 'dhcp';
            if (!in_array($mode, ['dhcp', 'static', 'disabled'], true)) $errors[] = "$label : mode réseau inconnu";
            $matchBy = $iface['match_by'] ?? 'primary';
            if (!in_array($matchBy, ['primary', 'index', 'mac'], true)) $errors[] = "$label : sélection réseau inconnue";
            $mac = strtolower(trim((string)($iface['match']['mac'] ?? '')));
            if ($mac !== '' && !preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/D', $mac)) $errors[] = "$label : MAC invalide";
            if ($mac !== '' && isset($usedMacs[$mac])) $errors[] = "$label : MAC déclarée plusieurs fois ($mac)";
            if ($mac !== '') $usedMacs[$mac] = true;
            if ($matchBy === 'mac' && $mac === '') $errors[] = "$label : match_by=mac mais aucun MAC fourni";
            if ($mode !== 'disabled' && $i !== (int)($plan['primary'] ?? 0) && $mac === '') {
                $errors[] = "$label : MAC requise pour configurer une interface secondaire après installation";
            }

            if ($mode !== 'disabled') $activeCount++;

            // Vérification match.mac inconnu si on a un inventaire de référence
            if (!empty($knownMacs) && ($matchBy === 'mac' || $mac !== '')) {
                if ($mac === '') {
                    $errors[] = "$label : match_by=mac mais aucun MAC fourni";
                } elseif (!isset($knownMacs[$mac])) {
                    $errors[] = "$label : MAC $mac introuvable dans l'inventaire matériel de la machine de référence";
                }
            }

            if ($mode === 'static') {
                $st = is_array($iface['static'] ?? null) ? $iface['static'] : [];
                $ip   = (string)($st['ip'] ?? '');
                $cidr = is_numeric($st['cidr'] ?? null) ? (int)$st['cidr'] : 0;
                $gw   = (string)($st['gateway'] ?? '');
                $dns  = is_array($st['dns'] ?? null) ? $st['dns'] : Preset::parseDnsList(is_string($st['dns'] ?? null) ? $st['dns'] : null);

                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors[] = "$label (static) : IP IPv4 valide requise";
                }
                if ($cidr < 1 || $cidr > 32) {
                    $errors[] = "$label (static) : préfixe CIDR (1-32) requis";
                }
                if (!filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors[] = "$label (static) : gateway IPv4 valide requise";
                }
                // Gateway dans le subnet — seulement si IP+CIDR+GW tous valides
                if ($cidr >= 1 && $cidr <= 32
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && filter_var($gw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    && !self::cidrContains($ip, $cidr, $gw)) {
                    $errors[] = "$label (static) : passerelle $gw hors du subnet $ip/$cidr";
                }

                $dnsValid = array_filter($dns, fn($x) => filter_var($x, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
                if (empty($dnsValid)) {
                    $errors[] = "$label (static) : au moins un DNS IPv4 valide requis";
                } elseif (count($dnsValid) !== count($dns)) {
                    $errors[] = "$label (static) : chaque DNS doit être une adresse IPv4 valide";
                }
            }

            if (isset($iface['vlan']['id'])) {
                $vid = $iface['vlan']['id'];
                if (!is_numeric($vid) || (int)$vid != $vid || (int)$vid < 1 || (int)$vid > 4094) {
                    $errors[] = "$label : VLAN id $vid hors plage 1-4094";
                }
                if ($mode !== 'disabled' && $i === (int)($plan['primary'] ?? 0)) {
                    $errors[] = "$label : VLAN sur l’interface primaire non supporté par le boot Debian Installer ; utilisez un port PXE non tagué.";
                }
            }
        }

        if ($activeCount === 0) {
            $errors[] = 'Au moins une interface réseau doit être active (mode dhcp ou static)';
        }

        $errors = array_merge($errors, self::validateBonds($interfaces));

        // Primary doit pointer une interface non-disabled
        $primaryIdx = (int)($plan['primary'] ?? 0);
        if ($primaryIdx < 0 || $primaryIdx >= count($interfaces)) {
            $errors[] = "Interface primaire (index $primaryIdx) hors plage";
        } elseif (($interfaces[$primaryIdx]['mode'] ?? '') === 'disabled') {
            $errors[] = 'Interface primaire ne peut pas être désactivée';
        }

        return $errors;
    }

    /** Resolve selectors against this target so the renderer uses the reviewed MAC. */
    public static function bindNetworkConfig(array $config, array $machine): array
    {
        $hw = HardwareContext::fromMachine($machine);
        if (!$hw['nics']) return $config;
        $network = isset($config['network']['interfaces']) && $config['network']['interfaces'] !== []
            ? $config['network'] : Preset::parseNetwork($config);
        foreach ($network['interfaces'] as &$iface) {
            $matchBy = $iface['match_by'] ?? 'primary';
            foreach ($hw['nics'] as $index => $nic) {
                $matches = ($matchBy === 'mac' && strtolower((string)($iface['match']['mac'] ?? '')) === strtolower($nic['mac']))
                    || ($matchBy === 'index' && (int)($iface['index'] ?? 0) === $index)
                    || ($matchBy === 'primary' && strtolower($nic['mac']) === strtolower($hw['boot_mac']));
                if (!$matches) continue;
                $iface['match_by'] = 'mac';
                $iface['match'] = ['mac' => strtolower($nic['mac'])];
                $iface['index'] = $index;
                break;
            }
        }
        unset($iface);
        $config['network'] = $network;
        return $config;
    }

    /** Custom recipes must agree with the firmware observed during discovery. */
    public static function validateFirmwarePlan(array $config, ?string $observedMode): array
    {
        if (($config['partition_mode'] ?? 'default') !== 'custom') return [];
        $plan = Preset::parsePartitioningPlan($config['partition_custom_json'] ?? null);
        $targets = $plan['target_disks'];
        if (!$targets) return [];
        $errors = [];
        $bootdev = $config['advanced']['bootloader']['bootdev'] ?? 'default';
        if ($bootdev === 'default') {
            if (count($targets) > 1) $errors[] = 'Bootdev : choisissez explicitement un disque cible pour GRUB dans un plan multi-disques.';
            $bootdev = $targets[0];
        } elseif (!in_array($bootdev, $targets, true)) {
            $errors[] = 'Bootdev : le disque GRUB doit faire partie des disques cibles vérifiés.';
        }
        $efi = []; $bios = []; $hasGpt = false;
        foreach ($plan['disks'] as $disk) foreach ($disk['partitions'] as $part) {
            if ($part['fs'] === 'efi') { $efi[] = $disk['path']; $hasGpt = true; }
            if ($part['fs'] === 'biosgrub') { $bios[] = $disk['path']; $hasGpt = true; }
        }
        if ($efi && $bios) $errors[] = 'Mode de boot : choisissez une recette UEFI ou BIOS ; les partitions efi et biosgrub ne doivent pas être mélangées.';
        $observedMode = strtolower((string)$observedMode);
        if (!in_array($observedMode, ['uefi', 'bios'], true)) {
            $errors[] = 'Mode de boot inconnu : relancez un discovery en BIOS ou UEFI avant de confirmer le partitionnement personnalisé.';
        } elseif ($observedMode === 'uefi') {
            if (!in_array($bootdev, $efi, true)) $errors[] = 'Mode UEFI : une partition efi montée sur /boot/efi est requise sur le disque GRUB.';
            if ($bios) $errors[] = 'Mode UEFI incompatible avec une recette BIOS biosgrub.';
        } else {
            if ($efi) $errors[] = 'Mode BIOS incompatible avec une recette UEFI efi.';
            if ($hasGpt && !in_array($bootdev, $bios, true)) $errors[] = 'Mode BIOS sur GPT : une partition biosgrub est requise sur le disque GRUB.';
        }
        return $errors;
    }

    /**
     * Un bond = toutes les NICs partageant bond.name ; le « master » porte l'IP.
     * Le rendu (preseed.php.j2) suppose : 1 master, ≥ 2 membres identifiés par MAC,
     * nom d'interface valide, mode connu, pas de VLAN sur un membre.
     *
     * @param list<array<string,mixed>> $interfaces
     * @return array<int,string>
     */
    private static function validateBonds(array $interfaces): array
    {
        $modes  = ['active-backup', 'balance-rr', 'balance-xor', 'broadcast', '802.3ad', 'balance-tlb', 'balance-alb'];
        $groups = [];
        $errors = [];
        foreach ($interfaces as $i => $iface) {
            $bond = is_array($iface['bond'] ?? null) ? $iface['bond'] : [];
            if (empty($bond['role']) && empty($bond['name'])) continue;
            $name = trim((string)($bond['name'] ?? ''));
            if (!in_array($bond['role'] ?? '', ['master', 'slave'], true) || $name === '') {
                $errors[] = 'NIC #' . ($i + 1) . " : agrégation incomplète (rôle et nom du bond requis)";
                continue;
            }
            $groups[$name][] = [$i, $iface, $bond];
        }

        foreach ($groups as $name => $members) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,14}$/D', (string)$name)) {
                $errors[] = "Bond « $name » : nom invalide (lettres, chiffres, - et _, 15 caractères max)";
            }
            if (count($members) < 2) {
                $errors[] = "Bond $name : au moins 2 interfaces requises";
            }
            $masters = array_filter($members, fn($m) => $m[2]['role'] === 'master');
            if (count($masters) !== 1) {
                $errors[] = "Bond $name : exactement 1 interface « master » requise (porte l'IP du bond)";
            } else {
                [$mi, $master, $mb] = reset($masters);
                if (($master['mode'] ?? 'dhcp') === 'disabled') {
                    $errors[] = 'NIC #' . ($mi + 1) . " : le master du bond $name ne peut pas être désactivé";
                }
                if (!in_array($mb['mode'] ?? 'active-backup', $modes, true)) {
                    $errors[] = "Bond $name : mode « " . ($mb['mode'] ?? '') . " » inconnu";
                }
            }
            foreach ($members as [$mi, $iface]) {
                if (trim((string)($iface['match']['mac'] ?? '')) === '') {
                    $errors[] = 'NIC #' . ($mi + 1) . " : membre du bond $name, MAC requise";
                }
                if (isset($iface['vlan']['id'])) {
                    $errors[] = 'NIC #' . ($mi + 1) . " : VLAN non supporté sur un membre du bond $name";
                }
            }
        }
        return $errors;
    }

    /**
     * Construit le dict install_config_json depuis les données POST.
     * Le format correspond exactement à ce qui est stocké dans machines.install_config_json
     * et lu par preseed.php.
     *
     * @return array<string,mixed>
     */
    public static function buildFromPost(bool $preview = false): array
    {
        $cidrRaw = $_POST['static_cidr'] ?? '';
        $cidr    = ($cidrRaw === '' || $cidrRaw === null) ? null : (int)$cidrRaw;
        $plan    = self::extractPartitioningPlan();
        $isCustom = ($_POST['partition_mode'] ?? '') === 'custom';

        // Phase 3 chantier preset-hardware-aware : si POST contient `network[interfaces]`,
        // on extrait la structure multi-NIC ; sinon on retombe sur les colonnes flat
        // legacy (rétrocompat wizard install single-NIC).
        $networkPlan = self::extractNetworkPlan();
        $legacyNetwork = self::legacyNetworkFromPlan($networkPlan);

        // Keep an inherited variant visible to preflight rather than silently
        // converting it into a supported Debian installation.
        $variant      = (($_POST['variant_proxmox'] ?? '') === '1') ? 'proxmox-ve' : 'debian';
        $distroVal   = (string)($_POST['distro'] ?? '');
        $codenameVal = trim((string)($_POST['codename'] ?? '')) ?: null;
        $ansibleRole = trim((string)($_POST['ansible_role'] ?? '')) ?: null;

        return [
            'distro'                => $distroVal,
            // codename (modèle distro-name) : release ('trixie'). Source du codename
            // apt-suite (preseed) + segment de path netboot (grub). NULL = dérivé runtime.
            'codename'              => $codenameVal,
            // version_full : point release exacte de l'OS (ex 13.4.0). NULL = preseed.php
            // / render-per-mac-pxelinux.sh résoudront via OsImage::latestForDistro.
            'version_full'          => trim((string)($_POST['version_full'] ?? '')) ?: null,
            'network_mode'          => $legacyNetwork['network_mode'] ?? (in_array($_POST['network_mode'] ?? '', Preset::NETWORK_MODES, true) ? $_POST['network_mode'] : 'dhcp'),
            'static_ip'             => $legacyNetwork !== null ? $legacyNetwork['static_ip'] : (trim((string)($_POST['static_ip'] ?? '')) ?: null),
            'static_cidr'           => $legacyNetwork !== null ? $legacyNetwork['static_cidr'] : $cidr,
            'static_gateway'        => $legacyNetwork !== null ? $legacyNetwork['static_gateway'] : (trim((string)($_POST['static_gateway'] ?? '')) ?: null),
            'static_dns'            => $legacyNetwork !== null ? $legacyNetwork['static_dns'] : self::extractDnsList(),
            // Snapshot multi-NIC. Vide si le form n'expose pas encore les champs
            // (Phase 3 backward-compat : le legacy single-NIC reste honoré via les
            // colonnes flat ci-dessus).
            'network'               => $networkPlan,
            'partition_mode'        => in_array($_POST['partition_mode'] ?? '', Preset::PARTITION_MODES, true) ? $_POST['partition_mode'] : 'default',
            'disk_identity_selection_confirmed' => (($_POST['disk_identity_selection_confirmed'] ?? '1') === '1'),
            'disk_target_constraints' => self::diskConstraintsFromPost(),
            // En mode custom : sérialise tout le plan arborescent. En mode default : pas de plan stocké.
            'partition_custom_json' => $isCustom ? json_encode($plan, JSON_UNESCAPED_SLASHES) : null,
            'admin_user_login'      => trim((string)($_POST['admin_user_login'] ?? '')),
            'admin_user_fullname'   => trim((string)($_POST['admin_user_fullname'] ?? '')) ?: null,
            'admin_user_pwhash'     => self::hashPasswordFromPost(),
            // Ciphertext base64 (Crypto::encrypt via master.key). Permet l'affichage
            // en clair dans l'aperçu machine sans passphrase opérateur.
            'admin_user_password_cipher' => $preview ? null : self::encryptPasswordFromPost(),
            'admin_user_groups'     => trim((string)($_POST['admin_user_groups'] ?? '')) ?: 'sudo',
            'admin_user_pubkeys'    => trim((string)($_POST['admin_user_pubkeys'] ?? '')) ?: null,
            // root_login_mode : 'enabled' (root + late_command useradd, legacy) | 'disabled'
            // (passwd/make-user natif d-i). Sans choix explicite, root est verrouillé.
            'root_login_mode'       => in_array($_POST['root_login_mode'] ?? '', Preset::ROOT_LOGIN_MODES, true)
                ? (string)$_POST['root_login_mode'] : 'disabled',
            'ansible_role'          => $ansibleRole,
            'ansible_extra_vars'    => trim((string)($_POST['ansible_extra_vars'] ?? '')) ?: null,
            // Mot de passe root propre à cette machine, sans repli global.
            'root_password_hash'    => self::hashRootPasswordFromPost(),
            // Paramètres avancés du preseed (localization, mirror, packages,
            // apt, bootloader, finish, post_install). Toujours présent dans le
            // snapshot — Preset::normalizeAdvancedPayload retombe sur les
            // defaults si POST ne fournit pas la clé adv[...].
            'advanced'              => Preset::normalizeAdvancedPayload($_POST['adv'] ?? null),
            // Une variante héritée non prise en charge reste visible au contrôle préalable.
            'variant'                => $variant,
        ];
    }

    /** Retain malformed input for validation; it must never become a generic recipe. */
    public static function diskConstraintsFromPost(): mixed
    {
        $raw = trim((string)($_POST['disk_target_constraints_json'] ?? ''));
        return $raw === '' ? [] : (json_decode($raw, true) ?? $raw);
    }

    /** Public, credential-free review of the same normalized data used at launch. */
    public static function review(array $config, array $errors = []): array
    {
        $rows = [];
        $add = static function (string $label, string $value) use (&$rows): void {
            $rows[] = ['label' => $label, 'value' => $value];
        };
        $add('Système', trim(implode(' · ', array_filter([$config['distro'] ?? '', $config['codename'] ?? '', $config['version_full'] ?? 'dernière version disponible']))));
        $hostname = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($config['hostname'] ?? '')));
        $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower((string)($config['domain'] ?? ''))) ?: 'Aucun domaine imposé';
        $add('Nom et domaine', ($hostname ?: 'Nom fourni par DHCP ou par l’installateur') . ' · ' . $domain);
        $add('Accès administrateur', ($config['admin_user_login'] ?? '') . ' · groupes ' . ($config['admin_user_groups'] ?? 'sudo')
            . ' · mot de passe ' . (!empty($config['admin_user_pwhash']) ? 'défini' : 'non défini')
            . ' · ' . count(array_filter(explode("\n", trim((string)($config['admin_user_pubkeys'] ?? ''))))) . ' clé(s) SSH');
        $add('Compte root', ($config['root_login_mode'] ?? 'disabled') === 'disabled' ? 'Désactivé' : (!empty($config['root_password_hash']) ? 'Activé · mot de passe défini' : 'Verrouillé · aucun mot de passe root défini'));
        if (isset($config['review_boot_mode'])) $add('Mode de démarrage observé', (string)$config['review_boot_mode']);
        foreach (is_array($config['disk_target_constraints'] ?? null) ? $config['disk_target_constraints'] : [] as $path => $serial) {
            $add('Disque imposé par le relevé', $path . ' · numéro de série ' . (is_string($serial) ? $serial : 'invalide'));
        }
        $advanced = Preset::parseAdvancedConfig(json_encode($config['advanced'] ?? [], JSON_UNESCAPED_SLASHES));
        $packages = $advanced['packages']['pkgsel_include'];
        foreach ($config['network']['interfaces'] ?? [] as $nic) {
            if (!empty($nic['bond']['name'])) $packages[] = 'ifenslave';
            if (!empty($nic['vlan']['id'])) $packages[] = 'vlan';
        }
        $add('Paquets de l’installation Debian', implode(', ', array_values(array_unique($packages))));
        $add('Tâches Debian', implode(', ', $advanced['packages']['tasksel']));
        $network = $config['network'] ?? [];
        $interfaces = $network['interfaces'] ?? [];
        if (!$interfaces) {
            $interfaces = [['mode' => $config['network_mode'] ?? 'dhcp', 'static' => [
                'ip' => $config['static_ip'] ?? '', 'cidr' => $config['static_cidr'] ?? '',
                'gateway' => $config['static_gateway'] ?? '', 'dns' => $config['static_dns'] ?? [],
            ]]];
        }
        foreach ($interfaces as $i => $nic) {
            $mode = $nic['mode'] ?? 'dhcp';
            $identity = $nic['match']['mac'] ?? $nic['match']['name'] ?? 'carte détectée';
            $value = $identity . ' · ' . match ($mode) { 'static' => 'statique', 'disabled' => 'désactivée', default => 'DHCP' };
            if ($mode === 'static') {
                $st = $nic['static'] ?? [];
                $value .= ' · ' . ($st['ip'] ?? '') . '/' . ($st['cidr'] ?? '')
                    . ' · passerelle ' . ($st['gateway'] ?? '') . ' · DNS ' . implode(', ', $st['dns'] ?? []);
            }
            if (isset($nic['vlan']['id'])) $value .= ' · VLAN ' . $nic['vlan']['id'];
            if (!empty($nic['bond']['role'])) $value .= ' · bond ' . ($nic['bond']['name'] ?? '') . ' (' . $nic['bond']['role'] . ', ' . ($nic['bond']['mode'] ?? '') . ')';
            $add('Réseau ' . ($i + 1) . ($i === (int)($network['primary'] ?? 0) ? ' · primaire' : ''), $value);
        }
        if (($config['partition_mode'] ?? 'default') === 'custom') {
            $raw = $config['partition_custom_json'] ?? null;
            $plan = is_array($raw) ? $raw : Preset::parsePartitioningPlan($raw);
            $add('Disques à partitionner', implode(', ', $plan['target_disks'] ?? []));
            $rawPlan = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
            foreach ($rawPlan['target_disk_identities'] ?? [] as $disk => $identity) {
                $add('Identité disque ' . $disk, 'Numéro de série ' . ($identity['value'] ?? '') . ' · source ' . ($identity['source'] ?? 'inconnue'));
            }
            $describe = static function (array $part): string {
                $value = strtoupper($part['fs'] ?? '') . ' · ' . ($part['mount'] ?? '') . ' · '
                    . (!empty($part['fill']) ? 'espace restant' : ($part['size_mb'] ?? 0) . ' MB');
                if (isset($part['reserved_pct']) && in_array($part['fs'] ?? '', ['ext2','ext3','ext4'], true)) $value .= ' · réserve ' . $part['reserved_pct'] . ' %';
                if (!empty($part['mount_options'])) $value .= ' · options ' . implode(', ', $part['mount_options']);
                return $value;
            };
            foreach ($plan['disks'] ?? [] as $disk) foreach ($disk['partitions'] ?? [] as $part) {
                $add(($disk['path'] ?? '') . ' / ' . ($part['id'] ?? ''), $describe($part));
            }
            foreach ($plan['raid_arrays'] ?? [] as $raid) $add('RAID ' . ($raid['name'] ?? ''), 'niveau ' . ($raid['level'] ?? '') . ' · membres ' . implode(', ', $raid['members'] ?? []) . ' · secours ' . implode(', ', $raid['spares'] ?? []) . (!empty($raid['is_pv']) ? ' · volume physique LVM' : ''));
            foreach ($plan['vgs'] ?? [] as $vg) $add('Groupe LVM ' . ($vg['name'] ?? ''), implode(', ', $vg['pvs'] ?? []));
            foreach ($plan['logical_volumes'] ?? [] as $lv) $add('Volume ' . ($lv['vg'] ?? '') . '/' . ($lv['name'] ?? ''), $describe($lv));
        } else {
            $add('Partitionnement', 'LVM automatique · disque lié au numéro de série du relevé · données remplacées');
            if (!empty($config['review_disks'])) $add('Disque système vérifié', implode(', ', $config['review_disks']));
        }
        if (isset($config['review_preserved_disks'])) $add('Disques hors partitionnement et pré-effacement', implode(', ', $config['review_preserved_disks']) ?: 'Aucun autre disque inventorié');
        $add('Disque du chargeur GRUB', (string)$advanced['bootloader']['bootdev']);
        $wipe = $config['pre_wipe_mode'] ?? 'aucun';
        if (isset($config['review_pre_wipe']) && is_array($config['review_pre_wipe'])) {
            $wipe = array_column($config['review_pre_wipe'], 'mode', 'name');
        }
        if (is_string($wipe) && str_starts_with($wipe, '{')) $wipe = json_decode($wipe, true) ?: [];
        foreach (is_array($wipe) ? $wipe : ['disques sélectionnés' => $wipe] as $disk => $mode) {
            if ($mode !== 'aucun') $add('Pré-effacement ' . $disk, $mode === 'partial' ? 'Partiel (~14 Go) · données perdues' : 'Complet · toutes les données perdues');
        }
        $add('Automatisation', $config['ansible_role'] ?? 'Aucun rôle demandé');
        if (!empty($config['ansible_extra_vars'])) $add('Variables Ansible', 'Variables personnalisées présentes (contenu masqué)');
        $defaults = Preset::defaultAdvancedConfig();
        foreach ($config['advanced'] ?? [] as $section => $values) {
            if (!is_array($values) || $section === 'network') continue;
            foreach ($values as $key => $value) {
                if ($value === ($defaults[$section][$key] ?? null)) continue;
                if ($key === 'tune2fs_reserved') continue;
                $display = is_bool($value) ? ($value ? 'activé' : 'désactivé') : (is_array($value) ? count($value) . ' entrée(s)' : (string)$value);
                if (in_array($key, ['proxy', 'remote_pubkey_urls'], true)) $display = empty($value) ? 'aucun' : 'configuré (contenu masqué)';
                $add('Option ' . $section . ' / ' . $key, $display === '' ? 'vide' : $display);
            }
        }
        return $rows;
    }

    /** Keep existing validation messages compatible and attach navigation targets. */
    public static function localizeErrors(array $errors): array
    {
        return array_map(static function (string $message): array {
            $step = 1; $field = 'distro';
            if (preg_match('/NIC #(\d+)/', $message, $m)) {
                $step = 2; $prefix = 'network[interfaces][' . ((int)$m[1] - 1) . ']';
                $suffix = match (true) {
                    str_contains($message, 'DNS') => '[static][dns][]',
                    str_contains($message, 'CIDR') => '[static][cidr]',
                    str_contains($message, 'gateway'), str_contains($message, 'passerelle') => '[static][gateway]',
                    str_contains($message, 'VLAN') => '[vlan][id]',
                    str_contains($message, 'IP IPv4') => '[static][ip]',
                    default => '[mode]',
                };
                $field = $prefix . $suffix;
            } elseif (preg_match('/static|interface|réseau/i', $message)) {
                $step = 2;
                $field = match (true) {
                    str_contains($message, 'DNS') => 'dns[]', str_contains($message, 'CIDR') => 'static_cidr',
                    str_contains($message, 'gateway'), str_contains($message, 'passerelle') => 'static_gateway',
                    str_contains($message, 'primaire') => 'network[primary]', default => 'static_ip',
                };
            } elseif (preg_match('/disque|partition|RAID|VG |PV |LV |montage|custom/i', $message)) {
                $step = 3; $field = 'partition_mode';
            } elseif (preg_match('/admin|SSH|root/i', $message)) {
                $step = 2; $field = str_contains($message, 'SSH') || str_contains($message, 'root') ? 'admin_user_pubkeys' : 'admin_user_login';
            } elseif (str_contains($message, 'Timezone')) $field = 'adv[timezone][zone]';
            elseif (str_contains($message, 'Bootdev')) $field = 'adv[bootloader][bootdev]';
            elseif (str_contains($message, 'Codename')) $field = 'codename';
            elseif (str_contains($message, 'Version')) $field = 'version_full';
            elseif ($message === 'Nom requis') $field = 'name';
            return ['message' => $message, 'step' => $step, 'field' => $field];
        }, $errors);
    }

    /**
     * Validation stricte du dict install_config.
     *
     * @param array<string,mixed> $config
     * @param array<int,string>   $allowedDistros
     * @return array<int,string> liste d'erreurs (vide = valide)
     */
    /**
     * @param array<string,mixed>      $config
     * @param array<int,string>        $allowedDistros
     * @param array<string,int>        $diskCapacitiesMb chemin (/dev/sda) → capacité brute en MB SI (inventory).
     *                                                    Vide = pas de validation overflow (mode preset générique).
     * @param bool                     $strict           Kept for caller compatibility; structure is always checked,
     *                                                    and supplied capacities always enforce overflow checks.
     * @param array<int,array<string,mixed>> $availableNics inventaire HardwareContext['nics'] pour
     *                                                       valider les match.mac réseau
     * @return array<int,string> liste d'erreurs (vide = valide)
     */
    public static function validate(array $config, array $allowedDistros, array $diskCapacitiesMb = [], bool $strict = true, array $availableNics = []): array
    {
        $errors = DiskIdentity::validateConstraints($config);

        if (empty($config['distro'])) {
            $errors[] = 'Distribution requise';
        } elseif (!in_array($config['distro'], $allowedDistros, true)) {
            $errors[] = 'Distribution invalide (autorisées: ' . implode(',', $allowedDistros) . ')';
        } else {
            // Codename (modèle distro-name) : valider contre le catalogue du distro.
            // Optionnel (NULL = dérivé runtime) ; skip si catalogue vide (1er boot)
            // pour ne pas bloquer la création de preset avant le 1er refresh.
            $cn = (string)($config['codename'] ?? '');
            $validCn = \Admin\Models\CatalogEntry::codenamesForDistro((string)$config['distro']);
            if (!empty($validCn) && $cn !== '' && !in_array($cn, $validCn, true)) {
                $errors[] = 'Codename invalide pour ' . $config['distro']
                          . ' (attendus: ' . implode(',', $validCn) . ')';
            }
        }

        // version_full optionnelle (NULL = fallback latest). Si fournie : doit
        // matcher le format X.Y.Z ou être un major (ex "9", "41"). Pas de check
        // d'existence en DB ici — résolu au save controller (qui peut déclencher
        // un auto-download via os_images).
        if (!empty($config['version_full'])) {
            if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', (string)$config['version_full'])) {
                $errors[] = 'Version OS invalide (format attendu: X, X.Y ou X.Y.Z)';
            }
        }

        if (empty($config['admin_user_login'])) {
            $errors[] = 'Login admin requis';
        }

        // Validation legacy single-NIC : reste pour rétrocompat (presets pré-Phase 3
        // qui n'ont que les colonnes flat). Si un plan multi-NIC est présent, c'est lui
        // qui fait foi (cf. validateNetworkPlan ci-dessous) — on skip alors la validation
        // legacy pour éviter les doublons d'erreurs.
        $hasMultiNic = isset($config['network']['interfaces'])
            && is_array($config['network']['interfaces'])
            && !empty($config['network']['interfaces']);

        if (!$hasMultiNic && $config['network_mode'] === 'static') {
            if (empty($config['static_ip']) || !filter_var($config['static_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'Mode static : IP IPv4 valide requise';
            }
            if (!is_int($config['static_cidr']) || $config['static_cidr'] < 1 || $config['static_cidr'] > 32) {
                $errors[] = 'Mode static : préfixe CIDR (1-32) requis';
            }
            if (empty($config['static_gateway']) || !filter_var($config['static_gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = 'Mode static : gateway IPv4 valide requise';
            }
            $dns = is_array($config['static_dns'] ?? null) ? $config['static_dns'] : ['invalid'];
            $dnsValid = array_filter($dns, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
            if (count($dnsValid) === 0) {
                $errors[] = 'Mode static : au moins un DNS IPv4 valide requis';
            } elseif (count($dnsValid) !== count($dns)) {
                $errors[] = 'Mode static : chaque DNS doit être une adresse IPv4 valide';
            }
        }

        if ($hasMultiNic) {
            $errors = array_merge($errors, self::validateNetworkPlan($config['network'], $availableNics));
        }

        if ($config['partition_mode'] === 'custom') {
            $plan = Preset::parsePartitioningPlan($config['partition_custom_json'] ?? null);
            $errors = array_merge($errors, self::validatePartitioningPlan($plan, $diskCapacitiesMb, $strict));
        }

        // Garde-fou : refuser une install qui produirait une machine inaccessible
        // par SSH. Au moins un de ces 3 chemins doit exister :
        //   - module Ansible activé ET pubkey configurée → user `ansible` créé avec clé
        //   - admin_user_pwhash non vide → user admin avec password
        //   - admin_user_pubkeys non vide → user admin avec clé(s)
        // Sans ça, le hardening sshd (PasswordAuthentication no + PermitRootLogin no)
        // appliqué par le late_command rend la machine ingérable à distance.
        $ansiblePubkey  = trim((string)Setting::get(Setting::KEY_ANSIBLE_SSH_PUBKEY, ''));
        $ansibleEnabled = Setting::get(Setting::KEY_ANSIBLE_ENABLED, 'off') === 'on' && $ansiblePubkey !== '';
        $adminHasPwd    = !empty($config['admin_user_pwhash']);
        $adminHasKeys   = !empty(trim((string)($config['admin_user_pubkeys'] ?? '')));
        if (!$ansibleEnabled && !$adminHasPwd && !$adminHasKeys) {
            $errors[] = 'Aucun chemin SSH disponible : activez le module Ansible avec une clé pubkey configurée, OU fournissez un mot de passe admin, OU des clés SSH publiques admin. Sinon la machine sera inaccessible à distance après installation.';
        }

        return $errors;
    }

    /**
     * Validation du plan arborescent (multi-disques + LVM multi-VG + RAID stub Phase B).
     *
     * Invariants vérifiés :
     *   - target_disks non vide, chaque disque a au moins 1 partition
     *   - chaque partition `lvm-pv` est référencée par exactement 1 VG
     *   - chaque MD `is_pv` est référencé par exactement 1 VG (Phase B)
     *   - chaque LV référence un VG existant
     *   - noms uniques par scope (id partition, name MD, name VG, name LV par VG)
     *   - /boot et /boot/efi sont des partitions standards (pas lvm-pv ni raid-member)
     *   - au moins 1 LV ou partition standard avec mount=`/`
     *   - somme des partitions fixes par disque ≤ capacité utile (si $diskCapacitiesMb fourni)
     *   - somme des LVs fixes par VG ≤ capacité du VG (idem)
     *
     * @param array<string,mixed> $plan
     * @param array<string,int>   $diskCapacitiesMb chemin (/dev/sda) → capacité brute en MB SI. Vide = skip overflow.
     * @param bool                $strict           Kept for caller compatibility; all recipes receive structural
     *                                                    validation, including those without a reference machine.
     * @return array<int,string>
     */
    public static function validatePartitioningPlan(array $plan, array $diskCapacitiesMb = [], bool $strict = true): array
    {
        // The structure is always checked, including generic presets. Only
        // capacity checks depend on a hardware inventory being available.

        $errors = [];

        $targetDisks = $plan['target_disks'] ?? [];
        $disks       = $plan['disks'] ?? [];
        $raidArrays  = $plan['raid_arrays'] ?? [];
        $vgs         = $plan['vgs'] ?? [];
        $lvs         = $plan['logical_volumes'] ?? [];

        if (empty($targetDisks)) {
            $errors[] = 'Mode custom : au moins un disque cible requis';
            return $errors;
        }
        foreach ($targetDisks as $path) {
            if (!is_string($path) || !preg_match('#^/dev/(?:sd[a-z]+|hd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme[0-9]+n[0-9]+|mmcblk[0-9]+)$#D', $path)) {
                $errors[] = 'Disque cible invalide : chemin de disque entier /dev/sdX, /dev/nvmeXnY ou /dev/vdX requis (by-id reste une preuve d’audit).';
            }
        }
        $declaredPaths = array_column($disks, 'path');
        if (count($declaredPaths) !== count(array_unique($declaredPaths))) $errors[] = 'Disque déclaré plusieurs fois dans le plan de partitionnement.';
        foreach ($targetDisks as $path) {
            if (!in_array($path, $declaredPaths, true)) $errors[] = "Disque cible $path : recette de partitions manquante";
        }

        // Cohérence avec l'inventaire matériel : si un inventaire est fourni
        // (diskCapacitiesMb non vide), chaque disque ciblé DOIT exister physiquement.
        // Empêche de persister un plan ciblant un disque absent (ex preset /dev/sda
        // appliqué à une machine qui n'a que /dev/sdb) → partman échouerait à l'install.
        if (!empty($diskCapacitiesMb)) {
            foreach ($targetDisks as $td) {
                if (!isset($diskCapacitiesMb[$td])) {
                    $errors[] = "Disque cible $td absent de l'inventaire matériel "
                              . '(détectés : ' . implode(', ', array_keys($diskCapacitiesMb)) . ')';
                }
            }
        }

        // Référentiel global d'IDs visibles depuis VG : partitions lvm-pv + MD is_pv
        $availablePvIds = [];
        $availableRaidMemberIds = []; // pour validation RAID Phase B

        // 1. Validation par disque
        $partIds = [];
        $rootMountFound = false;
        $bootMountFound = false;
        $mounts = [];

        foreach ($disks as $diskIdx => $disk) {
            $diskPath = $disk['path'] ?? '';
            if (!in_array($diskPath, $targetDisks, true)) {
                $errors[] = "Disque #" . ($diskIdx + 1) . " : path '$diskPath' absent de target_disks";
            }
            if (!empty($diskCapacitiesMb) && $diskPath !== '' && !isset($diskCapacitiesMb[$diskPath])) {
                $errors[] = "Disque $diskPath absent de l'inventaire matériel — le modèle cible-t-il le bon disque ?";
            }
            $parts = $disk['partitions'] ?? [];
            if (count($parts) === 0) {
                $errors[] = "Disque $diskPath : au moins une partition requise";
            }

            $diskFillCount = 0;
            foreach ($parts as $pIdx => $p) {
                $fs = $p['fs'] ?? '';
                if (!in_array($fs, Preset::ALLOWED_FS, true)) {
                    $errors[] = "Disque $diskPath partition #" . ($pIdx + 1) . " : fs '$fs' invalide";
                    continue;
                }
                $id   = $p['id'] ?? null;
                $mount = $p['mount'] ?? null;
                $isFill = !empty($p['fill']);
                $size = (int)($p['size_mb'] ?? 0);

                if ($id === null || $id === '') {
                    $errors[] = "Disque $diskPath partition #" . ($pIdx + 1) . " : id manquant";
                    continue;
                }
                if (isset($partIds[$id])) {
                    $errors[] = "Identifiant de partition dupliqué : $id";
                }
                $partIds[$id] = true;

                if ($isFill) {
                    $diskFillCount++;
                } elseif ($size <= 0) {
                    $errors[] = "Disque $diskPath partition $id ($fs) : taille > 0 requise (ou cocher fill)";
                }

                // Validation mount selon fs
                if ($fs === 'lvm-pv') {
                    $availablePvIds[$id] = true;
                    if ($mount !== null) {
                        // mount ignoré pour PV — pas une erreur, on tolère
                    }
                } elseif ($fs === 'raid-member') {
                    $availableRaidMemberIds[$id] = true;
                    // mount/reserved/options ignorés (container, formaté par le MD au-dessus)
                } elseif ($fs === 'swap') {
                    if ($mount !== null) {
                        // mount ignoré pour swap
                    }
                } elseif ($fs === 'biosgrub') {
                    // Partition BIOS GRUB : ni mount, ni reserved, ni options.
                    // Doit être en première position du disque pour que GRUB legacy
                    // y soit embarqué correctement (1 MiB suffit).
                    if ($pIdx !== 0) {
                        $errors[] = "Disque $diskPath : la partition biosgrub doit être en première position";
                    }
                    if ($isFill) $errors[] = "Disque $diskPath partition $id : biosgrub ne peut pas utiliser fill";
                    if (!$isFill && $size > 0 && $size > 4) {
                        // 1 MiB suffit largement ; warn si l'utilisateur a tapé une taille anormale
                        $errors[] = "Disque $diskPath partition $id (biosgrub) : taille recommandée 1 MiB (max 4)";
                    }
                } else {
                    // partition standard (ext, xfs, efi)
                    if ($mount === null || $mount === '') {
                        $errors[] = "Disque $diskPath partition $id ($fs) : point de montage requis";
                    } else {
                        if ($mount === '/boot') $bootMountFound = true;
                        if ($mount === '/')     $rootMountFound = true;
                    }
                    if ($fs === 'efi' && $mount !== '/boot/efi') $errors[] = "Disque $diskPath partition $id : EFI requiert /boot/efi";
                    if ($fs !== 'efi' && $mount === '/boot/efi') $errors[] = "Disque $diskPath partition $id : /boot/efi requiert le type efi";
                    if ($mount !== null && $mount !== '') {
                        if (isset($mounts[$mount])) $errors[] = "Point de montage dupliqué : $mount";
                        $mounts[$mount] = true;
                    }
                }
            }

            if ($diskFillCount > 1) {
                $errors[] = "Disque $diskPath : une seule partition peut utiliser \"fill\"";
            }
        }

        // 2. Validation RAID arrays
        $raidNames = [];
        $usedAsRaidMember = [];
        foreach ($raidArrays as $rIdx => $arr) {
            $name = $arr['name'] ?? '';
            if ($name === '') {
                $errors[] = "Array RAID #" . ($rIdx + 1) . " : nom requis (ex: md0)";
                continue;
            }
            if (isset($raidNames[$name])) {
                $errors[] = "Nom d'array RAID dupliqué : $name";
            }
            $raidNames[$name] = true;
            $level = (int)($arr['level'] ?? 0);
            $members = $arr['members'] ?? [];
            $spares  = $arr['spares']  ?? [];

            $minMembers = match ($level) {
                0 => 2, 1 => 2, 5 => 3, 6 => 4, 10 => 4,
                default => PHP_INT_MAX,
            };
            if (count($members) < $minMembers) {
                $errors[] = "Array $name (RAID$level) : au moins $minMembers membres requis";
            }
            foreach ($members as $m) {
                if (!isset($availableRaidMemberIds[$m])) {
                    $errors[] = "Array $name : membre '$m' introuvable (pas une partition raid-member)";
                }
                if (isset($usedAsRaidMember[$m])) {
                    $errors[] = "Partition $m utilisée par plusieurs arrays RAID";
                }
                $usedAsRaidMember[$m] = true;
            }
            foreach ($spares as $s) {
                if (!isset($availableRaidMemberIds[$s])) {
                    $errors[] = "Array $name : spare '$s' introuvable (pas une partition raid-member)";
                }
                if (isset($usedAsRaidMember[$s])) {
                    $errors[] = "Partition $s utilisée par plusieurs arrays RAID (membre + spare)";
                }
                $usedAsRaidMember[$s] = true;
            }
            if ($level === 0 && count($spares) > 0) {
                $errors[] = "Array $name (RAID0) : pas de spares possibles";
            }
            if (!empty($arr['is_pv'])) {
                $availablePvIds[$name] = true;
            }
        }

        // raid-member orphelins : déclarés mais non référencés par aucun array
        foreach (array_keys($availableRaidMemberIds) as $rmId) {
            if (!isset($usedAsRaidMember[$rmId])) {
                $errors[] = "Partition raid-member '$rmId' orpheline (déclarée mais aucun array ne l'utilise)";
            }
        }

        // 3. Validation VGs et leur mapping de PVs
        $vgNames = [];
        $usedAsPv = [];
        foreach ($vgs as $vg) {
            $name = $vg['name'] ?? '';
            if (isset($vgNames[$name])) {
                $errors[] = "Nom de VG dupliqué : $name";
            }
            $vgNames[$name] = true;
            $pvs = $vg['pvs'] ?? [];
            if (count($pvs) === 0) {
                $errors[] = "VG $name : au moins un PV requis";
            }
            foreach ($pvs as $pvId) {
                if (!isset($availablePvIds[$pvId])) {
                    $errors[] = "VG $name : PV référencé '$pvId' introuvable (pas une partition lvm-pv ni un MD is_pv)";
                }
                if (isset($usedAsPv[$pvId])) {
                    $errors[] = "PV $pvId utilisé par plusieurs VG";
                }
                $usedAsPv[$pvId] = true;
            }
        }

        // PV orphelins : référencés nulle part
        foreach (array_keys($availablePvIds) as $pvId) {
            if (!isset($usedAsPv[$pvId])) {
                $errors[] = "PV $pvId orphelin (déclaré mais aucun VG ne l'utilise)";
            }
        }

        // 4. Validation LVs
        $lvNamesByVg = []; // [vg][name] => true
        $lvFillByVg  = []; // [vg] => count
        foreach ($lvs as $lv) {
            $vg = $lv['vg'] ?? '';
            $name = $lv['name'] ?? '';
            $fs = $lv['fs'] ?? '';
            $mount = $lv['mount'] ?? null;
            $isFill = !empty($lv['fill']);
            $size = (int)($lv['size_mb'] ?? 0);

            if (!isset($vgNames[$vg])) {
                $errors[] = "LV $name : VG '$vg' introuvable";
                continue;
            }
            if (!in_array($fs, Preset::ALLOWED_FS_LV, true)) {
                $errors[] = "LV $vg/$name : fs '$fs' invalide pour un LV (autorisés: " . implode(',', Preset::ALLOWED_FS_LV) . ')';
                continue;
            }
            if (isset($lvNamesByVg[$vg][$name])) {
                $errors[] = "Nom de LV dupliqué dans le VG $vg : $name";
            }
            $lvNamesByVg[$vg][$name] = true;

            if ($isFill) {
                $lvFillByVg[$vg] = ($lvFillByVg[$vg] ?? 0) + 1;
            } elseif ($size <= 0) {
                $errors[] = "LV $vg/$name : taille > 0 requise (ou cocher fill)";
            }

            if ($fs === 'swap') {
                // swap LV : pas de mount
            } else {
                if ($mount === null || $mount === '') {
                    $errors[] = "LV $vg/$name ($fs) : point de montage requis";
                } else {
                    if ($mount === '/') $rootMountFound = true;
                    if (isset($mounts[$mount])) $errors[] = "Point de montage dupliqué : $mount";
                    $mounts[$mount] = true;
                }
            }
        }
        foreach ($lvFillByVg as $vg => $n) {
            if ($n > 1) {
                $errors[] = "VG $vg : un seul LV peut utiliser \"fill\"";
            }
        }

        // 5. Invariants globaux
        if (!$rootMountFound) {
            $errors[] = 'Point de montage / manquant (sur partition standard ou LV)';
        }
        if (!$bootMountFound) {
            $errors[] = 'Partition standard /boot manquante (obligatoire — pas LVM ni RAID)';
        }

        // 6. Overflow par disque (somme partitions fixes ≤ capacité - marge partman)
        //    + calcul des tailles effectives des PV pour valider l'overflow VG.
        //    Skip silencieux si $diskCapacitiesMb vide (cas preset générique sans
        //    machine cible : on ne connaît pas les tailles physiques).
        $pvSizesMibById = [];
        if (!empty($diskCapacitiesMb)) {
            foreach ($disks as $disk) {
                $diskPath = $disk['path'] ?? '';
                if (!isset($diskCapacitiesMb[$diskPath])) continue;
                $parts = $disk['partitions'] ?? [];
                $marginMb = self::partitionTableMarginMb($parts);
                $usableMib = max(0, (int)$diskCapacitiesMb[$diskPath] - $marginMb);

                $fixedSumMib = 0;
                $fillCount   = 0;
                foreach ($parts as $p) {
                    if (!empty($p['fill'])) {
                        $fillCount++;
                    } else {
                        $fixedSumMib += (int)($p['size_mb'] ?? 0);
                    }
                }
                if ($fixedSumMib > $usableMib) {
                    $overflow = $fixedSumMib - $usableMib;
                    $errors[] = sprintf(
                        'Disque %s : dépassement de %d MB (somme partitions = %d MB, capacité utile = %d MB, marge partman = %d MB)',
                        $diskPath,
                        $overflow,
                        $fixedSumMib,
                        $usableMib,
                        $marginMb
                    );
                }

                // Taille effective des PV (sert ensuite à la validation overflow VG)
                $remainingMib = max(0, $usableMib - $fixedSumMib);
                $perFillMib   = $fillCount > 0 ? intdiv($remainingMib, $fillCount) : 0;
                foreach ($parts as $p) {
                    if (($p['fs'] ?? '') !== 'lvm-pv') continue;
                    $id = $p['id'] ?? '';
                    if ($id === '') continue;
                    $pvSizesMibById[$id] = !empty($p['fill'])
                        ? $perFillMib
                        : (int)($p['size_mb'] ?? 0);
                }
            }
        }

        // 7. Overflow par VG (somme LVs fixes ≤ capacité VG utile)
        //    Capacité utile = capacité brute du VG - overhead LVM/partman :
        //      - LVM_PV_HEADER_MB par PV (header + safety)
        //      - LVM_LV_ALIGNMENT_MB par LV (alignement aux extents)
        //      - PARTMAN_VG_SAFETY_MB par VG (marge interne partman)
        //    Sans cet overhead, un plan qui tient à <30 MB près passe la validation
        //    mais partman fait fallback atomic silencieux à l'install.
        if (!empty($pvSizesMibById)) {
            $vgCapacityMib = [];
            $vgNumPvs      = [];
            foreach ($vgs as $vg) {
                $name = $vg['name'] ?? '';
                if ($name === '') continue;
                $vgCapacityMib[$name] = 0;
                $vgNumPvs[$name]      = 0;
                foreach (($vg['pvs'] ?? []) as $pvId) {
                    if (isset($pvSizesMibById[$pvId])) {
                        $vgCapacityMib[$name] += $pvSizesMibById[$pvId];
                        $vgNumPvs[$name]++;
                    }
                }
            }
            $lvFixedSumByVg = [];
            $lvCountByVg    = [];
            foreach ($lvs as $lv) {
                $vg = $lv['vg'] ?? '';
                if ($vg === '') continue;
                $lvCountByVg[$vg] = ($lvCountByVg[$vg] ?? 0) + 1;
                if (!empty($lv['fill'])) continue;
                $lvFixedSumByVg[$vg] = ($lvFixedSumByVg[$vg] ?? 0) + (int)($lv['size_mb'] ?? 0);
            }
            foreach ($lvFixedSumByVg as $vg => $sumMib) {
                $rawCap = $vgCapacityMib[$vg] ?? 0;
                if ($rawCap <= 0) continue;
                $nPvs     = $vgNumPvs[$vg] ?? 1;
                $nLvs     = $lvCountByVg[$vg] ?? 0;
                $overhead = self::LVM_PV_HEADER_MB * $nPvs
                          + self::LVM_LV_ALIGNMENT_MB * $nLvs
                          + self::PARTMAN_VG_SAFETY_MB;
                $effectiveCap = max(0, $rawCap - $overhead);
                if ($sumMib > $effectiveCap) {
                    $overflow = $sumMib - $effectiveCap;
                    $errors[] = sprintf(
                        'VG %s : dépassement de %d MB (somme LVs = %d MB, capacité VG utile = %d MB après overhead LVM/partman = %d MB)',
                        $vg,
                        $overflow,
                        $sumMib,
                        $effectiveCap,
                        $overhead
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Marge réservée par partman pour la table de partition + alignement,
     * exprimée en MB SI (cohérent avec la sémantique partman expert_recipe).
     * GPT (présence biosgrub ou efi) = 2 MB, MBR/msdos = 1 MB.
     *
     * @param array<int,array<string,mixed>> $partitions
     */
    public static function partitionTableMarginMb(array $partitions): int
    {
        foreach ($partitions as $p) {
            if (in_array($p['fs'] ?? '', ['biosgrub', 'efi'], true)) return 2;
        }
        return 1;
    }
}
