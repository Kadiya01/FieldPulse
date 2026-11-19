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
 *   period_type 'WEEKLY' | 'MONTHLY', default WEEKLY
 *   period      'YYYY-MM-DD' period start (Monday for weekly, 1st for monthly),
 *               defaulting to the current period
 *   site_id     optional; narrows the board to agents assigned to that site
 *   limit       1..100, default 25
 *   offset      >= 0
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

        $periodType = $this->resolvePeriodType($request);
        $period     = $this->resolvePeriod($request, $periodType);
        $siteId     = $this->resolveSiteId($request, $context->agentId());
        $limit      = $this->clampInt($request->queryInt('limit'), 25, 1, 100);
        $offset     = $this->clampInt($request->queryInt('offset'), 0, 0, 10000);

        $board = $this->leaderboard->board($period, $limit, $offset, $siteId, $context->agentId(), $periodType);

        return Response::json([
            'data' => [
                'period_type'       => $periodType,
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
                'period_type'       => $periodType,
                'available_periods' => $this->leaderboard->availablePeriods(12, $periodType),
                'available_sites'   => $this->leaderboard->sitesForAgent($context->agentId()),
            ],
        ]);
    }

    private function resolvePeriodType(Request $request): string
    {
        $type = $request->query('period_type');

        if ($type === null || $type === '') {
            return PeriodResolver::TYPE_WEEKLY;
        }

        $type = strtoupper((string) $type);

        if (!in_array($type, PeriodResolver::PERIOD_TYPES, true)) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'period_type must be WEEKLY or MONTHLY.',
                ['field' => 'period_type', 'allowed' => PeriodResolver::PERIOD_TYPES]
            );
        }

        return $type;
    }

    /**
     * An explicit period must be a real period start date (Monday for weekly, 1st of month for monthly).
     */
    private function resolvePeriod(Request $request, string $periodType): string
    {
        $requested = $request->query('period');

        if ($requested === null || $requested === '') {
            return $periodType === PeriodResolver::TYPE_MONTHLY
                ? PeriodResolver::monthlyPeriodStartDate()
                : PeriodResolver::periodStartDate();
        }

        if (!Validator::isIsoDate($requested)) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'period must be an ISO date (YYYY-MM-DD).',
                ['field' => 'period']
            );
        }

        $tz       = \FieldPulse\Config\Config::instance()->businessTimezone();
        $local    = new \DateTimeImmutable($requested . ' 00:00:00', $tz);
        $expected = PeriodResolver::periodStartDateForType(
            $periodType,
            \FieldPulse\Support\Clock::sql($local)
        );

        if ($expected !== $requested) {
            $message = $periodType === PeriodResolver::TYPE_MONTHLY
                ? 'period must be the first day of the month (YYYY-MM-01).'
                : 'period must be the first day of the week.';

            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                $message,
                ['field' => 'period', 'expected' => $expected]
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
