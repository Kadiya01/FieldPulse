<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\NotificationRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET /api/v1/dashboard.php
 *
 * The agent's current monthly standing. Everything is read from the persisted
 * summary and the submission ledger; nothing is estimated client-side, and a
 * missing target is reported as "not configured" rather than as 0%.
 *
 * Target Achievement (%) = (Approved Enrollments / Monthly Target) * 100
 *
 * Only VERIFIED submissions are in the numerator. Pending and rejected records
 * are reported separately so an agent can see why their achievement is lower
 * than the number of photos they have taken.
 */
final class DashboardController implements ActionInterface
{
    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly LeaderboardRepository $leaderboard = new LeaderboardRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $agent = $this->agents->findById($context->agentId());

        if ($agent === null) {
            throw ApiException::notFound(ErrorCode::UNKNOWN_AGENT, 'Account not found.');
        }

        $period = PeriodResolver::monthlyPeriodFor();
        $periodDate = $period['date'];

        $summary = $this->leaderboard->summaryFor(
            $context->agentId(),
            $periodDate,
            PeriodResolver::TYPE_MONTHLY
        );

        $verified = (int) ($summary['total_verified_count'] ?? 0);
        $target   = (int) ($agent['monthly_target'] ?? 0);

        $board = $this->leaderboard->board(
            $periodDate,
            1,
            0,
            null,
            $context->agentId(),
            PeriodResolver::TYPE_MONTHLY
        );

        $rank = $board['self_rank'] ?? null;

        return Response::json([
            'data' => [
                'agent' => [
                    'id'             => (int) $agent['id'],
                    'agent_code'     => (string) $agent['agent_code'],
                    'full_name'      => (string) $agent['full_name'],
                    'email'          => $agent['email'] ?? null,
                    'location'       => $agent['location'] ?? null,
                    'monthly_target' => $target,
                ],
                'period' => [
                    'period_type'       => PeriodResolver::TYPE_MONTHLY,
                    'period_start_date' => $periodDate,
                    'business_timezone' => $period['business_tz'],
                ],
                'totals' => [
                    'approved_enrollments' => $verified,
                    'total_submissions'    => (int) ($summary['total_submissions'] ?? 0),
                    'pending'              => (int) ($summary['total_pending'] ?? 0),
                    'rejected'             => (int) ($summary['total_rejected'] ?? 0),
                ],
                'target' => [
                    'monthly_target'       => $target,
                    'configured'           => $target > 0,
                    'achievement_percent'  => $target > 0
                        ? round(($verified / $target) * 100, 2)
                        : null,
                    'remaining'            => $target > 0 ? max(0, $target - $verified) : null,
                ],
                'leaderboard' => [
                    'rank'        => $rank,
                    'total_agents' => (int) ($board['total'] ?? 0),
                ],
                'recent_submissions' => $this->presentSubmissions(
                    $this->submissions->listForAgent($context->agentId(), 10)
                ),
                'notifications' => [
                    'unread_count' => $this->notifications->unreadCount($context->agentId()),
                    'items'        => array_map(
                        static fn (array $row): array => [
                            'id'         => (int) $row['id'],
                            'type'       => (string) $row['type'],
                            'title'      => (string) $row['title'],
                            'message'    => (string) $row['message'],
                            'read_at'    => $row['read_at'] ?? null,
                            'created_at' => (string) $row['created_at'],
                        ],
                        $this->notifications->listForAgent($context->agentId(), 5)
                    ),
                ],
            ],
        ]);
    }

    /**
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function presentSubmissions(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'submission_id'   => (int) $row['id'],
            'submission_uuid' => (string) $row['submission_uuid'],
            'count_claimed'   => (int) $row['count_claimed'],
            'reporting_month' => $row['reporting_month'] ?? null,
            'status'          => (string) $row['status'],
            'received_at'     => (string) $row['server_received_at'],
            'verified_at'     => $row['verified_at'] ?? null,
        ], $rows);
    }
}
