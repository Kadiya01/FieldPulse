<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/auth/refresh.php */
final class AbhAgentRefreshController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $raw = $_COOKIE[AbhAgentAuthCookie::NAME] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'No ABH refresh session was presented.')
                ->withCookie(AbhAgentAuthCookie::cleared());
        }

        try {
            $tokens = AbhAuthRepository::rotate($raw);
        } catch (ApiException $error) {
            throw $error->withCookie(AbhAgentAuthCookie::cleared());
        }

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
