<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\RateLimiter;

/**
 * Authenticated password change.
 *
 * The caller is already a device-bound agent, so this route can say that the
 * current password was wrong without becoming an account oracle. Requiring the
 * current password means possession of a live session is not enough to take
 * the account over permanently: an attacker who walks up to an unlocked handset
 * still cannot change the credential they would need after the session expires.
 */
final class PasswordController implements ActionInterface
{
    private const ENDPOINT = 'auth.password';

    /** Matches LoginController's limit: long enough for real passwords, bounded. */
    private const CURRENT_PASSWORD_MAX = 200;

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['current_password', 'new_password'], 'auth.password');

        $current = Validator::string(
            $body['current_password'] ?? null,
            1,
            self::CURRENT_PASSWORD_MAX,
            'current_password'
        );
        $new = Validator::password($body['new_password'] ?? null, 'new_password');

        $context = $request->requireAuth();
        $agentId = $context->agentId();
        $ip = $request->clientIp();

        $limits = RateLimiter::limitsFor(self::ENDPOINT);
        RateLimiter::enforce(self::ENDPOINT, (string) $agentId, $ip, $limits['limit'], $limits['window']);

        /*
         * Read the credential from the repository rather than carrying the hash
         * on every authenticated request. AuthContext is built for every route,
         * and a password hash has no business travelling in a value controllers
         * may log or serialise.
         */
        $agent = $this->agents->findById($agentId);
        $hash = is_array($agent) && is_string($agent['password_hash'] ?? null)
            ? $agent['password_hash']
            : null;

        if (!Credentials::verify($current, $hash)) {
            RateLimiter::record(self::ENDPOINT, (string) $agentId, $ip, false, 'CURRENT_PASSWORD_INCORRECT');

            throw ApiException::validation(
                'The current password is incorrect.',
                ['field' => 'current_password']
            );
        }

        $username = is_array($agent) ? ($agent['username'] ?? null) : null;

        if (!is_string($username) || $username === '') {
            // Authenticated accounts always have a username; this guards a
            // damaged row rather than a client mistake.
            throw ApiException::conflict(
                ErrorCode::STATE_CONFLICT,
                'This account has no username to keep.'
            );
        }

        $this->agents->setCredentials($agentId, $username, Credentials::hash($new));

        $this->audit->recordSafe([
            'actor_agent_id' => $agentId,
            'action' => 'auth.password_changed',
            'entity_type' => 'agent',
            'entity_id' => $agentId,
            'ip_address' => $ip,
            'metadata' => ['username' => $username],
        ]);

        RateLimiter::record(self::ENDPOINT, (string) $agentId, $ip, true);

        return Response::json(['status' => 'CHANGED']);
    }
}
