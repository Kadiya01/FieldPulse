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
     * Mint a *bootstrap* session: an agent with no device bound.
     *
     * Login is pure credentials, so at that moment the client provably has no
     * device — its key pair is generated in the browser and registered
     * afterwards. A session that cannot be represented is a login response with
     * nowhere to put its refresh cookie, so bootstrap sessions exist. They are
     * deliberately weak: bound to no device, they cannot produce a valid device
     * signature, and Security\Authenticator admits them only to the bearer-only
     * bootstrap route. DeviceController replaces them on completion.
     *
     * @param  array<string,mixed> $agent
     * @return array{access_token:string,refresh_token:string,refresh_expires_at:string,expires_in:int,token_type:string,device_bound:bool}
     */
    public function issueBootstrap(array $agent, ?string $userAgent = null): array
    {
        $c = Config::instance();

        $accessToken = Jwt::issue([
            'agent_id'    => (int) $agent['id'],
            'agent_code'  => (string) $agent['agent_code'],
            'device_uuid' => null,
            'scope'       => 'bootstrap',
        ]);

        $rawRefresh     = Str::base64UrlEncode(random_bytes(48));
        $family         = Uuid::v4();
        $refreshExpires = RefreshTokenRepository::expiryUtc($c->int('security.refresh_ttl'));

        $this->tokens->create(
            (int) $agent['id'],
            null,
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
            'device_bound'      => false,
        ];
    }

    /**
     * Mint an access token and a refresh token for an agent/device pair.
     *
     * @param  array<string,mixed> $agent
     * @param  array<string,mixed> $device
     * @return array{access_token:string,refresh_token:string,refresh_expires_at:string,expires_in:int,token_type:string,device_bound:bool}
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
            'device_bound'      => true,
        ];
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * @return array{access_token:string,refresh_token:string,refresh_expires_at:string,expires_in:int,token_type:string,device_bound:bool}
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

            /*
             * Only revoke the *device* when the token was ever bound to one.
             * A bootstrap session has no device, and (int) null is 0, so the
             * unguarded form would try to revoke device 0 — a row that either
             * does not exist or, worse, belongs to somebody.
             */
            if ($row['device_id'] !== null) {
                $this->devices->setStatus((int) $row['device_id'], DeviceStatus::REVOKED);
            }

            $this->audit->record([
                'actor_agent_id' => (int) $row['agent_id'],
                'action'         => 'auth.refresh_reuse_detected',
                'entity_type'    => 'device',
                'entity_id'      => $row['device_id'] === null ? null : (int) $row['device_id'],
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
        $device = $row['device_id'] === null ? null : $this->devices->findById((int) $row['device_id']);

        if ($agent === null) {
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'Refresh token is no longer valid.');
        }

        // Re-check the agent state on refresh: an agent suspended between login
        // and refresh must not be able to extend its session.
        if ($agent['status'] !== AgentRepository::ACTIVE) {
            $this->tokens->revokeFamily((string) $row['token_family'], 'AGENT_INACTIVE');
            throw new ApiException(403, ErrorCode::AGENT_INACTIVE, 'This agent is not active.');
        }

        /*
         * A bootstrap refresh token belongs to a session that never completed
         * device registration — the page reloaded between login and the
         * registration call. Rotating it yields another bootstrap session, so
         * the client can still finish registering. It is not an error, and it
         * is not a licence to skip registration: a bootstrap token cannot
         * produce a device signature, so it cannot reach any signed route.
         */
        if ($device === null) {
            $issued = $this->issueBootstrap($agent, $userAgent);
            $this->linkSuccessor((int) $row['id'], $issued['refresh_token']);

            return $issued;
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

        $this->linkSuccessor((int) $row['id'], $issued['refresh_token']);

        return $issued;
    }

    /**
     * Point a rotated token at its replacement.
     *
     * The link is what makes later presentation of the *old* token detectable as
     * reuse rather than as an unknown token, which is the difference between
     * revoking a family and doing nothing.
     */
    private function linkSuccessor(int $previousId, string $successorRaw): void
    {
        $successor = $this->tokens->findByHash(RefreshTokenRepository::hash($successorRaw));

        if ($successor !== null) {
            $this->tokens->markRotated($previousId, (int) $successor['id']);
        }
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
     * Retire the bootstrap sessions for an agent, once a device is bound.
     *
     * @return int Number of sessions revoked.
     */
    public function revokeBootstrapSessions(int $agentId, string $reason): int
    {
        return $this->tokens->revokeUnboundForAgent($agentId, $reason);
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
