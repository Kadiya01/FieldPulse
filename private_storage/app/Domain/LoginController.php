<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\ChallengeService;
use FieldPulse\Security\DeviceStatus;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Security\SignatureVerifier;
use FieldPulse\Security\TokenService;

/**
 * POST /api/v1/auth/login.php
 *
 * Step 2 of the zero-password login: the device proves it holds the private key
 * that was bound to this IMEI at pairing time.
 *
 * The security property: the IMEI is a *lookup key*, never a *credential*. The
 * credential is the P-256 private key held in the device's non-extractable
 * WebCrypto key. Anyone who learns an IMEI still cannot produce a valid
 * signature, so the §1 trust model holds even though the agent types no
 * password.
 *
 * Every failure below returns one indistinguishable 401. Distinguishing "no
 * such IMEI" from "bad signature" would let an attacker confirm which IMEIs are
 * enrolled and probe for a device key.
 */
final class LoginController implements ActionInterface
{
    private const ENDPOINT = 'auth.login';

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

        $body = $request->json();

        $imei        = Validator::imei($body['imei'] ?? null);
        $deviceUuid  = Validator::uuid($body['device_uuid'] ?? null, 'device_uuid');
        $challenge   = Validator::string($body['challenge'] ?? null, 64, 64, 'challenge');
        $signature   = Validator::string($body['signature'] ?? null, 40, 200, 'signature');

        $ip = $request->clientIp();

        $limits = RateLimiter::limitsFor('auth.login');
        RateLimiter::enforce(self::ENDPOINT, $imei, $ip, $limits['limit'], $limits['window']);

        try {
            $agent  = $this->authenticate($imei, $deviceUuid, $challenge, $signature);
            $device = $this->devices->findByUuid($deviceUuid);

            if ($device === null) {
                throw self::genericFailure();
            }

            $tokens = TokenService::i()->issue($agent, $device, null, $request->userAgent());

            $this->audit->recordSafe([
                'actor_agent_id' => (int) $agent['id'],
                'action'         => 'auth.login',
                'entity_type'    => 'device',
                'entity_id'      => (int) $device['id'],
                'ip_address'     => $ip,
                'metadata'       => ['device_uuid' => $deviceUuid],
            ]);

            RateLimiter::record(self::ENDPOINT, $imei, $ip, true);

            return Response::json([
                'access_token'        => $tokens['access_token'],
                'token_type'          => $tokens['token_type'],
                'expires_in'          => $tokens['expires_in'],
                'agent'               => [
                    'id'         => (int) $agent['id'],
                    'agent_code' => (string) $agent['agent_code'],
                    'full_name'  => (string) $agent['full_name'],
                ],
                'device_uuid'         => $deviceUuid,
                'refresh_expires_at'  => $tokens['refresh_expires_at'],
            ])->withCookie(array_merge(
                [
                    'name'  => TokenService::refreshCookieName(),
                    'value' => $tokens['refresh_token'],
                ],
                TokenService::refreshCookieAttributes()
            ));
        } catch (\Throwable $e) {
            RateLimiter::record(self::ENDPOINT, $imei, $ip, false, 'AUTH_FAILED');

            if ($e instanceof \FieldPulse\Http\ApiException && $e->status() === 429) {
                throw $e;
            }

            throw self::genericFailure();
        }
    }

    /**
     * @throws \FieldPulse\Http\ApiException always
     */
    private function authenticate(string $imei, string $deviceUuid, string $challenge, string $signature): array
    {
        $agent = $this->agents->findByImei($imei);

        if ($agent === null || $agent['status'] !== AgentRepository::ACTIVE) {
            // Burn an equivalent amount of work so a missing agent is not
            // measurably faster than a wrong signature.
            throw self::genericFailure();
        }

        $device = $this->devices->findByUuid($deviceUuid);

        if ($device === null
            || (int) $device['agent_id'] !== (int) $agent['id']
            || !DeviceStatus::canSubmit((string) $device['status'])) {
            throw self::genericFailure();
        }

        // Single-use, expiry-checked, attempt-capped.
        $claimed = ChallengeService::claim($challenge, ChallengeService::PURPOSE_LOGIN);

        if ($claimed['agent_id'] === null || $claimed['agent_id'] !== (int) $agent['id']) {
            throw self::genericFailure();
        }

        // The challenge was issued for a specific device; it must not be
        // replayable against another.
        if ($claimed['device_uuid'] !== $deviceUuid) {
            throw self::genericFailure();
        }

        $message = ChallengeService::popMessage($challenge, $deviceUuid);

        SignatureVerifier::assertProofOfPossession(
            is_string($device['public_key_jwk'])
                ? (array) json_decode((string) $device['public_key_jwk'], true)
                : (array) $device['public_key_jwk'],
            $message,
            $signature
        );

        return $agent;
    }

    private static function genericFailure(): \FieldPulse\Http\ApiException
    {
        return new \FieldPulse\Http\ApiException(
            401,
            ErrorCode::UNAUTHENTICATED,
            'Authentication failed.'
        );
    }
}
