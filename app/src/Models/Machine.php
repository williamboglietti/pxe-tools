<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

final class Machine
{
    public const STATUSES = ['unknown', 'inventorying', 'discovered', 'configured', 'installing', 'installed', 'provisioned', 'failed', 'wiping', 'wiped', 'testing', 'tested'];

    /** Normalize a MAC address to aa:bb:cc:dd:ee:ff format. Returns '' on invalid. */
    public static function normalizeMac(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/^01[-:]/', '', $raw);            // strip BOOTIF prefix "01-"
        $hex = preg_replace('/[^a-f0-9]/', '', $raw) ?? '';
        if (strlen($hex) !== 12) return '';
        return implode(':', str_split($hex, 2));
    }

    /**
     * Génère un hostname style Docker (adjectif_nom-de-scientifique) déterministe
     * à partir d'une seed (typiquement la MAC). Même MAC = même hostname à chaque
     * appel, donc l'utilisateur voit la même proposition s'il recharge la page.
     * Exemples : `relaxed_einstein`, `clever_curie`, `boring_turing`.
     */
    public static function generateHostname(string $seed): string
    {
        $adjectives = [
            'admiring','adoring','amazing','awesome','beautiful','blissful','bold','boring','brave',
            'busy','charming','clever','cool','compassionate','condescending','confident','cranky',
            'crazy','dazzling','determined','distracted','dreamy','eager','ecstatic','elated',
            'elegant','eloquent','epic','exciting','fervent','festive','flamboyant','focused',
            'friendly','funny','gallant','gifted','goofy','gracious','great','happy','hardcore',
            'heuristic','hopeful','hungry','inspiring','intelligent','jolly','jovial','keen','kind',
            'laughing','loving','lucid','magical','modest','musing','mystifying','naughty','nervous',
            'nice','nifty','nostalgic','optimistic','peaceful','pedantic','pensive','practical',
            'priceless','quirky','quizzical','recursing','relaxed','reverent','romantic','serene',
            'sharp','silly','sleepy','stoic','strange','suspicious','sweet','tender','thirsty',
            'trusting','unruffled','upbeat','vibrant','vigilant','vigorous','wizardly','wonderful',
            'youthful','zealous','zen',
        ];
        $names = [
            'archimedes','aryabhata','babbage','banach','bardeen','bell','bhabha','bhaskara','bohr',
            'booth','bose','boyd','brahmagupta','cartwright','carver','chandrasekhar','chebyshev',
            'cori','curie','darwin','davinci','dijkstra','dirac','easley','edison','einstein',
            'elgamal','elion','euclid','euler','faraday','fermat','fermi','feynman','franklin',
            'gagarin','galileo','galois','gauss','germain','goldberg','goodall','greider','hamilton',
            'hawking','heisenberg','herschel','hertz','hodgkin','hopper','hypatia','jemison',
            'jepsen','johnson','jones','kalam','kepler','khorana','kilby','kirch','knuth',
            'kowalevski','lamarr','lamport','leakey','leavitt','lehmann','lewin','liskov','lovelace',
            'mahavira','margulis','maxwell','mcclintock','meitner','mendel','mendeleev','mirzakhani',
            'montalcini','moore','morse','nash','neumann','newton','nightingale','noether',
            'pascal','pasteur','pavlov','planck','poincare','ptolemy','pythagoras','raman','ramanujan',
            'ride','ritchie','rubin','sagan','shamir','shannon','shockley','sinoussi','tesla',
            'tharp','thompson','torvalds','turing','vaughan','volhard','wescoff','wiles','wozniak',
            'wright','yalow','yonath',
        ];

        $hash = sprintf('%u', crc32(strtolower($seed)));
        $adj  = $adjectives[$hash % count($adjectives)];
        $name = $names[(int)($hash / count($adjectives)) % count($names)];
        return $adj . '_' . $name;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(?string $statusFilter = null): array
    {
        $sql = 'SELECT m.*, p.name AS preset_name FROM machines m '
             . 'LEFT JOIN presets p ON m.preset_id = p.id ';
        $params = [];
        if ($statusFilter && $statusFilter !== 'all') {
            $sql .= 'WHERE m.status = ? ';
            $params[] = $statusFilter;
        }
        $sql .= 'ORDER BY m.updated_at DESC';
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM machines WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Extrait les capacités des disques d'une machine depuis `inventory_json.disks[]`.
     * Utilisé par la validation overflow du partition builder côté server.
     * Sémantique alignée avec partman expert_recipe : capacité en **MB SI**
     * (10^6 bytes). Un disque "120 GB" → 120 034 MB SI.
     *
     * @param array<string,mixed> $machine ligne SQL (avec `inventory_json` brut JSON string)
     * @return array<string,int> chemin (/dev/sda) → capacité brute en MB SI
     */
    public static function diskCapacitiesMbFromInventory(array $machine): array
    {
        $raw = $machine['inventory_json'] ?? '';
        if (!is_string($raw) || trim($raw) === '') return [];
        $inv = json_decode($raw, true);
        if (!is_array($inv) || !isset($inv['disks']) || !is_array($inv['disks'])) return [];

        $out = [];
        foreach ($inv['disks'] as $d) {
            if (!is_array($d)) continue;
            $path  = (string)($d['name'] ?? '');
            $bytes = $d['size_bytes'] ?? null;
            if ($path === '' || !is_numeric($bytes) || (int)$bytes <= 0) continue;
            // Normalise en /dev/X : le plan (target_disks / disks[].path) et le wizard
            // utilisent /dev/, l'inventaire peut stocker 'sdb' sans préfixe → aligne
            // les clés pour l'overflow check ET la validation de présence disque.
            $path = str_starts_with($path, '/dev/') ? $path : '/dev/' . ltrim($path, '/');
            $out[$path] = (int) floor((int)$bytes / 1_000_000);
        }
        return $out;
    }

/** @return array<string,mixed>|null */
    public static function findByMac(string $mac): ?array
    {
        $mac = self::normalizeMac($mac);
        if ($mac === '') return null;
        $stmt = Db::get()->prepare('SELECT * FROM machines WHERE mac = ?');
        $stmt->execute([$mac]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cherche une machine par MAC : d'abord sur la colonne primaire `mac`,
     * puis fallback sur les MACs secondaires stockés dans
     * `inventory_json.interfaces[].mac` (cas multi-NIC : BIOS UEFI peut booter
     * via une interface différente de celle découverte initialement).
     *
     * Évite de créer un doublon de machine au discovery quand une 2e NIC arrive.
     *
     * @return array<string,mixed>|null
     */
    public static function findByAnyMac(string $mac): ?array
    {
        $mac = self::normalizeMac($mac);
        if ($mac === '') return null;

        // 1. MAC primaire
        $direct = self::findByMac($mac);
        if ($direct) return $direct;

        // 2. Fallback : scan inventory_json.interfaces[].mac
        // Note : on ne filtre PAS sur inventory_json IS NOT NULL — un stub
        // 'inventorying' (créé par grub.php avec NULL inventory) doit pouvoir
        // être retrouvé via sa MAC primaire par la branche 1, et json_decode('')
        // renvoie null donc la boucle skip naturellement.
        $rows = Db::get()->query('SELECT * FROM machines')->fetchAll();
        foreach ($rows as $row) {
            $inv = json_decode($row['inventory_json'] ?? '', true);
            if (!is_array($inv)) continue;
            foreach (($inv['interfaces'] ?? []) as $iface) {
                $imac = self::normalizeMac((string)($iface['mac'] ?? ''));
                if ($imac !== '' && $imac === $mac) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * Cherche TOUTES les machines matchant l'une des MACs fournies, sur la
     * colonne primaire `mac` OU sur les MACs secondaires dans
     * inventory_json.interfaces[].mac.
     *
     * Utilisé au POST /api/discovery pour réconcilier d'éventuels stubs
     * orphelins (status='inventorying' créés par grub.php avec une MAC
     * firmware) avec la vraie machine multi-NIC qui POST son inventaire
     * via une autre interface.
     *
     * @param array<int,string> $macs
     * @return array<int,array<string,mixed>>
     */
    public static function findAllByMacs(array $macs): array
    {
        $normalized = [];
        foreach ($macs as $m) {
            $n = self::normalizeMac($m);
            if ($n !== '' && !in_array($n, $normalized, true)) $normalized[] = $n;
        }
        if ($normalized === []) return [];

        $rows = Db::get()->query('SELECT * FROM machines')->fetchAll();
        $matches = [];
        foreach ($rows as $row) {
            $hit = in_array(strtolower((string)$row['mac']), $normalized, true);
            if (!$hit && !empty($row['inventory_json'])) {
                $inv = json_decode((string)$row['inventory_json'], true);
                if (is_array($inv)) {
                    foreach (($inv['interfaces'] ?? []) as $iface) {
                        $imac = self::normalizeMac((string)($iface['mac'] ?? ''));
                        if ($imac !== '' && in_array($imac, $normalized, true)) {
                            $hit = true;
                            break;
                        }
                    }
                }
            }
            if ($hit) $matches[] = $row;
        }
        return $matches;
    }

    /**
     * Sélectionne la machine "gagnante" parmi un ensemble de doublons à
     * fusionner : priorité au statut le plus avancé, à égalité l'id le plus
     * petit (la plus ancienne en DB).
     *
     * @param array<int,array<string,mixed>> $machines
     * @return array<string,mixed>
     */
    public static function pickWinner(array $machines): array
    {
        $rank = [
            'unknown'      => 0,
            'inventorying' => 1,
            'discovered'   => 2,
            'failed'       => 3,
            'wiped'        => 4,
            'tested'       => 4,
            'configured'   => 5,
            'wiping'       => 6,
            'testing'      => 6,
            'installing'   => 7,
            'installed'    => 8,
            'provisioned'  => 9,
        ];
        usort($machines, function (array $a, array $b) use ($rank): int {
            $ra = $rank[$a['status'] ?? ''] ?? 0;
            $rb = $rank[$b['status'] ?? ''] ?? 0;
            if ($ra !== $rb) return $rb <=> $ra;
            return ((int)$a['id']) <=> ((int)$b['id']);
        });
        return $machines[0];
    }

    /** @param array<string,mixed> $data */
    /**
     * Whitelist des colonnes que create/update acceptent. Toute colonne ajoutée
     * via migration (boot_arch Spec D.4, root_password_hash Spec F.2, etc.) doit
     * être listée ici sinon les writes sont silencieusement droppés.
     */
    private const WRITABLE_COLS = [
        'hostname', 'domain', 'status', 'preset_id',
        'inventory_json', 'install_config_json', 'notes', 'last_seen_at',
        'boot_mode', 'local_boot_source', 'rescue_mode', 'next_action',
        'awaiting_action_until',  // Fenêtre 1h post-discovery (countdown auto-poweroff)
        'boot_arch',           // Spec D.4 — arch détectée au discovery
        'root_password_hash',  // Spec F.2 — root pwd per-machine
        'root_password_encrypted', 'root_password_kdf_salt', 'root_password_nonce',  // legacy SecretBox (passphrase) — lecture seule
        'root_password_cipher',  // Crypto master.key — format actuel
        'auto_deploy',         // Toggle redéploiement Ansible auto sur push GitHub
    ];

    public static function create(array $data): int
    {
        $mac = self::normalizeMac($data['mac'] ?? '');
        if ($mac === '') {
            throw new \InvalidArgumentException('Invalid MAC');
        }
        $cols   = ['mac'];
        $marks  = ['?'];
        $values = [$mac];
        foreach (self::WRITABLE_COLS as $col) {
            if (array_key_exists($col, $data)) {
                $cols[]   = $col;
                $marks[]  = '?';
                $values[] = $data[$col];
            }
        }
        $sql = 'INSERT INTO machines (' . implode(',', $cols) . ') VALUES (' . implode(',', $marks) . ')';
        Db::get()->prepare($sql)->execute($values);
        return (int) Db::get()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $set = [];
        $values = [];
        foreach (self::WRITABLE_COLS as $col) {
            if (array_key_exists($col, $data)) {
                $set[] = "$col = ?";
                $values[] = $data[$col];
            }
        }
        if (empty($set)) return;
        $values[] = $id;
        $sql = 'UPDATE machines SET ' . implode(',', $set) . ' WHERE id = ?';
        Db::get()->prepare($sql)->execute($values);
    }

    public static function setStatus(int $id, string $status): void
    {
        // Lit l'ancien status pour ne logger que les vrais changements (évite
        // le bruit sur les set au même statut).
        $old = null;
        $stmt = Db::get()->prepare('SELECT status FROM machines WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) $old = (string)$row['status'];

        Db::get()->prepare('UPDATE machines SET status = ? WHERE id = ?')->execute([$status, $id]);

        // Un nouvel install ou wipe invalide toute autorisation antérieure :
        // l'administrateur pourra la remettre si l'OS est sur un autre disque.
        $clearedLocalBoot = false;
        if (in_array($status, ['installing', 'wiping', 'wiped'], true)
            && Db::columnExists('machines', 'local_boot_source')) {
            $clearStmt = Db::get()->prepare("UPDATE machines SET local_boot_source = 'none' WHERE id = ? AND local_boot_source != 'none'");
            $clearStmt->execute([$id]);
            $clearedLocalBoot = $clearStmt->rowCount() > 0;
            if ($clearedLocalBoot) {
                MachineEvent::log($id, MachineEvent::LEVEL_INFO,
                    'Démarrage local désactivé au début de l’opération');
            }
        }

        if ($old !== null && $old !== $status) {
            MachineEvent::log($id, MachineEvent::LEVEL_INFO, "Status: $old → $status");
            // Le menu PXE par machine BIOS (pxelinux.cfg/01-<MAC>) dépend du
            // status. À chaque changement, on demande au worker de regénérer
            // les fichiers per-MAC pour que le prochain PXE boot serve le bon
            // menu (wipe / install / discovery / boot local).
            self::enqueueRenderPxeMenus();
        } elseif ($clearedLocalBoot) {
            self::enqueueRenderPxeMenus();
        }
    }

    public static function setLocalBootSource(int $id, string $source): void
    {
        if (!in_array($source, ['none', 'pxe', 'manual'], true)) {
            throw new \InvalidArgumentException('Invalid local boot source');
        }
        $stmt = Db::get()->prepare('SELECT local_boot_source FROM machines WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetchColumn();
        if ($old === false || $old === $source) return;
        Db::get()->prepare('UPDATE machines SET local_boot_source = ? WHERE id = ?')
            ->execute([$source, $id]);
        MachineEvent::log($id, MachineEvent::LEVEL_ACTION,
            'Démarrage local : ' . match ($source) {
                'pxe' => 'activé après installation PXE réussie',
                'manual' => 'activé manuellement par administrateur',
                default => 'désactivé',
            });
        self::enqueueRenderPxeMenus();
    }

    /**
     * Enqueue un init_job si aucun rendu n'est déjà en attente. Un rendu en
     * cours peut avoir lu l'ancien état : il faut alors prévoir un second
     * passage. Une seule demande en attente suffit pour tous les changements
     * visibles avant son démarrage.
     * Le worker rendra les fichiers TFTP per-MAC pour
     * pxelinux (BIOS) + le default + les configs preseed dynamiques.
     */
    private static function enqueueRenderPxeMenus(): void
    {
        try {
            $pdo = Db::get();
            $pdo->prepare(
                "INSERT INTO init_jobs (job_type, status, message) "
              . "SELECT ?, 'pending', ? WHERE NOT EXISTS ("
              . "SELECT 1 FROM init_jobs WHERE job_type=? AND status='pending')"
            )->execute(['bootstrap:render_templates', 'Régénération des menus PXE par machine', 'bootstrap:render_templates']);
        } catch (\Throwable) {
            // Best-effort : si la table init_jobs n'existe pas (DB fraîche) ou
            // si on est dans un context CLI (migrate.php), on skip.
        }
    }

    /**
     * Active ou désactive le mode rescue pour une machine.
     * Quand rescue_mode=1, grub.php sert le menuentry rescue (Debian d-i en récupération)
     * au prochain PXE boot, indépendamment du status actuel.
     */
    public static function setRescueMode(int $id, bool $enable): void
    {
        Db::get()->prepare('UPDATE machines SET rescue_mode = ? WHERE id = ?')
                  ->execute([$enable ? 1 : 0, $id]);
    }

    public static function setStatusByMac(string $mac, string $status): void
    {
        $mac = self::normalizeMac($mac);
        if ($mac === '') return;
        Db::get()->prepare('UPDATE machines SET status = ?, last_seen_at = CURRENT_TIMESTAMP WHERE mac = ?')
                  ->execute([$status, $mac]);
    }

    /**
     * Programme une action à exécuter au prochain poll de discover.sh.
     * $action ∈ {null, 'poweroff', 'reboot'}.
     * NULL = pas d'action, machine reste en attente (boucle de poll).
     */
    public static function setNextAction(int $id, ?string $action): void
    {
        Db::get()->prepare('UPDATE machines SET next_action = ? WHERE id = ?')
                  ->execute([$action, $id]);
    }

    /**
     * Set/reset la deadline d'auto-poweroff post-discovery (fenêtre 1h pendant
     * laquelle l'admin peut décider d'une action depuis l'UI). $epoch = null
     * = pas de fenêtre active (action consommée OU machine pas en attente).
     */
    public static function setAwaitingUntil(int $id, ?int $epoch): void
    {
        $iso = $epoch === null ? null : gmdate('Y-m-d H:i:s', $epoch);
        Db::get()->prepare('UPDATE machines SET awaiting_action_until = ? WHERE id = ?')
                  ->execute([$iso, $id]);
    }

    /**
     * Lit + reset atomiquement next_action pour la machine ayant ce MAC
     * (primaire ou secondaire). Appelé par l'endpoint de poll côté discover.sh.
     * Retourne 'wait' si rien à faire, sinon l'action ('poweroff' | 'reboot').
     *
     * Le reset à NULL après lecture évite que la machine boucle sur la même
     * action au tick suivant (one-shot). Pour un redémarrage, la deadline est
     * effacée ; pour un arrêt, elle est expirée et sert d'indice au dashboard.
     */
    public static function consumeNextAction(string $mac): string
    {
        $row = self::findByAnyMac($mac);
        if (!$row || empty($row['next_action'])) return 'wait';
        $action = (string)$row['next_action'];
        // Un ordre d'extinction consommé ferme la fenêtre d'attente et sert
        // d'indice d'arrêt pour le dashboard, même si le dernier ping de
        // discover.sh se perd. Un redémarrage efface simplement la fenêtre.
        $deadline = $action === 'poweroff' ? gmdate('Y-m-d H:i:s', time()) : null;
        Db::get()->prepare('UPDATE machines SET next_action = NULL, awaiting_action_until = ? WHERE id = ?')
                  ->execute([$deadline, (int)$row['id']]);
        return $action;
    }

    /**
     * L'agent discovery a atteint sa limite d'attente ou reçu un ordre
     * d'extinction. Il peut ne plus joindre l'API au dernier instant : la
     * deadline conservée permet au dashboard d'inférer l'arrêt sans ping IP.
     */
    public static function isOfflineAfterDiscovery(array $machine): bool
    {
        $raw = $machine['awaiting_action_until'] ?? null;
        $status = $machine['status'] ?? '';
        if ($raw === null || $raw === '') {
            // Avant ce correctif, le poll effaçait la deadline au timeout.
            // Retrouver ces sessions déjà terminées via le dernier inventaire,
            // sans classer une machine ajoutée manuellement comme éteinte.
            if ($status !== 'discovered' || empty($machine['inventory_json'])) return false;
            $lastSeen = $machine['last_seen_at'] ?? null;
            $lastSeenEpoch = $lastSeen ? strtotime((string)$lastSeen . ' UTC') : false;
            return $lastSeenEpoch !== false && $lastSeenEpoch + 3600 <= time();
        }
        if (in_array($status, ['inventorying', 'installing', 'wiping', 'testing', 'failed'], true)) return false;
        $deadline = strtotime((string)$raw . ' UTC');
        return $deadline !== false && $deadline <= time();
    }

    /** Hold the root-owned shared menu lock before a DB deletion or unlink. */
    public static function lockPxeMenus(): array
    {
        $root = rtrim((string)(getenv('TFTP_ROOT') ?: '/srv/tftp'), '/');
        if ($root === '' || $root[0] !== '/' || str_contains($root, "\0")) {
            throw new \RuntimeException('Racine TFTP invalide ; suppression refusée.');
        }
        $current = '';
        $parts = explode('/', trim($root, '/'));
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('Racine TFTP invalide ; suppression refusée.');
            }
            $current .= '/' . $part;
            $info = @lstat($current);
            $stickyAncestor = $info !== false && $index < count($parts) - 1 && ($info['mode'] & 01000) !== 0;
            if ($info === false || ($info['mode'] & 0170000) !== 0040000
                || $info['uid'] !== 0 || (($info['mode'] & 0022) !== 0 && !$stickyAncestor)) {
                throw new \RuntimeException('Racine TFTP non protégée ; suppression refusée.');
            }
        }
        $menu = @lstat($root . '/pxelinux.cfg');
        if ($menu === false || ($menu['mode'] & 0170000) !== 0040000
            || $menu['uid'] !== 0 || ($menu['mode'] & 0002) !== 0) {
            throw new \RuntimeException('Répertoire des menus non protégé ; suppression refusée.');
        }
        $path = $root . '/.pxe-menu.lock';
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000
            || $before['uid'] !== 0 || ($before['mode'] & 0002) !== 0 || $before['nlink'] !== 1) {
            throw new \RuntimeException('Verrou des menus indisponible ; suppression refusée.');
        }
        $lock = @fopen($path, 'r+');
        if ($lock === false) throw new \RuntimeException('Verrou des menus inaccessible ; suppression refusée.');
        $opened = fstat($lock);
        if ($opened === false || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
            fclose($lock);
            throw new \RuntimeException('Verrou des menus modifié ; suppression refusée.');
        }
        $deadline = microtime(true) + 10;
        do {
            if (flock($lock, LOCK_EX | LOCK_NB)) return [$lock, $root];
            usleep(50000);
        } while (microtime(true) < $deadline);
        fclose($lock);
        throw new \RuntimeException('Rendu PXE occupé ; réessayez la suppression.');
    }

    public static function delete(int $id): void
    {
        [$lock, $tftpRoot] = self::lockPxeMenus();
        try {
            $stmt = Db::get()->prepare('SELECT mac FROM machines WHERE id = ?');
            $stmt->execute([$id]);
            $value = $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($value === false) return;
            $rawMac = strtolower(trim((string)$value));
            if (!preg_match('/^(?:[0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $rawMac)
                && !preg_match('/^(?:[0-9a-f]{2}-){5}[0-9a-f]{2}$/D', $rawMac)
                && !preg_match('/^[0-9a-f]{12}$/D', $rawMac)) {
                throw new \RuntimeException('Adresse MAC invalide ; suppression refusée.');
            }
            $mac = implode(':', str_split(str_replace([':', '-'], '', $rawMac), 2));
            $name = '01-' . str_replace(':', '-', $mac);
            $cfgFile = $tftpRoot . '/pxelinux.cfg/' . $name;
            $pdo = Db::get();
            if ($pdo->inTransaction()) {
                throw new \RuntimeException('Transaction déjà ouverte ; suppression refusée.');
            }
            $saved = null;
            $pdo->beginTransaction();
            try {
                $info = @lstat($cfgFile);
                if ($info !== false) {
                    $kind = $info['mode'] & 0170000;
                    if ($kind !== 0100000 && $kind !== 0120000) {
                        throw new \RuntimeException('Menu PXE invalide ; suppression refusée.');
                    }
                    $saved = $cfgFile . '.pending-delete-' . bin2hex(random_bytes(16));
                    if (file_exists($saved) || is_link($saved) || !rename($cfgFile, $saved)) {
                        $saved = null;
                        throw new \RuntimeException('Menu PXE inaccessible ; suppression refusée.');
                    }
                }
                $delete = $pdo->prepare('DELETE FROM machines WHERE id = ? AND mac = ?');
                $delete->execute([$id, (string)$value]);
                if ($delete->rowCount() !== 1) {
                    throw new \RuntimeException('Machine modifiée ; suppression refusée.');
                }
                $pdo->commit();
            } catch (\Throwable $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($saved !== null && (file_exists($saved) || is_link($saved))) {
                    if (file_exists($cfgFile) || is_link($cfgFile) || !rename($saved, $cfgFile)) {
                        throw new \RuntimeException('Suppression refusée ; restauration manuelle du menu nécessaire.', 0, $error);
                    }
                }
                throw $error;
            }
            if ($saved !== null && (file_exists($saved) || is_link($saved)) && !unlink($saved)) {
                error_log('Machine::delete: fichier temporaire de menu conservé : ' . $saved);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Décode la config d'install snapshot stockée dans machines.install_config_json.
     * Renvoie null si pas de config (machine pas encore configurée).
     *
     * @return array<string,mixed>|null
     */
    public static function installConfig(array $machine): ?array
    {
        $raw = $machine['install_config_json'] ?? null;
        if ($raw === null || trim((string)$raw) === '') return null;
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Persiste un snapshot de config d'install pour une machine.
     * Source de vérité utilisée par preseed.php depuis le refactor 2026-05-07.
     *
     * @param array<string,mixed> $config
     */
    public static function saveInstallConfig(int $id, array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Db::get()->prepare('UPDATE machines SET install_config_json = ? WHERE id = ?')
                  ->execute([$json, $id]);
    }

    /**
     * Concatène vendor + product depuis inventory_json.
     * Retourne '' si aucune info ou valeurs 'n/a'.
     */
    public static function vendorProduct(array $machine): string
    {
        $hw = ($machine['inventory_json'] ?? null)
            ? (json_decode((string)$machine['inventory_json'], true)['inventory'] ?? [])
            : [];
        $vendor  = trim((string)($hw['vendor'] ?? ''));
        $product = trim((string)($hw['product'] ?? ''));
        $isReal  = static fn(string $s): bool => $s !== '' && $s !== 'n/a';
        if ($isReal($vendor) && $isReal($product)) return $vendor . ' ' . $product;
        if ($isReal($vendor))  return $vendor;
        if ($isReal($product)) return $product;
        return '';
    }

    /**
     * Label le plus parlant pour identifier la machine en UI :
     * hostname si défini, sinon vendor/product, sinon MAC.
     */
    public static function displayLabel(array $machine): string
    {
        $hostname = trim((string)($machine['hostname'] ?? ''));
        if ($hostname !== '') return $hostname;
        $vp = self::vendorProduct($machine);
        if ($vp !== '') return $vp;
        return (string)($machine['mac'] ?? '');
    }

    /**
     * IP « courante » pour l'affichage (liste + détail) : IP d'install si l'OS
     * est posé (static_ip du snapshot, ou « DHCP »), sinon l'IP DHCP obtenue au
     * discovery (inventory_json.discovery_ip = REMOTE_ADDR du POST). null si
     * inconnue. Aligné sur la logique de la fiche détail (show.php).
     */
    public static function primaryIp(array $machine): ?string
    {
        $status = (string)($machine['status'] ?? '');
        if (in_array($status, ['installed', 'provisioned'], true)) {
            $cfg = !empty($machine['install_config_json'])
                ? (json_decode((string)$machine['install_config_json'], true) ?: [])
                : [];
            if (!empty($cfg['static_ip']))            return (string)$cfg['static_ip'];
            if (($cfg['network_mode'] ?? null) === 'dhcp') return 'DHCP';
        }
        $inv = !empty($machine['inventory_json'])
            ? (json_decode((string)$machine['inventory_json'], true) ?: [])
            : [];
        $dip = $inv['discovery_ip'] ?? null;
        return ($dip !== null && $dip !== '') ? (string)$dip : null;
    }

    /**
     * Signature stable de la liste rendue (lignes + filtre statut). Sert au
     * polling HTMX de #machines-section : si la signature envoyée par le client
     * == celle calculée, le contrôleur renvoie 204 (pas de swap → pas de
     * re-création du tableau → pas de clignotement des cases cochées). Toute
     * modif d'une colonne change le hash ; l'ordre des lignes est déterministe
     * (ORDER BY SQL de all()). Doit être calculée IDENTIQUEMENT en page complète
     * (index) et en fragment (listFragment).
     */
    public static function listSignature(array $machines, ?string $statusFilter, array $extra = []): string
    {
        // $extra : données affichées dans la liste mais absentes des rows machines
        // (ex: % des tests de disque actifs) — sans elles dans le hash, l'anti-flash
        // 204 gèlerait le badge entre deux changements de statut.
        // L'échéance peut être franchie sans UPDATE DB : inclure l'état dérivé
        // pour que le polling HTMX ne réponde pas 204 au changement hors ligne.
        $offline = array_map(self::isOfflineAfterDiscovery(...), $machines);
        return md5((string)json_encode([$statusFilter, $machines, $extra, $offline]));
    }

    /** Récupère machine + preset complet pour preseed/grub generation */
    public static function findWithPreset(int $id): ?array
    {
        $stmt = Db::get()->prepare(
            'SELECT m.*, p.* '
          . 'FROM machines m LEFT JOIN presets p ON m.preset_id = p.id '
          . 'WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
