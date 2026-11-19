<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\RequestId;

/** Frozen-snapshot qualification preview and one-time evaluation. */
final class AbhAdminRewardQualificationRepository
{
    private function __construct() {}

    /** @return array<string,mixed> */
    public static function preview(int $programId): array
    {
        $program = self::loadProgram($programId);
        if ($program === null) return ['error' => 'program_not_found'];
        if ((string) $program['period_status'] !== 'CLOSED') return ['error' => 'period_not_closed'];
        if ((string) $program['status'] !== 'ACTIVE') return ['error' => 'program_not_active'];

        $rules = self::loadRules($programId);
        if ($rules === []) return ['error' => 'rules_required'];

        $rows = self::loadSnapshots((int) $program['reporting_period_id']);
        return self::buildPreview($program, $rules, $rows);
    }

    /** @return array<string,mixed> */
    public static function evaluate(int $adminId, int $programId): array
    {
        return Connection::transaction(static function (\PDO $pdo) use ($adminId, $programId): array {
            $stmt = $pdo->prepare(
                'SELECT p.id, p.status, p.reporting_period_id, rp.period_key, rp.status AS period_status
                   FROM abh_reward_programs p
                   INNER JOIN abh_reporting_periods rp ON rp.id = p.reporting_period_id
                  WHERE p.id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $programId]);
            $program = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($program)) return ['error' => 'program_not_found'];
            if ((string) $program['period_status'] !== 'CLOSED') return ['error' => 'period_not_closed'];
            if ((string) $program['status'] !== 'ACTIVE') return ['error' => 'program_not_active'];

            $rulesStmt = $pdo->prepare(
                'SELECT id, rule_key, rule_version, rank_from, rank_to, minimum_approved_quantity,
                        eligibility_conditions_json
                   FROM abh_reward_rules
                  WHERE program_id = :program_id AND is_active = 1
                  ORDER BY rule_key ASC, rule_version DESC'
            );
            $rulesStmt->execute(['program_id' => $programId]);
            $rules = $rulesStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rules === []) return ['error' => 'rules_required'];

            $snapshotStmt = $pdo->prepare(
                'SELECT agent_id, rank_position, approved_quantity, agent_code_snapshot, display_name_snapshot
                   FROM abh_monthly_leaderboard_snapshots
                  WHERE reporting_period_id = :period_id
                  ORDER BY rank_position ASC, agent_id ASC'
            );
            $snapshotStmt->execute(['period_id' => (int) $program['reporting_period_id']]);
            $snapshots = $snapshotStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($snapshots === []) return ['error' => 'snapshot_empty'];

            $existingStmt = $pdo->prepare('SELECT COUNT(*) FROM abh_reward_qualifications WHERE program_id = :id');
            $existingStmt->execute(['id' => $programId]);
            $existing = (int) $existingStmt->fetchColumn();
            if ($existing > 0) {
                if ($existing !== count($snapshots)) return ['error' => 'qualification_snapshot_incomplete'];
                return [
                    'program_id' => $programId, 'period_key' => (string) $program['period_key'],
                    'evaluated_count' => $existing, 'idempotent_replay' => true,
                ];
            }

