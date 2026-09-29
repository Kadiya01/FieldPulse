<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\Clock;

/**
 * refresh_tokens access. SHA-256 hashes only — the raw token exists solely in
 * the device's HttpOnly cookie.
 */
final class RefreshTokenRepository extends Repository
{
    /**
     * Record a refresh token.
     *
     * $deviceId is nullable: a session created by login, before the browser has
     * registered a device, is a bootstrap session bound to no device. Those
     * tokens can only reach the bearer-only bootstrap route. See
     * TokenService::issueBootstrap().
     */
    public function create(
        int $agentId,
        ?int $deviceId,
        string $tokenHash,
        string $family,
        string $expiresAt,
        ?string $userAgentHash = null
    ): int {
        $this->exec(
            'INSERT INTO refresh_tokens
                (agent_id, device_id, token_hash, token_family, expires_at, revoked_at, user_agent_hash, created_at, last_used_at)
             VALUES
                (:agent_id, :device_id, :token_hash, :token_family, :expires_at, NULL, :ua, UTC_TIMESTAMP(), NULL)',
            [
                'agent_id'    => $agentId,
                'device_id'   => $deviceId,
                'token_hash'  => $tokenHash,
                'token_family' => $family,
                'expires_at'  => $expiresAt,
                'ua'          => $userAgentHash,
            ]
        );

        return (int) Connection::lastInsertId();
    }

    /**
     * Fetch a token row regardless of state, so the caller can distinguish
     * "unknown" from "already rotated" and react to the latter.
     *
     * @return array<string,mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        return $this->one('SELECT * FROM refresh_tokens WHERE token_hash = :h', ['h' => $hash]);
    }

    /**
     * Link a rotated token to its successor.
     */
    public function markRotated(int $id, int $replacedById): void
    {
        $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason, replaced_by_id = :next, last_used_at = UTC_TIMESTAMP()
              WHERE id = :id',
            ['reason' => 'ROTATED', 'next' => $replacedById, 'id' => $id]
        );
    }

    public function markUsed(int $id): void
    {
        $this->exec('UPDATE refresh_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    public function revoke(int $id, string $reason): void
    {
        $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason WHERE id = :id AND revoked_at IS NULL',
            ['reason' => $reason, 'id' => $id]
        );
    }

    /**
     * Revoke every token descended from one login. This is the response to
     * refresh-token reuse: a rotated-but-presented token is proof that the
     * cookie was captured, so the whole family dies, not just the one token.
     */
    public function revokeFamily(string $family, string $reason): int
    {
        return $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
              WHERE token_family = :family AND revoked_at IS NULL',
            ['reason' => $reason, 'family' => $family]
        );
    }

    public function revokeAllForDevice(int $deviceId, string $reason): int
    {
        return $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
              WHERE device_id = :device_id AND revoked_at IS NULL',
            ['reason' => $reason, 'device_id' => $deviceId]
        );
    }

    public function revokeAllForAgent(int $agentId, string $reason): int
    {
        return $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
              WHERE agent_id = :agent_id AND revoked_at IS NULL',
            ['reason' => $reason, 'agent_id' => $agentId]
        );
    }

    /**
     * Revoke only the sessions that are not yet bound to a device.
     *
     * A bootstrap session exists for the short window between a correct
     * password and device registration. Once a device is bound, a surviving
     * bootstrap session is pure liability: it can still mint another bootstrap
     * token, and therefore enrol another device, long after the agent has
     * proved possession of a key. That is the whole reason the binding step
     * exists, so the bootstrap family is retired at the moment it is spent.
     *
     * Scoped by device_id IS NULL rather than by family, because the controller
     * does not necessarily have the bootstrap cookie to hand — the registration
     * request authenticates on the access token — and failing to retire the
     * family would leave the gap open. The device-bound token minted in the
     * same request is not affected, because its device_id is already set.
     */
    public function revokeUnboundForAgent(int $agentId, string $reason): int
    {
        return $this->exec(
            'UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
              WHERE agent_id = :agent_id AND device_id IS NULL AND revoked_at IS NULL',
            ['reason' => $reason, 'agent_id' => $agentId]
        );
    }

    public function pruneExpired(int $limit = 5000): int
    {
        return $this->exec(
            'DELETE FROM refresh_tokens
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
              ORDER BY expires_at ASC' . self::limitClause($limit, 10000)
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findActiveByHash(string $hash): ?array
    {
        return $this->one(
            'SELECT * FROM refresh_tokens
              WHERE token_hash = :h AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['h' => $hash]
        );
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function expiryUtc(int $ttlSeconds): string
    {
        return Clock::sql(Clock::shift($ttlSeconds));
    }
}
