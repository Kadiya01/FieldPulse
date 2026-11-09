<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\MonthlyTargetRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET/POST /api/v1/admin/targets.php
 *
 * Targets are management inputs, not derived statistics. This endpoint only
 * stores what an administrator explicitly enters and refuses to invent a
 * default. target = 0 is kept as an explicit "not configured" row when SET is
 * used with 0; CLEAR removes the row so a broader target can apply again.
 *
 * Allowed scopes:
 *   ORGANIZATION  one per period, scope_key ''
 *   LOCATION      one per period per location text
 *   AGENT         one per period per agent id
 */
final class AdminTargetController implements ActionInterface
{
    private const FIELDS = [
        'scope_type',
        'scope_key',
        'agent_id',
        'location',
        'period',
        'period_start_date',
        'target',
        'action',
    ];

    public function __construct(
        private readonly MonthlyTargetRepository $targets = new MonthlyTargetRepository(),
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $request->requireAuth();

        return $request->method() === 'POST'
            ? $this->store($request)
            : $this->index($request);
    }

    private function index(Request $request): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $raw = $request->query('period') ?? $request->query('period_start_date');
        $periodDate = $raw === null || $raw === ''
            ? \FieldPulse\Domain\PeriodResolver::monthlyPeriodStartDate()
            : Validator::periodMonth($raw, 'period');

        $rows = $this->targets->forPeriod($periodDate);

        $available = array_values(array_unique(array_merge(
            \FieldPulse\Domain\PeriodResolver::monthlyPeriodRange(12),
            $this->targets->availablePeriods(24)
        )));
        rsort($available);

        $agents = $this->agents->listForAdmin(1000, 0, ['status' => 'ACTIVE']);

        return Response::json([
            'data' => array_map([AdminPresenter::class, 'target'], $rows),
            'meta' => [
                'period'              => $periodDate,
                'period_start_date'   => $periodDate,
                'period_end_date'     => \FieldPulse\Domain\PeriodResolver::periodEndDateForType(
                    $periodDate,
                    \FieldPulse\Domain\PeriodResolver::TYPE_MONTHLY
                ),
                'organization_target' => $this->targets->organizationTarget($periodDate),
                'locations'           => $this->agents->distinctLocations(),
                'agents'              => array_map(static fn (array $row): array => [
                    'id'             => (int) $row['id'],
                    'agent_code'     => (string) $row['agent_code'],
                    'full_name'      => (string) $row['full_name'],
                    'location'       => $row['location'] ?? null,
                    'monthly_target' => (int) $row['monthly_target'],
                ], $agents),
                'available_periods'   => $available,
            ],
        ]);
    }

    private function store(Request $request): Response
    {
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, self::FIELDS, 'target');

        $rawPeriod = $body['period'] ?? $body['period_start_date'] ?? null;
        $periodDate = $rawPeriod === null
            ? \FieldPulse\Domain\PeriodResolver::monthlyPeriodStartDate()
            : Validator::periodMonth($rawPeriod, 'period');

        $action = strtoupper(trim((string) ($body['action'] ?? 'SET')));

        if (!in_array($action, ['SET', 'CLEAR'], true)) {
            throw ApiException::validation(
                'Field "action" must be SET or CLEAR.',
                ['field' => 'action']
            );
        }

        [$scopeType, $scopeKey, $agent] = $this->resolveScope($body);

        if ($action === 'CLEAR') {
            $deleted = $this->targets->delete($periodDate, $scopeType, $scopeKey);

            $this->audit->recordSafe([
                'actor_agent_id' => $request->requireAuth()->agentId(),
                'action'         => 'admin.target.cleared',
                'entity_type'    => 'monthly_target',
                'entity_id'      => null,
                'metadata'       => [
                    'period_start_date' => $periodDate,
                    'scope_type'        => $scopeType,
                    'scope_key'         => $scopeKey,
                    'deleted'           => $deleted,
                ],
                'ip_address'     => $request->clientIp(),
            ]);

            return Response::json([
                'data' => null,
                'meta' => [
                    'period'            => $periodDate,
                    'period_start_date' => $periodDate,
                    'scope_type'        => $scopeType,
                    'scope_key'         => $scopeKey,
                    'deleted'           => $deleted,
                    'organization_target' => $this->targets->organizationTarget($periodDate),
                ],
            ]);
        }

        $target = Validator::intRange($body['target'] ?? null, 0, 1_000_000, 'target');

        $row = $this->targets->upsert(
            $periodDate,
            $scopeType,
            $scopeKey,
            $target,
            $request->requireAuth()->agentId()
        );

        if ($row === null) {
            throw new ApiException(500, ErrorCode::INTERNAL_ERROR, 'Target could not be stored.');
        }

        if ($scopeType === MonthlyTargetRepository::SCOPE_AGENT) {
            $row['agent_code']      = $agent['agent_code'] ?? null;
            $row['agent_full_name'] = $agent['full_name'] ?? null;
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $request->requireAuth()->agentId(),
            'action'         => 'admin.target.set',
            'entity_type'    => 'monthly_target',
            'entity_id'      => (int) $row['id'],
            'metadata'       => [
                'period_start_date' => $periodDate,
                'scope_type'        => $scopeType,
                'scope_key'         => $scopeKey,
                'target'            => $target,
            ],
            'ip_address'     => $request->clientIp(),
        ]);

        return Response::json([
            'data' => AdminPresenter::target($row),
            'meta' => [
                'period'              => $periodDate,
                'period_start_date'   => $periodDate,
                'organization_target' => $this->targets->organizationTarget($periodDate),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array{0:string,1:string,2:array<string,mixed>|null}
     */
    private function resolveScope(array $body): array
    {
        $requested = isset($body['scope_type']) ? strtoupper(trim((string) $body['scope_type'])) : '';

        if ($requested === '') {
            if (isset($body['agent_id'])) {
                $requested = MonthlyTargetRepository::SCOPE_AGENT;
            } elseif (isset($body['location'])) {
                $requested = MonthlyTargetRepository::SCOPE_LOCATION;
            } else {
                $requested = MonthlyTargetRepository::SCOPE_ORGANIZATION;
            }
        }

        if (!in_array($requested, MonthlyTargetRepository::SCOPES, true)) {
            throw ApiException::validation(
                'Field "scope_type" must be ORGANIZATION, LOCATION or AGENT.',
                ['field' => 'scope_type']
            );
        }

        if ($requested === MonthlyTargetRepository::SCOPE_ORGANIZATION) {
            return [$requested, '', null];
        }

        if ($requested === MonthlyTargetRepository::SCOPE_AGENT) {
            $rawId = $body['agent_id'] ?? $body['scope_key'] ?? null;
            $agentId = Validator::intRange($rawId, 1, PHP_INT_MAX, 'agent_id');
            $agent = $this->agents->findById($agentId);

            if ($agent === null) {
                throw ApiException::notFound(ErrorCode::UNKNOWN_AGENT, 'Agent not found.');
            }

            return [$requested, (string) $agentId, $agent];
        }

        $location = Validator::location($body['location'] ?? $body['scope_key'] ?? null, 'location');

        if ($location === null) {
            throw ApiException::validation(
                'Field "location" is required for a LOCATION target.',
                ['field' => 'location']
            );
        }

        return [$requested, $location, null];
    }
}
