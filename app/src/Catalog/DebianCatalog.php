<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Debian — Bookworm (12) oldstable + Trixie (13) stable + Forky (14) testing.
 * Bullseye (11) retiré du catalogue (oldoldstable, pas pertinent).
 *
 * Méthode HYBRIDE (extract_method par-row, pas par-distro) :
 *
 *  - CURRENT de chaque codename → 'direct'. kernel/initrd pris du netboot
 *    installer COURANT, servi par le miroir paquets
 *    (deb.debian.org/debian/dists/<codename>/main/installer-<arch>/current/images/
 *    netboot/debian-installer/<arch>/). MÊME miroir que les preseed et les udebs
 *    téléchargés par anna → kernel/initrd alignés par construction, zéro skew.
 *    C'est la version qui sert le discovery / rescue (default netboot path).
 *
 *  - POINT RELEASES ARCHIVÉES → 'iso_extract' (best-effort). kernel/initrd
 *    extraits de l'ISO netinst figé sur cdimage.org/cdimage/archive/. Permet
 *    d'exposer toutes les versions historiques dans le picker d'install. Le
 *    skew installer/udebs peut réapparaître sur ces versions figées (anna
 *    « Detect and mount installation media ») — c'est assumé : ces entrées
 *    servent l'install versionnée volontaire, jamais le discovery.
 *
 * Le firmware non-free (2e initrd) reste servi par codename/current pour les
 * DEUX modes (non versionné upstream) → rétro-compatible au sein d'un codename.
 *
 * Historique : tout était passé en 'direct' (current only) pour tuer le skew
 * discovery. L'hybride réintroduit les archives SANS toucher le current direct,
 * donc le discovery/rescue reste sur le netboot miroir robuste.
 */
final class DebianCatalog implements DistroCatalogInterface
{
    // Miroir paquets Debian = source du netboot installer ET des udebs (anna) ET
    // des preseed (deb.debian.org). Même hôte partout → kernel/initrd alignés.
    // HTTPS (F-05) : defense-in-depth contre MITM/DNS-poisoning sur le fetch du
    // kernel/initrd par le worker. La garantie reste la chaîne GPG côté worker
    // (verify_debian_netboot), transport-agnostique. N'affecte PAS le mirror du
    // preseed d-i (hardcodé http deb.debian.org dans les templates, hors scope).
    private const INSTALLER_MIRROR = 'https://deb.debian.org/debian';
    // Firmware non-free (2e initrd) : servi par codename/current, non versionné.
    private const FIRMWARE_BASE = 'https://cdimage.debian.org/cdimage/firmware/';
    // Archives ISO des point releases passées (current exclu, voir mode direct).
    private const CDIMAGE_ARCHIVE = 'https://cdimage.debian.org/cdimage/archive/';

    /**
     * Cache statique mémoïsant la current par codename, pour ne pas refaire un
     * appel réseau par (versionFull, arch) dans urlsFor() : refresh-catalog
     * appelle urlsFor() N fois (chaque version × chaque arch). On y stocke le
     * résultat de fetchCurrentVersion (ou false si réseau KO, distinct de null).
     * @var array<string, string|null|false>
     */
    private static array $currentCache = [];

    /**
     * @var array<int, array{codename:string, channel:string, current:?string, archive:array<int,string>}>
     */
    public const MAPPING = [
        12 => [
            'codename' => 'bookworm',
            'channel'  => 'oldstable',
            // 'current' = fallback figé : discoverVersions() lit d'abord le miroir
            // (champ Version: du Release) et ne retombe ici qu'en cas d'échec réseau.
            'current'  => '12.14.0',
            'archive'  => [
                '12.13.0', '12.12.0', '12.11.0', '12.10.0', '12.9.0', '12.8.0',
                '12.7.0',  '12.6.0',  '12.5.0',  '12.4.0',  '12.2.0',
                '12.1.0',  '12.0.0',   // 12.3.0 jamais publiée (NEVER-RELEASED upstream)
            ],
        ],
        13 => [
            'codename' => 'trixie',
            'channel'  => 'stable',
            'current'  => '13.5.0',
            'archive'  => ['13.4.0', '13.3.0', '13.2.0', '13.1.0', '13.0.0'],
        ],
        14 => [
            'codename' => 'forky',
            'channel'  => 'testing',
            'current'  => null,        // pas encore de release stable
            'archive'  => [],
        ],
    ];

