<?php
declare(strict_types=1);

namespace Admin;

/**
 * Version SemVer embarquée dans l'image par le workflow de build.
 * Le numéro est calculé à partir des commits Conventional Commits depuis le
 * dernier tag de version. BETA reste affiché tant que le produit est en bêta.
 */
final class Version
{
    public const DEFAULT_NUMBER = '0.1.0-dev';

    public static function displayNumber(): string
    {
        // Native packages carry their exact Debian version separately from the
        // SemVer build version used by Docker.
        $nativeVersion = @file_get_contents(__DIR__ . '/native-build-version.txt');
        $nativeVersion = is_string($nativeVersion) ? trim($nativeVersion) : '';
        if (preg_match('/\A[0-9][A-Za-z0-9.+~\-]{0,40}\z/', $nativeVersion)) {
            return $nativeVersion;
        }

        $version = @file_get_contents(__DIR__ . '/build-version.txt');
        $version = is_string($version) ? trim($version) : '';

        if (!preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?\z/', $version)) {
            $version = self::DEFAULT_NUMBER;
        }

        return $version;
    }
}
