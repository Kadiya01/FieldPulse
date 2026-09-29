<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * RFC 4122 v4 identifiers.
 *
 * Cryptographically random. Used for submission_uuid (client supplied, but
 * validated for shape and uniqueness on arrival) and for server-issued
 * device_uuid values.
 */
final class Uuid
{
    private function __construct()
    {
    }

    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