    public function code(): string { return 'debian'; }
    public function label(): string { return 'Debian'; }
    public function variantLabel(): string { return 'Codename'; }
    public function archs(): array { return ['amd64', 'arm64']; }
    public function extractMethod(): string { return 'direct'; }

    public function discoverVersions(): array
    {
        // HYBRIDE : (a) CURRENT en 'direct' (netboot miroir, sert discovery) +
        // (b) point releases ARCHIVÉES en 'iso_extract' (best-effort, picker).
        //
        // Le « current » réel suit le miroir : Debian publie une point release
        // tous les ~2 mois, donc on le LIT en direct (champ Version: du fichier
        // Release du codename) au lieu de le figer. Fallback sur self::MAPPING si
        // le réseau échoue. Appelé uniquement par le worker (job refresh_catalog),
        // jamais en rendu de page → les appels réseau sont sans impact UI.
        $out = [];
        foreach (self::MAPPING as $major => $meta) {
            $codename = $meta['codename'];

            // (a) CURRENT → direct. forky (testing) n'en a pas → on saute juste
            //     la current, mais ses archives éventuelles passeront ci-dessous.
            $current = null;
            if (!empty($meta['current'])) {
                // currentFor() (mémoïsé) : MÊME source que isCurrent()/urlsFor()
                // → extract_method='direct' posé ici et le mode direct de urlsFor
                // restent cohérents (pas de row 'direct' à kernel_url NULL).
                $current = $this->currentFor($codename, $major);
                $out[] = [
                    'major'          => $major,
                    'version_full'   => $current,
                    'codename'       => $codename,
                    'channel'        => $meta['channel'],
                    'archs'          => $this->archs(),
                    'extract_method' => 'direct',
                ];
            }

            // (b) ARCHIVES → iso_extract. Lecture live du listing cdimage/archive/
            //     (fallback meta['archive'] si réseau KO). Dédup contre la current
            //     (déjà émise en direct) pour ne pas avoir 2 entrées même version.
            $archives = $this->fetchArchivedVersions($major);
            if (empty($archives)) {
                $archives = $meta['archive'];   // fallback figé
            }
            foreach ($archives as $vf) {
                if ($vf === $current) continue;   // == current : déjà en direct
                $out[] = [
                    'major'          => $major,
                    'version_full'   => $vf,
                    'codename'       => $codename,
                    'channel'        => $meta['channel'],
                    'archs'          => $this->archs(),
                    'extract_method' => 'iso_extract',
                ];
            }
        }
        return $out;
    }

    /**
     * Point releases archivées d'un major, lues en direct du listing
     * cdimage/archive/. Regex STRICTE (3 composants numériques X.Y.Z du major
     * demandé) → exclut le bruit du listing (-live, NEVER-RELEASED, BROKEN,
     * *_di_*, latest-*, project, …). Retourne [] si réseau KO (l'appelant
     * retombe alors sur meta['archive']). Dédup + tri version_compare DESC.
     *
     * @return array<int, string>
     */
    private function fetchArchivedVersions(int $major): array
    {
        // Timeout court : ce scrape tourne aussi dans le POST /init/images (seed
        // synchrone Debian d'office) → cumul à garder sous le fastcgi_read_timeout
        // nginx (60s). Sur expiration, l'appelant retombe sur meta['archive'].
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
        $body = @file_get_contents(self::CDIMAGE_ARCHIVE, false, $ctx);
        if ($body === false) {
            return [];
        }
        // href="12.13.0/" — exactement X.Y.Z du major (3 composants numériques).
        if (!preg_match_all('#href="(' . $major . '\.\d+\.\d+)/"#', $body, $m)) {
            return [];
        }
        $versions = array_values(array_unique($m[1]));
        usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        return $versions;
    }

