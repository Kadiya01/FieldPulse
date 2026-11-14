<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\RequestId;

/**
 * Monthly target management for ABH administrators. This repository uses only
 * the ABH periods, target and audit ledgers.
 */
final class AbhAdminTargetRepository
{
    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function getForMonth(string $periodKey): array
    {
        $period = Connection::fetchOne(
            'SELECT id, period_key, starts_on, ends_on, timezone, status, closed_at
               FROM abh_reporting_periods
              WHERE period_key = :period_key
              LIMIT 1',
            ['period_key' => $periodKey]
        );

        $organisationTarget = null;
        $agentTargets = [];
        if ($period !== null) {
            $organisationTarget = Connection::fetchValue(
                'SELECT target_quantity
                   FROM abh_monthly_targets
                  WHERE reporting_period_id = :period_id
                    AND scope_type = "ORGANISATION"
                    AND scope_owner_id = 0
                  LIMIT 1',
                ['period_id' => (int) $period['id']]
            );

            $agentTargets = Connection::fetchAll(
                'SELECT t.id AS target_id, t.agent_id, t.target_quantity, t.updated_at,
                        a.agent_code, a.full_name, a.phone
                   FROM abh_monthly_targets t
                   INNER JOIN abh_agents a ON a.id = t.agent_id
                  WHERE t.reporting_period_id = :period_id
                    AND t.scope_type = "AGENT"
                  ORDER BY a.full_name, a.agent_code',
                ['period_id' => (int) $period['id']]
            );
        }

        $agents = Connection::fetchAll(
            'SELECT id, agent_code, full_name, phone, location_text
               FROM abh_agents
              WHERE status = "ACTIVE"
              ORDER BY full_name, agent_code
              LIMIT 1000'
        );

        return [
            'period' => $period,
            'organisation_target' => $organisationTarget === null ? null : (int) $organisationTarget,
            'agent_targets' => $agentTargets,
            'agents' => $agents,
        ];
    }

    /**
     * Create the month if it does not yet exist, then upsert one target while
     * holding the period row lock. Closing a month therefore cannot race a
     * target change.
     *
     * @return array<string,mixed>
     */
    public static function save(
        int $adminId,
        string $periodKey,
        string $scopeType,
        ?int $agentId,
        int $targetQuantity
    ): array {
        return Connection::transaction(
            static function (\PDO $pdo) use ($adminId, $periodKey, $scopeType, $agentId, $targetQuantity): array {
                $start = new \DateTimeImmutable($periodKey . '-01', new \DateTimeZone('Africa/Lagos'));
                $startDate = $start->format('Y-m-d');
                $endDate = $start->modify('last day of this month')->format('Y-m-d');

                // The no-op duplicate update safely creates the period once
                // even when two target-save requests arrive concurrently.
                $pdo->prepare(
                    'INSERT INTO abh_reporting_periods
                     (period_key, starts_on, ends_on, timezone, status, created_at, updated_at)
                     VALUES (:period_key, :starts_on, :ends_on, "Africa/Lagos", "OPEN", UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                     ON DUPLICATE KEY UPDATE period_key = VALUES(period_key)'
                )->execute([
                    'period_key' => $periodKey,
                    'starts_on' => $startDate,
                    'ends_on' => $endDate,
                ]);

                $periodStmt = $pdo->prepare(
                    'SELECT id, status
                       FROM abh_reporting_periods
                      WHERE period_key = :period_key
                      LIMIT 1 FOR UPDATE'
                );
                $periodStmt->execute(['period_key' => $periodKey]);
                $period = $periodStmt->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($period)) {
                    throw new \RuntimeException('Reporting period could not be created.');
                }
                if ((string) $period['status'] !== 'OPEN') {
                    return ['error' => 'period_closed'];
                }

                if ($scopeType === 'AGENT') {
                    $agentStmt = $pdo->prepare(
                        'SELECT id FROM abh_agents WHERE id = :agent_id AND status = "ACTIVE" LIMIT 1'
                    );
                    $agentStmt->execute(['agent_id' => $agentId]);
                    if ($agentStmt->fetchColumn() === false) {
                        return ['error' => 'agent_not_active'];
                    }
                    $scopeOwnerId = (int) $agentId;
                    $targetAgentId = $agentId;
                } else {
                    $scopeOwnerId = 0;
                    $targetAgentId = null;
                }

                $beforeStmt = $pdo->prepare(
                    'SELECT id, target_quantity
                       FROM abh_monthly_targets
                      WHERE reporting_period_id = :period_id
                        AND scope_owner_id = :scope_owner_id
                      LIMIT 1 FOR UPDATE'
                );
                $beforeStmt->execute([
                    'period_id' => (int) $period['id'],
                    'scope_owner_id' => $scopeOwnerId,
                ]);
                $before = $beforeStmt->fetch(\PDO::FETCH_ASSOC);

                $pdo->prepare(
                    'INSERT INTO abh_monthly_targets
                     (reporting_period_id, scope_type, scope_owner_id, agent_id,
                      target_quantity, created_by_admin_id, updated_by_admin_id, created_at, updated_at)
                     VALUES
                     (:period_id, :scope_type, :scope_owner_id, :agent_id,
                      :target_quantity, :admin_id, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                     ON DUPLICATE KEY UPDATE
                       target_quantity = VALUES(target_quantity),
                       updated_by_admin_id = :updated_by_admin_id,
                       updated_at = UTC_TIMESTAMP(6)'
                )->execute([
                    'period_id' => (int) $period['id'],
                    'scope_type' => $scopeType,
                    'scope_owner_id' => $scopeOwnerId,
                    'agent_id' => $targetAgentId,
                    'target_quantity' => $targetQuantity,
                    'admin_id' => $adminId,
                    'updated_by_admin_id' => $adminId,
                ]);

                $targetStmt = $pdo->prepare(
                    'SELECT id FROM abh_monthly_targets
                      WHERE reporting_period_id = :period_id
                        AND scope_owner_id = :scope_owner_id
                      LIMIT 1'
                );
                $targetStmt->execute([
                    'period_id' => (int) $period['id'],
                    'scope_owner_id' => $scopeOwnerId,
                ]);
                $targetId = (int) $targetStmt->fetchColumn();

                $beforeJson = $before === false ? null : json_encode([
                    'target_quantity' => (int) $before['target_quantity'],
                ], JSON_THROW_ON_ERROR);
                $afterJson = json_encode([
                    'period_key' => $periodKey,
                    'scope_type' => $scopeType,
                    'scope_owner_id' => $scopeOwnerId,
                    'target_quantity' => $targetQuantity,
                ], JSON_THROW_ON_ERROR);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                      before_json, after_json, request_id, created_at)
                     VALUES ("ADMIN", :admin_id, "target.saved", "monthly_target", :target_id,
                             :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'target_id' => $targetId,
                    'before_json' => $beforeJson,
                    'after_json' => $afterJson,
                    'request_id' => RequestId::current(),
                ]);

                return [
                    'id' => $targetId,
                    'period_key' => $periodKey,
                    'scope_type' => $scopeType,
                    'agent_id' => $targetAgentId,
                    'target_quantity' => $targetQuantity,
                    'created' => $before === false,
                ];
            }
        );
    }
}
