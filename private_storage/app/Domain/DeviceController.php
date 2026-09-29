<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\Authenticator;
use FieldPulse\Security\ChallengeService;
use FieldPulse\Security\DeviceStatus;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Security\SignatureVerifier;
use FieldPulse\Security\TokenService;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * POST /api/v1/device/register.php  — one-time device pairing.
 *
 * Why pairing exists
 * ------------------
 * The zero-password model is "IMEI identifies, device key proves". That only
 * holds if the key is bound to the agent BEFORE the first login. Allowing a
 * client to register its own public key during login would leave the IMEI as the
 * only secret: an attacker holding a stolen IMEI could generate a keypair, sign
 * the challenge with it, and the signature would verify perfectly while proving
 * nothing.
 *
 * So the one-time pairing factor is supplied by the operator:
 *
 *   bin/pair_device.php --code AG-001     -> prints a 10-digit code (30 min TTL)
 *   agent enters IMEI + pairing code      -> POST /api/v1/device/register.php
 *   every later login                     -> IMEI + device key only
 *
 * The pairing code is compared in constant time against a SHA-256 hash, and the
 * row is consumed atomically so it cannot be redeemed twice.
 *
 * A second device may be added either with a fresh pairing code or from a
 * handset that already holds a valid access token for this agent, which is how a
 * lost handset is replaced without waiting for an operator. See
 * authoriseRegistration() for why nothing weaker than that is accepted.
 */
final class DeviceController implements ActionInterface
{
    private const ENDPOINT = 'device.register';

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $body     = $request->json();
        $imei     = Validator::imei($body['imei'] ?? null);
        $publicJwkRaw = $body['public_key_jwk'] ?? null;
        $challenge    = Validator::string($body['challenge'] ?? null, 64, 64, 'challenge');
        $signature    = Validator::string($body['signature'] ?? null, 40, 200, 'signature');

        $deviceUuid = $body['device_uuid'] ?? null;
        $deviceUuid = is_string($deviceUuid) && $deviceUuid !== ''
            ? Validator::uuid($deviceUuid, 'device_uuid')
            : ChallengeService::newDeviceUuid();

        if (!is_array($publicJwkRaw)) {
            throw ApiException::validation('Field "public_key_jwk" must be a JWK object.', ['field' => 'public_key_jwk']);
        }

        $ip = $request->clientIp();

        $limits = RateLimiter::limitsFor('device.register');
        RateLimiter::enforce(self::ENDPOINT, $imei, $ip, $limits['limit'], $limits['window']);

