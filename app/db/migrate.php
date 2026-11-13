<?php
declare(strict_types=1);

/**
 * Migration one-shot idempotente — driver-aware (SQLite / MySQL / MariaDB).
 *
 * Pré-requis : le schema initial a été appliqué via php-init.sh
 * (sqlite3 ... < schema.sqlite.sql  ou  mysql ... < schema.mysql.sql).
 * Ce script s'occupe UNIQUEMENT des migrations de data + ALTER TABLE
 * idempotents pour les colonnes ajoutées au fil de l'eau (back-compat des
 * installations historiques).
 *
 * 1. Presets legacy :
 *    - static_netmask (notation pointée)  -> static_cidr (entier)
 *    - static_dns (CSV/espace)            -> JSON array
 *    - partition_mode dans les anciens 5 modes -> 'default' ou 'custom'
 *
 * 2. Les anciens formats de partition et les colonnes historiques ne sont
 *    pas convertis automatiquement : ils nécessitent une migration séparée.
 *
 * 3. Machines : copier preset assigné -> machines.install_config_json (snapshot).
 *
 * Idempotence : skip les lignes déjà migrées. Appelé par php-init.sh après le
 * déploiement du code et avant que le worker tourne.
 */

// Autoload PSR-4 minimal pour Admin\
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, 6));
    $file = __DIR__ . '/../src/' . $rel . '.php';
    if (is_file($file)) require_once $file;
});

use Admin\Db;
use Admin\Catalog\DebianCatalog;
use Admin\Models\Preset;

// Driver actif (lit /etc/pxe-tools/db.conf ou fallback legacy SQLite).
$driver = Db::driver();
echo "migrate: driver=$driver\n";

// WAL modifie le fichier SQLite ; différer son activation jusqu'après les
// contrôles de compatibilité afin qu'un refus laisse les octets intacts.
$cfg = Db::config();
if ($driver === 'sqlite' && !is_file($cfg['path'])) {
    fwrite(STDERR, "Migration refusée : base SQLite existante requise.\n");
    exit(2);
}
$pdo = Db::get(false);

// -----------------------------------------------------------------------------
// Garde-fous : colonnes critiques attendues (présentes dans schema.<driver>.sql)
// -----------------------------------------------------------------------------
if (!Db::columnExists('presets', 'static_cidr')) {
    fwrite(STDERR, "ERREUR : colonne presets.static_cidr absente — appliquer schema.$driver.sql d'abord\n");
    exit(2);
}
if (!Db::columnExists('machines', 'install_config_json')) {
    fwrite(STDERR, "ERREUR : colonne machines.install_config_json absente — appliquer schema.$driver.sql d'abord\n");
    exit(2);
}

function netmaskToCidr(?string $mask): ?int {
    if (!$mask) return null;
    $mask = trim($mask);
    if ($mask === '') return null;
    $long = ip2long($mask);
    if ($long === false) return null;
    $bin = sprintf('%032b', $long);
    if (!preg_match('/^1*0*$/', $bin)) return null;
    return substr_count($bin, '1');
}

function normalizeDns(?string $raw): string {
    $raw = (string)$raw;
    $raw = trim($raw);
    if ($raw === '') return json_encode([], JSON_UNESCAPED_SLASHES);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $clean = array_values(array_filter(array_map('trim', $decoded), fn($x) => $x !== ''));
        return json_encode($clean, JSON_UNESCAPED_SLASHES);
    }
    $parts = preg_split('/[\s,]+/', $raw);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    return json_encode($parts, JSON_UNESCAPED_SLASHES);
}

/**
 * Détecte le format legacy de partition_custom_json (array indexée d'objets {fs,...}).
 * Le nouveau format est un objet avec target_disks/disks/vgs/logical_volumes.
 */
function isLegacyPartitionsJson(?string $raw): bool {
    if ($raw === null || trim($raw) === '') return false;
    $d = json_decode($raw, true);
    if (!is_array($d)) return false;
    return array_is_list($d);
}

