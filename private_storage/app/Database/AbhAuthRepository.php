<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;

/**
 * ABH agent authentication persistence.
 *
 * All queries are restricted to abh_* tables. Access and refresh credentials
 * are random opaque values; only their SHA-256 hashes are persisted.
 */
final class AbhAuthRepository
{
    private function __construct()
    {
    }

    /** @return array<string,mixed>|null */
    public static function findLoginIdentity(string $phone, string $deviceUuid): ?array
    {
        return Connection::fetchOne(
            'SELECT
                a.id, a.agent_code, a.full_name, a.phone, a.email, a.location_text,
                a.status AS agent_status, c.password_hash,
                d.id AS device_id, d.device_uuid, d.imei_value, d.device_type,
                d.status AS device_status, d.public_key_jwk
             FROM abh_agents a
             INNER JOIN abh_agent_credentials c ON c.agent_id = a.id
             INNER JOIN abh_agent_devices d ON d.agent_id = a.id
             WHERE a.phone_normalized = :phone
               AND d.device_uuid = :device_uuid
             LIMIT 1',
            ['phone' => $phone, 'device_uuid' => $deviceUuid]
        );
    }

    /**
     * Store a one-time proof nonce. A unique device/nonce key makes replay
     * detection atomic across concurrent requests.
     */
    public static function consumeNonce(int $deviceId, string $nonce): bool
    {
        $ttl = Config::instance()->int('security.nonce_ttl');
        $expires = Clock::sql(Clock::shift($ttl));

        try {
            Connection::execute(
                'INSERT INTO abh_auth_nonces (device_id, nonce, expires_at, created_at)
                 VALUES (:device_id, :nonce, :expires_at, UTC_TIMESTAMP(6))',
                ['device_id' => $deviceId, 'nonce' => $nonce, 'expires_at' => $expires]
            );
        } catch (\PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $error;
        }

        Connection::execute(
            'DELETE FROM abh_auth_nonces
              WHERE expires_at < UTC_TIMESTAMP(6)
              ORDER BY expires_at ASC
              LIMIT 500'
        );

        return true;
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{access_token:string,refresh_token:string,expires_in:int,refresh_expires_at:string,agent:array{id:int,agent_code:string,full_name:string,role:string}}
     */
    public static function issueSession(array $identity): array
    {
        return Connection::transaction(
            static fn(\PDO $pdo): array => self::insertSession($pdo, $identity, Uuid::v4())
        );
    }

    /**
     * Rotate a single-use refresh token. A rotated token presented again
     * revokes its complete family and the bound device.
     *
     * @return array<string,mixed>
     */
    public static function rotate(string $rawRefreshToken): array
    {
        $refreshHash = hash('sha256', $rawRefreshToken);

        $result = Connection::transaction(
            static function (\PDO $pdo) use ($refreshHash): array {
                $stmt = $pdo->prepare(
                    'SELECT s.*, a.agent_code, a.full_name, a.status AS agent_status,
                            d.status AS device_status, d.device_uuid, d.imei_value,
                            d.device_type, d.public_key_jwk
                       FROM abh_agent_sessions s
                       INNER JOIN abh_agents a ON a.id = s.agent_id
                       INNER JOIN abh_agent_devices d ON d.id = s.device_id
                      WHERE s.refresh_token_hash = :refresh_hash
                      LIMIT 1
                      FOR UPDATE'
                );
                $stmt->execute(['refresh_hash' => $refreshHash]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($row)) {
                    return ['error' => 'invalid'];
                }

                if ($row['revoked_at'] !== null) {
                    if ($row['revoked_reason'] === 'ROTATED') {
                        $pdo->prepare(
                            'UPDATE abh_agent_sessions
                                SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                                    revoked_reason = CASE WHEN revoked_reason IS NULL THEN "REUSE_DETECTED" ELSE revoked_reason END
                              WHERE token_family = :family'
                        )->execute(['family' => $row['token_family']]);

                        $pdo->prepare(
                            'UPDATE abh_agent_devices
                                SET status = "REVOKED",
                                    revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6))
                              WHERE id = :device_id'
                        )->execute(['device_id' => $row['device_id']]);

                        $pdo->prepare(
                            'INSERT INTO abh_audit_events
                             (actor_type, actor_agent_id, action_key, entity_type, entity_id, after_json, created_at)
                             VALUES ("AGENT", :agent_id, "auth.refresh_reuse_detected", "device", :device_id,
                                     :after_json, UTC_TIMESTAMP(6))'
                        )->execute([
                            'agent_id' => $row['agent_id'],
                            'device_id' => $row['device_id'],
                            'after_json' => json_encode(
                                ['reason' => 'REUSE_DETECTED', 'token_family' => $row['token_family']],
                                JSON_THROW_ON_ERROR
                            ),
                        ]);

                        return ['error' => 'reuse'];
                    }

                    return ['error' => 'invalid'];
                }

                $refreshExpiry = Clock::parseSql((string) $row['refresh_expires_at']);
                if ($refreshExpiry === null || $refreshExpiry->getTimestamp() <= Clock::timestamp()) {
                    $pdo->prepare(
                        'UPDATE abh_agent_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "EXPIRED"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'expired'];
                }

                if ($row['agent_status'] !== 'ACTIVE') {
                    $pdo->prepare(
                        'UPDATE abh_agent_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "AGENT_INACTIVE"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'agent_inactive'];
                }

                if ($row['device_status'] !== 'ACTIVE') {
                    $pdo->prepare(
                        'UPDATE abh_agent_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "DEVICE_NOT_ACTIVE"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'device_inactive'];
                }

                $identity = [
                    'id' => (int) $row['agent_id'],
                    'agent_code' => (string) $row['agent_code'],
                    'full_name' => (string) $row['full_name'],
                    'agent_status' => (string) $row['agent_status'],
                    'device_id' => (int) $row['device_id'],
                    'device_uuid' => (string) $row['device_uuid'],
                    'imei_value' => (string) $row['imei_value'],
                    'device_type' => (string) ($row['device_type'] ?? ''),
                    'device_status' => (string) $row['device_status'],
                    'public_key_jwk' => $row['public_key_jwk'],
                ];

                $issued = self::insertSession($pdo, $identity, (string) $row['token_family']);

                $newHash = hash('sha256', $issued['refresh_token']);
                $newIdStmt = $pdo->prepare(
                    'SELECT id FROM abh_agent_sessions WHERE refresh_token_hash = :hash LIMIT 1'
                );
                $newIdStmt->execute(['hash' => $newHash]);
                $newId = (int) $newIdStmt->fetchColumn();

                $pdo->prepare(
                    'UPDATE abh_agent_sessions
                        SET revoked_at = UTC_TIMESTAMP(6),
                            revoked_reason = "ROTATED",
                            rotated_to_session_id = :new_id,
                            last_used_at = UTC_TIMESTAMP(6)
                      WHERE id = :old_id AND revoked_at IS NULL'
                )->execute(['new_id' => $newId, 'old_id' => $row['id']]);

                return $issued;
            }
        );

        if (isset($result['error'])) {
            $code = (string) $result['error'];
            $status = $code === 'agent_inactive' || $code === 'device_inactive' ? 403 : 401;
            $errorCode = $code === 'agent_inactive' ? ErrorCode::AGENT_INACTIVE
                : ($code === 'device_inactive' ? ErrorCode::DEVICE_NOT_ACTIVE : ErrorCode::REFRESH_INVALID);
            $message = $code === 'reuse'
                ? 'A rotated session was reused. This device has been revoked; contact an administrator.'
                : ($code === 'agent_inactive'
                    ? 'This agent account is not active.'
                    : ($code === 'device_inactive'
                        ? 'This device is not authorised.'
                        : 'Refresh token is not valid.'));
            throw new ApiException($status, $errorCode, $message);
        }

        return $result;
    }

    public static function revokeRefresh(string $rawRefreshToken): void
    {
        $row = Connection::fetchOne(
            'SELECT token_family FROM abh_agent_sessions
              WHERE refresh_token_hash = :hash LIMIT 1',
            ['hash' => hash('sha256', $rawRefreshToken)]
        );

        if ($row === null) {
            return;
        }

        Connection::execute(
            'UPDATE abh_agent_sessions
                SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                    revoked_reason = CASE WHEN revoked_reason IS NULL THEN "LOGOUT" ELSE revoked_reason END
              WHERE token_family = :family',
            ['family' => $row['token_family']]
        );
    }

    /** @param array<string,mixed> $identity */
    private static function insertSession(\PDO $pdo, array $identity, string $family): array
    {
        $config = Config::instance();
        $accessToken = Str::randomToken(32);
        $refreshToken = Str::randomToken(48);
        $accessTtl = $config->int('security.access_ttl');
        $refreshTtl = $config->int('security.refresh_ttl');
        $accessExpiry = Clock::sql(Clock::shift($accessTtl));
        $refreshExpiry = Clock::sql(Clock::shift($refreshTtl));

        $stmt = $pdo->prepare(
            'INSERT INTO abh_agent_sessions
             (agent_id, device_id, access_token_hash, refresh_token_hash, token_family,
              access_expires_at, refresh_expires_at, created_at)
             VALUES
             (:agent_id, :device_id, :access_hash, :refresh_hash, :family,
              :access_expiry, :refresh_expiry, UTC_TIMESTAMP(6))'
        );
        $stmt->execute([
            'agent_id' => $identity['id'],
            'device_id' => $identity['device_id'],
            'access_hash' => hash('sha256', $accessToken),
            'refresh_hash' => hash('sha256', $refreshToken),
            'family' => $family,
            'access_expiry' => $accessExpiry,
            'refresh_expiry' => $refreshExpiry,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl,
            'refresh_expires_at' => $refreshExpiry,
            'agent' => [
                'id' => (int) $identity['id'],
                'agent_code' => (string) $identity['agent_code'],
                'full_name' => (string) $identity['full_name'],
                'role' => 'AGENT',
            ],
        ];
    }
}