            $insert = $pdo->prepare(
                'INSERT INTO abh_reward_qualifications
                 (program_id, rule_id, agent_id, reporting_period_id, frozen_rank,
                  frozen_approved_quantity, qualification_status, eligibility_snapshot_json, evaluated_at)
                 VALUES (:program_id, :rule_id, :agent_id, :period_id, :rank,
                         :quantity, :status, :snapshot, UTC_TIMESTAMP(6))'
            );
            $eligibleCount = 0;
            foreach ($snapshots as $snapshot) {
                $rank = (int) $snapshot['rank_position'];
                $quantity = (int) $snapshot['approved_quantity'];
                $matched = self::matchingRule($rules, $rank, $quantity);
                $recordRule = $matched ?? $rules[0];
                $status = $matched === null ? 'INELIGIBLE' : 'ELIGIBLE';
                if ($matched !== null) $eligibleCount++;
                $snapshotJson = [
                    'source' => 'abh_monthly_leaderboard_snapshots',
                    'period_key' => (string) $program['period_key'],
                    'rank_position' => $rank,
                    'approved_quantity' => $quantity,
                    'agent_code' => (string) $snapshot['agent_code_snapshot'],
                    'display_name' => (string) $snapshot['display_name_snapshot'],
                    'evaluated_rules' => array_map(static fn(array $rule): array => [
                        'rule_id' => (int) $rule['id'],
                        'rule_key' => (string) $rule['rule_key'],
                        'rule_version' => (int) $rule['rule_version'],
                        'rank_from' => $rule['rank_from'] === null ? null : (int) $rule['rank_from'],
                        'rank_to' => $rule['rank_to'] === null ? null : (int) $rule['rank_to'],
                        'minimum_approved_quantity' => $rule['minimum_approved_quantity'] === null
                            ? null : (int) $rule['minimum_approved_quantity'],
                    ], $rules),
                    'matched_rule_id' => $matched === null ? null : (int) $matched['id'],
                    'selection_policy' => 'first_matching_rule_by_rule_key_ascending',
                ];
                $insert->execute([
                    'program_id' => $programId,
                    'rule_id' => (int) $recordRule['id'],
                    'agent_id' => (int) $snapshot['agent_id'],
                    'period_id' => (int) $program['reporting_period_id'],
                    'rank' => $rank,
                    'quantity' => $quantity,
                    'status' => $status,
                    'snapshot' => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                ]);
                $notification = $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_agent_id, event_key, title, message, created_at)
                     VALUES ("AGENT", :agent_id, :event_key, :title, :message, UTC_TIMESTAMP(6))'
                );
                $notification->execute([
                    'agent_id' => (int) $snapshot['agent_id'],
                    'event_key' => $status === 'ELIGIBLE' ? 'reward.qualified' : 'reward.not_qualified',
                    'title' => $status === 'ELIGIBLE' ? 'Reward qualification available' : 'Reward evaluation completed',
                    'message' => $status === 'ELIGIBLE'
                        ? 'Your frozen monthly result meets an active reward programme rule. The award remains subject to management review.'
                        : 'Your frozen monthly result did not meet an active rule in this reward programme.',
                ]);
            }

            $pdo->prepare(
                'INSERT INTO abh_audit_events
                 (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                  before_json, after_json, request_id, created_at)
                 VALUES ("ADMIN", :admin_id, "reward.qualification.evaluated", "reward_program", :program_id,
                         NULL, :after_json, :request_id, UTC_TIMESTAMP(6))'
            )->execute([
                'admin_id' => $adminId,
                'program_id' => $programId,
                'after_json' => json_encode([
                    'period_key' => (string) $program['period_key'],
                    'evaluated_count' => count($snapshots),
                    'eligible_count' => $eligibleCount,
                    'ineligible_count' => count($snapshots) - $eligibleCount,
                ], JSON_THROW_ON_ERROR),
                'request_id' => RequestId::current(),
            ]);

            return [
                'program_id' => $programId, 'period_key' => (string) $program['period_key'],
                'evaluated_count' => count($snapshots), 'eligible_count' => $eligibleCount,
                'ineligible_count' => count($snapshots) - $eligibleCount, 'idempotent_replay' => false,
            ];
        });
    }

    /** @return array<string,mixed>|null */
    private static function loadProgram(int $programId): ?array
    {
        $row = Connection::fetchAll(
            'SELECT p.id, p.status, p.reporting_period_id, rp.period_key, rp.status AS period_status
               FROM abh_reward_programs p
               INNER JOIN abh_reporting_periods rp ON rp.id = p.reporting_period_id
              WHERE p.id = :id LIMIT 1',
            ['id' => $programId]
        )[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private static function loadRules(int $programId): array
    {
        return Connection::fetchAll(
            'SELECT id, rule_key, rule_version, rank_from, rank_to, minimum_approved_quantity,
                    eligibility_conditions_json
               FROM abh_reward_rules
              WHERE program_id = :program_id AND is_active = 1
              ORDER BY rule_key ASC, rule_version DESC',
            ['program_id' => $programId]
        );
    }

    /** @return list<array<string,mixed>> */
    private static function loadSnapshots(int $periodId): array
    {
        return Connection::fetchAll(
            'SELECT agent_id, rank_position, approved_quantity, agent_code_snapshot, display_name_snapshot
               FROM abh_monthly_leaderboard_snapshots
              WHERE reporting_period_id = :period_id
              ORDER BY rank_position ASC, agent_id ASC',
            ['period_id' => $periodId]
        );
    }

    /** @param list<array<string,mixed>> $rules @return array<string,mixed>|null */
    private static function matchingRule(array $rules, int $rank, int $quantity): ?array
    {
        foreach ($rules as $rule) {
            $from = $rule['rank_from'] === null ? null : (int) $rule['rank_from'];
            $to = $rule['rank_to'] === null ? null : (int) $rule['rank_to'];
            $minimum = $rule['minimum_approved_quantity'] === null
                ? null : (int) $rule['minimum_approved_quantity'];
            if ($from !== null && $rank < $from) continue;
            if ($to !== null && $rank > $to) continue;
            if ($minimum !== null && $quantity < $minimum) continue;
            return $rule;
        }
        return null;
    }

    /** @param array<string,mixed> $program @param list<array<string,mixed>> $rules
     *  @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private static function buildPreview(array $program, array $rules, array $rows): array
    {
        $items = [];
        $eligible = 0;
        foreach ($rows as $row) {
            $rank = (int) $row['rank_position'];
            $quantity = (int) $row['approved_quantity'];
            $matched = self::matchingRule($rules, $rank, $quantity);
            if ($matched !== null) $eligible++;
            $items[] = [
                'agent_id' => (int) $row['agent_id'],
                'agent_code' => (string) $row['agent_code_snapshot'],
                'display_name' => (string) $row['display_name_snapshot'],
                'rank_position' => $rank,
                'approved_quantity' => $quantity,
                'qualification_status' => $matched === null ? 'INELIGIBLE' : 'ELIGIBLE',
                'matched_rule' => $matched === null ? null : [
                    'id' => (int) $matched['id'], 'rule_key' => (string) $matched['rule_key'],
                    'rule_version' => (int) $matched['rule_version'],
                ],
            ];
        }
        return [
            'program_id' => (int) $program['id'],
            'period_key' => (string) $program['period_key'],
            'period_status' => (string) $program['period_status'],
            'program_status' => (string) $program['status'],
            'snapshot_count' => count($rows),
            'eligible_count' => $eligible,
            'ineligible_count' => count($rows) - $eligible,
            'items' => $items,
            'read_only' => true,
        ];
    }
}
