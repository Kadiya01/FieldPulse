<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Monthly target storage (migration 028).
 *
 * A target belongs to a period and a scope. scope_key is '' for ORGANIZATION,
 * the location text for LOCATION, and the agent id as text for AGENT. The
 * unique key is (period_start_date, scope_type, scope_key), so setting the same
 * scope twice updates the row instead of creating a second one.
 *
 * Precedence is deliberately not stored as a denormalised value. It is resolved
 * by effectiveTarget() from the rows that exist, so changing a location target
 * immediately changes every agent in that location that has no more specific
 * target of their own.
 */
final class MonthlyTargetRepository extends Repository
{
    public const SCOPE_ORGANIZATION = 'ORGANIZATION';
    public const SCOPE_LOCATION     = 'LOCATION';
    public const SCOPE_AGENT        = 'AGENT';

    public const SCOPES = [
        self::SCOPE_ORGANIZATION,
        self::SCOPE_LOCATION,
        self::SCOPE_AGENT,
    ];

    /** @return list<array<string,mixed>> */
    public function forPeriod(string $periodDate): array
    {
        return $this->all(
            "SELECT m.*, a.agent_code AS agent_code, a.full_name AS agent_full_name
               FROM monthly_targets m
               LEFT JOIN agents a
                 ON m.scope_type = 'AGENT'
                AND a.id = CAST(m.scope_key AS UNSIGNED)
              WHERE m.period_start_date = :period
              ORDER BY CASE m.scope_type
                         WHEN 'ORGANIZATION' THEN 0
                         WHEN 'LOCATION' THEN 1
                         ELSE 2
                       END,
                       m.scope_key",
            ['period' => $periodDate]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $periodDate, string $scopeType, string $scopeKey): ?array
    {
        return $this->one(
            'SELECT * FROM monthly_targets
              WHERE period_start_date = :period
                AND scope_type = :scope_type
                AND scope_key = :scope_key',
            [
                'period'     => $periodDate,
                'scope_type' => $scopeType,
                'scope_key'  => $scopeKey,
            ]
        );
    }

    /** @return array<string,mixed>|null */
    public function upsert(
        string $periodDate,
        string $scopeType,
        string $scopeKey,
        int $target,
        ?int $createdBy
    ): ?array {
        $this->exec(
            'INSERT INTO monthly_targets
                (period_start_date, scope_type, scope_key, target, created_by, created_at, updated_at)
             VALUES (:period, :scope_type, :scope_key, :target, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                target = VALUES(target),
                created_by = VALUES(created_by),
                updated_at = UTC_TIMESTAMP()',
            [
                'period'     => $periodDate,
                'scope_type' => $scopeType,
                'scope_key'  => $scopeKey,
                'target'     => $target,
                'created_by' => $createdBy,
            ]
        );

        return $this->find($periodDate, $scopeType, $scopeKey);
    }

    public function delete(string $periodDate, string $scopeType, string $scopeKey): int
    {
        return $this->exec(
            'DELETE FROM monthly_targets
              WHERE period_start_date = :period
                AND scope_type = :scope_type
                AND scope_key = :scope_key',
            [
                'period'     => $periodDate,
                'scope_type' => $scopeType,
                'scope_key'  => $scopeKey,
            ]
        );
    }

    /**
     * Period-specific targets for one agent, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forAgent(int $agentId, int $limit = 12): array
    {
        return $this->all(
            'SELECT * FROM monthly_targets
              WHERE scope_type = :scope_type AND scope_key = :scope_key
              ORDER BY period_start_date DESC' . self::limitClause($limit, 48, 0),
            [
                'scope_type' => self::SCOPE_AGENT,
                'scope_key'  => (string) $agentId,
            ]
        );
    }

    /**
     * The target that actually applies to one agent in one period.
     *
     * @return array{target:int,configured:bool,scope:string,scope_key:string,period_start_date:string}
     */
    public function effectiveTarget(
        int $agentId,
        ?string $location,
        int $legacyTarget,
        string $periodDate
    ): array {
        $agent = $this->find($periodDate, self::SCOPE_AGENT, (string) $agentId);

        if ($agent !== null && (int) $agent['target'] > 0) {
            return $this->resolved((int) $agent['target'], self::SCOPE_AGENT, (string) $agentId, $periodDate);
        }

        if ($legacyTarget > 0) {
            return $this->resolved($legacyTarget, 'AGENT_DEFAULT', (string) $agentId, $periodDate);
        }

        if ($location !== null && trim($location) !== '') {
            $locationRow = $this->find($periodDate, self::SCOPE_LOCATION, trim($location));

            if ($locationRow !== null && (int) $locationRow['target'] > 0) {
                return $this->resolved((int) $locationRow['target'], self::SCOPE_LOCATION, trim($location), $periodDate);
            }
        }

        $organization = $this->find($periodDate, self::SCOPE_ORGANIZATION, '');

        if ($organization !== null && (int) $organization['target'] > 0) {
            return $this->resolved((int) $organization['target'], self::SCOPE_ORGANIZATION, '', $periodDate);
        }

        return $this->resolved(0, 'NONE', '', $periodDate);
    }

    /** @return array{target:int,configured:bool,scope:string,scope_key:string,period_start_date:string} */
    private function resolved(int $target, string $scope, string $scopeKey, string $periodDate): array
    {
        return [
            'target'            => $target,
            'configured'        => $target > 0,
            'scope'             => $scope,
            'scope_key'         => $scopeKey,
            'period_start_date' => $periodDate,
        ];
    }

    public function organizationTarget(string $periodDate): int
    {
        $row = $this->find($periodDate, self::SCOPE_ORGANIZATION, '');

        return $row === null ? 0 : (int) $row['target'];
    }

    /**
     * Periods that have at least one target row, newest first.
     *
     * @return list<string>
     */
    public function availablePeriods(int $limit = 24): array
    {
        $rows = $this->all(
            'SELECT DISTINCT period_start_date
               FROM monthly_targets
              ORDER BY period_start_date DESC' . self::limitClause($limit, 48, 0)
        );

        return array_map(static fn (array $row): string => (string) $row['period_start_date'], $rows);
    }
}
