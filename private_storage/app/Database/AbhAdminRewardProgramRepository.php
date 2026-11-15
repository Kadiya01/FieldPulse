<?php

declare(strict_types=1);

namespace FieldPulse\\Database;

use FieldPulse\\Support\\RequestId;

/** ABH reward programme and immutable eligibility-rule version management. */
final class AbhAdminRewardProgramRepository
{
    private function __construct()
    {
    }

    /** @return array{items:list<array<string,mixed>>} */
    public static function listForMonth(string $month): array
    {
        $rows = Connection::fetchAll(
            'SELECT p.id, p.program_code, p.name, p.description, p.status,
                    rp.period_key, p.created_at, p.updated_at,
                    (SELECT COUNT(*) FROM abh_reward_rules r
                      WHERE r.program_id = p.id AND r.is_active = 1) AS active_rule_count
               FROM abh_reward_programs p
               INNER JOIN abh_reporting_periods rp ON rp.id = p.reporting_period_id
              WHERE rp.period_key = :month
              ORDER BY p.created_at DESC, p.id DESC',
            ['month' => $month]
        );
        return ['items' => $rows];
    }

    /** @return array<string,mixed> */
    public static function createProgram(int $adminId, string $month, string $code, string $name, ?string $description): array
    {
        return Connection::transaction(
            static function (\\PDO $pdo) use ($adminId, $month, $code, $name, $description): array {
                $period = $pdo->prepare(
                    'SELECT id, status FROM abh_reporting_periods WHERE period_key = :month LIMIT 1 FOR UPDATE'
                );
                $period->execute(['month' => $month]);
                $periodRow = $period->fetch(\\PDO::FETCH_ASSOC);
                if (!is_array($periodRow)) {
                    return ['error' => 'period_not_found'];
                }
                if ((string) $periodRow['status'] !== 'OPEN') {
                    return ['error' => 'period_not_open'];
                }

                $insert = $pdo->prepare(
                    'INSERT INTO abh_reward_programs
                     (reporting_period_id, program_code, name, description, status, created_by_admin_id)
                     VALUES (:period_id, :code, :name, :description, "DRAFT", :admin_id)'
                );
                $insert->execute([
                    'period_id' => (int) $periodRow['id'],
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'admin_id' => $adminId,
                ]);
                $id = (int) $pdo->lastInsertId();

                self::audit($pdo, $adminId, 'reward.program.created', 'reward_program', $id, null, [
                    'period_key' => $month, 'program_code' => $code, 'name' => $name, 'status' => 'DRAFT',
                ]);
                return ['id' => $id, 'program_code' => $code, 'name' => $name, 'status' => 'DRAFT'];
            }
        );
    }

    /** @return array{items:list<array<string,mixed>>} */
    public static function listRules(int $programId): array
    {
        $rows = Connection::fetchAll(
            'SELECT id, program_id, rule_key, rule_version, rank_from, rank_to,
                    minimum_approved_quantity, eligibility_conditions_json, is_active, created_at
               FROM abh_reward_rules
              WHERE program_id = :program_id
              ORDER BY rule_key, rule_version DESC',
            ['program_id' => $programId]
        );
        return ['items' => $rows];
    }

    /** @return array<string,mixed> */
    public static function addRule(
        int $adminId,
        int $programId,
        string $ruleKey,
        ?int $rankFrom,
        ?int $rankTo,
        ?int $minimumQuantity
    ): array {
        return Connection::transaction(
            static function (\\PDO $pdo) use ($adminId, $programId, $ruleKey, $rankFrom, $rankTo, $minimumQuantity): array {
                $stmt = $pdo->prepare(
                    'SELECT p.id, p.status, rp.status AS period_status
                       FROM abh_reward_programs p
                       INNER JOIN abh_reporting_periods rp ON rp.id = p.reporting_period_id
                      WHERE p.id = :id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['id' => $programId]);
                $program = $stmt->fetch(\\PDO::FETCH_ASSOC);
                if (!is_array($program)) {
                    return ['error' => 'program_not_found'];
                }
                if ((string) $program['status'] !== 'DRAFT' || (string) $program['period_status'] !== 'OPEN') {
                    return ['error' => 'program_locked'];
                }

                $versionStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(rule_version), 0) FROM abh_reward_rules
                      WHERE program_id = :program_id AND rule_key = :rule_key'
                );
                $versionStmt->execute(['program_id' => $programId, 'rule_key' => $ruleKey]);
                $version = (int) $versionStmt->fetchColumn() + 1;

                $pdo->prepare(
                    'UPDATE abh_reward_rules SET is_active = 0
                      WHERE program_id = :program_id AND rule_key = :rule_key AND is_active = 1'
                )->execute(['program_id' => $programId, 'rule_key' => $ruleKey]);

                $insert = $pdo->prepare(
                    'INSERT INTO abh_reward_rules
                     (program_id, rule_key, rule_version, rank_from, rank_to,
                      minimum_approved_quantity, eligibility_conditions_json, is_active,
                      created_by_admin_id)
                     VALUES (:program_id, :rule_key, :version, :rank_from, :rank_to,
                             :minimum_quantity, "{}", 1, :admin_id)'
                );
                $insert->execute([
                    'program_id' => $programId,
                    'rule_key' => $ruleKey,
                    'version' => $version,
                    'rank_from' => $rankFrom,
                    'rank_to' => $rankTo,
                    'minimum_quantity' => $minimumQuantity,
                    'admin_id' => $adminId,
                ]);
                $id = (int) $pdo->lastInsertId();

                self::audit($pdo, $adminId, 'reward.rule.version_created', 'reward_rule', $id, null, [
                    'program_id' => $programId, 'rule_key' => $ruleKey, 'rule_version' => $version,
                    'rank_from' => $rankFrom, 'rank_to' => $rankTo,
                    'minimum_approved_quantity' => $minimumQuantity,
                ]);
                return [
                    'id' => $id, 'program_id' => $programId, 'rule_key' => $ruleKey,
                    'rule_version' => $version, 'rank_from' => $rankFrom,
                    'rank_to' => $rankTo, 'minimum_approved_quantity' => $minimumQuantity,
                    'is_active' => true,
                ];
            }
        );
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private static function audit(\\PDO $pdo, int $adminId, string $action, string $entity, int $entityId, ?array $before, array $after): void
    {
        $pdo->prepare(
            'INSERT INTO abh_audit_events
             (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
              before_json, after_json, request_id, created_at)
             VALUES ("ADMIN", :admin_id, :action, :entity, :entity_id,
                     :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
        )->execute([
            'admin_id' => $adminId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => json_encode($after, JSON_THROW_ON_ERROR),
            'request_id' => RequestId::current(),
        ]);
    }
}
