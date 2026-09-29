<?php

declare(strict_types=1);

namespace FieldPulse\Security;

/**
 * Operator-issued one-time pairing codes.
 *
 * Login is username and password, and device registration is gated on the
 * bootstrap session login returns. A pairing code is the one factor neither of
 * those supplies: something only the operator and the agent's supervisor can
 * produce, so that a stolen password alone cannot be used to bind an attacker's
 * key to the account. How often it is demanded is the configured policy — see
 * DeviceController::codeRequired().
 *
 * The code is never stored. code_hash is SHA-256 over a domain-separated
 * string, so a database dump does not yield usable codes, and the comparison is
 * a plain indexed equality rather than a per-row constant-time compare, which
 * is the right trade here: the code has 10^10 possibilities and is
 * single-use.
 *
 * This replaces Security\ChallengeService, which served the old IMEI
 * proof-of-possession flow. That flow is gone: a challenge identified an agent
 * by their IMEI and asked the device to sign it, which proved possession of a
 * key that the very same request would have been able to register.
 */
final class PairingCode
{
    private function __construct()
    {
    }

    /**
     * 10 digits, zero-padded, from a CSPRNG.
     */
    public static function random(): string
    {
        return str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', 'fieldpulse-pair:' . $code);
    }

    public static function isValid(mixed $code): bool
    {
        return is_string($code) && preg_match('/^\d{10}$/', $code) === 1;
    }
}
