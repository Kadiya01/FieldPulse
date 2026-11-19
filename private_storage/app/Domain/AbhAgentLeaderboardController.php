<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhLeaderboardRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET /api/v1/abh/agents/leaderboard.php */
final class AbhAgentLeaderboardController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $agent = $request->requireAuth();
        $month = Validator::periodMonth(
            $request->query('month') ?? (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Lagos')))->format('Y-m'),
            'month',
            true
        );
        $limit = $request->queryInt('limit', 25) ?? 25;
        $offset = $request->queryInt('offset', 0) ?? 0;
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw ApiException::validation('Invalid pagination values.', ['field' => 'limit']);
        }

        return Response::json([
            'data' => AbhLeaderboardRepository::getForMonth(
                substr($month, 0, 7),
                $agent->agentId(),
                $limit,
                $offset
            ),
        ]);
    }
}
