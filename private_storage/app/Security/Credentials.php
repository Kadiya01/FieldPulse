<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Password credentials.
 *
 * Two things live here because they have to be in one place to be right.
 *
 * 1. Hashing and verification, delegated to PHP's password_hash()/
 *    password_verify(). These are bcrypt by default. They are used rather than a
 *    hand-rolled SHA-256 so that the work factor is a parameter, the salt is
 *    generated per password, and verification is constant-time. Nothing in this
 *    application ever sees a plaintext password except at the moment it is
 *    hashed or compared.
 *
 * 2. The unknown-user timing burn.
 *
 *    A login for a username that does not exist can be answered as soon as the
 *    lookup misses, while a login for a real username pays for a bcrypt
 *    comparison. That difference is roughly a hundredfold and is measurable
 *    over a network, which turns login into a username oracle: an attacker
 *    enumerates valid usernames without ever guessing a password. verify()
 *    therefore always performs a real bcrypt comparison, against a decoy hash
 *    when the account does not exist, so both paths cost the same.
 *
 *    The decoy is a real bcrypt hash of a random secret, generated once per
 *    process. It is never a hash of a guessable string, so nothing about it
 *    leaks, and password_verify() against it always returns false because the
 *    supplied password cannot match it.
 */
final class Credentials
{
    /**
     * Cost of the decoy comparison. Deliberately the default: if the real
     * hashes were raised to a higher cost, the decoy would become the faster
     * path and reintroduce a timing signal in the other direction.
     */
    private const DECOY_COST = 10;

    private static ?string $decoyHash = null;

    private function __construct()
    {
    }

    /**
     * Produce a storable password hash.
     *
     * @throws ApiException when the password cannot be hashed, which on most
     *                       builds means password_hash() returned null.
     */
    public static function hash(string $plaintext): string
    {
        // PHP truncates silently at 72 bytes for bcrypt, so a longer password
        // would be accepted while only its prefix mattered. Refusing is the
        // only way the stored hash means what the operator thinks it means.
        if (strlen($plaintext) > 72) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_ERROR,
                'Password must be at most 72 bytes.'
            );
        }

        $hash = password_hash($plaintext, PASSWORD_BCRYPT);

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('password_hash() returned no hash.');
        }

        return $hash;
    }

    /**
     * Verify a password against an agent's stored credential.
     *
     * Always performs a bcrypt comparison, whether or not the account exists,
     * so the response time does not reveal which usernames are real. Returns
     * false in every failure case; the caller decides what to do about it, and
     * the caller must not distinguish the cases to the client either.
     */
    public static function verify(string $plaintext, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            self::burnUnknownUser();

            return false;
        }

        /*
         * Note the absence of a password_needs_rehash() branch. Rehashing needs
         * both the plaintext and the agent id, and this function deliberately
         * has neither: widening its signature to rehash on the fly would mean
         * it could also write, and a verification path that writes is a much
         * larger thing to trust than one that only compares. Raising the cost
         * is an operator action, applied deliberately, not something that
         * happens to drift upwards.
         */
        return password_verify($plaintext, $hash);
    }

    /**
     * Spend the same work a real verification would, then return false.
     *
     * Private because it has no legitimate caller other than verify(); a public
     * "burn" would eventually be called on the success path by accident, which
     * would double every login's cost.
     */
    private static function burnUnknownUser(): void
    {
        if (self::$decoyHash === null) {
            $decoy = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, [
                'cost' => self::DECOY_COST,
            ]);

            self::$decoyHash = is_string($decoy) ? $decoy : self::constantTimeFallback();
        }

        password_verify(str_repeat('x', 24), self::$decoyHash);
    }

    /**
     * A hash-shaped constant, used only if password_hash() fails outright.
     *
     * password_verify() against a malformed hash returns false without doing
     * the work, so this does not buy a timing guarantee. It exists so that the
     * failure path cannot itself throw while handling a failed login.
     */
    private static function constantTimeFallback(): string
    {
        return '$2y$10$' . str_repeat('.', 53);
    }
}
