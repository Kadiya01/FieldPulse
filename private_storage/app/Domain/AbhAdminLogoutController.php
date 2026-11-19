<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/auth/logout.php */
final class AbhAdminLogoutController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $raw = $_COOKIE[AbhAdminAuthCookie::NAME] ?? null;
        if (is_string($raw) && $raw !== '') {
            AbhAdminAuthRepository::revokeRefresh($raw);
        }

        return Response::json(['logged_out' => true])
            ->withCookie(AbhAdminAuthCookie::cleared());
    }
}
