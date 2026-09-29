<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\TokenService;

/**
 * POST /api/v1/auth/refresh.php
 *
 * Cookie-authenticated: the refresh token arrives in an HttpOnly cookie, which
 * is the one place in this API where the browser attaches a credential
 * automatically. That makes this the only CSRF-exposed endpoint, and it is
 * protected by the Origin equality check in Http\Kernel plus SameSite=Strict.
 *
 * Tokens rotate on every call. Replaying a rotated token revokes the entire
 * family and the device — see Security\TokenService::rotate().
 */
final class RefreshController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $cookieName = TokenService::refreshCookieName();
        $raw        = $_COOKIE[$cookieName] ?? null;

        if (!is_string($raw) || $raw === '') {
            throw new ApiException(401, ErrorCode::REFRESH_INVALID, 'No refresh token was presented.');
        }

        try {
            $tokens = TokenService::i()->rotate($raw, $request->userAgent());
        } catch (ApiException) {
            // Whatever went wrong, the client must end up holding no cookie.
            throw self::expired()->withCookie(self::clearedCookie());
        }

        return Response::json([
            'access_token'        => $tokens['access_token'],
            'token_type'          => $tokens['token_type'],
            'expires_in'          => $tokens['expires_in'],
            'refresh_expires_at'  => $tokens['refresh_expires_at'],
        ])->withCookie(array_merge(
            [
                'name'  => $cookieName,
                'value' => $tokens['refresh_token'],
            ],
            TokenService::refreshCookieAttributes()
        ));
    }

    /**
     * A failed refresh always clears the browser cookie, so a revoked or stolen
     * session does not persist in the client and retry forever.
     */
    public static function expired(): ApiException
    {
        return new ApiException(401, ErrorCode::REFRESH_INVALID, 'Refresh token is not valid.');
    }

    public static function clearedCookie(): array
    {
        $c = \FieldPulse\Config\Config::instance();

        return Response::expiredRefreshCookie(
            TokenService::refreshCookieName(),
            $c->str('security.cookie_path'),
            $c->bool('security.cookie_secure'),
            $c->str('security.cookie_samesite')
        );
    }
}
