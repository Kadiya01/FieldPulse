<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** Minimal public liveness/readiness probe; exposes no credentials or database identifiers. */
final class AbhHealthController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $database = Connection::fetchValue('SELECT 1');
        if ((int) $database !== 1) {
            throw new \RuntimeException('ABH database health check failed.');
        }

        return Response::json([
            'status' => 'ok',
            'application' => 'abh-monthly-enrollment',
            'database' => 'connected',
        ]);
    }
}