// Avant toute modification, refuser les données que cette migration ne peut
// pas convertir sans perte. Une sauvegarde et un outil dédié sont nécessaires.
foreach (['partition_target_disk', 'partition_lvm_vg'] as $column) {
    if (Db::columnExists('presets', $column)) {
        fwrite(STDERR, "Migration refusée : colonnes historiques de partition à examiner.\n");
        exit(2);
    }
}
$legacyModes = ['atomic_lvm', 'home_separate', 'var_separate', 'encrypted'];
foreach ($pdo->query('SELECT partition_mode, partition_custom_json FROM presets')->fetchAll(PDO::FETCH_ASSOC) as $preset) {
    if (in_array($preset['partition_mode'], $legacyModes, true) || isLegacyPartitionsJson($preset['partition_custom_json'])) {
        fwrite(STDERR, "Migration refusée : recette de partition historique à examiner.\n");
        exit(2);
    }
}
foreach ($pdo->query('SELECT install_config_json FROM machines WHERE install_config_json IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $rawConfig) {
    $config = json_decode((string)$rawConfig, true);
    if (!is_array($config)) continue;
    $partitions = $config['partition_custom_json'] ?? null;
    if (in_array($config['partition_mode'] ?? null, $legacyModes, true)
        || (is_string($partitions) && isLegacyPartitionsJson($partitions))
        || (is_array($partitions) && array_is_list($partitions))) {
        fwrite(STDERR, "Migration refusée : configuration machine historique à examiner.\n");
        exit(2);
    }
}
$keyQ = Db::quoteIdent('key');
$sensitiveLegacy = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE $keyQ IN ('ansible_vault_password', 'ansible_vault_applied_hash')")->fetchColumn();
if ($sensitiveLegacy > 0) {
    fwrite(STDERR, "Migration refusée : anciennes données sensibles à traiter séparément.\n");
    exit(2);
}
$legacyDhcp = $pdo->query("SELECT value FROM settings WHERE $keyQ='dhcp_mode'")->fetchColumn();
if ($legacyDhcp !== false && !in_array($legacyDhcp, ['proxy', 'tftp_only'], true)) {
    fwrite(STDERR, "Migration refusée : ancien mode réseau non reconnu.\n");
    exit(2);
}
// Refuse populated tables outside the current core schema. They may contain
// independently managed data that this edition cannot safely migrate or delete.
// Empty unknown tables are left untouched. A future module registry must make
// module-owned tables explicit before this conservative guard can be relaxed.
$schemaFile = __DIR__ . '/schema.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
$schema = @file_get_contents($schemaFile);
if ($schema === false || !preg_match_all('/^[ \t]*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-zA-Z_][a-zA-Z0-9_]*)/mi', $schema, $matches)) {
    fwrite(STDERR, "Migration refusée : schéma de référence indisponible.\n");
    exit(2);
}
$coreTables = array_fill_keys($matches[1], true);
try {
    $tableQuery = $driver === 'sqlite'
        ? "SELECT name FROM sqlite_master WHERE type='table' AND substr(name, 1, 7) <> 'sqlite_'"
        : "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'";
    $tables = $pdo->query($tableQuery)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (isset($coreTables[(string)$table])) continue;
        $quoted = Db::quoteIdent((string)$table);
        if ($pdo->query("SELECT 1 FROM $quoted LIMIT 1")->fetchColumn() !== false) {
            throw new RuntimeException('unmanaged populated table');
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration refusée : données externes ou tables non reconnues à examiner séparément.\n");
    exit(2);
}
if ($driver === 'sqlite') {
    $pdo->exec('PRAGMA journal_mode = WAL');
}

// ---------------------------------------------------------------------------
// 1. Presets : netmask / DNS / partition_mode legacy
// ---------------------------------------------------------------------------
$rows = $pdo->query('SELECT id, static_netmask, static_cidr, static_dns, partition_mode, partition_custom_json FROM presets')->fetchAll(PDO::FETCH_ASSOC);
$migrated = 0;

foreach ($rows as $row) {
    $updates = [];
    $params  = [];

    if ($row['static_cidr'] === null && !empty($row['static_netmask'])) {
        $cidr = netmaskToCidr($row['static_netmask']);
        if ($cidr !== null) {
            $updates[] = 'static_cidr = ?';
            $params[]  = $cidr;
        }
    }

    $dns = $row['static_dns'];
    if ($dns !== null && $dns !== '') {
        $decoded = json_decode((string)$dns, true);
        if (!is_array($decoded)) {
            $updates[] = 'static_dns = ?';
            $params[]  = normalizeDns($dns);
        }
    }

    if (!empty($updates)) {
        $params[] = $row['id'];
        $sql = 'UPDATE presets SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($params);
        $migrated++;
    }
}

echo "migrate-presets: $migrated row(s) updated, " . count($rows) . " total\n";

// Les anciennes colonnes et configurations de partition restent intactes.

// ---------------------------------------------------------------------------
// 4. Snapshot install_config_json depuis preset assigné (machines sans config)
// ---------------------------------------------------------------------------
// Délègue à Preset::toInstallConfig() pour inclure systématiquement TOUS les
// champs du snapshot (distro, network, partition, admin, advanced, root_login_mode,
// admin_user_fullname, ansible_*). Auparavant une copie locale omettait les
// nouveaux champs avancés, créant des snapshots legacy qui fallback bait au
// mode 'enabled' même si le preset était 'disabled'.

$snapshotsCreated = 0;
$machineRowsToSnapshot = $pdo->query(
    'SELECT m.id, m.preset_id, m.install_config_json, p.* '
  . 'FROM machines m LEFT JOIN presets p ON m.preset_id = p.id '
  . 'WHERE m.preset_id IS NOT NULL '
  . 'AND (m.install_config_json IS NULL OR m.install_config_json = "")'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($machineRowsToSnapshot as $mrow) {
    if (empty($mrow['preset_id']) || empty($mrow['name'])) continue;
    $config = Preset::toInstallConfig($mrow);
    $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->prepare('UPDATE machines SET install_config_json = ? WHERE id = ?')
        ->execute([$json, $mrow['id']]);
    $snapshotsCreated++;
}

echo "migrate-machines: $snapshotsCreated snapshot(s) créé(s) depuis preset assigné\n";

// ---------------------------------------------------------------------------
// 5. ALTER TABLE idempotents — back-compat upgrades SQLite legacy
// ---------------------------------------------------------------------------
// init_jobs.updated_at : heartbeat watchdog (worker.py flush log)
if (!Db::columnExists('init_jobs', 'updated_at')) {
    $pdo->exec("ALTER TABLE init_jobs ADD COLUMN updated_at DATETIME");
    echo "migrate-init: added init_jobs.updated_at column (heartbeat watchdog)\n";
}

// Sentinel wizard_completed : ne pose '0' QUE si la row n'existe pas.
// Bug historique : Db::upsert faisait ON CONFLICT DO UPDATE value=excluded
// → écrasait la valeur '1' à chaque restart container → wizard re-déclenché
// après chaque deploy. Maintenant : check d'existence + INSERT seulement
// si absent → la value '1' posée par un /init/complete précédent est
// préservée à travers les restarts.
$keyQ = Db::quoteIdent('key');
$exists = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE $keyQ='wizard_completed'")->fetchColumn();
if ($exists === 0) {
    $pdo->prepare("INSERT INTO settings ($keyQ, value) VALUES ('wizard_completed', '0')")->execute();
    echo "migrate-auth: settings.wizard_completed sentinel posed (initial)\n";
} else {
    echo "migrate-auth: settings.wizard_completed sentinel already present (kept)\n";
}

// ---------------------------------------------------------------------------
// 5bis. Renommage settings.orchestrator_ip → pxe_ip (rename 2026-05-12)
// ---------------------------------------------------------------------------
// Recopier l'ancienne valeur si nécessaire, sans supprimer l'originale.
$keyQ = Db::quoteIdent('key');
$oldIp = $pdo->query("SELECT value FROM settings WHERE $keyQ='orchestrator_ip'")->fetchColumn();
$newIp = $pdo->query("SELECT value FROM settings WHERE $keyQ='pxe_ip'")->fetchColumn();
if ($oldIp !== false && $newIp === false) {
    $pdo->prepare("INSERT INTO settings ($keyQ, value) VALUES ('pxe_ip', ?)")->execute([$oldIp]);
    echo "migrate-settings: copied the previous server setting\n";
} elseif ($oldIp !== false && $newIp !== false) {
    echo "migrate-settings: both server settings retained\n";
}

// ---------------------------------------------------------------------------
// 6. Renommage settings.dhcp_mode → dhcp_proxy_enabled (mai 2026)
// ---------------------------------------------------------------------------
$old = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='dhcp_mode'")->fetchColumn();
$new = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='dhcp_proxy_enabled'")->fetchColumn();
if ($old !== false && $new === false) {
    $mapped = ($old === 'proxy') ? 'on' : 'off';
    $pdo->prepare(
        "INSERT INTO settings (" . Db::quoteIdent('key') . ", value) VALUES ('dhcp_proxy_enabled', ?)"
    )->execute([$mapped]);
    echo "migrate-settings: copied the previous network mode\n";
} elseif ($old !== false && $new !== false) {
    echo "migrate-settings: both network settings retained\n";
}

// ---------------------------------------------------------------------------
// 7. os_images : ALTER TABLE legacy (live progress/message/updated_at + minor_version + channel)
// ---------------------------------------------------------------------------
// Initial MySQL schemas omitted these columns used by the same image model.
foreach (['firmware_url' => 'TEXT', 'size_bytes' => $driver === 'mysql' ? 'BIGINT' : 'INTEGER', 'error_msg' => 'TEXT'] as $column => $type) {
    if (!Db::columnExists('os_images', $column)) {
        $pdo->exec("ALTER TABLE os_images ADD COLUMN $column $type");
        echo "migrate-images: added os_images.$column\n";
    }
}
if (!Db::columnExists('os_images', 'progress_percent')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN progress_percent INTEGER NOT NULL DEFAULT 0");
    echo "migrate-images: added os_images.progress_percent (live %)\n";
}
if (!Db::columnExists('os_images', 'message')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN message TEXT");
    echo "migrate-images: added os_images.message (sub-message live)\n";
}
if (!Db::columnExists('os_images', 'updated_at')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN updated_at DATETIME");
    echo "migrate-images: added os_images.updated_at (heartbeat watchdog)\n";
}
if (!Db::columnExists('os_images', 'minor_version')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN minor_version TEXT");
    echo "migrate-images: added os_images.minor_version (populated by bootstrap:refresh_catalog)\n";
}
if (!Db::columnExists('os_images', 'channel')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN channel TEXT");
    echo "migrate-images: added os_images.channel (stable|oldstable|testing)\n";
}

// v2 : nouvelles colonnes pour multi-distros + point releases
if (!Db::columnExists('os_images', 'codename')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN codename TEXT");
    echo "migrate-images: added os_images.codename (v2 : Debian/Ubuntu codename)\n";
}
if (!Db::columnExists('os_images', 'major')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN major INTEGER");
    echo "migrate-images: added os_images.major (v2 : version majeure)\n";
}
if (!Db::columnExists('os_images', 'version_full')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN version_full TEXT");
    echo "migrate-images: added os_images.version_full (v2 : canonique X.Y.Z)\n";
}
if (!Db::columnExists('os_images', 'iso_url')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN iso_url TEXT");
    echo "migrate-images: added os_images.iso_url (v2 : URL ISO si iso_extract)\n";
}
if (!Db::columnExists('os_images', 'extract_method')) {
    $pdo->exec("ALTER TABLE os_images ADD COLUMN extract_method TEXT NOT NULL DEFAULT 'direct'");
    echo "migrate-images: added os_images.extract_method (v2 : direct|iso_extract)\n";
}

// catalog_entries — cache discovery (v2). CREATE TABLE IF NOT EXISTS idempotent.
$catalogTableSql = ($driver === 'mysql')
    ? "CREATE TABLE IF NOT EXISTS catalog_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        distro VARCHAR(64) NOT NULL,
        codename VARCHAR(64),
        major INT,
        version_full VARCHAR(64) NOT NULL,
        channel VARCHAR(16),
        arch VARCHAR(16) NOT NULL,
        kernel_url TEXT,
        initrd_url TEXT,
        firmware_url TEXT,
        iso_url TEXT,
        extract_method VARCHAR(32) NOT NULL DEFAULT 'direct',
        discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_catalog_distro_version_arch (distro, version_full, arch),
        INDEX idx_catalog_entries_distro (distro, major DESC, version_full DESC)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    : "CREATE TABLE IF NOT EXISTS catalog_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        distro TEXT NOT NULL,
        codename TEXT,
        major INTEGER,
        version_full TEXT NOT NULL,
        channel TEXT,
        arch TEXT NOT NULL,
        kernel_url TEXT,
        initrd_url TEXT,
        firmware_url TEXT,
        iso_url TEXT,
        extract_method TEXT NOT NULL DEFAULT 'direct',
        discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(distro, version_full, arch)
       )";
$pdo->exec($catalogTableSql);
if ($driver !== 'mysql') {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_catalog_entries_distro ON catalog_entries(distro, major DESC, version_full DESC)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_os_images_full ON os_images(distro, version_full, arch) WHERE version_full IS NOT NULL");
}
echo "migrate-images: catalog_entries table ensured (v2)\n";

// Backfill version_full + codename + major depuis DebianCatalog::MAPPING (v2)
foreach (DebianCatalog::MAPPING as $major => $meta) {
    $cn = $meta['codename'] ?? null;
    if ($cn === null) continue;
    $pdo->prepare(
        "UPDATE os_images SET codename = ?, major = ? "
      . "WHERE distro = 'debian' AND version = ? AND (codename IS NULL OR codename = '')"
    )->execute([$cn, $major, $cn]);
}
// version_full = COALESCE(minor_version, version || '.0') pour rows debian existantes
$pdo->exec(
    "UPDATE os_images SET version_full = minor_version "
  . "WHERE distro = 'debian' AND minor_version IS NOT NULL AND minor_version != '' "
  . "AND (version_full IS NULL OR version_full = '')"
);
echo "migrate-images: backfilled v2 columns (codename, major, version_full)\n";

// Les images et leurs fichiers sont conservés, même si une version n'est plus
// proposée dans le catalogue. Leur suppression doit être une action explicite.

// v2 : suppression des pre-seeds Debian. Le catalogue commence vide ; l'admin
// ajoute des images via le picker (/settings/images/add). Les rows existantes
// sur les déploiements v1 sont préservées (pas de DELETE), juste plus de
// re-création automatique au démarrage.

// Backfill channel depuis DebianCatalog::MAPPING (pour rows v1 préservées).
foreach (DebianCatalog::MAPPING as $major => $meta) {
    $codename = $meta['codename'] ?? null;
    if ($codename === null) continue;
    $pdo->prepare(
        "UPDATE os_images SET channel = ? "
      . "WHERE distro = 'debian' AND version = ? AND (channel IS NULL OR channel = '')"
    )->execute([$meta['channel'], $codename]);
}
echo "migrate-images: backfilled channel from DebianCatalog::MAPPING (rétro-compat v1 rows)\n";

// Migration layout legacy /netboot/<version>/ → /netboot/<version>/<arch>/
$httpRoot = '/srv/http';
foreach ($pdo->query("SELECT DISTINCT version, arch FROM os_images")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $newDir = "$httpRoot/netboot/{$row['version']}/{$row['arch']}";
    $legacyKernel = "$httpRoot/netboot/{$row['version']}/linux";
    if (is_file($legacyKernel) && !is_file("$newDir/linux")) {
        @mkdir($newDir, 0755, true);
        $moved = 0;
        foreach (['linux', 'initrd.gz', 'firmware.cpio.gz'] as $f) {
            $src = "$httpRoot/netboot/{$row['version']}/$f";
            $dst = "$newDir/$f";
            if (is_file($src) && @rename($src, $dst)) $moved++;
        }
        if ($moved > 0) {
            echo "migrate-images: layout migrated {$row['version']}/* → {$row['version']}/{$row['arch']}/* ($moved fichiers)\n";
        }
    }
}

// Backfill : marquer status='downloaded' si fichiers déjà présents sur disque
$rows = $pdo->query("SELECT id, distro, version, arch FROM os_images WHERE status='pending' AND enabled=1")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $dir = "$httpRoot/netboot/{$row['version']}/{$row['arch']}";
    $kernel = "$dir/linux";
    if (is_file($kernel)) {
        $size = (int)@filesize($kernel) + (int)@filesize("$dir/initrd.gz") + (int)@filesize("$dir/firmware.cpio.gz");
        $pdo->prepare("UPDATE os_images SET status='downloaded', downloaded_at=CURRENT_TIMESTAMP, size_bytes=? WHERE id=?")
            ->execute([$size, $row['id']]);
        echo "migrate-images: backfilled os_images id={$row['id']} ({$row['version']}/{$row['arch']}) status=downloaded ({$size} bytes on disk)\n";
    }
}

// ---------------------------------------------------------------------------
// 8. Spec F — Ansible optionnel + sentinel par défaut
// ---------------------------------------------------------------------------
$repoUrl = trim((string)(getenv('ANSIBLE_REPO_URL') ?: ''));
if ($repoUrl !== '') {
    $existingRepo = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='ansible_repo_url'")->fetchColumn();
    if ($existingRepo === false) {
        $pdo->prepare("INSERT INTO settings (" . Db::quoteIdent('key') . ", value) VALUES ('ansible_repo_url', ?)")
            ->execute([$repoUrl]);
        echo "migrate-ansible: ansible_repo_url initialisé depuis l'environnement\n";
    } elseif (trim((string)$existingRepo) === '') {
        $pdo->prepare("UPDATE settings SET value=? WHERE " . Db::quoteIdent('key') . "='ansible_repo_url'")
            ->execute([$repoUrl]);
    }
}
$envPubkey = trim((string)(getenv('ANSIBLE_SSH_PUBKEY') ?: ''));
if ($envPubkey !== '') {
    $existingPubkey = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='ansible_ssh_pubkey'")->fetchColumn();
    if ($existingPubkey === false) {
        $pdo->prepare("INSERT INTO settings (" . Db::quoteIdent('key') . ", value) VALUES ('ansible_ssh_pubkey', ?)")
            ->execute([$envPubkey]);
        echo "migrate-ansible: clé publique initialisée depuis l'environnement\n";
    } elseif (trim((string)$existingPubkey) === '') {
        $pdo->prepare("UPDATE settings SET value=? WHERE " . Db::quoteIdent('key') . "='ansible_ssh_pubkey'")
            ->execute([$envPubkey]);
    }
}
$ansibleEnabled = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='ansible_enabled'")->fetchColumn();
if ($ansibleEnabled === false) {
    $dbPubkey  = $pdo->query("SELECT value FROM settings WHERE " . Db::quoteIdent('key') . "='ansible_ssh_pubkey'")->fetchColumn() ?: '';
    $shouldEnable = ($envPubkey !== '' || $dbPubkey !== '');
    $pdo->prepare(
        "INSERT INTO settings (" . Db::quoteIdent('key') . ", value) VALUES ('ansible_enabled', ?)"
    )->execute([$shouldEnable ? 'on' : 'off']);
    echo "migrate-ansible: ansible_enabled=" . ($shouldEnable ? 'on' : 'off') . " (auto-detected from existing config)\n";
}

// ---------------------------------------------------------------------------
// 9. ALTER TABLE machines — back-compat upgrades SQLite legacy
// ---------------------------------------------------------------------------
if (!Db::columnExists('machines', 'root_password_hash')) {
    $pdo->exec("ALTER TABLE machines ADD COLUMN root_password_hash TEXT");
    echo "migrate-machines: added root_password_hash column (per-machine root pwd)\n";
}

// Spec sécurité — stockage chiffré du mot de passe root en clair (optionnel)
if (!Db::columnExists('machines', 'root_password_encrypted')) {
    $blobType = ($driver === 'mysql') ? 'BLOB' : 'BLOB';  // identique sur les 2
    $pdo->exec("ALTER TABLE machines ADD COLUMN root_password_encrypted $blobType DEFAULT NULL");
    $pdo->exec("ALTER TABLE machines ADD COLUMN root_password_kdf_salt $blobType DEFAULT NULL");
    $pdo->exec("ALTER TABLE machines ADD COLUMN root_password_nonce $blobType DEFAULT NULL");
    echo "migrate-machines: added root_password_encrypted/kdf_salt/nonce (Spec sécurité)\n";
}

// Refonte chiffrement : passage de SecretBox (passphrase opérateur) à Crypto
// (master.key système). Un seul blob base64 par mdp, plus de friction passphrase.
// Les anciennes colonnes root_password_{encrypted,kdf_salt,nonce} restent en
// lecture seule pour rétrocompatibilité des machines déjà installées.
if (!Db::columnExists('machines', 'root_password_cipher')) {
    $pdo->exec("ALTER TABLE machines ADD COLUMN root_password_cipher TEXT DEFAULT NULL");
    echo "migrate-machines: added root_password_cipher (Crypto master.key)\n";
}

// Spec D.4 — colonne machines.boot_arch
if (!Db::columnExists('machines', 'boot_arch')) {
    $pdo->exec("ALTER TABLE machines ADD COLUMN boot_arch TEXT");
    echo "migrate-machines: added boot_arch column (Spec D.4)\n";
}

// Feature countdown auto-poweroff post-discovery — colonne machines.awaiting_action_until
if (!Db::columnExists('machines', 'awaiting_action_until')) {
    $pdo->exec("ALTER TABLE machines ADD COLUMN awaiting_action_until DATETIME DEFAULT NULL");
    echo "migrate-machines: added awaiting_action_until column (post-discovery 1h window)\n";
}

// Feature auto-redeploy Ansible — toggle par machine déclenché par webhook GitHub
if (!Db::columnExists('machines', 'auto_deploy')) {
    $intType = $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
    $pdo->exec("ALTER TABLE machines ADD COLUMN auto_deploy $intType NOT NULL DEFAULT 0");
    echo "migrate-machines: added auto_deploy column (toggle redéploiement auto sur push GitHub)\n";
}

// Feature wipe→install enchaîné — colonne wipes.chain_install_config_json
if (!Db::columnExists('wipes', 'chain_install_config_json')) {
    $blob = ($driver === 'mysql') ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("ALTER TABLE wipes ADD COLUMN chain_install_config_json $blob DEFAULT NULL");
    echo "migrate-wipes: added chain_install_config_json column (feature wipe→install enchaîné)\n";
}

// ---------------------------------------------------------------------------
// 10. presets : champs avancés (preseed Debian étendu)
//     - advanced_config_json : groupes optionnels (localization, mirror,
//       timezone, packages, apt, bootloader, finish, post_install). NULL =
//       fallback sur les valeurs hardcodées de preseed-base.cfg.j2.
//     - root_login_mode : 'enabled' (root + late_command useradd, défaut, compat)
//       ou 'disabled' (passwd/make-user natif d-i ; pattern preseed-board).
//     - admin_user_fullname : passwd/user-fullname requis en mode disabled.
// ---------------------------------------------------------------------------
if (!Db::columnExists('presets', 'advanced_config_json')) {
    $blob = ($driver === 'mysql') ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN advanced_config_json $blob DEFAULT NULL");
    echo "migrate-presets: added advanced_config_json column (preseed étendu)\n";
}
if (!Db::columnExists('presets', 'root_login_mode')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(16)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN root_login_mode $type NOT NULL DEFAULT 'enabled'");
    echo "migrate-presets: added root_login_mode column (enabled|disabled)\n";
}
if (!Db::columnExists('presets', 'admin_user_fullname')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(190)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN admin_user_fullname $type DEFAULT NULL");
    echo "migrate-presets: added admin_user_fullname column\n";
}
if (!Db::columnExists('presets', 'domain')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(253)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN domain $type DEFAULT NULL");
    echo "migrate-presets: added domain column (netcfg/get_domain)\n";
}
// pre_wipe_mode : si != 'aucun', un wipe est lancé avant l'installation au
// bulk-install (séquence wipe → install en 1 reboot via chain_install_config_json).
// Valeurs : 'aucun' (défaut, install directe) | 'partial' (~14 GB début+fin)
// | 'full' (intégralité, plusieurs heures sur HDD).
if (!Db::columnExists('presets', 'pre_wipe_mode')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(16)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN pre_wipe_mode $type NOT NULL DEFAULT 'aucun'");
    echo "migrate-presets: added pre_wipe_mode column (aucun|partial|full)\n";
}
// version_full : point release de l'OS (ex 13.4.0). NULL = résolution runtime
// vers la dernière version downloaded du distro (cf. OsImage::resolveVersionFull).
// Permet de pinger l'installer sur une version précise vs current du miroir.
if (!Db::columnExists('presets', 'version_full')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(64)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN version_full $type DEFAULT NULL");
    echo "migrate-presets: added version_full column (point release de l'OS)\n";
}
// variant : variante du preset. 'debian' (défaut, install Debian standard) |
// 'proxmox-ve' (install Debian 13 puis couche Proxmox VE via le rôle Ansible
// proxmox_ve déclenché au 1er boot). Le variant pilote des défauts + une
// validation côté contrôleur ; le déclencheur réel d'install reste ansible_role.
if (!Db::columnExists('presets', 'variant')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(32)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN variant $type NOT NULL DEFAULT 'debian'");
    echo "migrate-presets: added variant column (debian|proxmox-ve)\n";
}
// Chiffrement du mdp admin utilisateur en clair (master.key Crypto) en plus du
// hash sha512crypt (admin_user_pwhash). Permet l'affichage en clair dans
// l'aperçu machine sans passphrase opérateur.
if (!Db::columnExists('presets', 'admin_user_password_cipher')) {
    $pdo->exec("ALTER TABLE presets ADD COLUMN admin_user_password_cipher TEXT DEFAULT NULL");
    echo "migrate-presets: added admin_user_password_cipher (Crypto master.key)\n";
}
// Aucun domaine personnel n'est ajouté aux profils existants.

// ---------------------------------------------------------------------------
// 10bis. Modèle distro-name (2026-05-31) : presets.distro = NOM d'OS ('debian'),
//        nouvelle colonne codename = release ('trixie'). Avant, distro stockait
//        le codename → incohérent avec catalog_entries/os_images (distro='debian')
//        et cassait OsImage::latestForDistro/ensureForDistroVersion. Le codename
//        reste nécessaire (apt-suite preseed + segment de path netboot grub) → il
//        passe dans sa propre colonne ; preseed/grub le dérivent au runtime.
// ---------------------------------------------------------------------------
if (!Db::columnExists('presets', 'codename')) {
    $type = ($driver === 'mysql') ? 'VARCHAR(64)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN codename $type DEFAULT NULL");
    echo "migrate-presets: added codename column (modèle distro-name)\n";
}

// Backfill distro=codename → distro=nom OS + codename. Résolution codename→distro
// via catalog_entries, fallback DebianCatalog::MAPPING (couvre catalogue vide au
// 1er boot). Idempotent : skip si codename déjà rempli OU distro déjà un nom OS.
$osNames = $pdo->query("SELECT DISTINCT distro FROM catalog_entries")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$osNames = array_values(array_unique(array_map('strtolower', array_merge(
    $osNames, ['debian', 'ubuntu', 'rocky', 'almalinux', 'fedora', 'centos']
))));
$cnToDistro = [];   // codename(lower) => distro
foreach ($pdo->query("SELECT DISTINCT codename, distro FROM catalog_entries WHERE codename IS NOT NULL AND codename != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cnToDistro[strtolower((string)$r['codename'])] = (string)$r['distro'];
}
foreach (DebianCatalog::MAPPING as $meta) {
    $cn = strtolower((string)($meta['codename'] ?? ''));
    if ($cn !== '' && !isset($cnToDistro[$cn])) $cnToDistro[$cn] = 'debian';
}
$dnMigrated = 0;
foreach ($pdo->query("SELECT id, distro, codename FROM presets")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $d  = strtolower(trim((string)$pr['distro']));
    $cn = $pr['codename'];
    if (($cn !== null && $cn !== '') || in_array($d, $osNames, true)) continue;  // déjà migré / déjà nom OS
    $newDistro = $cnToDistro[$d] ?? 'debian';   // fallback debian (homelab Debian-only)
    $pdo->prepare("UPDATE presets SET distro = ?, codename = ? WHERE id = ?")
        ->execute([$newDistro, $d, (int)$pr['id']]);
    $dnMigrated++;
}
if ($dnMigrated > 0) {
    echo "migrate-presets: $dnMigrated preset(s) migré(s) vers distro-name (distro=nom OS + codename)\n";
}

// Les profils sont définis par l'administrateur ; aucune configuration personnelle
// n'est créée ni modifiée par la migration.

// ---------------------------------------------------------------------------
// 13. Actions + Checkpoints (2026-05-16) — table actions + FK action_id
// ---------------------------------------------------------------------------

// CREATE TABLE actions (idempotent)
if ($driver === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS actions (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        machine_id  BIGINT UNSIGNED NOT NULL,
        type        VARCHAR(32) NOT NULL,
        status      VARCHAR(16) NOT NULL DEFAULT 'pending',
        token       VARCHAR(64) NOT NULL,
        created_by  VARCHAR(190) NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        ended_at    DATETIME NULL,
        UNIQUE KEY idx_actions_token (token),
        INDEX idx_actions_machine (machine_id),
        CONSTRAINT fk_actions_machine
            FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS actions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        machine_id  INTEGER NOT NULL,
        type        TEXT NOT NULL,
        status      TEXT NOT NULL DEFAULT 'pending',
        token       TEXT NOT NULL UNIQUE,
        created_by  TEXT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ended_at    TIMESTAMP NULL,
        FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_actions_machine ON actions(machine_id)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_actions_token ON actions(token)");
}
echo "migrate-actions: table actions ensured\n";

// ALTER TABLE — ajout colonne action_id sur les 4 tables sous-job
$actionIdType = ($driver === 'mysql') ? 'BIGINT UNSIGNED' : 'INTEGER';
foreach ([
    'wipes'         => 'idx_wipes_action',
    'installations' => 'idx_installations_action',
    'discoveries'   => 'idx_discoveries_action',
    'disk_tests'    => 'idx_disk_tests_action',
] as $table => $idxName) {
    if (!Db::columnExists($table, 'action_id')) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN action_id $actionIdType NULL REFERENCES actions(id)");
        echo "migrate-actions: added $table.action_id\n";
        if ($driver !== 'mysql') {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $idxName ON $table(action_id)");
        }
    }
}

// ALTER TABLE machine_events — ajout checkpoint + action_id
if (!Db::columnExists('machine_events', 'checkpoint')) {
    $checkpointType = ($driver === 'mysql') ? 'VARCHAR(64)' : 'TEXT';
    $pdo->exec("ALTER TABLE machine_events ADD COLUMN checkpoint $checkpointType NULL");
    echo "migrate-actions: added machine_events.checkpoint\n";
}
if (!Db::columnExists('machine_events', 'action_id')) {
    $pdo->exec("ALTER TABLE machine_events ADD COLUMN action_id $actionIdType NULL REFERENCES actions(id)");
    echo "migrate-actions: added machine_events.action_id\n";
    if ($driver !== 'mysql') {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_machine_events_action ON machine_events(action_id)");
    }
}

// ---------------------------------------------------------------------------
// 14. installations.install_log (LOGCAP01 — 2026-05-17)
//     Capture log debian-installer côté serveur via batch POST depuis
//     late_command preseed. MEDIUMTEXT MySQL (16 MB) ; TEXT SQLite (illimité,
//     borné applicatif 5 MB cf. Installation::appendInstallLog).
// ---------------------------------------------------------------------------
if (!Db::columnExists('installations', 'install_log')) {
    $blob = ($driver === 'mysql') ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("ALTER TABLE installations ADD COLUMN install_log $blob DEFAULT NULL");
    echo "migrate-installations: added install_log column (LOGCAP01 — capture log d-i)\n";
}

// ---------------------------------------------------------------------------
// 15. presets : fondations hardware-aware (chantier preset-hardware 2026-05-19)
//     - based_on_machine_id : FK soft vers machines(id). SQLite ne supporte pas
//       ALTER TABLE ADD FK → la contrainte ON DELETE SET NULL est gérée côté
//       applicatif (PresetController au moment de la suppression machine).
//     - hostname_pattern : pattern de génération hostname (ex: srv-{mac_last4}),
//       sinon fallback Machine::generateHostname.
//     - pre_wipe_mode_json : structure stable per-disque (le scalar pre_wipe_mode
//       reste lisible legacy, voir bloc 10).
// ---------------------------------------------------------------------------
if (!Db::columnExists('presets', 'based_on_machine_id')) {
    $intType = ($driver === 'mysql') ? 'BIGINT UNSIGNED' : 'INTEGER';
    $pdo->exec("ALTER TABLE presets ADD COLUMN based_on_machine_id $intType DEFAULT NULL");
    echo "migrate-presets: added based_on_machine_id column (chantier hardware-aware)\n";
}
if (!Db::columnExists('presets', 'hostname_pattern')) {
    // Note 2026-05-21 : la colonne s'appelle hostname_pattern pour des raisons
    // historiques (chantier hardware-aware abandonné), mais elle stocke
    // maintenant un simple nom statique (cf. presets/edit.php → "Nom d'hôte").
    // L'expansion {mac_last4} et autres placeholders n'est PAS implémentée.
    $type = ($driver === 'mysql') ? 'VARCHAR(190)' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN hostname_pattern $type DEFAULT NULL");
    echo "migrate-presets: added hostname_pattern column (chantier hardware-aware)\n";
}
// Toggle "Générer un nom aléatoire à l'installation" — si 1, le wizard d'install
// remplace le hostname vide par Machine::generateHostname($mac) (style Docker).
if (!Db::columnExists('presets', 'hostname_random')) {
    $intType = ($driver === 'mysql') ? 'TINYINT(1)' : 'INTEGER';
    $pdo->exec("ALTER TABLE presets ADD COLUMN hostname_random $intType NOT NULL DEFAULT 0");
    echo "migrate-presets: added hostname_random column (toggle nom aléatoire à l'install)\n";
}
if (!Db::columnExists('presets', 'pre_wipe_mode_json')) {
    $blob = ($driver === 'mysql') ? 'MEDIUMTEXT' : 'TEXT';
    $pdo->exec("ALTER TABLE presets ADD COLUMN pre_wipe_mode_json $blob DEFAULT NULL");
    echo "migrate-presets: added pre_wipe_mode_json column (chantier hardware-aware)\n";
}

// ---------------------------------------------------------------------------
// 16. Fix netboot Debian : iso_extract → direct (netboot installer current)
//     (2026-05-31). Les kernel/initrd extraits d'un ISO netinst FIGÉ se
//     désynchronisaient du miroir deb.debian.org dès qu'il roulait vers un
//     nouvel installeur → anna échouait sur « Detect and mount installation
//     media » (udebs incompatibles). On recalcule les URLs via
//     DebianCatalog::urlsFor (netboot installer COURANT, aligné avec les udebs
//     et le miroir des preseed) et on force un vrai re-download.
//
//     PIÈGE : les fichiers iso_extract stale sont au MÊME path v2 que le nouveau
//     download direct, et bootstrap-download-image.sh skip si le fichier existe
//     déjà → status='pending' seul ne re-télécharge rien. On supprime donc
//     physiquement linux+initrd.gz stale (PAS le firmware : même source
//     codename/current, déjà aligné, ~500 Mo économisés).
//
//     Placé en DERNIER pour que status='pending' ne soit pas ré-écrasé par le
//     backfill on-disk plus haut. Les rows CURRENT migrées deviennent 'direct'+
//     kernel_url non-NULL → traitées une seule fois. Les ARCHIVES du modèle
//     hybride (iso_extract, kernel_url NULL = état NORMAL) restent matchées par
//     le WHERE mais le guard `empty($urls['kernel'])` ci-dessous les SKIP (urlsFor
//     d'une archive ne fournit pas de kernel direct) → aucun re-traitement réel.
// ---------------------------------------------------------------------------
$debCat   = new DebianCatalog();
$httpRoot = getenv('HTTP_ROOT') ?: '/srv/http';
$nowStr   = date('Y-m-d H:i:s');
$debRows  = $pdo->query(
    "SELECT id, arch, version_full FROM os_images "
  . "WHERE distro='debian' AND (extract_method='iso_extract' OR kernel_url IS NULL OR kernel_url='')"
)->fetchAll(PDO::FETCH_ASSOC);
$debFixed = 0;
foreach ($debRows as $r) {
    $vf = ($r['version_full'] !== null && $r['version_full'] !== '') ? (string)$r['version_full'] : null;
    if ($vf === null) continue;                 // row v1 codename-only → retraitée au refresh
    $arch = $r['arch'] ?: 'amd64';
    $urls = $debCat->urlsFor($vf, $arch);
    if (empty($urls['kernel'])) continue;       // major absent du MAPPING → skip
    $pdo->prepare(
        "UPDATE os_images SET extract_method='direct', kernel_url=?, initrd_url=?, "
      . "firmware_url=?, iso_url=NULL, status='pending', error_msg=NULL, "
      . "progress_percent=0, updated_at=? WHERE id=?"
    )->execute([$urls['kernel'], $urls['initrd'], $urls['firmware'], $nowStr, (int)$r['id']]);
    // Conserver les fichiers précédents ; le remplacement est une action distincte.
    $debFixed++;
}
if ($debFixed > 0) {
    echo "migrate-images: $debFixed Debian image(s) migrated iso_extract → direct; previous files retained\n";
    // Régénérer catalog_entries avec les nouvelles URLs netboot (idempotent).
    $chk = $pdo->prepare(
        "SELECT COUNT(*) FROM init_jobs WHERE job_type='bootstrap:refresh_catalog_v2' AND status IN ('pending','running')"
    );
    $chk->execute();
    if ((int)$chk->fetchColumn() === 0) {
        $pdo->prepare(
            "INSERT INTO init_jobs (job_type, status, message) VALUES ('bootstrap:refresh_catalog_v2', 'pending', ?)"
        )->execute(['Refresh catalogue post-migration netboot Debian']);
        echo "migrate-images: enqueued bootstrap:refresh_catalog_v2 (régénération catalog_entries)\n";
    }
}

// 2026-06-10 — disktest temps réel : progression structurée par disque (écrite
// par l'agent via /api/disktest-disk-progress) + flag in_session (test enchaîné
// depuis l'écran d'attente discovery, sans cycle reboot PXE).
if (!Db::columnExists('disk_tests', 'progress_json')) {
    $pdo->exec("ALTER TABLE disk_tests ADD COLUMN progress_json TEXT");
    echo "migrate: disk_tests.progress_json added\n";
}
if (!Db::columnExists('disk_tests', 'in_session')) {
    $pdo->exec("ALTER TABLE disk_tests ADD COLUMN in_session INTEGER NOT NULL DEFAULT 0");
    echo "migrate: disk_tests.in_session added\n";
}

// Historique des relevés SMART bruts, lié au test et au numéro de série.
if ($driver === 'mysql') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS disk_smart_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        machine_id BIGINT UNSIGNED NOT NULL,
        test_id BIGINT UNSIGNED NOT NULL,
        serial VARCHAR(128) NOT NULL,
        device VARCHAR(128) NULL,
        phase VARCHAR(8) NOT NULL,
        smart_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT uq_disk_smart_snapshots_test_serial_phase UNIQUE (test_id, serial, phase),
        CONSTRAINT fk_disk_smart_snapshots_machine
            FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
        CONSTRAINT fk_disk_smart_snapshots_test
            FOREIGN KEY (test_id) REFERENCES disk_tests(id) ON DELETE CASCADE,
        INDEX idx_disk_smart_snapshots_serial_created (serial, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS disk_smart_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        machine_id INTEGER NOT NULL,
        test_id INTEGER NOT NULL,
        serial TEXT NOT NULL,
        device TEXT NULL,
        phase TEXT NOT NULL,
        smart_json TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT uq_disk_smart_snapshots_test_serial_phase UNIQUE (test_id, serial, phase),
        CONSTRAINT fk_disk_smart_snapshots_machine
            FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
        CONSTRAINT fk_disk_smart_snapshots_test
            FOREIGN KEY (test_id) REFERENCES disk_tests(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_disk_smart_snapshots_serial_created ON disk_smart_snapshots(serial, created_at)');
}
echo "migrate: disk_smart_snapshots table ensured\n";

// Historique des configurations GRUB servies et des entrées effectivement
// sélectionnées. Création idempotente pour les installations existantes.
if ($driver === 'sqlite') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS boot_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        machine_id INTEGER NOT NULL REFERENCES machines(id) ON DELETE CASCADE,
        action_id INTEGER NULL REFERENCES actions(id) ON DELETE SET NULL,
        mac TEXT NOT NULL,
        default_menu TEXT NOT NULL,
        selected_menu TEXT,
        token TEXT NOT NULL UNIQUE,
        client_ip TEXT,
        served_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        selected_at DATETIME
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_boot_sessions_machine ON boot_sessions(machine_id, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_boot_sessions_action ON boot_sessions(action_id)');
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS boot_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        machine_id BIGINT UNSIGNED NOT NULL,
        action_id BIGINT UNSIGNED NULL,
        mac VARCHAR(17) NOT NULL,
        default_menu VARCHAR(24) NOT NULL,
        selected_menu VARCHAR(24) NULL,
        token CHAR(32) NOT NULL,
        client_ip VARCHAR(45) NULL,
        served_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        selected_at DATETIME NULL,
        UNIQUE KEY idx_boot_sessions_token (token),
        INDEX idx_boot_sessions_machine (machine_id, id DESC),
        INDEX idx_boot_sessions_action (action_id),
        CONSTRAINT fk_boot_sessions_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
        CONSTRAINT fk_boot_sessions_action FOREIGN KEY (action_id) REFERENCES actions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Boot local autorisé seulement après installation PXE réussie ou décision
// explicite de l'administrateur. Rétrocompat : anciens installed/provisioned
// avec une installation success en DB conservent leur démarrage local.
if (!Db::columnExists('machines', 'local_boot_source')) {
    $pdo->exec("ALTER TABLE machines ADD COLUMN local_boot_source VARCHAR(16) NOT NULL DEFAULT 'none'");
    $pdo->exec("UPDATE machines SET local_boot_source = 'pxe'
        WHERE status IN ('installed', 'provisioned')
          AND EXISTS (SELECT 1 FROM installations i
                      WHERE i.machine_id = machines.id AND i.install_status = 'success')");
    echo "migrate-machines: local_boot_source added\n";
}

// Les anciennes données sensibles sont refusées avant toute écriture.

echo "migrate: done (driver=$driver).\n";
