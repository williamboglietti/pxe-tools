<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Ubuntu — LTS uniquement (les non-LTS ont 9 mois de support, non pertinents
 * pour un serveur PXE de homelab).
 *
 * Méthode : iso_extract pour TOUTES les versions (kernel/initrd dans `casper/`
 * du live-server). Pas de mode 'direct' : Ubuntu ne sert jamais le discovery /
 * rescue (c'est le netboot Debian direct qui le fait) → moins de garde-fous que
 * DebianCatalog (pas de risque de row 'direct' à kernel_url NULL).
 *
 * HÉBERGEMENT (le piège résolu ici) : releases.ubuntu.com ne garde QUE la (ou
 * les 1-2 dernières) point release(s) d'une série. Dès qu'une nouvelle sort
 * (24.04.4…), l'ancienne (24.04.2) est RETIRÉE et bascule sur
 * old-releases.ubuntu.com. Figer les point releases dans un MAPPING statique
 * (ce que faisait la version précédente) → 404 garanti au bout de ~6 mois, et le
 * worker bouclait sur le download en échec. On découvre donc la point release
 * COURANTE en direct du serveur, comme DebianCatalog le fait du sien.
 *
 * Tous les hosts sont officiels Canonical (géo-distribués, mondiaux — pas de
 * miroir régional) :
 *  - COURANTES (présentes sur releases.ubuntu.com) → téléchargées de là. La plus
 *    haute = la « dernière stable » seedée par défaut au wizard.
 *  - ARCHIVÉES (retirées) → old-releases.ubuntu.com/releases/ (best-effort : la
 *    seule source qui conserve l'historique). Exposées dans le picker d'install.
 *
 * arm64 : releases.ubuntu.com est amd64-only ; les autres arches vivent sur
 * cdimage.ubuntu.com (qui, à l'inverse, n'a PAS l'amd64 live-server). On route
 * donc amd64→releases, arm64→cdimage pour les courantes. amd64 = cas nominal.
 */
final class UbuntuCatalog implements DistroCatalogInterface
{
    // Host de la version COURANTE amd64 : serveur de release officiel Canonical
    // (mondial). Ne conserve que les point releases récentes d'une série.
    private const MIRROR = 'https://releases.ubuntu.com/';
    // Host de la version courante arm64 : cdimage Canonical (releases.ubuntu.com
    // est amd64-only).
    private const CDIMAGE = 'https://cdimage.ubuntu.com/releases/';
    // Host des point releases ARCHIVÉES (retirées du serveur de release). Seule
    // source qui conserve l'historique complet d'une série LTS.
    private const OLD_RELEASES = 'https://old-releases.ubuntu.com/releases/';

    /**
     * Cache statique mémoïsant les point releases présentes sur le miroir, par
     * série (base major.minor). Partagé par discoverVersions() ET urlsFor() : les
     * deux DOIVENT voir le même ensemble, sinon une version pourrait être émise
     * comme « courante » mais voir son URL routée vers old-releases (ou l'inverse)
     * → 404. Stocke le tableau (vide possible) ou false si le réseau a échoué.
     * @var array<string, array<int,string>|false>
     */
    private static array $mirrorCache = [];

    /**
     * @var array<int, array{codename:string, base:string, channel:string, current:string, archive:array<int,string>}>
     *
     * 2 majeures LTS : jammy 22.04 (oldstable) + noble 24.04 (stable). 'current'
     * et 'archive' = fallbacks FIGÉS : utilisés seulement si le réseau échoue
     * (discovery live indisponible).
     */
    private const MAPPING = [
        22 => [
            'codename' => 'jammy',
            'base'     => '22.04',
            'channel'  => 'oldstable',
            'current'  => '22.04.5',
            'archive'  => ['22.04.4', '22.04.3', '22.04.2', '22.04.1'],
        ],
        24 => [
            'codename' => 'noble',
            'base'     => '24.04',
            'channel'  => 'stable',
            'current'  => '24.04.4',
            'archive'  => ['24.04.2', '24.04.1'],
        ],
    ];

    public function code(): string { return 'ubuntu'; }
    public function label(): string { return 'Ubuntu'; }
    public function variantLabel(): string { return 'Codename'; }
    public function archs(): array { return ['amd64', 'arm64']; }
    public function extractMethod(): string { return 'iso_extract'; }

    public function discoverVersions(): array
    {
        // Pour chaque série LTS : (a) les point releases COURANTES lues en direct
        // du miroir (DESC, la plus haute en tête → seedée par défaut), puis (b)
        // les ARCHIVÉES (old-releases, dédupliquées contre le miroir). Toutes en
        // iso_extract. Appelé uniquement par le worker (job refresh_catalog) →
        // les appels réseau sont sans impact UI.
        $out = [];
        foreach (self::MAPPING as $major => $meta) {
            $base    = $meta['base'];
            $current = $this->mirrorVersionsFor($base);   // DESC, fallback figé

            foreach ($current as $vf) {
                $out[] = [
                    'major'          => $major,
                    'version_full'   => $vf,
                    'codename'       => $meta['codename'],
                    'channel'        => $meta['channel'],
                    'archs'          => $this->archs(),
                    'extract_method' => 'iso_extract',
                ];
            }

            $archives = $this->fetchArchivedVersions($base);
            if (empty($archives)) {
                $archives = $meta['archive'];   // fallback figé
            }
            foreach ($archives as $vf) {
                if (in_array($vf, $current, true)) continue;   // déjà émise (miroir)
                $out[] = [
                    'major'          => $major,
                    'version_full'   => $vf,
                    'codename'       => $meta['codename'],
                    'channel'        => $meta['channel'],
                    'archs'          => $this->archs(),
                    'extract_method' => 'iso_extract',
                ];
            }
        }
        return $out;
    }

    public function urlsFor(string $versionFull, string $arch): array
    {
        $parts = explode('.', $versionFull);
        $major = (int)($parts[0] ?? 0);
        $base  = isset($parts[0], $parts[1]) ? "{$parts[0]}.{$parts[1]}" : $versionFull;
        if (!isset(self::MAPPING[$major]) || !in_array($arch, $this->archs(), true)) {
            return ['kernel' => null, 'initrd' => null, 'firmware' => null, 'iso' => null];
        }

        $iso = "ubuntu-$versionFull-live-server-$arch.iso";

        if (in_array($versionFull, $this->mirrorVersionsFor($base), true)) {
            // COURANTE : releases.ubuntu.com (amd64) / cdimage (arm64, absent de
            // releases.ubuntu.com qui est amd64-only).
            $url = $arch === 'amd64'
                ? self::MIRROR . "$base/$iso"
                : self::CDIMAGE . "$base/release/$iso";
        } else {
            // ARCHIVÉE : old-releases (best-effort, seule source de l'historique).
            $url = self::OLD_RELEASES . "$base/$iso";
        }

        return [
            'kernel'   => null,
            'initrd'   => null,
            'firmware' => null,
            'iso'      => $url,
        ];
    }

    /**
     * Point releases d'une série présentes sur le miroir COURANT, mémoïsées.
     * urlsFor() est appelée N fois par refresh-catalog (version × arch) → on ne
     * veut pas N scrapes. Sur échec réseau (false en cache, distinct de []), on
     * retombe sur [MAPPING current] : la current figée reste routée vers le
     * miroir, cohérent entre discoverVersions() et urlsFor().
     *
     * @return array<int, string> DESC (plus haute en tête)
     */
    private function mirrorVersionsFor(string $base): array
    {
        if (!array_key_exists($base, self::$mirrorCache)) {
            self::$mirrorCache[$base] = $this->scrapeIsoVersions(self::MIRROR . "$base/", $base);
        }
        $versions = self::$mirrorCache[$base];
        if ($versions === false || $versions === []) {
            // Réseau KO (ou listing vide) → fallback figé sur la current du MAPPING.
            $major   = (int)explode('.', $base)[0];
            $current = self::MAPPING[$major]['current'] ?? null;
            return $current !== null ? [$current] : [];
        }
        return $versions;
    }

    /**
     * Point releases archivées d'une série, lues en direct du listing
     * old-releases. Retourne [] si réseau KO (l'appelant retombe sur
     * meta['archive']).
     *
     * @return array<int, string> DESC
     */
    private function fetchArchivedVersions(string $base): array
    {
        $versions = $this->scrapeIsoVersions(self::OLD_RELEASES . "$base/", $base);
        return $versions === false ? [] : $versions;
    }

    /**
     * Scrape un listing HTTP et extrait les version_full Ubuntu d'une série depuis
     * les noms d'ISO live-server amd64. Regex ancrée sur la base (preg_quote) +
     * point release optionnelle (`24.04` ou `24.04.N`) → exclut le bruit d'une
     * autre série. amd64 seul (référence) : les point releases arm64 suivent.
     * Retourne false sur échec réseau (distinct de [] = listing vide/sans match),
     * pour que l'appelant distingue « hors-ligne » de « rien d'archivé ».
     *
     * @return array<int, string>|false DESC sur succès
     */
    private function scrapeIsoVersions(string $url, string $base): array|false
    {
        // Timeout court À DESSEIN : discoverVersions() (donc ce scrape) tourne
        // AUSSI dans le POST /init/images via le seed synchrone. Le cumul des
        // scrapes (Ubuntu + Debian d'office) doit rester sous le
        // fastcgi_read_timeout nginx (60s) sinon 504. 5s × ~9 scrapes ≈ 45s, et
        // sur expiration on retombe sur le MAPPING figé (seed quand même garanti).
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return false;
        }
        $re = '#ubuntu-(' . preg_quote($base, '#') . '(?:\.\d+)?)-live-server-amd64\.iso#';
        if (!preg_match_all($re, $body, $m)) {
            return [];
        }
        $versions = array_values(array_unique($m[1]));
        usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        return $versions;
    }
}