    /**
     * Point release courante d'un codename, lue dans le champ Version: du fichier
     * Release du miroir (ex « Version: 13.5 » → « 13.5.0 », convention cdimage).
     * Retourne null (→ l'appelant retombe sur self::MAPPING) si le réseau échoue,
     * si le format est inattendu, ou si le major ne correspond pas au codename
     * (garde-fou contre un parsing aberrant).
     */
    private function fetchCurrentVersion(string $codename, int $expectedMajor): ?string
    {
        $url  = self::INSTALLER_MIRROR . "/dists/$codename/Release";
        // Timeout court : appelé aussi dans le POST /init/images (seed synchrone).
        // Cumul des scrapes à garder sous le fastcgi_read_timeout nginx (60s).
        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'method' => 'GET']]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        // « Version: 13.5 » (oldstable/stable) ; on accepte 2 ou 3 composants.
        if (!preg_match('/^Version:\s*([0-9]+(?:\.[0-9]+){1,2})\s*$/m', $body, $m)) {
            return null;
        }
        $parts = explode('.', $m[1]);
        if ((int)$parts[0] !== $expectedMajor) {
            return null;   // major ≠ codename attendu → parsing douteux, on ignore
        }
        if (count($parts) === 2) {
            $parts[] = '0';   // major.minor → major.minor.0 (libellé point release)
        }
        return implode('.', $parts);
    }

    public function urlsFor(string $versionFull, string $arch): array
    {
        $major    = (int)explode('.', $versionFull)[0];
        $codename = self::MAPPING[$major]['codename'] ?? '';
        if ($codename === '' || !in_array($arch, $this->archs(), true)) {
            return ['kernel' => null, 'initrd' => null, 'firmware' => null, 'iso' => null];
        }

        // Firmware non-free (2e initrd chargé par tous les menus GRUB/PXE) :
        // servi par codename/current, non versionné → aligné dans les 2 modes.
        // Rétro-compatible au sein d'un codename : une archive 12.11.0 charge le
        // firmware de bookworm/current, ce que les templates attendent déjà.
        $firmware = self::FIRMWARE_BASE . "$codename/current/firmware.cpio.gz";

        if ($this->isCurrent($codename, $major, $versionFull)) {
            // MODE DIRECT (current) — INCHANGÉ. Netboot installer COURANT servi
            // par le miroir paquets → aligné avec les udebs (anna) et les preseed.
            // arch Debian = amd64|arm64 (identité dans le path, pas de remap).
            $base = self::INSTALLER_MIRROR
                  . "/dists/$codename/main/installer-$arch/current/images/netboot/debian-installer/$arch";
            return [
                'kernel'   => "$base/linux",
                'initrd'   => "$base/initrd.gz",
                'firmware' => $firmware,
                'iso'      => null,   // direct : pas d'ISO, extraction depuis miroir
            ];
        }

        // MODE ISO_EXTRACT (archive) — kernel/initrd extraits de l'ISO netinst
        // figé. bootstrap-download-image-iso.sh fait l'extraction (install.amd/…).
        $iso = self::CDIMAGE_ARCHIVE . "$versionFull/$arch/iso-cd/debian-$versionFull-$arch-netinst.iso";
        return [
            'kernel'   => null,   // pas de kernel direct : sera extrait de l'ISO
            'initrd'   => null,
            'firmware' => $firmware,
            'iso'      => $iso,
        ];
    }

    /**
     * $versionFull est-elle la current de son codename ? Mémoïse fetchCurrentVersion
     * par codename (cache statique de classe) : urlsFor() est appelée N fois par
     * refresh-catalog (version × arch), on ne veut pas N appels réseau.
     *
     * false en cache (réseau KO, distinct de null) → on retombe sur meta['current']
     * figé, cohérent avec le fallback de discoverVersions().
     */
    /**
     * Current résolue d'un codename, MÉMOÏSÉE (cache statique) et partagée par
     * discoverVersions() ET isCurrent()/urlsFor() : les deux DOIVENT voir la même
     * valeur, sinon extract_method (posé en discoverVersions) et le mode de
     * urlsFor (direct/iso) divergent → row 'direct' à kernel_url NULL (le worker
     * « télécharge » du vide et marque downloaded → discovery cassé en silence).
     *
     * false en cache = réseau KO (distinct de null) → fallback meta['current'] figé.
     */
    private function currentFor(string $codename, int $major): ?string
    {
        if (!array_key_exists($codename, self::$currentCache)) {
            self::$currentCache[$codename] = $this->fetchCurrentVersion($codename, $major) ?? false;
        }
        $current = self::$currentCache[$codename];
        if ($current === false) {
            $current = self::MAPPING[$major]['current'] ?? null;   // fallback figé
        }
        return $current;
    }

    private function isCurrent(string $codename, int $major, string $versionFull): bool
    {
        $current = $this->currentFor($codename, $major);
        return $current !== null && $current === $versionFull;
    }
}
