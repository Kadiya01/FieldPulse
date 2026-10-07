<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\RewardRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Reward\RewardService;

/**
 * Reward endpoints (§14).
 *
 *   GET  /api/v1/rewards/self     the caller's own published entitlements
 *   GET  /api/v1/rewards/index    one period's entitlements (operator)
 *   POST /api/v1/rewards/decide   approve, pay or void (operator)
 *
 * The split matters as much as the routes. /self is `bearer`: an agent sees
 * their own standing and nothing else, which is the point of the screen.
 * /index and /decide are gated by Http\Kernel's `operator` requirement, so a
 * role check cannot be forgotten inside a controller.
 *
 * Nothing here reads agent_performance_summary to work out what an agent is
 * owed. Every figure below was copied onto agent_rewards at period close, from
 * the frozen ranking in reward_rankings, and is served from there unchanged —
 * which is exactly the cutoff rule, seen from the read side.
 */
final class RewardController implements ActionInterface
{
    private const ACTIONS = [
        RewardService::ACTION_APPROVE,
        RewardService::ACTION_PAY,
        RewardService::ACTION_VOID,
    ];

    public function __construct(
        private readonly RewardService $rewards = new RewardService()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return match ($request->endpoint()) {
            'decide' => $this->decide($request),
            'self'   => $this->self($request),
            default  => $this->index($request),
        };
    }

    /* ------------------------------------------------------------------ */

    private function self(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit = $this->clamp($request->queryInt('limit'), 24, 1, 120);

        $rows = $this->rewards->forAgent($context->agentId(), $limit);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->present($row, false), $rows),
            'meta' => [
                'agent_code'   => $context->agentCode(),
                'grace_hours'  => $this->rewards->graceHours(),
                // Spelled out so the PWA cannot render PENDING as "paid" by
                // inference: these two are different states, and the only one
                // that means the organisation settled up is PAID.
                'payment_note' => 'PENDING and APPROVED are published entitlements. '
                    . 'Only PAID means the organisation has settled this reward.',
            ],
        ]);
    }

    private function index(Request $request): Response
    {
        $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit  = $this->clamp($request->queryInt('limit'), 25, 1, 100);
        $offset = $this->clamp($request->queryInt('offset'), 0, 0, 100000);

        $period = $this->period($request);

        $result = $this->rewards->forPeriod($period, $limit, $offset);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->present($row, true), $result['items']),
            'meta' => [
                'period'            => $period,
                'closed'            => $result['closed'],
                'available_periods' => $this->rewards->closedPeriods(24),
                'tiers'             => $this->rewards->tiers(),
                'pagination'        => [
                    'total'  => $result['total'],
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
            ],
        ]);
    }

    private function decide(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();

        Validator::assertNoUnknownKeys($body, ['reward_id', 'action', 'reason'], 'reward');

        $rewardId = Validator::intRange($body['reward_id'] ?? null, 1, PHP_INT_MAX, 'reward_id');

        $action = strtoupper(Validator::string($body['action'] ?? null, 3, 10, 'action'));

        if (!in_array($action, self::ACTIONS, true)) {
            throw ApiException::validation(
                'action must be APPROVE, PAY or VOID.',
                ['field' => 'action', 'allowed' => self::ACTIONS]
            );
        }

        $reason = null;

        if ($action === RewardService::ACTION_VOID) {
            // Cancelling an entitlement someone has already been shown, with no
            // recorded reason, produces an audit row that cannot answer the
            // only question anyone will later ask of it.
            $reason = Validator::string($body['reason'] ?? null, 5, 500, 'reason');
        }

        $updated = $this->rewards->decide($rewardId, $action, $context->agentId(), $reason);

        return Response::json(['data' => $this->present($updated, true)]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Which period the operator asked for.
     *
     * Defaults to the newest period that actually produced entitlements rather
     * than to the current week, because the current week is not closed and an
     * empty board with no explanation is the failure this avoids.
     */
    private function period(Request $request): string
    {
        $requested = $request->query('period');

        if ($requested !== null && $requested !== '') {
            if (!Validator::isIsoDate($requested)) {
                throw ApiException::validation(
                    'period must be a YYYY-MM-DD period start date.',
                    ['field' => 'period']
                );
            }

            return $requested;
        }

        $closed = $this->rewards->closedPeriods(1);

        return $closed[0] ?? PeriodResolver::periodStartDate();
    }

    /**
     * Shape a reward row for the client.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, bool $operator): array
    {
        $status = (string) $row['status'];

        $payload = [
            'id'                   => (int) $row['id'],
            'period_start_date'    => (string) $row['period_start_date'],
            'rank'                 => (int) $row['rank'],
            'total_verified_count' => (int) $row['total_verified_count'],
            'tier'                 => [
                'id'    => $row['tier_id'] === null ? null : (int) $row['tier_id'],
                'label' => $row['tier_label'] === null ? null : (string) $row['tier_label'],
            ],
            // NULL means the organisation has not set a figure for this band
            // yet. It is published as null rather than as zero, because zero
            // would read as "worth nothing" instead of "not yet decided".
            'amount'               => $row['reward_amount'] === null ? null : (float) $row['reward_amount'],
            'currency'             => $row['currency'] === null ? null : (string) $row['currency'],
            'status'               => $status,
            'is_paid'              => $status === RewardRepository::STATUS_PAID,
            'published_at'         => (string) $row['published_at'],
            'approved_at'          => $row['approved_at'] ?? null,
            'paid_at'              => $row['paid_at'] ?? null,
            'voided_at'            => $row['voided_at'] ?? null,
            'void_reason'          => $row['void_reason'] ?? null,
            'notes'                => $row['notes'] ?? null,
        ];

        if ($operator) {
            $payload['agent'] = [
                'agent_code' => (string) $row['agent_code'],
                'full_name'  => (string) ($row['full_name'] ?? ''),
            ];
            $payload['approved_by_operator_id'] = $row['approved_by_operator_id'] === null
                ? null
                : (int) $row['approved_by_operator_id'];
            $payload['paid_by_operator_id'] = $row['paid_by_operator_id'] === null
                ? null
                : (int) $row['paid_by_operator_id'];
            $payload['voided_by_operator_id'] = $row['voided_by_operator_id'] === null
                ? null
                : (int) $row['voided_by_operator_id'];
        }

        return $payload;
    }

    private function clamp(?int $value, int $default, int $min, int $max): int
    {
        return $value === null ? $default : max($min, min($value, $max));
    }
}
