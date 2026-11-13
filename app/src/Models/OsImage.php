<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

/**
 * Spec E — Catalogue d'images OS téléchargeables.
 *
 * Ligne = 1 (distro, version, arch). Le worker télécharge les images
 * dont `enabled=1`. Les templates GRUB/PXE/preseed exposent dans leur menu
 * les images dont `enabled=1 AND status='downloaded'`.
 *
 * Statuts :
 *   - pending     : non téléchargée, en attente de prise en charge worker
 *   - downloading : worker en cours de download
 *   - downloaded  : OK, fichiers présents sur disque
 *   - failed      : worker a échoué (voir error_msg + retry via UI)
 */
final class OsImage
{
    public const HTTP_ROOT = '/srv/http';

    public const STATUS_PENDING     = 'pending';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_DOWNLOADED  = 'downloaded';
    public const STATUS_FAILED      = 'failed';

    /** Au-delà de ce seuil sans updated_at, un image en 'downloading' est
     * considéré stale (worker probablement crashé). L'UI propose alors un retry. */
    public const STALE_THRESHOLD_S = 30;

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::get()
            ->query("SELECT * FROM os_images ORDER BY distro, version, arch")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Comme all() mais enrichit chaque row avec :
     *   - age_s : ancienneté du dernier heartbeat (NULL si jamais touché)
     *   - stale : bool — true si status=downloading AND age_s > STALE_THRESHOLD_S
     * Utilisé par l'UI /settings/images pour afficher live progress + warning stale.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allWithProgress(): array
    {
        // age_s = unix UTC now - unix UTC du dernier heartbeat. SQLite et
        // MySQL/MariaDB diffèrent (strftime vs UNIX_TIMESTAMP) → helpers Db::*.
        $now = Db::nowEpoch();
        $col = Db::unixEpoch('COALESCE(updated_at, downloaded_at)');
        // Pas de CAST : isStale() fait `(int)$row['age_s']` côté PHP — robuste
        // peu importe le type natif (SQLite INTEGER vs MySQL BIGINT).
        $rows = Db::get()->query(
            "SELECT *, ($now - $col) AS age_s "
            . "FROM os_images ORDER BY distro, version, arch"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['stale'] = self::isStale($r);
        }
        unset($r);
        return $rows;
    }

