<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * Tiny string / byte helpers used across the security and imaging layers.
 */
final class Str
{
    private function __construct()
    {
    }

    public static function isHex(string $value, int $length): bool
    {
        return strlen($value) === $length && ctype_xdigit($value);
    }

    /**
     * Constant-time comparison for secrets, hashes and MACs.
     */
    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Strict base64url decode. Rejects anything outside the URL-safe alphabet,
     * which stops a signature field from carrying alternate encodings of the
     * same bytes into openssl_verify().
     */
    public static function base64UrlDecode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $padded  = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder === 1) {
            return null;
        }
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * DER length prefix encoder (short form below 128, long form above).
     */
    public static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes  = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    public static function derElement(int $tag, string $contents): string
    {
        return chr($tag) . self::derLength(strlen($contents)) . $contents;
    }

    public static function toPem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END {$label}-----\n";
    }
}
