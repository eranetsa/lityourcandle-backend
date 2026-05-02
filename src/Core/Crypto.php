<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AES-256-GCM helper for encrypting sensitive notes at rest.
 * Stored format: IV(12) || TAG(16) || CIPHERTEXT
 */
final class Crypto
{
    private static function key(): string
    {
        $hex = (string)App::config('crypto.notes_key_hex');
        if (strlen($hex) !== 64) {
            throw new \RuntimeException('NOTES_ENCRYPTION_KEY must be 64 hex chars (32 bytes)');
        }
        return hex2bin($hex);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return $iv . $tag . $ct;
    }

    public static function decrypt(string $blob): string
    {
        if (strlen($blob) < 28) throw new \RuntimeException('Ciphertext too short');
        $iv  = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct  = substr($blob, 28);
        $pt  = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $pt;
    }
}