    /** True si la row est en cours de téléchargement depuis plus de STALE_THRESHOLD_S
     * sans heartbeat. Ne dépend que de updated_at + status. */
    public static function isStale(array $row): bool
    {
        if (($row['status'] ?? '') !== self::STATUS_DOWNLOADING) return false;
        $age = $row['age_s'] ?? null;
        return $age !== null && (int)$age > self::STALE_THRESHOLD_S;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::get()->prepare("SELECT * FROM os_images WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Images activées ET téléchargées (consommé par les templates GRUB/preseed).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function enabledDownloaded(): array
    {
        return Db::get()
            ->query("SELECT * FROM os_images WHERE enabled = 1 AND status = 'downloaded' ORDER BY distro, version")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liste des versions enabled+downloaded pour une distro donnée.
     * Consommé par render_template.py via lecture directe DB.
     *
     * @return array<int,string>
     */
    public static function downloadedVersions(string $distro = 'debian'): array
    {
        $stmt = Db::get()->prepare(
            "SELECT version FROM os_images
             WHERE distro = ? AND enabled = 1 AND status = 'downloaded'
             ORDER BY version"
        );
        $stmt->execute([$distro]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Répertoire local de stockage. Spec D : layout /netboot/<version>/<arch>/
     * pour permettre amd64 + arm64 cohabitant. Les fichiers existants amd64
     * (layout legacy /netboot/<version>/) sont migrés au démarrage par
     * migrate.php section "Spec D layout migration".
     */
    public static function localDir(array $image): string
    {
        $root = rtrim((string)(getenv('PXE_HTTP_ROOT') ?: getenv('HTTP_ROOT') ?: self::HTTP_ROOT), '/');
        $version = (string)($image['version_full'] ?? '');
        $parts = $version !== '' ? [$image['distro'], $version, $image['arch']] : [$image['version'], $image['arch']];
        foreach ($parts as $part) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', (string)$part) || str_contains((string)$part, '..')) {
                throw new \RuntimeException('Chemin d’image invalide.');
            }
        }
        return $root . '/netboot/' . implode('/', $parts);
    }

    /**
     * Dernière version_full téléchargée pour un distro (codename) donné.
     * Tri SQL DESC : utilise l'ordre lexicographique de la chaîne, qui marche
     * pour les versions SemVer-ish "13.4.0" > "13.2.0" > "12.7.0". Pour des
     * schémas non-lexico-friendly (ex "9.10" < "9.9"), il faudra un tri serveur.
     *
     * @return ?string version_full ou null si aucune image downloaded.
     */
    public static function latestForDistro(string $distro): ?string
    {
        $stmt = Db::get()->prepare(
            "SELECT version_full FROM os_images "
          . "WHERE distro = ? AND enabled = 1 AND status = 'downloaded' "
          . "AND version_full IS NOT NULL AND version_full != '' "
          . "ORDER BY version_full DESC LIMIT 1"
        );
        $stmt->execute([$distro]);
        $v = $stmt->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Résout la version_full effective pour un couple (distro, version_full).
     *   - $versionFull non vide → renvoyé tel quel (l'appelant garantit la validité
     *     via le catalog ou déclenche un download si non encore présent).
     *   - $versionFull vide/null → fallback latest downloaded du distro.
     *
     * Retourne null si rien n'est résolu (aucune image, aucune version).
     * L'appelant décide quoi faire d'un null (erreur 404 PXE, message UI, etc).
     */
    public static function resolveVersionFull(string $distro, ?string $versionFull): ?string
    {
        if (is_string($versionFull) && trim($versionFull) !== '') {
            return trim($versionFull);
        }
        return self::latestForDistro($distro);
    }

    /**
     * Assure qu'une entry os_images existe pour (distro, version_full, arch).
     *   - Existante avec status='downloaded' → no-op.
     *   - Existante avec status pending/downloading/failed → no-op (déjà en queue).
     *   - Inexistante mais présente dans catalog_entries → INSERT status='pending'
     *     (le worker la prendra au prochain poll).
     *   - Inexistante ET absente du catalog → retourne false (l'appelant peut alerter).
     *
     * Idempotent : appel safe depuis PresetController::create/update +
     * MachineController::install (auto-download au choix d'une version non dispo).
     * Réutilise le même pattern SELECT puis INSERT que ImagesController::addSubmit
     * (le UNIQUE INDEX sur version_full est partial → pas d'ON CONFLICT possible).
     *
     * @return bool true si l'entry existe maintenant (ou existait déjà) ; false si
     *              le catalog ne connait pas (distro, version_full, arch).
     */
    public static function ensureForDistroVersion(
        string $distro,
        string $versionFull,
        string $arch = 'amd64'
    ): bool {
        if ($distro === '' || $versionFull === '') return false;
        $pdo = Db::get();

        $stmt = $pdo->prepare(
            "SELECT id, status FROM os_images "
          . "WHERE distro = ? AND version_full = ? AND arch = ? LIMIT 1"
        );
        $stmt->execute([$distro, $versionFull, $arch]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            // Entry existe (peu importe le status). Si failed/pending, le worker
            // la reprendra. Si downloaded, c'est nominal.
            return true;
        }

        $entry = CatalogEntry::find($distro, $versionFull, $arch);
        if ($entry === null) return false;

        $ins = $pdo->prepare(
            "INSERT INTO os_images "
          . "(distro, version, arch, kernel_url, initrd_url, firmware_url, "
          . " iso_url, extract_method, enabled, status, "
          . " codename, major, version_full, channel) "
          . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending', ?, ?, ?, ?) "
          // A different request can insert the same catalogue tuple after the
          // lookup. Treat only that exact unique conflict as already present;
          // unrelated constraint errors must still reach the caller.
          . "ON CONFLICT(distro, version_full, arch) WHERE version_full IS NOT NULL DO NOTHING"
        );
        $ins->execute([
            $distro,
            $versionFull, // `version` legacy = version_full (cf. ImagesController commentaire)
            $arch,
            $entry['kernel_url']   ?? null,
            $entry['initrd_url']   ?? null,
            $entry['firmware_url'] ?? null,
            $entry['iso_url']      ?? null,
            $entry['extract_method'] ?? 'direct',
            $entry['codename'] ?? null,
            $entry['major']    ?? null,
            $versionFull,
            $entry['channel']  ?? null,
        ]);
        return true;
    }

    /** Change availability and any required download state as one guarded mutation. */
    public static function setEnabled(int $id, bool $on): void
    {
        self::withImageLock($id, static function () use ($id, $on): void {
            $image = self::findById($id);
            $reason = self::activationBlock($image, self::dependencies($id), $on);
            if ($reason !== null) throw new \RuntimeException($reason);
            if ($on && in_array($image['status'], [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                Db::get()->prepare("UPDATE os_images SET enabled = 1, status = 'pending', error_msg = NULL WHERE id = ?")->execute([$id]);
            } else {
                Db::get()->prepare("UPDATE os_images SET enabled = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
            }
        });
    }

    /** Shared reasons keep the rendered actions aligned with server revalidation. */
    public static function activationBlock(array $image, array $dependencies, bool $on): ?string
    {
        if (self::deletePending((int)$image['id'])) return 'Suppression de cette image en cours.';
        if (!$on && $dependencies['blocking'] !== []) return implode(' ', $dependencies['blocking']);
        if ($on && in_array($image['status'], [self::STATUS_PENDING, self::STATUS_FAILED], true) && !empty($dependencies['active'])) {
            return 'Image utilisée par une opération active : son activation relancerait le téléchargement.';
        }
        return null;
    }

    public static function downloadBlock(array $image, array $dependencies): ?string
    {
        if (self::deletePending((int)$image['id'])) return 'Suppression de cette image en cours.';
        if (!empty($dependencies['active'])) return 'Image utilisée par une opération active : remplacement refusé.';
        if ($image['status'] === self::STATUS_DOWNLOADED && $dependencies['available'] && $dependencies['blocking'] !== []) {
            return implode(' ', $dependencies['blocking']);
        }
        return null;
    }

    public static function markStatus(int $id, string $status, ?string $error = null): void
    {
        Db::get()
            ->prepare("UPDATE os_images SET status = ?, error_msg = ? WHERE id = ?")
            ->execute([$status, $error, $id]);
    }

    public static function markDownloaded(int $id, int $sizeBytes): void
    {
        Db::get()
            ->prepare("UPDATE os_images SET status = 'downloaded', downloaded_at = CURRENT_TIMESTAMP, size_bytes = ?, error_msg = NULL WHERE id = ?")
            ->execute([$sizeBytes, $id]);
    }

    /**
     * Reset au statut pending — utilisé par le bouton "Re-télécharger" et par
     * la création d'une nouvelle image enabled=0→1 dont les fichiers manquent.
     */
    public static function resetToPending(int $id): void
    {
        self::withImageLock($id, static function () use ($id): void {
            $reason = self::downloadBlock(self::findById($id), self::dependencies($id));
            if ($reason !== null) throw new \RuntimeException($reason);
            Db::get()->prepare("UPDATE os_images SET status = 'pending', enabled = 1, error_msg = NULL WHERE id = ?")->execute([$id]);
        });
    }

    /**
     * v2 : liste plate des images téléchargées (ou en cours de DL), 1 row par
     * (distro, version_full, arch). Triée distro puis major DESC puis
     * version_full DESC puis arch. Inclut age_s + stale (cf allWithProgress).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function allFlat(): array
    {
        $now = Db::nowEpoch();
        $col = Db::unixEpoch('COALESCE(updated_at, downloaded_at)');
        $rows = Db::get()->query(
            "SELECT *, ($now - $col) AS age_s "
          . "FROM os_images "
          . "ORDER BY distro, "
          . "COALESCE(major, 0) DESC, "
          . "COALESCE(version_full, version) DESC, "
          . "arch"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['stale'] = self::isStale($r);
            $r['dependencies'] = self::dependencies((int)$r['id']);
        }
        unset($r);
        return $rows;
    }

    // NOTE: groupedByVersion() + latestStableMajor() retirées en v2 — remplacées
    // par allFlat() (table flat) et CatalogEntry::groupedForPicker() (picker
    // structured par distro/major). Mapping Debian désormais dans
    // Admin\Catalog\DebianCatalog::MAPPING.

    private static function withImageLock(int $id, callable $mutation): void
    {
        $scope = self::findById($id);
        if ($scope === null) throw new \RuntimeException('Image introuvable.');
        $pdo = Db::get();
        $pdo->beginTransaction();
        try {
            self::lockImageGroup($pdo, $scope);
            if (self::findById($id) === null) throw new \RuntimeException('Image supprimée depuis la dernière lecture.');
            $mutation();
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** A fixed numeric image ID is the only operation the worker accepts. */
    public static function deletePending(int $id): bool
    {
        $stmt = Db::get()->prepare("SELECT 1 FROM init_jobs WHERE job_type=? AND status IN ('pending','running') LIMIT 1");
        $stmt->execute(['image:delete:' . $id]);
        return $stmt->fetchColumn() !== false;
    }

    /** Preserve the original unprivileged path for writable legacy volumes. */
    public static function webCanDeleteDirect(array $image): bool
    {
        // Never use the legacy direct path when PHP itself is privileged or
        // its effective identity cannot be established.
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) return false;
        $directory = self::localDir($image);
        while (!file_exists($directory) && !is_link($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) return false;
            $directory = $parent;
        }
        return !is_link($directory) && is_dir($directory) && is_writable($directory);
    }

    public static function queueDelete(int $id): int
    {
        $job = 0;
        self::withImageLock($id, static function () use ($id, &$job): void {
            $image = self::findById($id);
            if (self::deletePending($id)) throw new \RuntimeException('Suppression déjà en cours.');
            $dependencies = self::dependencies($id);
            if ($dependencies['blocking'] !== []) throw new \RuntimeException(implode(' ', $dependencies['blocking']));
            self::localDir($image); // Reject malformed components before queuing.
            $meta = json_encode(['id' => $id, 'distro' => $image['distro'], 'version' => $image['version'],
                'version_full' => $image['version_full'], 'arch' => $image['arch']], JSON_THROW_ON_ERROR);
            $stmt = Db::get()->prepare("INSERT INTO init_jobs(job_type,status,message,log) VALUES(?,'pending',?,?)");
            $stmt->execute(['image:delete:' . $id, 'Suppression de l’image en attente', $meta]);
            $job = (int)Db::get()->lastInsertId();
        });
        return $job;
    }

    /** Lock replacement candidates together, before the transaction's first snapshot read. */
    private static function lockImageGroup(PDO $pdo, array $image): void
    {
        if (Db::driver() === 'mysql') {
            $lock = $pdo->prepare('SELECT id FROM os_images WHERE distro = ? AND arch = ? ORDER BY id FOR UPDATE');
            $lock->execute([$image['distro'], $image['arch']]);
            $lock->fetchAll();
        } else {
            // SQLite serializes writers for the database, including different image rows.
            $pdo->prepare('UPDATE os_images SET id = id WHERE distro = ? AND arch = ?')->execute([$image['distro'], $image['arch']]);
        }
    }

    /** Files observed by this process, distinct from the worker's recorded status. */
    public static function filesAvailable(array $image): bool
    {
        $dir = self::localDir($image);
        return is_readable($dir . '/linux') && is_readable($dir . '/initrd.gz')
            && filesize($dir . '/linux') > 0 && filesize($dir . '/initrd.gz') > 0;
    }

    /** Known uses are advisory for saved configurations, blocking for active operations. */
    public static function dependencies(int $id): array
    {
        $image = self::findById($id);
        if ($image === null) return ['usages' => [], 'blocking' => [], 'available' => false];
        $uses = [];
        $blocks = [];
        $activeUse = false;
        $pdo = Db::get();
        if ($image['status'] === self::STATUS_DOWNLOADING) $blocks[] = 'Téléchargement en cours.';
        if ($image['status'] === self::STATUS_PENDING && (int)$image['enabled'] === 1) $blocks[] = 'Téléchargement déjà demandé au worker.';
        $base = $image['distro'] === 'debian' && ($image['extract_method'] ?? 'direct') === 'direct';
        if ($base) {
            $uses[] = ['label' => 'Découverte et secours Debian · ' . $image['arch'], 'url' => null];
            $replacement = false;
            foreach (self::enabledDownloaded() as $candidate) {
                if ((int)$candidate['id'] !== $id && $candidate['distro'] === 'debian'
                    && ($candidate['extract_method'] ?? 'direct') === 'direct' && $candidate['arch'] === $image['arch']
                    && self::filesAvailable($candidate)) $replacement = true;
            }
            $usable = (int)$image['enabled'] === 1 && $image['status'] === self::STATUS_DOWNLOADED && self::filesAvailable($image);
            if ($usable && !$replacement) $blocks[] = 'Dernière image Debian de découverte/secours pour cette architecture. Téléchargez et activez un remplacement avant de la retirer.';
        }
        $matches = static function (array $config, ?string $arch = null) use ($image): bool {
            if ($arch !== null && $arch !== '' && $arch !== $image['arch']) return false;
            $distro = (string)($config['distro'] ?? '');
            if ($distro === '') return false;
            if ($distro !== $image['distro'] && $distro !== ($image['codename'] ?? '') && $distro !== $image['version']) return false;
            $version = (string)($config['version_full'] ?? '');
            // An unpinned version resolves at boot; show every candidate as a known use.
            return $version === '' || $version === (string)($image['version_full'] ?? '') || $version === $image['version'];
        };
        foreach ($pdo->query('SELECT id, name, distro, version_full FROM presets')->fetchAll(PDO::FETCH_ASSOC) as $preset) {
            if ($matches($preset)) $uses[] = ['label' => 'Modèle : ' . $preset['name'], 'url' => '/presets/' . $preset['id']];
        }
        $activeIds = array_fill_keys($pdo->query("SELECT machine_id FROM actions WHERE status IN ('pending','running') UNION SELECT machine_id FROM installations WHERE install_status IN ('pending','running','installing','in_progress') OR ansible_status IN ('pending','running','in_progress')")->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($pdo->query('SELECT id, hostname, mac, status, boot_arch, rescue_mode, install_config_json FROM machines')->fetchAll(PDO::FETCH_ASSOC) as $machine) {
            $config = json_decode($machine['install_config_json'] ?? '{}', true) ?: [];
            $usesBase = $base && ($machine['boot_arch'] ?: 'amd64') === $image['arch']
                && ((int)$machine['rescue_mode'] === 1 || in_array($machine['status'], ['inventorying', 'wiping', 'testing'], true));
            if (!$matches($config, $machine['boot_arch']) && !$usesBase) continue;
            $label = $machine['hostname'] ?: $machine['mac'];
            $uses[] = ['label' => 'Machine : ' . $label, 'url' => '/machines/' . $machine['id']];
            if ($usesBase || in_array($machine['status'], ['installing','inventorying','wiping','testing'], true) || isset($activeIds[$machine['id']])) {
                $activeUse = true;
                $blocks[] = 'Opération active ou prévue sur ' . $label . '.';
            }
        }
        foreach ($pdo->query("SELECT w.chain_install_config_json, m.id, m.hostname, m.mac, m.boot_arch FROM wipes w JOIN machines m ON m.id = w.machine_id WHERE w.wipe_status IN ('pending','running') AND w.chain_install_config_json IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $chain) {
            $config = json_decode($chain['chain_install_config_json'], true) ?: [];
            $config = is_array($config['install_config'] ?? null) ? $config['install_config'] : $config;
            if (!$matches($config, $chain['boot_arch'])) continue;
            $label = $chain['hostname'] ?: $chain['mac'];
            $uses[] = ['label' => 'Installation après effacement : ' . $label, 'url' => '/machines/' . $chain['id']];
            $activeUse = true;
            $blocks[] = 'Installation prévue après effacement sur ' . $label . '.';
        }
        return ['usages' => $uses, 'blocking' => array_values(array_unique($blocks)), 'available' => self::filesAvailable($image), 'active' => $activeUse];
    }

    /** Revalidate inside the transaction and restore files if SQL deletion fails. */
    public static function deleteWithFiles(int $id, ?int $jobId = null): void
    {
        $scope = self::findById($id);
        if ($scope === null) return;
        $pdo = Db::get();
        $moved = [];
        $pdo->beginTransaction();
        try {
            self::lockImageGroup($pdo, $scope);
            $img = self::findById($id);
            if ($img === null) { $pdo->rollBack(); return; }
            if ($jobId === null && self::deletePending($id)) {
                throw new \RuntimeException('Suppression déjà en cours.');
            }
            if ($jobId !== null) {
                $job = $pdo->prepare("SELECT log FROM init_jobs WHERE id=? AND job_type=? AND status='running'");
                $job->execute([$jobId, 'image:delete:' . $id]);
                $raw = $job->fetchColumn();
                $meta = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
                $expected = ['id' => $id, 'distro' => $img['distro'], 'version' => $img['version'],
                    'version_full' => $img['version_full'], 'arch' => $img['arch']];
                if ($meta !== $expected) throw new \RuntimeException('Tâche de suppression modifiée ; image conservée.');
            }
            $dir = self::localDir($img);
            if ($jobId !== null) {
                if ($jobId <= 0) throw new \RuntimeException('Identifiant de tâche invalide.');
                // A crash before the SQL commit can leave staged files. Put
                // them back before rechecking dependencies or trying again.
                foreach (['linux', 'initrd.gz', 'firmware.cpio.gz'] as $file) {
                    $path = $dir . '/' . $file;
                    $backup = $path . '.pxe-delete-' . $jobId;
                    if (is_link($path) || is_link($backup)) throw new \RuntimeException('Lien inattendu ; fichiers conservés.');
                    if (file_exists($backup)) {
                        if (file_exists($path) || !is_file($backup) || !@rename($backup, $path)) {
                            throw new \RuntimeException('Reprise ambiguë ; fichiers conservés pour vérification.');
                        }
                    }
                }
                self::syncImageDir($dir);
            }
            $dependencies = self::dependencies($id);
            if ($dependencies['blocking'] !== []) throw new \RuntimeException(implode(' ', $dependencies['blocking']));
            foreach (['linux', 'initrd.gz', 'firmware.cpio.gz'] as $file) {
                $path = $dir . '/' . $file;
                if (is_link($path)) throw new \RuntimeException('Lien inattendu ; fichier conservé.');
                if (!is_file($path)) continue;
                $backup = $path . ($jobId === null ? '.delete-' . bin2hex(random_bytes(8)) : '.pxe-delete-' . $jobId);
                if (file_exists($backup) || is_link($backup)) throw new \RuntimeException('Destination de quarantaine occupée.');
                if (!@rename($path, $backup)) throw new \RuntimeException('Impossible de retirer les fichiers de cette image.');
                $moved[$path] = $backup;
            }
            if ($jobId !== null) self::syncImageDir($dir);
            $pdo->prepare('DELETE FROM os_images WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $unrestored = [];
            foreach ($moved as $path => $backup) if (!@rename($backup, $path)) $unrestored[] = $path;
            if ($jobId !== null && $moved !== []) self::syncImageDir($dir);
            if ($unrestored !== []) throw new \RuntimeException('Suppression refusée ; restauration manuelle nécessaire : ' . implode(', ', $unrestored), 0, $error);
            throw $error;
        }
        foreach ($moved as $backup) {
            if (!@unlink($backup)) throw new \RuntimeException('Image retirée du catalogue, mais un fichier .delete doit être nettoyé : ' . $backup);
        }
        if ($jobId !== null) self::syncImageDir($dir);
    }

    private static function syncImageDir(string $directory): void
    {
        if (!is_dir($directory)) return;
        $stream = @fopen($directory, 'r');
        if ($stream === false) throw new \RuntimeException('Répertoire de l’image inaccessible ; fichiers conservés.');
        try {
            if (!fsync($stream)) throw new \RuntimeException('Synchronisation du répertoire impossible ; fichiers conservés.');
        } finally { fclose($stream); }
    }
}
