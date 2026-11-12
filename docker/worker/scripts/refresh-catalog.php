<?php
declare(strict_types=1);

/**
 * refresh-catalog.php — itère sur DistroCatalog::all(), peuple catalog_entries.
 *
 * Appelé par `bootstrap-refresh-catalog-v2.sh` (lui-même dispatché par
 * worker.py sur init_job `bootstrap:refresh_catalog_v2`).
 *
 * Publish progress via side-channel SQLite (init_jobs.message + progress_percent
 * + updated_at) si WORKER_JOB_ID est défini dans l'env.
 *
 * Idempotent : UPSERT sur (distro, version_full, arch) — le re-lancement
 * écrase les URLs avec la dernière vue upstream (utile si un mirror change).
 */

// Autoload PSR-4 minimal pour Admin\ (identique à app/public/index.php).
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Admin\\', 6) !== 0) return;
    $rel = str_replace('\\', '/', substr($class, 6));
    $file = __DIR__ . '/../../../app/src/' . $rel . '.php';
    // Fallback : chemin runtime container (/app/src/...)
    if (!is_file($file)) {
        $file = rtrim(getenv('PXE_APP_DIR') ?: '/app', '/') . '/src/' . $rel . '.php';
    }
    if (is_file($file)) require_once $file;
});

use Admin\Db;
use Admin\Catalog\DistroCatalog;

$jobId = getenv('WORKER_JOB_ID') ?: '';

/** Publish progress dans init_jobs (no-op si pas de WORKER_JOB_ID). */
function publish(string $message, int $percent, string $jobId): void {
    if ($jobId === '') return;
    try {
        Db::get()->prepare(
            "UPDATE init_jobs SET message = ?, progress_percent = ?, "
          . "updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?"
        )->execute([$message, $percent, $jobId]);
    } catch (\Throwable) {
        // best-effort, jamais bloquer le worker pour un fail de publish
    }
}

/**
 * Récupère le SHA256 d'un fichier listé dans un SHA256SUMS distant.
 * Retourne le hash hex minuscule, ou null si injoignable / introuvable.
 */
function fetch_sha256_for(string $shaUrl, string $relPath): ?string {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($shaUrl, false, $ctx);
    if ($body === false || $body === '') return null;
    foreach (preg_split('/\r?\n/', $body) as $line) {
        // Format : "<sha256>  ./netboot/debian-installer/<arch>/initrd.gz"
        if (str_contains($line, $relPath)) {
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[0]) && preg_match('/^[0-9a-f]{64}$/i', $parts[0])) {
                return strtolower($parts[0]);
            }
        }
    }
    return null;
}

$pdo = Db::get();
$distros = DistroCatalog::all();
$total = max(1, count($distros));
$index = 0;
$totalInserted = 0;
$totalSkipped = 0;

publish('Démarrage du refresh catalogue…', 1, $jobId);
echo "[refresh-catalog] ▶ démarrage, " . count($distros) . " distros\n";

foreach ($distros as $code => $cat) {
    $index++;
    $catLabel = $cat->label();
    $base = 1 + (int)(($index - 1) * 99 / $total);
    publish("Discovery {$catLabel}…", $base, $jobId);
    echo "[refresh-catalog]   $code ($catLabel) …\n";

    try {
        $versions = $cat->discoverVersions();
    } catch (\Throwable $e) {
        echo "[refresh-catalog]     ⚠ discovery échouée : " . $e->getMessage() . "\n";
        $versions = [];
    }

    if (empty($versions)) {
        echo "[refresh-catalog]     (aucune version retournée — distro stub ou mirror inaccessible)\n";
        $totalSkipped++;
        continue;
    }

    // Purge les anciennes entries de cette distro avant ré-insertion. Permet
    // de propager les retraits de mapping (ex Ubuntu 20.04, Debian bullseye)
    // sans intervention manuelle.
    $pdo->prepare("DELETE FROM catalog_entries WHERE distro = ?")->execute([$code]);

    $inserted = 0;
    foreach ($versions as $v) {
        $major   = $v['major'] ?? null;
        $vf      = $v['version_full'] ?? null;
        $codename = $v['codename'] ?? null;
        $channel = $v['channel'] ?? null;
        $archs   = $v['archs'] ?? [];
        if ($vf === null || $vf === '' || empty($archs)) continue;

        foreach ($archs as $arch) {
            $urls = $cat->urlsFor($vf, $arch);
            $sql = Db::upsert(
                'catalog_entries',
                ['distro', 'version_full', 'arch'],
                ['distro', 'version_full', 'arch', 'codename', 'major', 'channel',
                 'kernel_url', 'initrd_url', 'firmware_url', 'iso_url', 'extract_method']
            );
            $pdo->prepare($sql)->execute([
                $code, $vf, $arch, $codename, $major, $channel,
                $urls['kernel'] ?? null,
                $urls['initrd'] ?? null,
                $urls['firmware'] ?? null,
                $urls['iso'] ?? null,
                // extract_method PAR-ROW (hybride Debian) : la version courante
                // est 'direct', les archives 'iso_extract'. Fallback sur le défaut
                // distro pour les catalogues qui ne le portent pas par-version.
                $v['extract_method'] ?? $cat->extractMethod(),
            ]);
            $inserted++;
        }
    }
    $totalInserted += $inserted;
    echo "[refresh-catalog]     $inserted entry(ies) upserted\n";
}

