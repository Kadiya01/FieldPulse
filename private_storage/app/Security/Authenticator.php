<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * Two-stage request authentication.
 *
 * Stage 1 — bearer token (who): the JWT signature is verified, then agent and
 *           device rows are re-read from the database and BOTH statuses are
 *           checked. A valid token is not authorisation: revoking a device or
 *           suspending an agent takes effect on the very next request, with no
 *           wait for the token to expire.
 *
 * Stage 2 — device signature (which device, and untampered): the ECDSA
 *           signature over the canonical string is verified against the key
 *           bound to that device row, and the nonce is burned.
 *
 * The order matters. Token first, because the token is what identifies which
 * device row to load; signature second, because the signature is meaningless
 * without that key. Both must pass.
 */
final class Authenticator
{
    private function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AgentRepository $agents = new AgentRepository(),
    ) {
    }

    private static ?self $instance = null;

    public static function i(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Stage 1.
     *
     * @throws ApiException
     */
    public function authenticate(Request $request): AuthContext
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new ApiException(
                401,
                ErrorCode::UNAUTHENTICATED,
                'A Bearer access token is required.'
            );
        }

        $claims = Jwt::verifyAndDecode($token);

        $agentId = (int) $claims['sub'];

        // The device must be identified by the device row, not merely by a
        // claim: the claim tells us which device to look up, the row decides.
        $pair = $this->devices->findAuthorisedPair((string) $claims['device_uuid']);

        if ($pair === null) {
            throw new ApiException(401, ErrorCode::UNKNOWN_DEVICE, 'Device is not registered.');
        }

        // Binding check: a token minted for device A must not be replayed
        // against device B, and a token's agent must match the device's owner.
        if ((int) $pair['device']['agent_id'] !== $agentId) {
            Logger::warning('auth.device_agent_mismatch', ['agent_id' => $agentId]);

            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Token is not valid for this device.');
        }

        $agentStatus = (string) $pair['agent']['status'];

        if ($agentStatus !== AgentRepository::ACTIVE) {
            throw new ApiException(
                403,
                ErrorCode::AGENT_INACTIVE,
                $agentStatus === AgentRepository::SUSPENDED
                    ? 'This account is suspended. Contact your supervisor.'
                    : 'This account is not active.'
            );
        }

        $deviceStatus = (string) $pair['device']['status'];

        if (!DeviceStatus::canSubmit($deviceStatus)) {
            throw new ApiException(
                403,
                DeviceStatus::denialCode($deviceStatus),
                $deviceStatus === DeviceStatus::REVOKED
                    ? 'This device has been revoked. Re-pairing is required.'
                    : 'This device is not authorised.'
            );
        }

        return new AuthContext($pair['agent'], $pair['device'], $claims);
    }

    /**
     * Stage 1 for a session that has no device bound yet.
     *
     * Login is pure credentials, so the client arrives at device registration
     * holding a bootstrap token: a real, correctly signed, unexpired token
     * whose `device_uuid` claim is null. This method admits exactly that, and
     * only that, to the registration route.
     *
     * What it deliberately does NOT do is skip any check. The agent row is
     * re-read and its status re-checked, so a suspended account cannot register
     * a device; the token's `scope` must be `bootstrap`, so a normal token
     * cannot be traded for a device binding it never needed; and the caller is
     * responsible for enforcing the pairing policy, which is a property of the
     * agent's device count and not of the token.
     *
     * The returned AuthContext carries a null device, so any code that reaches
     * for it must handle that. That is the point: a bootstrap session is
     * structurally incapable of signing anything, because there is no key.
     *
     * @throws ApiException
     */
    public function authenticateBootstrap(Request $request): AuthContext
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new ApiException(
                401,
                ErrorCode::UNAUTHENTICATED,
                'A Bearer access token is required.'
            );
        }

        $claims = Jwt::verifyAndDecode($token);

        if (($claims['scope'] ?? null) !== 'bootstrap') {
            throw new ApiException(
                401,
                ErrorCode::TOKEN_INVALID,
                'This token cannot be used to register a device.'
            );
        }

        $agent = $this->agents->findById((int) $claims['sub']);

        if ($agent === null) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'This token cannot be used to register a device.');
        }

        if ((string) $agent['status'] !== AgentRepository::ACTIVE) {
            throw new ApiException(
                403,
                ErrorCode::AGENT_INACTIVE,
                (string) $agent['status'] === AgentRepository::SUSPENDED
                    ? 'This account is suspended. Contact your supervisor.'
                    : 'This account is not active.'
            );
        }

        return new AuthContext($agent, null, $claims);
    }

    /**
     * Stage 2. Verifies the signature and consumes the nonce.
     *
     * Order within this stage: validate header shape -> clock skew -> signature
     * -> burn nonce. The nonce is burned last so that a request rejected for a
     * bad signature does not consume a nonce the client may legitimately reuse
     * after fixing the signature.
     *
     * @throws ApiException
     */
    public function verifySignedRequest(Request $request, AuthContext $context): void
    {
        $c = Config::instance();

        // The device UUID header must match the token's device. This is what
        // stops a captured access token from being used with a different device
        // key.
        $headerUuid = $request->requireHeader('X-Device-UUID', ErrorCode::UNKNOWN_DEVICE_UUID);

        if (!hash_equals($context->deviceUuid(), $headerUuid)) {
            throw new ApiException(
                401,
                ErrorCode::TOKEN_INVALID,
                'X-Device-UUID does not match the access token.'
            );
        }

        $rawTimestamp = $request->requireHeader('X-Request-Timestamp', ErrorCode::SIGNATURE_INVALID);
        $nonce        = $request->requireHeader('X-Request-Nonce', ErrorCode::SIGNATURE_INVALID);
        $signature    = $request->requireHeader('X-Request-Signature', ErrorCode::SIGNATURE_INVALID);

        if (preg_match('/^\d{10}$/', $rawTimestamp) !== 1) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed X-Request-Timestamp.');
        }

        CanonicalPayload::assertNonceFormat($nonce);
        CanonicalPayload::assertFreshTimestamp(
            (int) $rawTimestamp,
            Clock::timestamp(),
            $c->int('security.clock_skew')
        );

        // Only reached once the body is known; multipart throws here if the
        // signed `payload` field is absent.
        $signedBody = $request->signedBody();

        SignatureVerifier::verify(
            $context->device(),
            $request->method(),
            $request->path(),
            (int) $rawTimestamp,
            $nonce,
            $signedBody,
            $signature
        );

        NonceGuard::consume($context->deviceId(), $nonce, $request->path());
        NonceGuard::pruneOccasionally();

        SignatureVerifier::touchDevice($context->deviceId());
    }

    /**
     * Convenience: both stages.
     */
    public function authenticateSignedRequest(Request $request): AuthContext
    {
        $context = $this->authenticate($request);
        $this->verifySignedRequest($request, $context);

        return $context;
    }
}
