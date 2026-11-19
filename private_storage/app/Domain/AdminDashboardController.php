<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AdminDashboardRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\MonthlyTargetRepository;
use FieldPulse\Database\RewardRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Reward\RewardService;

/**
 * GET /api/v1/admin/dashboard.php
 *
 * Management overview for one monthly period. Every figure is derived from the
 * enrollment ledger, the agent directory and the reward snapshot tables; there
 * are no client-computed or placeholder numbers.
 *
 * The headline target is the ORGANIZATION target. Location and agent rows also
 * carry the target that actually applies to them, resolved with the same
 * precedence the target endpoint uses.
 */
final class AdminDashboardController implements ActionInterface
{
    public function __construct(
        private readonly AdminDashboardRepository $reports = new AdminDashboardRepository(),
        private readonly MonthlyTargetRepository $targets = new MonthlyTargetRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
        private readonly RewardRepository $rewards = new RewardRepository(),
        private readonly RewardService $rewardService = new RewardService()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $type = strtoupper(trim((string) ($request->query('period_type') ?? PeriodResolver::TYPE_MONTHLY)));

        if ($type !== PeriodResolver::TYPE_MONTHLY) {
            throw ApiException::validation(
                'Only MONTHLY dashboard periods are supported.',
                ['field' => 'period_type']
            );
        }

        $rawPeriod = $request->query('period');
        $periodDate = $rawPeriod === null || $rawPeriod === ''
            ? PeriodResolver::monthlyPeriodStartDate()
            : Validator::periodMonth($rawPeriod, 'period');

        $period = PeriodResolver::monthlyPeriodFor();
        $totals = $this->reports->periodTotals($periodDate, $type);
        $organizationTarget = $this->targets->organizationTarget($periodDate);
        $frozen = $this->rewards->periodIsFrozen($periodDate, $type);
        $published = $this->reports->rewardRankingCount($periodDate, $type) > 0;

        $locations = [];
        foreach ($this->reports->locationBreakdown($periodDate, $type) as $row) {
            $resolved = $this->targets->effectiveTarget(0, $row['location'], 0, $periodDate);
            $locations[] = $row + [
                'target'  => $resolved['target'],
                'scope'   => $resolved['scope'],
            ] + AdminPresenter::achievement($resolved['target'], $row['approved']);
        }

        $topAgents = [];
        foreach ($this->reports->topAgents($periodDate, $type, 5) as $row) {
            $resolved = $this->targets->effectiveTarget(
                $row['agent_id'],
                $row['location'],
                $row['monthly_target'],
                $periodDate
            );
            $topAgents[] = [
                'agent_id'   => $row['agent_id'],
                'agent_code' => $row['agent_code'],
                'full_name'  => $row['full_name'],
                'location'   => $row['location'],
                'approved'   => $row['approved'],
                'pending'    => $row['pending'],
                'rejected'   => $row['rejected'],
                'target'     => $resolved['target'],
                'scope'      => $resolved['scope'],
            ] + AdminPresenter::achievement($resolved['target'], $row['approved']);
        }

        return Response::json([
            'data' => [
                'period' => [
                    'period_type'       => $type,
                    'period_start_date' => $periodDate,
                    'period_end_date'   => PeriodResolver::periodEndDateForType($periodDate, $type),
                    'business_timezone' => $period['business_tz'],
                    'due'               => $this->rewardService->isDue($periodDate, $type),
                    'frozen'            => $frozen,
                    'published'         => $published,
                ],
                'agents' => $this->reports->agentCounts(),
                'devices' => $this->reports->deviceCounts(),
                'enrollments' => $totals,
                'target' => [
                    'monthly_target' => $organizationTarget,
                    'scope'          => MonthlyTargetRepository::SCOPE_ORGANIZATION,
                ] + AdminPresenter::achievement($organizationTarget, $totals['approved']),
                'locations'       => $locations,
                'top_agents'      => $topAgents,
                'recent_activity' => AdminPresenter::auditRows($this->audit->adminSearch([], 10, 0)),
            ],
        ]);
    }
}
