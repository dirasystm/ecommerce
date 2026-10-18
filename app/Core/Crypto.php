<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Random tokens, token hashing and authenticated encryption (AES-256-GCM).
 */
final class Crypto
{
    /** Excludes look-alike characters (0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** URL-safe random token (default 32 bytes => 43 chars). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Human-friendly random code, e.g. for order numbers and collection codes. */
    public static function code(int $length): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Hash used to store tokens/codes at rest. Keyed with the app key. */
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $key = base64_decode((string) App::config()->get('app.key', ''), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('app.key must be a base64-encoded 32-byte key (see config.local.php).');
        }
        return $key;
    }
}
