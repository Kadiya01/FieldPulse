<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\RequestId;

/** Gift catalogue, award assignment/approval and append-only fulfilment ledger. */
final class AbhAdminRewardLifecycleRepository
{
    private function __construct() {}

    /** @return array<string,mixed> */
    public static function list(int $programId): array
    {
        $gifts = Connection::fetchAll(
            'SELECT g.id, g.program_id, g.gift_code, g.gift_title, g.gift_description,
                    g.available_quantity, g.estimated_value, g.currency_code, g.status,
                    COALESCE(x.assigned_quantity, 0) AS assigned_quantity
               FROM abh_reward_gifts g
               LEFT JOIN (
                    SELECT gift_id, SUM(gift_quantity) AS assigned_quantity
                      FROM abh_reward_awards
                     WHERE award_status <> "REJECTED"
                     GROUP BY gift_id
               ) x ON x.gift_id = g.id
              WHERE g.program_id = :program_id
              ORDER BY g.created_at DESC, g.id DESC',
            ['program_id' => $programId]
        );
        $qualifications = Connection::fetchAll(
            'SELECT q.id, q.agent_id, q.qualification_status, q.frozen_rank, q.frozen_approved_quantity,
                    ag.agent_code, ag.full_name AS display_name
               FROM abh_reward_qualifications q
               INNER JOIN abh_agents ag ON ag.id = q.agent_id
              WHERE q.program_id = :program_id AND q.qualification_status = "ELIGIBLE"
                AND NOT EXISTS (
                    SELECT 1 FROM abh_reward_awards a
                     WHERE a.qualification_id = q.id AND a.award_status <> "REJECTED"
                )
              ORDER BY q.frozen_rank ASC, q.agent_id ASC',
            ['program_id' => $programId]
        );
        $awards = Connection::fetchAll(
            'SELECT a.id, a.qualification_id, a.program_id, a.gift_id, a.agent_id,
                    a.gift_title_snapshot, a.gift_description_snapshot, a.gift_quantity,
                    a.estimated_value_snapshot, a.currency_code_snapshot, a.award_status,
                    a.assigned_by_admin_id, a.approved_by_admin_id, a.assigned_at, a.approved_at,
                    ag.agent_code, ag.full_name AS display_name,
                    COALESCE(SUM(CASE WHEN e.event_type = "PAYMENT" THEN e.amount ELSE 0 END), 0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN e.event_type = "DELIVERY" THEN e.quantity ELSE 0 END), 0) AS delivered_quantity
               FROM abh_reward_awards a
               INNER JOIN abh_agents ag ON ag.id = a.agent_id
               LEFT JOIN abh_reward_fulfilment_events e ON e.award_id = a.id
              WHERE a.program_id = :program_id
              GROUP BY a.id, a.qualification_id, a.program_id, a.gift_id, a.agent_id,
                       a.gift_title_snapshot, a.gift_description_snapshot, a.gift_quantity,
                       a.estimated_value_snapshot, a.currency_code_snapshot, a.award_status,
                       a.assigned_by_admin_id, a.approved_by_admin_id, a.assigned_at, a.approved_at,
                       ag.agent_code, ag.display_name_snapshot
              ORDER BY a.assigned_at DESC, a.id DESC',
            ['program_id' => $programId]
        );
        $events = Connection::fetchAll(
            'SELECT e.id, e.award_id, e.event_type, e.quantity, e.amount, e.currency_code,
                    e.reference_no, e.note, e.recorded_by_admin_id, e.created_at
               FROM abh_reward_fulfilment_events e
               INNER JOIN abh_reward_awards a ON a.id = e.award_id
              WHERE a.program_id = :program_id
              ORDER BY e.created_at DESC, e.id DESC',
            ['program_id' => $programId]
        );
        return ['gifts' => $gifts, 'qualifications' => $qualifications, 'awards' => $awards, 'events' => $events];
    }

    /** @return array<string,mixed> */
    public static function createGift(int $adminId, int $programId, string $code, string $title,
        ?string $description, ?int $stock, ?string $value, ?string $currency): array
    {
        return Connection::transaction(static function (\PDO $pdo) use (
            $adminId, $programId, $code, $title, $description, $stock, $value, $currency
        ): array {
            $program = $pdo->prepare('SELECT id, status FROM abh_reward_programs WHERE id = :id FOR UPDATE');
            $program->execute(['id' => $programId]);
            $row = $program->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return ['error' => 'program_not_found'];
            if ((string) $row['status'] !== 'ACTIVE') return ['error' => 'program_not_active'];
            $insert = $pdo->prepare(
                'INSERT INTO abh_reward_gifts
                 (program_id, gift_code, gift_title, gift_description, available_quantity,
                  estimated_value, currency_code, status, created_by_admin_id)
                 VALUES (:program_id, :code, :title, :description, :stock, :value, :currency, "ACTIVE", :admin_id)'
            );
            $insert->execute([
                'program_id' => $programId, 'code' => $code, 'title' => $title,
                'description' => $description, 'stock' => $stock, 'value' => $value,
                'currency' => $currency, 'admin_id' => $adminId,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::audit($pdo, $adminId, 'reward.gift.created', 'reward_gift', $id, null, [
                'program_id' => $programId, 'gift_code' => $code, 'gift_title' => $title,
                'available_quantity' => $stock, 'estimated_value' => $value, 'currency_code' => $currency,
            ]);
            return ['id' => $id, 'gift_code' => $code, 'gift_title' => $title, 'status' => 'ACTIVE'];
        });
    }

    /** @return array<string,mixed> */
    public static function assignGift(int $adminId, int $programId, int $qualificationId,
        int $giftId, int $quantity): array
    {
        return Connection::transaction(static function (\PDO $pdo) use (
            $adminId, $programId, $qualificationId, $giftId, $quantity
        ): array {
            $stmt = $pdo->prepare(
                'SELECT q.id, q.agent_id, q.reporting_period_id, q.qualification_status,
                        p.status AS program_status, rp.status AS period_status,
                        g.id AS gift_id, g.gift_title, g.gift_description, g.available_quantity,
                        g.estimated_value, g.currency_code, g.status AS gift_status
                   FROM abh_reward_qualifications q
                   INNER JOIN abh_reward_programs p ON p.id = q.program_id
                   INNER JOIN abh_reporting_periods rp ON rp.id = q.reporting_period_id
                   INNER JOIN abh_reward_gifts g ON g.id = :gift_id AND g.program_id = p.id
                  WHERE q.id = :qualification_id AND q.program_id = :program_id
                  LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['gift_id' => $giftId, 'qualification_id' => $qualificationId, 'program_id' => $programId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return ['error' => 'qualification_or_gift_not_found'];
            if ((string) $row['program_status'] !== 'ACTIVE' || (string) $row['period_status'] !== 'CLOSED') return ['error' => 'program_or_period_locked'];
            if ((string) $row['qualification_status'] !== 'ELIGIBLE') return ['error' => 'agent_not_eligible'];
            if ((string) $row['gift_status'] !== 'ACTIVE') return ['error' => 'gift_not_active'];
            if ($row['available_quantity'] !== null && (int) $row['available_quantity'] < $quantity) return ['error' => 'insufficient_stock'];

            $exists = $pdo->prepare('SELECT id FROM abh_reward_awards WHERE qualification_id = :qualification_id AND gift_id = :gift_id LIMIT 1');
            $exists->execute(['qualification_id' => $qualificationId, 'gift_id' => $giftId]);
            if ($exists->fetchColumn()) return ['error' => 'award_already_assigned'];

            $insert = $pdo->prepare(
                'INSERT INTO abh_reward_awards
                 (qualification_id, program_id, gift_id, agent_id, reporting_period_id,
                  gift_title_snapshot, gift_description_snapshot, gift_quantity,
                  estimated_value_snapshot, currency_code_snapshot, assigned_by_admin_id, award_status)
                 VALUES (:qualification_id, :program_id, :gift_id, :agent_id, :period_id,
                         :title, :description, :quantity, :value, :currency, :admin_id, "PENDING_APPROVAL")'
            );
            $insert->execute([
                'qualification_id' => $qualificationId, 'program_id' => $programId, 'gift_id' => $giftId,
                'agent_id' => (int) $row['agent_id'], 'period_id' => (int) $row['reporting_period_id'],
                'title' => (string) $row['gift_title'], 'description' => $row['gift_description'],
                'quantity' => $quantity, 'value' => $row['estimated_value'],
                'currency' => $row['currency_code'], 'admin_id' => $adminId,
            ]);
            $awardId = (int) $pdo->lastInsertId();
            self::notify($pdo, (int) $row['agent_id'], 'reward.award_pending',
                'Reward pending approval', 'Management has proposed a reward for you. It is not approved yet.');
            self::audit($pdo, $adminId, 'reward.award.assigned', 'reward_award', $awardId, null, [
                'qualification_id' => $qualificationId, 'gift_id' => $giftId,
                'gift_title_snapshot' => (string) $row['gift_title'], 'quantity' => $quantity,
                'award_status' => 'PENDING_APPROVAL',
            ]);
            return ['id' => $awardId, 'award_status' => 'PENDING_APPROVAL'];
        });
    }

    /** @return array<string,mixed> */
    public static function approveAward(int $adminId, int $awardId): array
    {
        return Connection::transaction(static function (\PDO $pdo) use ($adminId, $awardId): array {
            $stmt = $pdo->prepare(
                'SELECT a.id, a.agent_id, a.gift_id, a.gift_quantity, a.assigned_by_admin_id,
                        a.award_status, g.available_quantity, g.status AS gift_status
                   FROM abh_reward_awards a
                   INNER JOIN abh_reward_gifts g ON g.id = a.gift_id AND g.program_id = a.program_id
                  WHERE a.id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $awardId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return ['error' => 'award_not_found'];
            if ((int) $row['assigned_by_admin_id'] === $adminId) return ['error' => 'separation_of_duties'];
            if ((string) $row['award_status'] !== 'PENDING_APPROVAL') return ['error' => 'award_not_pending'];
            if ((string) $row['gift_status'] !== 'ACTIVE') return ['error' => 'gift_not_active'];
            if ($row['available_quantity'] !== null && (int) $row['available_quantity'] < (int) $row['gift_quantity']) return ['error' => 'insufficient_stock'];

            if ($row['available_quantity'] !== null) {
                $pdo->prepare('UPDATE abh_reward_gifts SET available_quantity = available_quantity - :quantity, updated_by_admin_id = :admin_id WHERE id = :gift_id')
                    ->execute(['quantity' => (int) $row['gift_quantity'], 'admin_id' => $adminId, 'gift_id' => (int) $row['gift_id']]);
            }
            $pdo->prepare(
                'UPDATE abh_reward_awards SET award_status = "APPROVED", approved_by_admin_id = :admin_id, approved_at = UTC_TIMESTAMP(6)
                  WHERE id = :id AND award_status = "PENDING_APPROVAL"'
            )->execute(['admin_id' => $adminId, 'id' => $awardId]);
            self::notify($pdo, (int) $row['agent_id'], 'reward.award_approved', 'Reward approved', 'Your reward award has been approved by management.');
            self::audit($pdo, $adminId, 'reward.award.approved', 'reward_award', $awardId,
                ['award_status' => 'PENDING_APPROVAL'],
                ['award_status' => 'APPROVED', 'approved_by_admin_id' => $adminId, 'stock_decremented' => $row['available_quantity'] !== null]);
            return ['id' => $awardId, 'award_status' => 'APPROVED'];
        });
    }

    /** @return array<string,mixed> */
    public static function recordEvent(int $adminId, int $awardId, string $type, ?int $quantity,
        ?string $amount, ?string $currency, ?string $reference, ?string $note, string $idempotencyKey): array
    {
        return Connection::transaction(static function (\PDO $pdo) use (
            $adminId, $awardId, $type, $quantity, $amount, $currency, $reference, $note, $idempotencyKey
        ): array {
            $stmt = $pdo->prepare(
                'SELECT id, agent_id, gift_quantity, estimated_value_snapshot,
                        currency_code_snapshot, award_status
                   FROM abh_reward_awards WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $awardId]);
            $award = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($award)) return ['error' => 'award_not_found'];
            $totals = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN event_type = "PAYMENT" THEN amount ELSE 0 END), 0) AS paid_total,
                        COALESCE(SUM(CASE WHEN event_type = "DELIVERY" THEN quantity ELSE 0 END), 0) AS delivered_total
                   FROM abh_reward_fulfilment_events WHERE award_id = :id'
            );
            $totals->execute(['id' => $awardId]);
            $totalsRow = $totals->fetch(\PDO::FETCH_ASSOC) ?: ['paid_total' => 0, 'delivered_total' => 0];
            $award['paid_total'] = $totalsRow['paid_total'];
            $award['delivered_total'] = $totalsRow['delivered_total'];
            $replay = $pdo->prepare('SELECT id, event_type, quantity, amount FROM abh_reward_fulfilment_events WHERE award_id = :award_id AND idempotency_key = :key LIMIT 1');
            $replay->execute(['award_id' => $awardId, 'key' => $idempotencyKey]);
            $existing = $replay->fetch(\PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if ((string) $existing['event_type'] !== $type
                    || ($existing['quantity'] === null ? null : (int) $existing['quantity']) !== $quantity
                    || ($existing['amount'] === null ? null : (string) $existing['amount']) !== $amount) {
                    return ['error' => 'idempotency_conflict'];
                }
                return ['id' => (int) $existing['id'], 'idempotent_replay' => true];
            }
            if ((string) $award['award_status'] !== 'APPROVED') return ['error' => 'award_not_approved'];
            if ($type === 'DELIVERY') {
                if ($quantity === null || $quantity < 1) return ['error' => 'invalid_event'];
                if ((int) $award['delivered_total'] + $quantity > (int) $award['gift_quantity']) return ['error' => 'delivery_exceeds_award'];
            } else {
                if ($amount === null || (float) $amount <= 0 || $currency === null) return ['error' => 'invalid_event'];
                if ($award['estimated_value_snapshot'] !== null
                    && (float) $award['paid_total'] + (float) $amount > (float) $award['estimated_value_snapshot']) return ['error' => 'payment_exceeds_value'];
                if ($award['currency_code_snapshot'] !== null && $currency !== (string) $award['currency_code_snapshot']) return ['error' => 'currency_mismatch'];
            }

            $insert = $pdo->prepare(
                'INSERT INTO abh_reward_fulfilment_events
                 (award_id, idempotency_key, event_type, quantity, amount, currency_code,
                  reference_no, note, recorded_by_admin_id, created_at)
                 VALUES (:award_id, :idempotency_key, :type, :quantity, :amount, :currency,
                         :reference, :note, :admin_id, UTC_TIMESTAMP(6))'
            );
            $insert->execute([
                'award_id' => $awardId, 'idempotency_key' => $idempotencyKey, 'type' => $type,
                'quantity' => $quantity, 'amount' => $amount, 'currency' => $currency,
                'reference' => $reference, 'note' => $note, 'admin_id' => $adminId,
            ]);
            $eventId = (int) $pdo->lastInsertId();
            self::notify($pdo, (int) $award['agent_id'],
                $type === 'PAYMENT' ? 'reward.payment.recorded' : 'reward.delivery.recorded',
                $type === 'PAYMENT' ? 'Reward payment recorded' : 'Reward delivery recorded',
                $type === 'PAYMENT' ? 'A reward payment has been recorded against your approved award.' : 'A reward delivery has been recorded against your approved award.');
            self::audit($pdo, $adminId, 'reward.fulfilment.' . strtolower($type), 'reward_award', $awardId, null, [
                'event_id' => $eventId, 'event_type' => $type, 'quantity' => $quantity,
                'amount' => $amount, 'currency_code' => $currency, 'reference_no' => $reference,
                'idempotency_key' => $idempotencyKey,
            ]);
            return ['id' => $eventId, 'idempotent_replay' => false];
        });
    }

    private static function notify(\PDO $pdo, int $agentId, string $key, string $title, string $message): void
    {
        $pdo->prepare(
            'INSERT INTO abh_notifications
             (recipient_type, recipient_agent_id, event_key, title, message, created_at)
             VALUES ("AGENT", :agent_id, :event_key, :title, :message, UTC_TIMESTAMP(6))'
        )->execute(['agent_id' => $agentId, 'event_key' => $key, 'title' => $title, 'message' => $message]);
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after */
    private static function audit(\PDO $pdo, int $adminId, string $action, string $entity, int $entityId, ?array $before, array $after): void
    {
        $pdo->prepare(
            'INSERT INTO abh_audit_events
             (actor_type, actor_admin_user_id, action_key, entity_type, entity_id, before_json, after_json, request_id, created_at)
             VALUES ("ADMIN", :admin_id, :action, :entity, :entity_id, :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
        )->execute([
            'admin_id' => $adminId, 'action' => $action, 'entity' => $entity, 'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => json_encode($after, JSON_THROW_ON_ERROR), 'request_id' => RequestId::current(),
        ]);
    }
}
