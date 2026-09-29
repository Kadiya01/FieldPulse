<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * JSON codec with hard failure on malformed input.
 *
 * json_decode() returning null is ambiguous (valid "null" vs. parse error), so
 * every decode here is checked against json_last_error() and throws instead of
 * letting a null propagate into business logic.
 */
final class Json
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * @return array<array-key,mixed>
     */
    public static function decodeArray(string $json, string $context = 'payload'): array
    {
        $decoded = json_decode($json, true, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(
                'Malformed JSON in ' . $context . ': ' . json_last_error_msg()
            );
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON object in ' . $context . '.');
        }

        return $decoded;
    }

    /**
     * Decode without requiring an object/array. Used by the verification
     * engine for scalar values.
     */
    public static function decode(string $json, string $context = 'payload'): mixed
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * Pretty form for log lines only. Never used on a response path.
     */
    public static function encodePretty(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS | JSON_PRETTY_PRINT);
    }
}
