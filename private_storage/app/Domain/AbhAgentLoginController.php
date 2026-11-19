<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\AbhAuthRateLimiter;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\SignatureVerifier;
use FieldPulse\Support\Clock;

/**
 * POST /api/v1/abh/auth/login.php
 *
 * The agent proves three independent things: knowledge of the password,
 * possession of the registered browser key, and approval of both account and
 * device by an administrator. IMEI is never used as a login secret.
 */
final class AbhAgentLoginController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys(
            $body,
            ['phone', 'password', 'device_uuid', 'timestamp', 'nonce', 'signature'],
            'ABH sign in'
        );

        $phoneRaw = trim(Validator::string($body['phone'] ?? null, 7, 32, 'phone'));
        $phone = Validator::nigerianPhone($phoneRaw);
        $password = Validator::password($body['password'] ?? null);
        $deviceUuid = Validator::string($body['device_uuid'] ?? null, 36, 36, 'device_uuid');

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceUuid) !== 1) {
            throw ApiException::validation('This browser device identity is invalid.', ['field' => 'device_uuid']);
        }

        $timestamp = filter_var($body['timestamp'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($timestamp) || $timestamp < 1000000000 || $timestamp > 9999999999) {
            throw ApiException::validation('A valid sign-in timestamp is required.', ['field' => 'timestamp']);
        }

        $nonce = Validator::string($body['nonce'] ?? null, 22, 128, 'nonce');
        $signature = Validator::string($body['signature'] ?? null, 80, 180, 'signature');

        AbhAuthRateLimiter::recordAndEnforce($phone, $request->clientIp());

        $identity = AbhAuthRepository::findLoginIdentity($phone, $deviceUuid);

        // Unknown phone/device combinations perform the same password-hash
        // verification work as a real account, and share one generic response.
        // Do the bcrypt comparison even when the phone/device lookup misses.
        // Credentials::verify() performs a decoy comparison for unknown users.
        $passwordValid = Credentials::verify($password, $identity['password_hash'] ?? null);
        if ($identity === null || !$passwordValid) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Phone number, password or registered device is incorrect.');
        }

        if ((string) $identity['agent_status'] !== 'ACTIVE') {
            $message = match ((string) $identity['agent_status']) {
                'PENDING' => 'Your application is awaiting administrator approval.',
                'REJECTED' => 'Your application was not approved. Contact the administrator.',
                'SUSPENDED' => 'Your account is suspended. Contact the administrator.',
                default => 'This account is not active.',
            };
            throw new ApiException(403, ErrorCode::AGENT_INACTIVE, $message);
        }

        if ((string) $identity['device_status'] !== 'ACTIVE') {
            throw new ApiException(
                403,
                ErrorCode::DEVICE_NOT_ACTIVE,
                'This registered device is awaiting approval or has been revoked.'
            );
        }

        $jwk = $identity['public_key_jwk'] ?? null;
        if (is_string($jwk)) {
            $jwk = json_decode($jwk, true);
        }
        if (!is_array($jwk)) {
            throw new ApiException(401, ErrorCode::DEVICE_KEY_INVALID, 'The registered device key is unavailable.');
        }

        $jwk = Jwk::validatePublicJwk($jwk);

        \FieldPulse\Security\CanonicalPayload::assertFreshTimestamp(
            $timestamp,
            Clock::timestamp(),
            \FieldPulse\Config\Config::instance()->int('security.clock_skew')
        );

        $proof = implode("\n", [
            'ABH_LOGIN',
            $phoneRaw,
            $deviceUuid,
            (string) $timestamp,
            $nonce,
        ]);
        SignatureVerifier::assertProofOfPossession($jwk, $proof, $signature);

        if (!AbhAuthRepository::consumeNonce((int) $identity['device_id'], $nonce)) {
            throw new ApiException(401, ErrorCode::REPLAY_DETECTED, 'This sign-in proof has already been used.');
        }

        $tokens = AbhAuthRepository::issueSession($identity);

        return Response::json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'device_bound' => true,
            'agent' => $tokens['agent'],
        ])->withCookie([
            'name' => AbhAgentAuthCookie::NAME,
            'value' => $tokens['refresh_token'],
            'expires' => strtotime($tokens['refresh_expires_at'] . ' UTC') ?: (time() + 86400),
            'path' => AbhAgentAuthCookie::PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
