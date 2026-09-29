<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Config\Config;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\PairingCode;
use FieldPulse\Security\DeviceStatus;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Security\TokenService;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * The authenticated device bootstrap.
 *
 * Registration is a *second* step, not part of logging in. Login returns a
 * bootstrap session (see LoginController) bound to no device, and this route
 * trades that session plus a browser-generated key pair for a device-bound one.
 * Kernel::AUTH marks it 'bootstrap', which admits a bootstrap token and nothing
 * else; a bootstrap session has no key, so it cannot reach any signed route.
 *
 * The route is idempotent, and deliberately so. A returning agent who already
 * has this device registered calls it on every login and gets a fresh
 * device-bound token. That lets the client flow "log in, then register" be
 * unconditional, with no "am I already registered?" round trip to get wrong,
 * and it makes re-login the ordinary way to recover a session rather than a
 * special case needing its own code path.
 *
 * Whether a *new* device needs a pairing code is a configured policy, not a
 * hardcoded rule. See authoriseRegistration().
 *
 * IMEI is not accepted, read, or required anywhere in this flow. A device is
 * identified by a key pair the browser generated; the server stores the public
 * half and never sees the private one. The devices.imei column is left NULL and
 * exists only for the administrative pairing CLI.
 */
final class DeviceController implements ActionInterface
{
    private const ENDPOINT = 'device.register';

    private const POLICY_ALWAYS       = 'ALWAYS';
    private const POLICY_FIRST_DEVICE = 'FIRST_DEVICE_ONLY';
    private const POLICY_NEVER        = 'NEVER';

    private const POLICIES = [self::POLICY_ALWAYS, self::POLICY_FIRST_DEVICE, self::POLICY_NEVER];

    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $body = $request->json();

        // The Kernel already ran authenticateBootstrap() for this route.
        $context = $request->requireAuth();

        $deviceUuid  = Validator::uuid($body['device_uuid'] ?? null, 'device_uuid');
        $publicJwkRaw = $body['public_key_jwk'] ?? null;

        if (!is_array($publicJwkRaw)) {
            throw ApiException::validation(
                'Field "public_key_jwk" must be a JWK object.',
                ['field' => 'public_key_jwk']
            );
        }

        $ip     = $request->clientIp();
        $limits = RateLimiter::limitsFor(self::ENDPOINT);

        // Raw identifier, not a pre-hashed one: RateLimiter hashes internally.
        RateLimiter::enforce(self::ENDPOINT, $deviceUuid, $ip, $limits['limit'], $limits['window']);

