<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/auth/logout.php */
final class AbhAgentLogoutController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $raw = $_COOKIE[AbhAgentAuthCookie::NAME] ?? null;
        if (is_string($raw) && $raw !== '') {
            AbhAuthRepository::revokeRefresh($raw);
        }

        return Response::json(['logged_out' => true])
            ->withCookie(AbhAgentAuthCookie::cleared());
    }
}
