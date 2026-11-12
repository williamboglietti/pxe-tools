<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Contrat pour une distribution exposée dans le picker /settings/images/add.
 *
 * Une implémentation est responsable de :
 *  - décrire la distro (code, label, archs supportées)
 *  - découvrir les versions disponibles depuis sa source upstream (HTML scrape
 *    ou liste hardcodée selon ce que publie le projet)
 *  - construire les URLs concrètes (kernel/initrd/firmware/iso) pour un couple
 *    (version_full, arch)
 *  - déclarer la méthode d'extraction des fichiers (direct ou ISO).
 *
 * Le registry Admin\Catalog\DistroCatalog instancie ces classes ; le worker
 * `bootstrap:refresh_catalog_v2` les appelle pour peupler la table
 * `catalog_entries`.
 */
interface DistroCatalogInterface
{
    /** Identifiant court, ASCII lowercase. Ex 'debian', 'ubuntu', 'fedora'. */
    public function code(): string;

    /** Libellé d'affichage UI. Ex 'Debian', 'CentOS Stream'. */
    public function label(): string;

    /**
     * Libellé du niveau 2 de la cascade UI Famille → Variante → Version.
     *   - Debian / Ubuntu : 'Codename' (trixie, bookworm, focal)
     *   - Rocky / Alma / Fedora / CentOS : 'Version' (8, 9, 10)
     *   - Windows (futur) : 'Édition' (Server 2022, 11 Pro)
     *
     * Affiché en label du select de niveau 2 dans presets/edit.php et le wizard
     * d'install. Le défaut générique est 'Distribution' si la classe ne surcharge
     * pas (mais toutes les impls actuelles le surchargent pour être plus parlant).
     */
    public function variantLabel(): string;

    /**
     * Architectures supportées par cette distro. Le picker générera un toggle
     * par arch dans le tableau. Ex ['amd64', 'arm64'].
     * @return array<int,string>
     */
    public function archs(): array;

    /**
     * Méthode d'extraction des fichiers téléchargés.
     *   - 'direct'      : kernel/initrd téléchargés tels quels (Fedora, CentOS,
     *                     AlmaLinux, Rocky — tree PXE natif).
     *   - 'iso_extract' : download ISO + extract kernel/initrd via 7z (Debian,
     *                     Ubuntu).
     */
    public function extractMethod(): string;

    /**
     * Découvre les versions disponibles. Retourne une liste d'entrées
     * normalisées prêtes à insérer dans `catalog_entries`.
     *
     * Chaque entrée :
     * [
     *   'major'        => int,           // 13, 24, 41
     *   'version_full' => '13.4.0',
     *   'codename'     => 'trixie',      // null si distro sans codename
     *   'channel'      => 'stable'|'oldstable'|'testing'|'unstable'|null,
     *   'archs'        => ['amd64', 'arm64'],  // archs réellement disponibles pour cette version
     *   'extract_method' => 'direct'|'iso_extract',  // OPTIONNEL : surcharge
     *       // extractMethod() PAR-VERSION (modèle hybride). Si absent, le
     *       // consommateur (refresh-catalog, seed) retombe sur extractMethod().
     *       // Debian s'en sert : current='direct', archives='iso_extract'.
     * ]
     *
     * @return array<int, array{
     *   major:?int, version_full:string, codename:?string,
     *   channel:?string, archs:array<int,string>, extract_method?:string
     * }>
     */
    public function discoverVersions(): array;

    /**
     * URLs concrètes à télécharger pour un couple (version_full, arch).
     * Retourne :
     *   ['kernel' => ?string, 'initrd' => ?string, 'firmware' => ?string, 'iso' => ?string]
     * Selon `extractMethod()` :
     *   - direct       : kernel + initrd renseignés, iso = null
     *   - iso_extract  : iso renseigné, kernel/initrd = null (calculés à
     *                    l'extraction par le worker)
     *
     * @return array{kernel:?string, initrd:?string, firmware:?string, iso:?string}
     */
    public function urlsFor(string $versionFull, string $arch): array;
}
