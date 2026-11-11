<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * Retired IMEI login endpoint.
 *
 * This route used to exchange an IMEI for a bootstrap session. That is exactly
 * the model Phase 5 removes: an IMEI is printed on the handset and the box, is
 * reused between owners, and is not a secret. It identifies hardware, never a
 * person, so it cannot authenticate one.
 *
 * The route is kept as a tombstone so older clients get a clear refusal rather
 * than a 404 that looks like a deployment fault. It reads no request body and
 * can never issue a token. Username/password login lives at auth.login.
 */
final class AgentLoginController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        throw new ApiException(
            401,
            ErrorCode::UNAUTHENTICATED,
            'Authentication failed. Sign in with your username and password.'
        );
    }
}
