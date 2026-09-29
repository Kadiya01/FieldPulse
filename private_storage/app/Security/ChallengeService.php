<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;

/**
 * Server-issued challenges for the zero-password proof-of-possession flow.
 *
 * The message a device signs is deliberately self-describing and versioned:
 *
 *   FieldPulse-PoP-v1 \n <challenge> \n <device_uuid>
 *
 * Domain separation matters here. Without the "FieldPulse-PoP-v1" prefix a
 * signature over a challenge could be lifted out of this context and replayed
 * as a request signature (§7) against a different canonical string, or vice
 * versa. Signing bare nonce bytes would make the two signature domains
 * interchangeable.
 *
 * A challenge is single-use, time-bounded, and attempt-capped.
 */
final class ChallengeService
{
    public const PURPOSE_LOGIN    = 'LOGIN';
    public const PURPOSE_REGISTER = 'REGISTER';

    private function __construct()
    {
    }

    /**
     * Issue a challenge and return the exact bytes the device must sign.
     *
     * agent_id is NULL when the IMEI matches no agent. The response shape is
     * identical either way, so this endpoint cannot be used to enumerate which
     * IMEIs are enrolled.
     *
     * @return array{challenge:string,payload:string,expires_at:string,expires_in:int}
     */
    public static function issue(?int $agentId, ?string $deviceUuid, string $purpose): array
    {
        $challenge = bin2hex(random_bytes(32));
        $ttl       = Config::instance()->int('security.challenge_ttl');
        $expires   = Clock::shift($ttl);

        Connection::execute(
            'INSERT INTO auth_challenges
                (challenge, agent_id, device_uuid, purpose, attempts, max_attempts, expires_at, created_at)
             VALUES
                (:challenge, :agent_id, :device_uuid, :purpose, 0, :max_attempts, :expires_at, UTC_TIMESTAMP())',
            [
                'challenge'    => $challenge,
                'agent_id'     => $agentId,
                'device_uuid'  => $deviceUuid,
                'purpose'      => $purpose,
                'max_attempts' => 3,
                'expires_at'   => Clock::sql($expires),
            ]
        );

        return [
            'challenge'  => $challenge,
            'payload'    => self::popMessage($challenge, (string) $deviceUuid),
            'expires_at' => Clock::sql($expires),
            'expires_in' => $ttl,
        ];
    }

    /**
     * The exact byte string the device signs.
     */
    public static function popMessage(string $challenge, string $deviceUuid): string
    {
        return "FieldPulse-PoP-v1\n" . $challenge . "\n" . $deviceUuid;
    }

    /**
     * Atomically claim a challenge.
     *
     * The UPDATE is the check: a challenge can be claimed exactly once, so two
     * concurrent submissions of the same challenge cannot both win.
     *
     * @return array{agent_id:int|null,device_uuid:string|null,purpose:string}
     * @throws ApiException
     */
    public static function claim(string $challenge, string $purpose): array
    {
        $now = Clock::sql();

        // Claim with a conditional update, then read the row back inside the same
        // statement's effect. Using rowCount() as the gate makes this safe under
        // concurrency without a transaction.
        $claimed = Connection::execute(
            'UPDATE auth_challenges
                SET attempts = attempts + 1, consumed_at = :now
              WHERE challenge = :challenge
                AND purpose = :purpose
                AND consumed_at IS NULL
                AND expires_at > :now2
                AND attempts < max_attempts',
            [
                'now'       => $now,
                'challenge' => $challenge,
                'purpose'   => $purpose,
                'now2'      => $now,
            ]
        );

        if ($claimed !== 1) {
            // Distinguish "unknown/already used" from "expired" purely for the
            // server log; the client always sees the same generic message.
            $exists = Connection::fetchOne(
                'SELECT expires_at, consumed_at FROM auth_challenges WHERE challenge = :c',
                ['c' => $challenge]
            );

            Logger::warning('challenge.claim_failed', [
                'found'  => $exists !== null,
                'expired' => $exists !== null && (string) $exists['expires_at'] <= $now,
            ]);

            throw new ApiException(401, ErrorCode::CHALLENGE_INVALID, 'Challenge is invalid or has already been used.');
        }

        $row = Connection::fetchOne(
            'SELECT agent_id, device_uuid, purpose FROM auth_challenges WHERE challenge = :c',
            ['c' => $challenge]
        );

        if ($row === null) {
            throw new ApiException(401, ErrorCode::CHALLENGE_INVALID, 'Challenge is invalid.');
        }

        return [
            'agent_id'    => $row['agent_id'] === null ? null : (int) $row['agent_id'],
            'device_uuid' => $row['device_uuid'] === null ? null : (string) $row['device_uuid'],
            'purpose'     => (string) $row['purpose'],
        ];
    }

    public static function newDeviceUuid(): string
    {
        return Uuid::v4();
    }

    public static function pruneExpired(int $limit = 5000): int
    {
        return Connection::execute(
            'DELETE FROM auth_challenges
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)
              ORDER BY expires_at ASC' . \FieldPulse\Database\Repository::limitClause($limit, 10000)
        );
    }

    public static function randomPairingCode(): string
    {
        // 10 digits, zero-padded, from a CSPRNG.
        return str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }

    public static function hashPairingCode(string $code): string
    {
        return hash('sha256', 'fieldpulse-pair:' . $code);
    }

    public static function isValidPairingCode(mixed $code): bool
    {
        return is_string($code) && preg_match('/^\d{10}$/', $code) === 1;
    }

    public static function randomNonce(int $bytes = 24): string
    {
        return Str::base64UrlEncode(random_bytes($bytes));
    }
}
