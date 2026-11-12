<?php
declare(strict_types=1);

namespace Admin\Util;

/**
 * Chiffrement symétrique authentifié via libsodium secretbox
 * (XSalsa20 + Poly1305). Lit la master key 32 bytes depuis le path
 * pointé par l'env PXE_MASTER_KEY_PATH (override pour tests) ou
 * /etc/pxe-tools/master.key par défaut.
 *
 * Format DB : base64(nonce(24B) || ciphertext+mac).
 *
 * À distinguer de Admin\Util\SecretBox :
 *   - SecretBox     : dérive la clé d'une passphrase (Argon2id + salt).
 *                     Usage : chiffrement interactif (vault, dump exporté).
 *   - Crypto (ici)  : clé maître fixe lue sur disque.
 *                     Usage : chiffrement automatique non-interactif des
 *                     secrets en DB (github_push_token, etc.).
 */
final class Crypto
{
    private const DEFAULT_KEY_PATH = '/etc/pxe-tools/master.key';

    private static function keyPath(): string
    {
        $env = getenv('PXE_MASTER_KEY_PATH');
        return $env !== false && $env !== '' ? $env : self::DEFAULT_KEY_PATH;
    }

    private static function loadKey(): string
    {
        $path = self::keyPath();
        if (!is_readable($path)) {
            throw new \RuntimeException("Master key absente ou illisible : $path");
        }
        $key = file_get_contents($path);
        if ($key === false) {
            throw new \RuntimeException("Master key illisible : $path");
        }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $size = strlen($key);
            sodium_memzero($key);
            throw new \RuntimeException("Master key invalide (taille $size, attendu " . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ") : $path");
        }
        return $key;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);
        return base64_encode($nonce . $ciphertext);
    }

    public static function decrypt(string $b64): string
    {
        $key = self::loadKey();
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            sodium_memzero($key);
            throw new \RuntimeException('Ciphertext invalide (base64 ou taille)');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);
        if ($plaintext === false) {
            throw new \RuntimeException('Decrypt failed (mauvais key ou ciphertext tampered)');
        }
        return $plaintext;
    }


}
