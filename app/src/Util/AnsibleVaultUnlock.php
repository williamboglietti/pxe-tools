<?php
declare(strict_types=1);

namespace Admin\Util;

use InvalidArgumentException;
use RuntimeException;

/**
 * Mot de passe Vault saisi pour la prochaine exécution Ansible.
 *
 * Jamais en base ni dans un fichier persistant : le worker consomme le fichier
 * déposé ici (`unlock`, dans le tmpfs), puis le supprime avec sa copie de travail.
 */
final class AnsibleVaultUnlock
{
    private const MAX_BYTES = 1024;

    public static function directory(): string
    {
        return (string)(getenv('PXE_VAULT_UNLOCK_DIR') ?: '/dev/shm/pxe-vault');
    }

    public static function isPending(): bool
    {
        return is_file(self::directory() . '/unlock');
    }

    public static function store(string $password): void
    {
        $length = strlen($password);
        if ($length === 0 || $length > self::MAX_BYTES || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Mot de passe Vault invalide.');
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Dossier de déverrouillage indisponible.');
        }
        // tempnam crée le fichier en 0600 ; le renommage remplace atomiquement un déverrouillage précédent.
        $staging = tempnam($directory, 'unlock-');
        if ($staging === false) {
            throw new RuntimeException('Déverrouillage Vault non enregistré.');
        }
        if (file_put_contents($staging, $password) !== $length || !rename($staging, $directory . '/unlock')) {
            @unlink($staging);
            throw new RuntimeException('Déverrouillage Vault non enregistré.');
        }
    }
}
