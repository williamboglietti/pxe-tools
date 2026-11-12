<?php
declare(strict_types=1);

namespace Admin\Catalog;

/**
 * Rocky Linux — RHEL-compatible community (alternative à AlmaLinux). Releases
 * majeures 8, 9, 10. Le repo BaseOS sert toujours le dernier minor.
 *
 * Méthode : direct.
 * Mapping arch : amd64↔x86_64, arm64↔aarch64.
 */
final class RockyCatalog implements DistroCatalogInterface
{
    /** @var array<int, array{channel:string}> */
    // 10 (juin 2025) = stable courant ; 9 et 8 = anciennes majeures encore maintenues.
    private const MAPPING = [
        8  => ['channel' => 'oldstable'],
        9  => ['channel' => 'oldstable'],
        10 => ['channel' => 'stable'],
    ];

    private const ARCH_MAP = ['amd64' => 'x86_64', 'arm64' => 'aarch64'];

    public function code(): string { return 'rocky'; }
    public function label(): string { return 'Rocky Linux'; }
    public function variantLabel(): string { return 'Version'; }
    public function archs(): array { return ['amd64', 'arm64']; }
    public function extractMethod(): string { return 'direct'; }

    public function discoverVersions(): array
    {
        $out = [];
        foreach (self::MAPPING as $major => $meta) {
            $out[] = [
                'major'        => $major,
                'version_full' => (string)$major,
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
        $base = "https://download.rockylinux.org/pub/rocky/$versionFull/BaseOS/$upstreamArch/os/images/pxeboot";
        return [
            'kernel'   => "$base/vmlinuz",
            'initrd'   => "$base/initrd.img",
            'firmware' => null,
            'iso'      => null,
        ];
    }
}
