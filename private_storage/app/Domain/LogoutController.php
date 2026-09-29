<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\TokenService;

/**
 * POST /api/v1/auth/logout.php
 *
 * Requires both the access token (so the audit trail knows WHO logged out) and
 * the refresh cookie (so the server-side session actually ends).
 *
 * Note what logout does NOT do: it cannot invalidate the access token, which is
 * a stateless JWT. That is not a gap in this flow — every authenticated request
 * re-reads the device row, so revoking the device or suspending the agent is
 * the actual kill switch, and it takes effect immediately. Logout ends the
 * long-lived session so a stolen cookie is worthless after the fact.
 */
final class LogoutController implements ActionInterface
{
    public function __construct(
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $cookieName = TokenService::refreshCookieName();
        $raw        = $_COOKIE[$cookieName] ?? null;

        if (is_string($raw) && $raw !== '') {
            TokenService::i()->revokeRaw($raw, 'LOGOUT');
        }

        // Identify the actor when a token happens to be present, but never
        // require it: an expired access token must still be able to end a
        // session, otherwise an agent with a dead token can never log out.
        $agentId = null;
        $token   = $request->bearerToken();

        if ($token !== null) {
            $claims = \FieldPulse\Security\Jwt::decodeWithoutVerification($token);
            $sub    = $claims['sub'] ?? null;

            if (is_string($sub) && ctype_digit($sub)) {
                $agentId = (int) $sub;
            }
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $agentId,
            'action'         => 'auth.logout',
            'ip_address'     => $request->clientIp(),
        ]);

        return Response::json([
            'status'  => 'LOGGED_OUT',
            'message' => 'Session ended.',
        ])->withCookie(RefreshController::clearedCookie());
    }
}