// ---------------------------------------------------------------------------
// Détection de skew + auto-réparation (netboot Debian).
// Le netboot installer « current » se réaligne au download, mais le miroir
// reroule (~tous les 2 mois → nouvel installeur deb13uX). Une image téléchargée
// il y a longtemps peut redevenir désynchronisée → anna échoue sur « Detect and
// mount installation media ». On compare le SHA256 de l'initrd local au
// SHA256SUMS upstream ; si différent → purge des fichiers stale + status='pending'
// (le worker re-télécharge au prochain poll).
//
// Timing/race : ce refresh tourne au bootstrap et au refresh manuel (pas en
// continu), donc le risque de purger un initrd pendant un boot d-i actif est
// faible. Best-effort : upstream injoignable → skip silencieux.
$httpRoot = getenv('HTTP_ROOT') ?: '/srv/http';
try {
    $debImgs = $pdo->query(
        "SELECT id, version_full, arch, initrd_url FROM os_images "
      . "WHERE distro='debian' AND status='downloaded' AND extract_method='direct' "
      . "AND initrd_url LIKE '%/netboot/debian-installer/%'"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {
    $debImgs = [];
}
$skewFixed = 0;
foreach ($debImgs as $img) {
    $vf        = (string)($img['version_full'] ?? '');
    $arch      = (string)($img['arch'] ?? 'amd64');
    $initrdUrl = (string)($img['initrd_url'] ?? '');
    if ($vf === '' || $initrdUrl === '') continue;

    $localInitrd = "$httpRoot/netboot/debian/$vf/$arch/initrd.gz";
    if (!is_file($localInitrd)) continue;   // pas téléchargé localement → rien à comparer

    // SHA256SUMS au dossier .../current/images/ (au-dessus de netboot/debian-installer/).
    $marker = '/netboot/debian-installer/';
    $pos = strpos($initrdUrl, $marker);
    if ($pos === false) continue;
    $shaUrl = substr($initrdUrl, 0, $pos) . '/SHA256SUMS';
    $rel    = './netboot/debian-installer/' . $arch . '/initrd.gz';

    $want = fetch_sha256_for($shaUrl, $rel);
    if ($want === null) continue;           // upstream injoignable / introuvable

    $have = @hash_file('sha256', $localInitrd);
    if ($have === false || $have === $want) continue;   // aligné → rien à faire

    // Skew confirmé : purge stale (linux+initrd) + re-download.
    @unlink("$httpRoot/netboot/debian/$vf/$arch/linux");
    @unlink($localInitrd);
    $pdo->prepare(
        "UPDATE os_images SET status='pending', error_msg=NULL, progress_percent=0, "
      . "updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?"
    )->execute([(int)$img['id']]);
    $skewFixed++;
    echo "[refresh-catalog]   ⟳ skew Debian $vf/$arch — netboot installer upstream a changé, re-download forcé\n";
}
if ($skewFixed > 0) {
    echo "[refresh-catalog] $skewFixed image(s) Debian re-planifiée(s) (skew netboot installer)\n";
}

publish("Catalogue à jour ($totalInserted entrée(s) découverte(s))", 100, $jobId);
echo "[refresh-catalog] ✓ done — total $totalInserted upserted, $totalSkipped distro(s) skipped\n";
exit(0);
