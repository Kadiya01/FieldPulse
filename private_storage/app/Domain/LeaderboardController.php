<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET /api/v1/leaderboard
 *
 * Query parameters:
 *   period   'YYYY-MM-DD' Monday of the week, defaulting to the current period
 *   site_id  optional; narrows the board to agents assigned to that site
 *   limit    1..100, default 25
 *   offset   >= 0
 *
 * Read-only and bearer-authenticated, not device-signed: signing a GET adds no
 * security, since there is no state to protect and the response is not a
 * credential. Device signing is reserved for requests that create something.
 *
 * Ranking is by VERIFIED count only. Pending submissions are shown as a
 * tiebreak for ordering but never as a score, because an agent must not be able
 * to inflate their standing by submitting work that has not been checked.
 */
final class LeaderboardController implements ActionInterface
{
    public function __construct(
        private readonly LeaderboardRepository $leaderboard = new LeaderboardRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $period = $this->resolvePeriod($request);
        $siteId = $this->resolveSiteId($request, $context->agentId());
        $limit  = $this->clampInt($request->queryInt('limit'), 25, 1, 100);
        $offset = $this->clampInt($request->queryInt('offset'), 0, 0, 10000);

        $board = $this->leaderboard->board($period, $limit, $offset, $siteId, $context->agentId());

        return Response::json([
            'data' => [
                'period_start_date' => $period,
                'scope'             => $siteId === null ? 'GLOBAL' : 'SITE',
                'site_id'           => $siteId,
                'entries'           => $board['entries'],
                'pagination'        => [
                    'total'  => $board['total'],
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                'you'               => $board['self'],
            ],
            'meta' => [
                'available_periods' => $this->leaderboard->availablePeriods(),
                'available_sites'   => $this->leaderboard->sitesForAgent($context->agentId()),
            ],
        ]);
    }

    /**
     * An explicit period must be a real Monday. Accepting any date and silently
     * snapping it would make a client's "week 42" cache key disagree with the
     * board it gets back, which is exactly the kind of ambiguity that turns
     * into a support ticket.
     */
    private function resolvePeriod(Request $request): string
    {
        $requested = $request->query('period');

        if ($requested === null || $requested === '') {
            return PeriodResolver::periodStartDate();
        }

        if (!Validator::isIsoDate($requested)) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'period must be an ISO date (YYYY-MM-DD).',
                ['field' => 'period']
            );
        }

        $monday = (new \DateTimeImmutable($requested . ' 00:00:00', \FieldPulse\Config\Config::instance()->businessTimezone()))
            ->modify('monday this week')
            ->format('Y-m-d');

        if ($monday !== $requested) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'period must be the Monday of the week.',
                ['field' => 'period', 'expected' => $monday]
            );
        }

        return $requested;
    }

    /**
     * An agent may only scope the board to a site they are actually assigned to.
     * Otherwise the filter is an enumeration tool for discovering other agents'
     * site assignments.
     */
    private function resolveSiteId(Request $request, int $agentId): ?int
    {
        $siteId = $request->queryInt('site_id');

        if ($siteId === null) {
            return null;
        }

        if ($siteId <= 0) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'site_id must be a positive integer.',
                ['field' => 'site_id']
            );
        }

        $assigned = false;

        foreach ($this->leaderboard->sitesForAgent($agentId) as $site) {
            if ($site['id'] === $siteId) {
                $assigned = true;
                break;
            }
        }

        if (!$assigned) {
            throw new ApiException(
                404,
                ErrorCode::UNKNOWN_SITE,
                'That site is not assigned to you.'
            );
        }

        return $siteId;
    }

    private function clampInt(?int $value, int $default, int $min, int $max): int
    {
        if ($value === null) {
            return $default;
        }

        return max($min, min($max, $value));
    }
}
