<?php
declare(strict_types=1);

namespace Admin\Util;

/**
 * Chiffrement/déchiffrement par passphrase via libsodium.
 *
 * - Dérivation clé : crypto_pwhash (Argon2id, mode INTERACTIVE ~100ms).
 * - Chiffrement   : crypto_secretbox (XSalsa20-Poly1305).
 * - Salt 16B + Nonce 24B générés aléatoirement à chaque encrypt.
 *
 * Usage type :
 *   $box = SecretBox::encrypt('s3cret', 'monpass1234');
 *   // -> ['ciphertext' => bin, 'salt' => bin, 'nonce' => bin]
 *   $clear = SecretBox::decrypt($box['ciphertext'], $box['salt'], $box['nonce'], 'monpass1234');
 *   // -> 's3cret' ou null si mauvaise passphrase
 */
final class SecretBox
{
    private const SALT_LEN  = SODIUM_CRYPTO_PWHASH_SALTBYTES;       // 16
    private const NONCE_LEN = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;   // 24
    private const KEY_LEN   = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;     // 32

    /**
     * @return array{ciphertext: string, salt: string, nonce: string}
     */
    public static function encrypt(string $plaintext, string $passphrase): array
    {
        $salt  = random_bytes(self::SALT_LEN);
        $nonce = random_bytes(self::NONCE_LEN);
        $key   = self::deriveKey($passphrase, $salt);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        sodium_memzero($key);
        return ['ciphertext' => $ciphertext, 'salt' => $salt, 'nonce' => $nonce];
    }

    /** Retourne le clear text ou null si la passphrase est incorrecte / data corrompue. */
    public static function decrypt(string $ciphertext, string $salt, string $nonce, string $passphrase): ?string
    {
        if (strlen($salt) !== self::SALT_LEN || strlen($nonce) !== self::NONCE_LEN) {
            return null;
        }
        $key = self::deriveKey($passphrase, $salt);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        sodium_memzero($key);
        return $plain === false ? null : $plain;
    }

    private static function deriveKey(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(
            self::KEY_LEN,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }
}
