<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Registry des distros exposées dans le picker /settings/images/add.
 *
 * Ordre = ordre d'affichage des onglets dans l'UI. Debian d'abord (le plus
 * mature côté implémentation), puis les autres distros par stabilité du
 * support upstream.
 *
 * Pour ajouter une distro :
 *   1. Créer Admin\Catalog\<Name>Catalog implements DistroCatalogInterface
 *   2. L'ajouter dans self::DISTRO_CLASSES (ordre voulu dans UI)
 */
final class DistroCatalog
{
    /** @var array<string, class-string<DistroCatalogInterface>> */
    private const DISTRO_CLASSES = [
        'debian'    => DebianCatalog::class,
        'ubuntu'    => UbuntuCatalog::class,
        'fedora'    => FedoraCatalog::class,
        'centos'    => CentOSCatalog::class,
        'almalinux' => AlmaLinuxCatalog::class,
        'rocky'     => RockyCatalog::class,
    ];

    /**
     * @var array<string, DistroCatalogInterface>|null
     */
    private static ?array $instances = null;

    /**
     * Toutes les distros instanciées. Ordre = ordre d'affichage UI.
     *
     * @return array<string, DistroCatalogInterface>
     */
    public static function all(): array
    {
        if (self::$instances === null) {
            self::$instances = [];
            foreach (self::DISTRO_CLASSES as $code => $cls) {
                self::$instances[$code] = new $cls();
            }
        }
        return self::$instances;
    }

    /** Récupère une instance précise par code. Null si inconnu. */
    public static function get(string $code): ?DistroCatalogInterface
    {
        return self::all()[$code] ?? null;
    }

    /**
     * Distros dont le pipeline d'INSTALLATION est réellement implémenté.
     *
     * Aujourd'hui Debian uniquement : le rendu PXE (render_template.py) et le
     * preseed (preseed.php.j2 / grub.php.j2) sont 100% debian-installer. Les
     * autres distros se TÉLÉCHARGENT (catalogue, picker /settings/images) mais ne
     * s'installent pas encore → le picker d'install de machine les affiche
     * désactivées (« Bientôt disponible »). Étendre cette liste quand le support
     * d'install multi-distro (casper/autoinstall, kickstart) sera ajouté.
     *
     * @var array<int,string>
     */
    private const INSTALLABLE = ['debian'];

    /** True si cette distro peut être installée (pas seulement téléchargée). */
    public static function supportsInstall(string $code): bool
    {
        return in_array($code, self::INSTALLABLE, true);
    }
}
