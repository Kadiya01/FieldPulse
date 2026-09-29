<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Uuid;

/**
 * Input validation.
 *
 * Every value that reaches the database, a file path, or a comparison goes
 * through this class first. The bias throughout is "reject rather than coerce":
 * a value the server does not fully understand never reaches a ledger row, and
 * the error names the field so the PWA can show it to the agent.
 */
final class Validator
{
    private function __construct()
    {
    }

    /**
     * IMEI: 14-16 digits, and Luhn-valid.
     *
     * The Luhn check is not decoration. Most of the 15-digit space is not a
     * possible IMEI, so this alone removes ~90% of blind-guess space before any
     * database lookup happens.
     */
    public static function imei(mixed $value, string $field = 'imei'): string
    {
        if (!is_string($value)) {
            throw ApiException::validation('Field "' . $field . '" is required.', ['field' => $field]);
        }

        $imei = trim($value);

        // Tolerate the separators an agent types from the handset box.
        $imei = (string) preg_replace('/[\s\-]/', '', $imei);

        $pattern = Config::instance()->str('auth.imei_pattern');

        if (preg_match($pattern, $imei) !== 1) {
            throw ApiException::validation(
                'Field "' . $field . '" must be 14 to 16 digits.',
                ['field' => $field]
            );
        }

        if (!self::luhn($imei)) {
            throw ApiException::validation(
                'Field "' . $field . '" is not a valid IMEI.',
                ['field' => $field]
            );
        }

        return $imei;
    }

    /**
     * Standard IMEI check digit.
     *
     * Digits are doubled from the right, excluding the check digit itself;
     * tens digits are subtracted (equivalent to a mod-10 sum).
     */
    public static function luhn(string $digits): bool
    {
        $length = strlen($digits);
        $sum    = 0;
        $double = true;   // the rightmost digit is the check digit

        for ($i = $length - 2; $i >= 0; $i--) {
            $value = (int) $digits[$i];

            if ($double) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }

            $sum   += $value;
            $double = !$double;
        }

        $check = (int) $digits[$length - 1];

