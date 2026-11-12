<?php
declare(strict_types=1);

namespace Admin\Models;

use Admin\Db;
use PDO;

/**
 * `catalog_entries` — cache des versions découvertes par
 * `bootstrap:refresh_catalog_v2`. Lecture seule depuis les controllers ; les
 * mutations passent par le script worker (refresh-catalog.php).
 *
 * Distinct de `os_images` : catalog_entries = ce qui est disponible upstream,
 * os_images = ce qui a été commandé par l'admin et est en DL/présent localement.
 */
final class CatalogEntry
{
    /**
     * Vue par distro pour le picker : [distro => [major => [version_full => [archs]]]]
     * (codename + channel inclus dans la 3e couche).
     *
     * @return array<string, array<int, array<string, array{
     *   codename:?string, channel:?string, archs:array<string, array<string,mixed>>
     * }>>>
     */
    public static function groupedForPicker(): array
    {
        // Tri SQL volontairement minimal (distro + major DESC) ; le tri des
        // version_full se fait en PHP avec version_compare pour gérer
        // correctement "12.13.0" > "12.9.0" (le SQL ferait du lexico → faux).
        try {
            $rows = Db::get()
                ->query("SELECT * FROM catalog_entries ORDER BY distro, major DESC, arch")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $distro = (string)$r['distro'];
            $major  = $r['major'] !== null ? (int)$r['major'] : 0;
            $vf     = (string)$r['version_full'];
            if (!isset($out[$distro][$major][$vf])) {
                $out[$distro][$major][$vf] = [
                    'codename' => $r['codename'] ?: null,
                    'channel'  => $r['channel'] ?: null,
                    'archs'    => [],
                ];
            }
            $out[$distro][$major][$vf]['archs'][(string)$r['arch']] = $r;
        }

        // Tri des version_full DESC à l'intérieur de chaque major (sémantique).
        foreach ($out as &$majors) {
            foreach ($majors as &$versions) {
                uksort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
            }
        }
        unset($majors, $versions);

        return $out;
    }

    /**
     * Mapping codename → [version_full, ...] (sémantique DESC), agrégé toutes archs.
     * Pour les distros sans codename (Rocky, Alma, Fedora, CentOS), on indexe par
     * version_full (= "9", "41") qui sert aussi de "codename" côté preset.distro.
     *
     * Consommé par le JS de cascade des views presets/edit + install wizard pour
     * peupler le select Version selon le codename/distro choisi.
     *
     * @return array<string, array<int,string>>
     */
    public static function versionsByCodename(): array
    {
        try {
            $rows = Db::get()
                ->query("SELECT DISTINCT codename, version_full FROM catalog_entries ORDER BY codename, version_full")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $vf = (string)$r['version_full'];
            $key = $r['codename'] !== null && $r['codename'] !== ''
                ? (string)$r['codename']
                : $vf; // fallback: version_full == codename pour distros sans codename
            $out[$key][$vf] = true; // dédup par version_full
        }

        foreach ($out as &$versions) {
            $versions = array_keys($versions);
            usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        }
        unset($versions);

        return $out;
    }

    /**
     * Mapping clé-de-select → label OS lisible pour le <select> « Système
     * d'exploitation » (qui stocke un codename, ou un version_full pour les
     * distros sans codename — cf. versionsByCodename) :
     *   'trixie' => 'Debian — Trixie (13)', 'bookworm' => 'Debian — Bookworm (12)',
     *   '41'     => 'Fedora — 41'
     * Évite d'afficher le codename/version brut comme nom d'OS.
     *
     * @return array<string,string>
     */
    public static function codenameLabels(): array
    {
        try {
            $rows = Db::get()
                ->query("SELECT DISTINCT distro, codename, major, version_full FROM catalog_entries")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $distroLabel = ucfirst((string)$r['distro']);
            $codename = (string)($r['codename'] ?? '');
            if ($codename !== '') {
                $label = $distroLabel . ' — ' . ucfirst($codename);
                if ($r['major'] !== null && $r['major'] !== '') {
                    $label .= ' (' . (int)$r['major'] . ')';
                }
                $out[$codename] = $label;
            } else {
                $vf = (string)$r['version_full'];
                if ($vf !== '') $out[$vf] = $distroLabel . ' — ' . $vf;
            }
        }
        return $out;
    }

    /**
     * Cascade OS → Codename → Version pour les selects wizard/preset (modèle
     * distro-name) : [distro => {label, codenames:[{codename,label,major,versions:[vf]}]}].
     * Réutilise groupedForPicker() (major DESC, version_full DESC déjà triés).
     * Distros sans codename (Fedora/Rocky…) : clé codename = le major (string).
     *
     * @return array<string, array{label:string, codenames:array<int,array{codename:string,label:string,major:int,versions:array<int,string>}>}>
     */
    public static function osCascade(): array
    {
        $grouped = self::groupedForPicker();
        $out = [];
        foreach ($grouped as $distro => $majors) {
            $codenames = [];
            foreach ($majors as $major => $versions) {
                $cn = null;
                $vfs = [];
                foreach ($versions as $vf => $meta) {
                    if ($cn === null) $cn = $meta['codename'] ?? null;
                    $vfs[] = (string)$vf;
                }
                $hasCn = $cn !== null && $cn !== '';
                $codenames[] = [
                    'codename' => $hasCn ? (string)$cn : (string)$major,
                    'label'    => $hasCn ? ucfirst((string)$cn) . ' (' . (int)$major . ')' : (string)$major,
                    'major'    => (int)$major,
                    'versions' => $vfs,
                ];
            }
            $out[(string)$distro] = [
                'label'     => ucfirst((string)$distro),
                'codenames' => $codenames,
            ];
        }
        return $out;
    }

    /** Noms d'OS distincts du catalogue (select OS + validation). Fallback [] (catalogue vide). */
    public static function distinctDistros(): array
    {
        try {
            return Db::get()->query("SELECT DISTINCT distro FROM catalog_entries ORDER BY distro")
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Codenames disponibles pour un distro (validation). Fallback [] (catalogue vide). */
    public static function codenamesForDistro(string $distro): array
    {
        try {
            $stmt = Db::get()->prepare(
                "SELECT DISTINCT codename FROM catalog_entries "
              . "WHERE distro = ? AND codename IS NOT NULL AND codename != '' ORDER BY codename"
            );
            $stmt->execute([$distro]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Détail d'une entrée par tuple. Null si introuvable. */
    public static function find(string $distro, string $versionFull, string $arch): ?array
    {
        $stmt = Db::get()->prepare(
            "SELECT * FROM catalog_entries "
          . "WHERE distro = ? AND version_full = ? AND arch = ? LIMIT 1"
        );
        $stmt->execute([$distro, $versionFull, $arch]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Compte les entrées par distro (pour la pastille onglet picker). */
    public static function countsByDistro(): array
    {
        try {
            $rows = Db::get()
                ->query("SELECT distro, COUNT(DISTINCT version_full) AS n FROM catalog_entries GROUP BY distro")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['distro']] = (int)$r['n'];
        }
        return $out;
    }
}
