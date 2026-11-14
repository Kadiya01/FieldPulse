<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\AbhAuthRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Support\Clock;

/**
 * ABH-only request authenticator. It never consults legacy FieldPulse tables.
 * Every accepted request re-checks agent, device and session state in MySQL,
 * then verifies the P-256 device signature and consumes a one-time nonce.
 */
final class AbhAuthenticator
{
    private function __construct()
    {
    }

    private static ?self $instance = null;

    public static function i(): self
    {
        return self::$instance ??= new self();
    }

    public function authenticateSignedRequest(Request $request): AuthContext
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'An ABH Bearer access token is required.');
        }

        $row = Connection::fetchOne(
            'SELECT
                s.id AS session_id, s.agent_id, s.device_id,
                a.agent_code, a.full_name, a.phone, a.status AS agent_status,
                d.device_uuid, d.imei_value, d.device_type,
                d.public_key_jwk, d.status AS device_status
             FROM abh_agent_sessions s
             INNER JOIN abh_agents a ON a.id = s.agent_id
             INNER JOIN abh_agent_devices d ON d.id = s.device_id
             WHERE s.access_token_hash = :token_hash
               AND s.revoked_at IS NULL
               AND s.access_expires_at > UTC_TIMESTAMP(6)
             LIMIT 1',
            ['token_hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'ABH access session is not valid.');
        }

        if ((string) $row['agent_status'] !== 'ACTIVE') {
            throw new ApiException(403, ErrorCode::AGENT_INACTIVE, 'This ABH agent account is not active.');
        }

        if ((string) $row['device_status'] !== 'ACTIVE') {
            throw new ApiException(403, ErrorCode::DEVICE_NOT_ACTIVE, 'This ABH device is not authorised.');
        }

        $agent = [
            'id' => (int) $row['agent_id'],
            'agent_code' => (string) $row['agent_code'],
            'full_name' => (string) $row['full_name'],
            'phone' => (string) $row['phone'],
            'imei' => (string) $row['imei_value'],
            'status' => (string) $row['agent_status'],
            'role' => 'AGENT',
        ];
        $device = [
            'id' => (int) $row['device_id'],
            'device_uuid' => (string) $row['device_uuid'],
            'public_key_jwk' => $row['public_key_jwk'],
            'status' => (string) $row['device_status'],
        ];
        $context = new AuthContext($agent, $device, [
            'sub' => (string) $row['agent_id'],
            'scope' => 'abh',
        ]);

        $headerUuid = $request->requireHeader('X-Device-UUID', ErrorCode::UNKNOWN_DEVICE_UUID);
        if (!hash_equals($context->deviceUuid(), $headerUuid)) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'ABH device binding does not match this session.');
        }

        $rawTimestamp = $request->requireHeader('X-Request-Timestamp', ErrorCode::SIGNATURE_INVALID);
        $nonce = $request->requireHeader('X-Request-Nonce', ErrorCode::SIGNATURE_INVALID);
        $signature = $request->requireHeader('X-Request-Signature', ErrorCode::SIGNATURE_INVALID);
        if (preg_match('/^\d{10}$/', $rawTimestamp) !== 1) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed X-Request-Timestamp.');
        }

        CanonicalPayload::assertNonceFormat($nonce);
        CanonicalPayload::assertFreshTimestamp(
            (int) $rawTimestamp,
            Clock::timestamp(),
            Config::instance()->int('security.clock_skew')
        );

        SignatureVerifier::verify(
            $device,
            $request->method(),
            $request->path(),
            (int) $rawTimestamp,
            $nonce,
            $request->signedBody(),
            $signature
        );

        if (!AbhAuthRepository::consumeNonce($context->deviceId(), $nonce)) {
            throw new ApiException(401, ErrorCode::REPLAY_DETECTED, 'This signed request has already been used.');
        }

        Connection::execute(
            'UPDATE abh_agent_devices SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = :id',
            ['id' => $context->deviceId()]
        );
        Connection::execute(
            'UPDATE abh_agent_sessions SET last_used_at = UTC_TIMESTAMP(6) WHERE id = :id',
            ['id' => (int) $row['session_id']]
        );

        return $context;
    }
}