        try {
            /*
             * Validate the key before anything is written or consumed, so a
             * client sending a malformed or off-curve key gets a 422 naming the
             * field rather than having burned a one-time pairing code on the way
             * to finding out.
             */
            $publicJwk = Jwk::validatePublicJwk($publicJwkRaw);

            $device = $this->devices->findByUuid($deviceUuid);

            if ($device !== null) {
                return $this->handleExisting($request, $context, $device, $publicJwk);
            }

            return $this->createDevice($request, $context, $deviceUuid, $publicJwk, $body);
        } catch (ApiException $e) {
            RateLimiter::record(self::ENDPOINT, $deviceUuid, $ip, false, $e->errorCode());

            throw $e;
        } catch (\Throwable $e) {
            RateLimiter::record(self::ENDPOINT, $deviceUuid, $ip, false, 'REGISTER_FAILED');

            Logger::channel('app')->warning('device.register_failed', [
                'device_uuid_hash' => RateLimiter::hashIdentifier($deviceUuid),
                'reason'           => $e->getMessage(),
            ]);

            throw self::genericFailure();
        }
    }

    /**
     * This device_uuid already exists. Re-bind it, or refuse.
     *
     * @param  array<string,mixed> $device
     * @param  array<string,mixed> $publicJwk
     */
    private function handleExisting(
        Request $request,
        \FieldPulse\Security\AuthContext $context,
        array $device,
        array $publicJwk
    ): Response {
        if ((int) $device['agent_id'] !== $context->agentId()) {
            /*
             * device_uuid is globally unique, so this is either a collision or a
             * probe for whether a given device is registered. The response is
             * deliberately identical to every other failure here: a distinct
             * "already taken" answer would confirm the device exists and turn
             * this route into a device-enumeration oracle.
             */
            Logger::channel('app')->warning('device.register_uuid_taken', [
                'device_uuid_hash' => RateLimiter::hashIdentifier((string) $device['device_uuid']),
            ]);

            throw self::genericFailure();
        }

        /*
         * The same agent re-registering the same device_uuid. The key must be
         * the one already bound: silently rebinding a device_uuid to a new key
         * would let anyone holding a session orphan a handset's identity, and
         * would leave the previous key's signatures unverifiable with no
         * explanation. The operator can still rotate a key, via
         * DeviceRepository::replaceKey() with a revoked-then-rebound device.
         */
        $storedJwk = json_decode((string) $device['public_key_jwk'], true);

        if (!is_array($storedJwk) || Jwk::thumbprint($storedJwk) !== Jwk::thumbprint($publicJwk)) {
            Logger::channel('app')->warning('device.register_key_mismatch', [
                'agent_id'        => $context->agentId(),
                'device_uuid_hash' => RateLimiter::hashIdentifier((string) $device['device_uuid']),
            ]);

            throw new ApiException(
                409,
                ErrorCode::DEVICE_KEY_INVALID,
                'This device is already registered with a different key.'
            );
        }

        if (!DeviceStatus::canSubmit((string) $device['status'])) {
            throw new ApiException(
                403,
                DeviceStatus::denialCode((string) $device['status']),
                (string) $device['status'] === DeviceStatus::REVOKED
                    ? 'This device has been revoked. Re-pairing is required.'
                    : 'This device is not authorised.'
            );
        }

        return $this->issueBoundSession($request, $context, $device, 'DEVICE_REBOUND');
    }

    /**
     * Register a device_uuid the server has never seen.
     *
     * @param array<string,mixed> $publicJwk
     * @param array<string,mixed> $body
     */
    private function createDevice(
        Request $request,
        \FieldPulse\Security\AuthContext $context,
        string $deviceUuid,
        array $publicJwk,
        array $body
    ): Response {
        $consumedCode = $this->authoriseRegistration($context, $body, $request->clientIp());

        /*
         * imei is NULL: the browser cannot read it and must not be asked to. It
         * is an administrative attribute, never an authentication input.
         */
        $deviceId = $this->devices->create(
            $context->agentId(),
            $deviceUuid,
            $publicJwk,
            null,
            DeviceStatus::ACTIVE
        );

        if ($consumedCode !== null) {
            Connection::execute(
                'UPDATE pairing_codes
                    SET consumed_by_device_id = :device_id
                  WHERE code_hash = :hash AND agent_id = :agent_id',
                [
                    'device_id' => $deviceId,
                    'hash'      => PairingCode::hash($consumedCode),
                    'agent_id' => $context->agentId(),
                ]
            );
        }

        $device = $this->devices->findById($deviceId);

        if ($device === null) {
            throw new \RuntimeException('Device row vanished immediately after insert.');
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'device.registered',
            'entity_type'    => 'device',
            'entity_id'      => $deviceId,
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'device_uuid_hash' => RateLimiter::hashIdentifier($deviceUuid),
                'key_thumbprint'   => Jwk::thumbprint($publicJwk),
                'pairing_policy'   => $this->policy(),
                'pairing_code_used' => $consumedCode !== null,
            ],
        ]);

        return $this->issueBoundSession($request, $context, $device, 'DEVICE_REGISTERED');
    }

    /**
     * Apply the configured pairing policy.
     *
     * @param  array<string,mixed> $body
     * @return string|null the pairing code consumed, or null if none was needed
     * @throws ApiException
     */
    private function authoriseRegistration(
        \FieldPulse\Security\AuthContext $context,
        array $body,
        string $ip
    ): ?string {
        $code = $body['pairing_code'] ?? null;
        $code = is_string($code) ? trim($code) : null;

        if ($this->codeRequired($context->agentId())) {
            if ($code === null || $code === '') {
                throw ApiException::validation(
                    'A pairing code is required to register a new device.',
                    ['field' => 'pairing_code']
                );
            }

            /*
             * The UPDATE is the check, not a SELECT followed by an UPDATE. A
             * pairing code is redeemable exactly once, so this makes two
             * concurrent redemptions of the same code impossible: the second
             * matches zero rows because consumed_at is no longer NULL.
             */
            $claimed = Connection::execute(
                'UPDATE pairing_codes
                    SET attempts = attempts + 1, consumed_at = UTC_TIMESTAMP()
                  WHERE code_hash = :hash
                    AND agent_id = :agent_id
                    AND consumed_at IS NULL
                    AND expires_at > :now
                    AND attempts < max_attempts',
                [
                    'hash'     => PairingCode::hash($code),
                    'agent_id' => $context->agentId(),
                    'now'      => Clock::sql(),
                ]
            );

            if ($claimed !== 1) {
                Logger::channel('app')->warning('device.pairing_code_rejected', [
                    'agent_id' => $context->agentId(),
                    'ip'       => $ip,
                ]);

                /*
                 * Wrong, expired, already-used and belonging-to-another-agent
                 * codes are all indistinguishable from here, deliberately: a
                 * distinct answer for "expired" versus "wrong" is a free oracle
                 * for confirming which codes exist.
                 */
                throw self::genericFailure();
            }

            return $code;
        }

        // Not required, so an unused code is discarded rather than burned.
        return null;
    }

    private function codeRequired(int $agentId): bool
    {
        return match ($this->policy()) {
            self::POLICY_ALWAYS => true,
            self::POLICY_NEVER  => false,
            // The first device is the one that needs an out-of-band code, since
            // after that the agent already has a device that can prove itself.
            self::POLICY_FIRST_DEVICE => !$this->hasActiveDevice($agentId),
        };
    }

    private function policy(): string
    {
        $policy = strtoupper(trim(Config::instance()->str('auth.device_pairing_policy')));

        /*
         * An unrecognised policy falls back to the strictest one, ALWAYS. The
         * alternative -- honouring an unknown value as permissive, or warning
         * and continuing -- would mean an operator who intended to require
         * pairing codes had silently disabled them because of a typo in .env.
         */
        return in_array($policy, self::POLICIES, true) ? $policy : self::POLICY_ALWAYS;
    }

    private function hasActiveDevice(int $agentId): bool
    {
        return (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM devices WHERE agent_id = :a AND status = :s',
            ['a' => $agentId, 's' => DeviceStatus::ACTIVE]
        ) > 0;
    }

    /**
     * Mint the device-bound session and hand the client a new refresh cookie.
     *
     * @param array<string,mixed> $device
     */
    private function issueBoundSession(
        Request $request,
        \FieldPulse\Security\AuthContext $context,
        array $device,
        string $auditAction
    ): Response {
        $tokens = TokenService::i()->issue($context->agent(), $device, null, $request->userAgent());

        /*
         * Spend the bootstrap session.
         *
         * Login is pure credentials, so until this point the agent could mint
         * bootstrap tokens indefinitely. Binding a device is the moment that
         * stops being true, and leaving the old family alive would mean a
         * captured bootstrap cookie could still enrol a second device long
         * after the user believed registration was finished. Revoked after the
         * bound token is issued, and scoped to device_id IS NULL, so the token
         * the client is about to receive is untouched.
         */
        $retired = TokenService::i()->revokeBootstrapSessions(
            (int) $context->agentId(),
            'BOOTSTRAP_CONSUMED'
        );

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => $auditAction,
            'entity_type'    => 'device',
            'entity_id'      => (int) $device['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => ['bootstrap_sessions_retired' => $retired],
        ]);

        RateLimiter::record(self::ENDPOINT, (string) $device['device_uuid'], $request->clientIp(), true);

        return Response::json([
            'device_uuid'        => (string) $device['device_uuid'],
            'status'             => (string) $device['status'],
            'device_bound'       => true,
            'access_token'       => $tokens['access_token'],
            'token_type'         => $tokens['token_type'],
            'expires_in'         => $tokens['expires_in'],
            'refresh_expires_at' => $tokens['refresh_expires_at'],
            'agent'              => [
                'id'         => $context->agentId(),
                'agent_code' => $context->agentCode(),
                'full_name'  => (string) ($context->agent()['full_name'] ?? ''),
            ],
        ])->withCookie(array_merge(
            [
                'name'  => TokenService::refreshCookieName(),
                'value' => $tokens['refresh_token'],
            ],
            TokenService::refreshCookieAttributes()
        ));
    }

    /**
     * One failure shape for every rejection here.
     *
     * Registration is a route an attacker can call at will, so the responses
     * must not distinguish "unknown device" from "device belongs to somebody
     * else" from "bad pairing code" from "agent suspended". Each of those is a
     * different fact about the system, and together they would let someone map
     * which device UUIDs are real and whose they are.
     */
    private static function genericFailure(): ApiException
    {
        return new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Device registration failed.');
    }
}
