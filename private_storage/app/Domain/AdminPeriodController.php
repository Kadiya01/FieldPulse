<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AdminDashboardRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\RewardRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Reward\RewardService;

/**
 * GET/POST /api/v1/admin/periods.php
 *
 * The management view of monthly (and weekly) closing history. GET reports the
 * same frozen flag, due decision and published counts the reward service uses;
 * it does not compute a second opinion. POST CLOSE delegates to
 * RewardService::closePeriod(), so closing from the admin screen has exactly
 * the same idempotency, grace-period and audit behaviour as the cron worker.
 *
 * A close request for a period that is not due returns 200 with closed=false
 * and reason=not_due. That is a valid answer to the request, not an error: an
 * administrator may click before the window has passed.
 */
final class AdminPeriodController implements ActionInterface
{
    private const FIELDS = ['period_type', 'period', 'period_start_date', 'action'];

    public function __construct(
        private readonly RewardService $rewardService = new RewardService(),
        private readonly RewardRepository $rewards = new RewardRepository(),
        private readonly AdminDashboardRepository $reports = new AdminDashboardRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $request->requireAuth();

        return $request->method() === 'POST'
            ? $this->close($request)
            : $this->index($request);
    }

    private function index(Request $request): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $type = $this->periodType($request->query('period_type'));
        $limit = $request->queryInt('limit', 12) ?? 12;
        $limit = max(1, min($limit, 48));

        $dates = $type === PeriodResolver::TYPE_MONTHLY
            ? PeriodResolver::monthlyPeriodRange($limit)
            : PeriodResolver::periodRange($limit);

        $rows = [];

        foreach ($dates as $date) {
            $totals = $this->reports->periodTotals($date, $type);
            $rows[] = [
                'period_type'       => $type,
                'period_start_date' => $date,
                'period_end_date'   => PeriodResolver::periodEndDateForType($date, $type),
                'due'               => $this->rewardService->isDue($date, $type),
                'frozen'            => $this->rewards->periodIsFrozen($date, $type),
                'published'         => $this->reports->rewardRankingCount($date, $type) > 0,
                'totals'            => $totals,
                'rewards'           => $this->reports->rewardCounts($date, $type),
            ];
        }

        return Response::json([
            'data' => $rows,
            'meta' => [
                'period_type'       => $type,
                'current_period'    => $type === PeriodResolver::TYPE_MONTHLY
                    ? PeriodResolver::monthlyPeriodStartDate()
                    : PeriodResolver::periodStartDate(),
                'grace_hours'       => $this->rewardService->graceHours(),
                'business_timezone' => \FieldPulse\Config\Config::instance()->businessTimezone()->getName(),
                'available_periods' => $dates,
            ],
        ]);
    }

    private function close(Request $request): Response
    {
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, self::FIELDS, 'period close');

        $type = $this->periodType($body['period_type'] ?? null);
        $action = strtoupper(trim((string) ($body['action'] ?? 'CLOSE')));

        if ($action !== 'CLOSE') {
            throw ApiException::validation(
                'Field "action" must be CLOSE.',
                ['field' => 'action']
            );
        }

        $raw = $body['period'] ?? $body['period_start_date'] ?? null;
        $periodDate = $this->periodDate($type, $raw);

        $result = $this->rewardService->closePeriod($periodDate, $type);

        $this->audit->recordSafe([
            'actor_agent_id' => $request->requireAuth()->agentId(),
            'action'         => 'admin.period.close_requested',
            'entity_type'    => 'period',
            'entity_id'      => null,
            'metadata'       => [
                'period_type'       => $type,
                'period_start_date' => $periodDate,
                'closed'            => (bool) ($result['closed'] ?? false),
                'reason'            => (string) ($result['reason'] ?? ''),
            ],
            'ip_address'     => $request->clientIp(),
        ]);

        return Response::json([
            'data' => $result + [
                'period_end_date' => PeriodResolver::periodEndDateForType($periodDate, $type),
            ],
            'meta' => [
                'period_type' => $type,
                'grace_hours' => $this->rewardService->graceHours(),
            ],
        ]);
    }

    private function periodType(?string $raw): string
    {
        $type = strtoupper(trim((string) ($raw ?? PeriodResolver::TYPE_MONTHLY)));

        if (!in_array($type, PeriodResolver::PERIOD_TYPES, true)) {
            throw ApiException::validation(
                'Field "period_type" must be WEEKLY or MONTHLY.',
                ['field' => 'period_type']
            );
        }

        return $type;
    }

    private function periodDate(string $type, mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return $type === PeriodResolver::TYPE_MONTHLY
                ? PeriodResolver::monthlyPeriodStartDate()
                : PeriodResolver::periodStartDate();
        }

        if ($type === PeriodResolver::TYPE_MONTHLY) {
            return Validator::periodMonth($raw, 'period');
        }

        $date = Validator::string($raw, 10, 10, 'period');

        if (!Validator::isIsoDate($date)) {
            throw ApiException::validation(
                'Field "period" must be an ISO date.',
                ['field' => 'period']
            );
        }

        return PeriodResolver::periodStartDateForType($type, $date . ' 00:00:00');
    }
}