        return ($sum + $check) % 10 === 0;
    }

    public static function uuid(mixed $value, string $field): string
    {
        if (!Uuid::isValid($value)) {
            throw ApiException::validation(
                'Field "' . $field . '" must be a valid UUID.',
                ['field' => $field]
            );
        }

        return strtolower((string) $value);
    }

    public static function intRange(mixed $value, int $min, int $max, string $field): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }

        if (!is_int($value)) {
            throw ApiException::validation('Field "' . $field . '" must be an integer.', ['field' => $field]);
        }

        if ($value < $min || $value > $max) {
            throw ApiException::validation(
                sprintf('Field "%s" must be between %d and %d.', $field, $min, $max),
                ['field' => $field, 'min' => $min, 'max' => $max]
            );
        }

        return $value;
    }

    /**
     * Latitude/longitude pair.
     *
     * @return array{latitude:float,longitude:float}
     */
    public static function coordinates(mixed $lat, mixed $lng, string $field = 'coordinates'): array
    {
        $latitude  = self::floatOrNull($lat);
        $longitude = self::floatOrNull($lng);

        if ($latitude === null || $longitude === null) {
            throw ApiException::validation(
                'Field "' . $field . '" must supply both latitude and longitude.',
                ['field' => $field]
            );
        }

        if ($latitude < -90.0 || $latitude > 90.0) {
            throw ApiException::validation('Latitude is out of range.', ['field' => $field . '.latitude']);
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw ApiException::validation('Longitude is out of range.', ['field' => $field . '.longitude']);
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }

    public static function floatOrNull(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^-?\d{1,3}(\.\d+)?$/', trim($value)) === 1) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * Client-asserted capture time, normalised to UTC.
     *
     * This is untrusted input (§1): it is range-checked so that an absurd value
     * cannot poison a comparison, and it is stored separately from the server's
     * own timestamps precisely so it can be compared against them.
     */
    public static function capturedAt(mixed $value, string $field = 'client_captured_at'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw ApiException::validation('Field "' . $field . '" must be an ISO-8601 string.', ['field' => $field]);
        }

        $raw = trim($value);

        // Accept "2026-09-28T10:15:00Z" and "2026-09-28T10:15:00+01:00".
        $normalised = str_ends_with($raw, 'Z') ? substr($raw, 0, -1) . '+00:00' : $raw;

        try {
            $dt = new \DateTimeImmutable($normalised);
        } catch (\Exception) {
            throw ApiException::validation(
                'Field "' . $field . '" is not a valid ISO-8601 timestamp.',
                ['field' => $field]
            );
        }

        $c = Config::instance();
        $now = Clock::now();
        $age = $now->getTimestamp() - $dt->getTimestamp();

        // A client timestamp far in the future or the past is not a formatting
        // problem; it is a device with a broken clock. Reject rather than guess.
        if ($age < -$c->int('timestamps.max_future_skew')) {
            throw ApiException::validation(
                'Field "' . $field . '" is in the future.',
                ['field' => $field]
            );
        }

        if ($age > $c->int('timestamps.max_past_skew')) {
            throw ApiException::validation(
                'Field "' . $field . '" is too old.',
                ['field' => $field]
            );
        }

        return Clock::sql($dt);
    }

    public static function sha256Hex(mixed $value, string $field = 'file_sha256'): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/i', trim($value)) !== 1) {
            throw ApiException::validation(
                'Field "' . $field . '" must be a 64-character SHA-256 hex digest.',
                ['field' => $field]
            );
        }

        return strtolower(trim($value));
    }

    public static function string(mixed $value, int $min, int $max, string $field): string
    {
        if (!is_string($value)) {
            throw ApiException::validation('Field "' . $field . '" is required.', ['field' => $field]);
        }

        $length = mb_strlen($value);

        if ($length < $min || $length > $max) {
            throw ApiException::validation(
                sprintf('Field "%s" must be between %d and %d characters.', $field, $min, $max),
                ['field' => $field]
            );
        }

        return $value;
    }

    /**
     * Reject any field outside the contract.
     *
     * Throws rather than logging, despite the name looking like a soft check.
     * Both call sites guard signed input:
     *
     *  - SubmitController checks the payload that the device signed, so an extra
     *    field means the client and server disagree about what is being attested
     *    to. Silently dropping it lets a request be verified against a set of
     *    fields the client never intended to bind.
     *  - ReviewController checks a supervisor's verdict body, where a typo'd
     *    field name is a rejected review that looks like it was applied.
     *
     * Either way, quietly ignoring the field converts a loud, fixable contract
     * bug into a silent data-loss bug. The field names come back in the error so
     * the client can be corrected immediately.
     *
     * @param  array<string,mixed> $source
     * @param  list<string>        $allowed
     */
    public static function assertNoUnknownKeys(array $source, array $allowed, string $context): void
    {
        $unknown = array_values(array_diff(array_keys($source), $allowed));

        if ($unknown === []) {
            return;
        }

        \FieldPulse\Support\Logger::warning('validation.unknown_fields', [
            'context' => $context,
            'fields'  => $unknown,
        ]);

        throw ApiException::validation(
            sprintf(
                'Unexpected field%s in %s: %s.',
                count($unknown) === 1 ? '' : 's',
                $context,
                implode(', ', $unknown)
            ),
            ['context' => $context, 'unexpected_fields' => $unknown]
        );
    }

    public static function error(string $message, string $field): ApiException
    {
        return ApiException::validation($message, ['field' => $field]);
    }

    /**
     * True for a real calendar date in ISO form.
     *
     * checkdate() is required on top of the format test: the regex alone
     * accepts 2026-02-30, which DateTimeImmutable would then silently roll
     * forward into March and return a period the client never asked for.
     */
    public static function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function badRequest(string $code, string $message): ApiException
    {
        return new ApiException(400, $code, $message);
    }
}
