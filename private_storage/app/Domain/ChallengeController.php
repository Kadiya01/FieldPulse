<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\ChallengeService;
use FieldPulse\Security\DeviceStatus;
use FieldPulse\Security\RateLimiter;

/**
 * POST /api/v1/auth/challenge.php
 *
 * Step 1 of the zero-password login. The agent supplies an IMEI; the server
 * returns a single-use challenge and the exact bytes to sign.
 *
 * Anti-enumeration: the response is byte-for-byte the same shape whether or not
 * the IMEI belongs to a real agent. An unknown IMEI still gets a stored,
 * expiring challenge whose agent_id is NULL, so the endpoint cannot be used to
 * harvest the set of enrolled IMEIs, and login fails later with a generic
 * credential error.
 */
final class ChallengeController implements ActionInterface
{
    private const ENDPOINT = 'auth.challenge';

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $body = $request->json();
        $imei = Validator::imei($body['imei'] ?? null);
        $ip   = $request->clientIp();

        // LOGIN binds to the agent's existing device. REGISTER binds to the
        // device_uuid the client is about to claim, which it must therefore
        // supply up front. Domain separation between the two purposes stops a
        // signature minted for one flow being replayed into the other.
        $purpose = ($body['purpose'] ?? ChallengeService::PURPOSE_LOGIN) === ChallengeService::PURPOSE_REGISTER
            ? ChallengeService::PURPOSE_REGISTER
            : ChallengeService::PURPOSE_LOGIN;

        $deviceUuid = null;

        if ($purpose === ChallengeService::PURPOSE_REGISTER) {
            $deviceUuid = Validator::uuid($body['device_uuid'] ?? null, 'device_uuid');
        }

        $limits = RateLimiter::limitsFor('auth.login');
        RateLimiter::enforce(self::ENDPOINT, $imei, $ip, $limits['limit'], $limits['window']);

        $agent  = $this->agents->findByImei($imei);
        $device = null;

        if ($agent !== null && $purpose === ChallengeService::PURPOSE_LOGIN) {
            $device = $this->resolveDevice($agent);
        }

        $challenge = ChallengeService::issue(
            $agent === null ? null : (int) $agent['id'],
            $device['device_uuid'] ?? $deviceUuid ?? ChallengeService::newDeviceUuid(),
            $purpose
        );

        RateLimiter::record(self::ENDPOINT, $imei, $ip, true);

        return Response::json([
            'challenge'    => $challenge['challenge'],
            'sign_payload' => $challenge['payload'],
            'purpose'      => $purpose,
            'expires_at'   => $challenge['expires_at'],
            'expires_in'   => $challenge['expires_in'],
            'algorithm'    => 'ES256',
        ]);
    }

    /**
     * Which device should the challenge be bound to?
     *
     * The device_uuid is part of the signed message, so the server must decide
     * it rather than accept a client-supplied value: otherwise a client could
     * aim the challenge at a device whose key it does not hold. When the agent
     * has exactly one ACTIVE device that device is selected; otherwise the
     * client must disambiguate on the login call and the signature is verified
     * against whichever device row it names.
     *
     * @param  array<string,mixed> $agent
     * @return array<string,mixed>|null
     */
    private function resolveDevice(array $agent): ?array
    {
        $row = \FieldPulse\Database\Connection::fetchOne(
            'SELECT id, device_uuid, status FROM devices
              WHERE agent_id = :a AND status = :s
              ORDER BY last_seen_at DESC, id ASC LIMIT 1',
            ['a' => (int) $agent['id'], 's' => DeviceStatus::ACTIVE]
        );

        if ($row === null) {
            // No paired device: the agent must complete pairing before login.
            return null;
        }

        return ['id' => (int) $row['id'], 'device_uuid' => (string) $row['device_uuid']];
    }
}
