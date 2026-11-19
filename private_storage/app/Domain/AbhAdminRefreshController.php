<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/auth/refresh.php */
final class AbhAdminRefreshController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $raw = $_COOKIE[AbhAdminAuthCookie::NAME] ?? null;
        if (!is_string($raw) || $raw === '') {
            $error = new ApiException(401, ErrorCode::REFRESH_INVALID, 'No ABH administrator refresh session was presented.');
            throw $error->withCookie(AbhAdminAuthCookie::cleared());
        }

        try {
            $tokens = AbhAdminAuthRepository::rotate($raw);
        } catch (ApiException $error) {
            throw $error->withCookie(AbhAdminAuthCookie::cleared());
        }

        return Response::json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'admin' => $tokens['admin'],
        ])->withCookie([
            'name' => AbhAdminAuthCookie::NAME,
            'value' => $tokens['refresh_token'],
            'expires' => strtotime($tokens['refresh_expires_at'] . ' UTC') ?: (time() + 86400),
            'path' => AbhAdminAuthCookie::PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
