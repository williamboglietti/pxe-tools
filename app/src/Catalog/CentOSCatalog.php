<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * CentOS Stream — rolling release upstream de RHEL (entre Fedora et RHEL).
 * Pas de point releases au sens classique (Stream = continuously updated).
 *
 * Méthode : direct — vmlinuz + initrd.img dans /<X>-stream/BaseOS/<arch>/os/images/pxeboot/
 *
 * Mapping arch : amd64↔x86_64, arm64↔aarch64.
 */
final class CentOSCatalog implements DistroCatalogInterface
{
    /** @var array<int, array{channel:string}> */
    // Stream 10 (déc. 2024) = stable courant (base de RHEL 10) ; Stream 9 = oldstable.
    private const MAPPING = [
        9  => ['channel' => 'oldstable'],
        10 => ['channel' => 'stable'],
    ];

    private const ARCH_MAP = ['amd64' => 'x86_64', 'arm64' => 'aarch64'];

    public function code(): string { return 'centos'; }
    public function label(): string { return 'CentOS Stream'; }
    public function variantLabel(): string { return 'Version'; }
    public function archs(): array { return ['amd64', 'arm64']; }
    public function extractMethod(): string { return 'direct'; }

    public function discoverVersions(): array
    {
        $out = [];
        foreach (self::MAPPING as $major => $meta) {
            $out[] = [
                'major'        => $major,
                'version_full' => "$major-stream",
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
        $base = "https://mirror.stream.centos.org/$versionFull/BaseOS/$upstreamArch/os/images/pxeboot";
        return [
            'kernel'   => "$base/vmlinuz",
            'initrd'   => "$base/initrd.img",
            'firmware' => null,
            'iso'      => null,
        ];
    }
}