        try {
            $agent = $this->agents->findByImei($imei);

            if ($agent === null || $agent['status'] !== AgentRepository::ACTIVE) {
                throw self::genericFailure();
            }

            // Reject a malformed or off-curve key BEFORE the pairing code is
            // consumed, so a client bug cannot burn a one-time code.
            $publicJwk = Jwk::validatePublicJwk($publicJwkRaw);

            $claimed = ChallengeService::claim($challenge, ChallengeService::PURPOSE_REGISTER);

            if ($claimed['agent_id'] === null || $claimed['agent_id'] !== (int) $agent['id']) {
                throw self::genericFailure();
            }

            // Proof of possession: the caller must hold the private key for the
            // key it is registering. Without this, anyone could register a
            // public key they also control for a victim agent.
            SignatureVerifier::assertProofOfPossession(
                $publicJwk,
                ChallengeService::popMessage($challenge, $deviceUuid),
                $signature
            );

            $authorisation = $this->authoriseRegistration($agent, $body, $ip, $request);

            // device_uuid is globally UNIQUE, so an attacker cannot pre-claim
            // another agent's slot: the insert fails on conflict and the generic
            // failure below hides the distinction.
            $deviceId = $this->devices->create(
                (int) $agent['id'],
                $deviceUuid,
                $publicJwk,
                $imei,
                DeviceStatus::ACTIVE
            );

            $code = $body['pairing_code'] ?? null;

            if ($authorisation === 'PAIRING_CODE' && is_string($code) && $code !== '') {
                $this->linkPairingCode((int) $agent['id'], $code, $deviceId);
            }

            $device = $this->devices->findById($deviceId);

            if ($device === null) {
                throw new \RuntimeException('Device row vanished immediately after insert.');
            }

            $tokens = TokenService::i()->issue($agent, $device, null, $request->userAgent());

            $this->audit->recordSafe([
                'actor_agent_id' => (int) $agent['id'],
                'action'         => 'device.registered',
                'entity_type'    => 'device',
                'entity_id'      => $deviceId,
                'ip_address'     => $ip,
                'metadata'       => [
                    'device_uuid'  => $deviceUuid,
                    'key_id'       => Jwk::thumbprint($publicJwk),
                ],
            ]);

            RateLimiter::record(self::ENDPOINT, $imei, $ip, true);

            return Response::json([
                'device_uuid'  => $deviceUuid,
                'status'       => DeviceStatus::ACTIVE,
                'access_token' => $tokens['access_token'],
                'token_type'   => $tokens['token_type'],
                'expires_in'   => $tokens['expires_in'],
                'agent'        => [
                    'id'         => (int) $agent['id'],
                    'agent_code' => (string) $agent['agent_code'],
                ],
            ], 201)->withCookie(array_merge(
                [
                    'name'  => TokenService::refreshCookieName(),
                    'value' => $tokens['refresh_token'],
                ],
                TokenService::refreshCookieAttributes()
            ));
        } catch (\Throwable $e) {
            RateLimiter::record(self::ENDPOINT, $imei, $ip, false, 'REGISTER_FAILED');

            if ($e instanceof ApiException && $e->status() === 429) {
                throw $e;
            }

            Logger::warning('device.register_failed', [
                'imei_hash' => \FieldPulse\Security\RateLimiter::hashIdentifier($imei),
                'reason'    => $e->getMessage(),
            ]);

            throw self::genericFailure();
        }
    }

    /**
     * Decide whether this registration attempt may bind a new public key.
     *
     * There are exactly two ways to authorise adding a device, and there is no
     * third:
     *
     *   1. An operator-issued pairing code bound to this agent.
     *   2. A valid, unexpired access token belonging to this same agent, i.e. a
     *      session already established from a previously paired handset.
     *
     * What is deliberately NOT accepted: the mere existence of another active
     * device for this agent. That check used to be here, and it was a hole —
     * anyone who learned an IMEI could register a keypair of their own against an
     * agent who was already enrolled, and from then on hold a valid signed
     * session. The IMEI is a lookup key, never a credential (§6), so it must not
     * be able to authorise anything.
     *
     * @param array<string,mixed> $agent
     * @param array<string,mixed> $body
     */
    private function authoriseRegistration(array $agent, array $body, string $ip, Request $request): ?string
    {
        $code = $body['pairing_code'] ?? null;

        if (ChallengeService::isValidPairingCode($code)) {
            // The UPDATE is the check: a pairing code is redeemable exactly once,
            // and concurrent redemptions cannot both succeed.
            $claimed = Connection::execute(
                'UPDATE pairing_codes
                    SET attempts = attempts + 1, consumed_at = UTC_TIMESTAMP()
                  WHERE code_hash = :hash
                    AND agent_id = :agent_id
                    AND consumed_at IS NULL
                    AND expires_at > :now
                    AND attempts < max_attempts',
                [
                    'hash'     => ChallengeService::hashPairingCode($code),
                    'agent_id' => (int) $agent['id'],
                    'now'      => Clock::sql(),
                ]
            );

            if ($claimed === 1) {
                return 'PAIRING_CODE';
            }

            Logger::warning('device.pairing_code_rejected', [
                'agent_id' => (int) $agent['id'],
                'ip'       => $ip,
            ]);

            throw self::genericFailure();
        }

        // Replacement flow: an existing session for this same agent is the second
        // factor. The token is verified for signature, expiry, and live agent
        // and device status by the authenticator; only then is the agent identity
        // compared, so a valid token for a *different* agent is useless here.
        if ($request->bearerToken() !== null) {
            try {
                $context = Authenticator::i()->authenticate($request);
            } catch (ApiException) {
                throw self::genericFailure();
            }

            if ($context->agentId() !== (int) $agent['id']) {
                Logger::warning('device.register_token_agent_mismatch', [
                    'token_agent_id' => $context->agentId(),
                    'imei_agent_id'  => (int) $agent['id'],
                    'ip'             => $ip,
                ]);

                throw self::genericFailure();
            }

            return 'ACCESS_TOKEN';
        }

        Logger::warning('device.register_unauthorised', [
            'agent_id'        => (int) $agent['id'],
            'ip'              => $ip,
            'has_pairing_code' => ChallengeService::isValidPairingCode($code),
            'has_bearer'      => $request->bearerToken() !== null,
        ]);

        throw ApiException::validation(
            'A 10-digit pairing code is required for first-time device setup.',
            ['field' => 'pairing_code']
        );
    }

    /**
     * Record which device consumed a pairing code, for the audit trail.
     *
     * Done after the device row exists, because the column is a foreign key to
     * devices and the device id is not known until then.
     */
    private function linkPairingCode(int $agentId, string $code, int $deviceId): void
    {
        Connection::execute(
            'UPDATE pairing_codes
                SET consumed_by_device_id = :device_id
              WHERE code_hash = :hash AND agent_id = :agent_id',
            [
                'device_id' => $deviceId,
                'hash'      => ChallengeService::hashPairingCode($code),
                'agent_id'  => $agentId,
            ]
        );
    }

    private static function genericFailure(): ApiException
    {
        return new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Device registration failed.');
    }
}
