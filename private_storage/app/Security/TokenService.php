<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\RefreshTokenRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;

/**
 * Access + refresh token lifecycle (§6).
 *
 * Refresh tokens are single-use and rotated on every exchange. Presenting a
 * token that has already been rotated is treated as theft: the entire token
 * family is revoked, the device is revoked, and the event is audited. This is
 * the standard OAuth BCP response and the reason token_family exists.
 *
 * The refresh token never appears in a response body — only in an HttpOnly,
 * Secure, SameSite=Strict cookie scoped to the auth path, so client-side
 * JavaScript cannot read it and cannot leak it through an XSS.
 */
final class TokenService
{
    private function __construct(
        private readonly RefreshTokenRepository $tokens = new RefreshTokenRepository(),
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    private static ?self $instance = null;

    public static function i(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Mint an access token and a refresh token for an agent/device pair.
     *
     * @param  array<string,mixed> $agent
     * @param  array<string,mixed> $device
     * @return array{access_token:string,refresh_token:string,refresh_expires_at:string,expires_in:int,token_type:string}
     */
    public function issue(array $agent, array $device, ?string $family = null, ?string $userAgent = null): array
    {
        $c = Config::instance();

        if (($agent['status'] ?? null) !== AgentRepository::ACTIVE) {
            throw new ApiException(403, ErrorCode::AGENT_INACTIVE, 'This agent is not active.');
        }

        if (!DeviceStatus::canSubmit((string) ($device['status'] ?? ''))) {
            throw new ApiException(
                403,
                DeviceStatus::denialCode((string) $device['status']),
                'This device is not authorised.'
            );
        }

        $accessToken = Jwt::issue([
            'agent_id'    => (int) $agent['id'],
            'agent_code'  => (string) $agent['agent_code'],
            'device_uuid' => (string) $device['device_uuid'],
        ]);

        $rawRefresh    = Str::base64UrlEncode(random_bytes(48));
        $family       ??= Uuid::v4();
        $refreshExpires = RefreshTokenRepository::expiryUtc($c->int('security.refresh_ttl'));

        $this->tokens->create(
            (int) $agent['id'],
            (int) $device['id'],
            RefreshTokenRepository::hash($rawRefresh),
            $family,
            $refreshExpires,
            $userAgent === null ? null : hash('sha256', $userAgent)
        );

        return [
            'access_token'      => $accessToken,
            'refresh_token'     => $rawRefresh,
            'refresh_expires_at' => $refreshExpires,
            'expires_in'        => $c->int('security.access_ttl'),
            'token_type'        => 'Bearer',
        ];
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * @return array{access_token:string,refresh_token:string,refresh_expires_at:string,expires_in:int,token_type:string}
     * @throws ApiException
     */
    public function rotate(string $rawToken, ?string $userAgent = null): array
    {
        $hash = RefreshTokenRepository::hash($rawToken);
        $row  = $this->tokens->findByHash($hash);

        if ($row === null) {
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'Refresh token is not recognised.');
        }

        // Reuse of a rotated token: the holder of the cookie is not the original
        // device (or the original device was cloned). Kill the family.
        if ($row['revoked_at'] !== null) {
            $this->tokens->revokeFamily((string) $row['token_family'], 'REUSE_DETECTED');
            $this->devices->setStatus((int) $row['device_id'], DeviceStatus::REVOKED);

            $this->audit->record([
                'actor_agent_id' => (int) $row['agent_id'],
                'action'         => 'auth.refresh_reuse_detected',
                'entity_type'    => 'device',
                'entity_id'      => (int) $row['device_id'],
                'metadata'       => ['family' => (string) $row['token_family']],
            ]);

            throw new ApiException(
                401,
                ErrorCode::REFRESH_INVALID,
                'Refresh token has already been used. All sessions for this device were revoked.'
            );
        }

        if (Clock::parseSql((string) $row['expires_at'])?->getTimestamp() <= Clock::timestamp()) {
            $this->tokens->revoke((int) $row['id'], 'EXPIRED');
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'Refresh token has expired.');
        }

        $agent  = $this->agents->findById((int) $row['agent_id']);
        $device = $this->devices->findById((int) $row['device_id']);

        if ($agent === null || $device === null) {
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'Refresh token is no longer valid.');
        }

        // Re-check both states on refresh: a device revoked between login and
        // refresh must not be able to extend its session.
        if ($agent['status'] !== AgentRepository::ACTIVE) {
            $this->tokens->revokeFamily((string) $row['token_family'], 'AGENT_INACTIVE');
            throw new ApiException(403, ErrorCode::AGENT_INACTIVE, 'This agent is not active.');
        }

        if (!DeviceStatus::canSubmit((string) $device['status'])) {
            $this->tokens->revokeFamily((string) $row['token_family'], 'DEVICE_NOT_ACTIVE');
            throw new ApiException(
                403,
                DeviceStatus::denialCode((string) $device['status']),
                'This device is not authorised.'
            );
        }

        $issued = $this->issue($agent, $device, (string) $row['token_family'], $userAgent);

        // Find the successor created above and link it to the old token.
        $successor = $this->tokens->findByHash(RefreshTokenRepository::hash($issued['refresh_token']));
        if ($successor !== null) {
            $this->tokens->markRotated((int) $row['id'], (int) $successor['id']);
        }

        return $issued;
    }

    public function revokeRaw(string $rawToken, string $reason): void
    {
        $row = $this->tokens->findByHash(RefreshTokenRepository::hash($rawToken));

        if ($row !== null) {
            $this->tokens->revoke((int) $row['id'], $reason);
        }
    }

    public function revokeDeviceSessions(int $deviceId, string $reason): void
    {
        $this->tokens->revokeAllForDevice($deviceId, $reason);
    }

    /**
     * Cookie attributes are fixed by config, not by caller input.
     *
     * @return array<string,mixed>
     */
    public static function refreshCookieAttributes(): array
    {
        $c = Config::instance();
        $ttl = $c->int('security.refresh_ttl');

        return [
            'expires'  => time() + $ttl,
            'path'     => $c->str('security.cookie_path'),
            'secure'   => $c->bool('security.cookie_secure'),
            'httponly' => true,           // never configurable: see docs/CONTRACT.md
            'samesite' => $c->str('security.cookie_samesite'),
        ];
    }

    public static function refreshCookieName(): string
    {
        return Config::instance()->str('security.cookie_name');
    }
}
