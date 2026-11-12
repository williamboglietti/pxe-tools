<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Fedora — Server edition, dernières 2 releases (cycle 6 mois). Pas de point
 * releases au sens Debian (chaque release majeure = 1 cible PXE).
 *
 * Méthode : direct — vmlinuz + initrd.img dans /Server/<arch>/os/images/pxeboot/
 *
 * Mapping arch : DB stocke amd64/arm64, URL upstream utilise x86_64/aarch64.
 */
final class FedoraCatalog implements DistroCatalogInterface
{
    /**
     * @var array<int, array{channel:string}>
     *
     * Cadence Fedora ~6 mois. dl.fedoraproject.org cesse de servir les versions
     * non maintenues (typiquement N-2). MAJ à effectuer quand une nouvelle
     * release sort ou quand une ancienne devient 404.
     */
    private const MAPPING = [
        43 => ['channel' => 'oldstable'],
        44 => ['channel' => 'stable'],
    ];

    private const ARCH_MAP = ['amd64' => 'x86_64', 'arm64' => 'aarch64'];

    public function code(): string { return 'fedora'; }
    public function label(): string { return 'Fedora'; }
    public function variantLabel(): string { return 'Version'; }
    public function archs(): array { return ['amd64', 'arm64']; }
    public function extractMethod(): string { return 'direct'; }

    public function discoverVersions(): array
    {
        $out = [];
        foreach (self::MAPPING as $major => $meta) {
            $out[] = [
                'major'        => $major,
                'version_full' => (string)$major,   // Fedora pas de mineure exposée côté PXE
                'codename'     => null,
                'channel'      => $meta['channel'],
                'archs'        => $this->archs(),
            ];
        }
        return $out;
    }

    public function urlsFor(string $versionFull, string $arch): array
    {
        $upstreamArch = self::ARCH_MAP[$arch] ?? $arch;
        $base = "https://dl.fedoraproject.org/pub/fedora/linux/releases/$versionFull/Server/$upstreamArch/os/images/pxeboot";
        return [
            'kernel'   => "$base/vmlinuz",
            'initrd'   => "$base/initrd.img",
            'firmware' => null,
            'iso'      => null,
        ];
    }
}
