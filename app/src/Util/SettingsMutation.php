<?php
declare(strict_types=1);
namespace Admin\Util;

use Admin\Db;
use Admin\Models\Setting;
use RuntimeException;

/** SQL transaction with staged file replacements and explicit compensation on failure. */
final class SettingsMutation
{
    /** @param array<string,string> $values @param array<string,?string> $files null removes a file */
    public static function apply(array $values, array $files = []): void
    {
        $staged = [];
        $backups = [];
        $changed = [];
        $pdo = Db::get();
        try {
            foreach ($files as $path => $contents) {
                if (is_link($path) || (file_exists($path) && !is_file($path))) {
                    throw new RuntimeException('Le fichier de configuration est un lien ou un répertoire : ' . $path);
                }
                if ($contents === null) continue;
                $temporary = @tempnam(dirname($path), '.pxe-settings-');
                if ($temporary === false || dirname($temporary) !== realpath(dirname($path))) {
                    if ($temporary !== false) @unlink($temporary);
                    throw new RuntimeException('Impossible de préparer le fichier : ' . $path);
                }
                $staged[$path] = $temporary;
                if (@file_put_contents($temporary, $contents) !== strlen($contents) || !@chmod($temporary, 0400)) {
                    throw new RuntimeException('Impossible d’écrire le fichier : ' . $path);
                }
                // Preserve the runtime owner. A new Vault file must be readable by Ansible.
                $owner = is_file($path) ? fileowner($path) : (function_exists('posix_getpwnam') ? (posix_getpwnam('ansible')['uid'] ?? null) : null);
                if ($owner !== null && $owner !== false && fileowner($temporary) !== $owner && !@chown($temporary, $owner)) {
                    throw new RuntimeException('Impossible de définir le propriétaire : ' . $path);
                }
            }
            $pdo->beginTransaction();
            foreach ($values as $key => $value) Setting::set($key, $value);
            foreach ($files as $path => $contents) {
                if (file_exists($path)) {
                    $backup = $path . '.rollback-' . bin2hex(random_bytes(8));
                    if (!@rename($path, $backup)) throw new RuntimeException('Impossible de sauvegarder le fichier : ' . $path);
                    $backups[$path] = $backup;
                }
                $changed[] = $path;
                if ($contents !== null && !@rename($staged[$path], $path)) {
                    throw new RuntimeException('Impossible de remplacer le fichier : ' . $path);
                }
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $unrestored = [];
            foreach (array_reverse($changed) as $path) {
                if (isset($backups[$path])) {
                    if (!@rename($backups[$path], $path)) $unrestored[] = $path;
                } elseif (is_file($path) && !@unlink($path)) {
                    $unrestored[] = $path;
                }
            }
            if ($unrestored !== []) {
                throw new RuntimeException('Échec de sauvegarde ; restauration manuelle nécessaire pour ' . implode(', ', $unrestored) . '. Les copies .rollback sont conservées.', 0, $error);
            }
            throw $error;
        } finally {
            foreach ($staged as $temporary) if (is_file($temporary)) @unlink($temporary);
        }
        foreach ($backups as $backup) {
            if (is_file($backup) && !@unlink($backup)) {
                throw new RuntimeException('Configuration enregistrée, mais suppression de la copie privée impossible : ' . $backup);
            }
        }
    }
}
