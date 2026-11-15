<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Agent-facing reward read model. Every query is scoped by the authenticated
 * ABH agent ID; callers cannot supply another agent identifier.
 */
final class AbhAgentRewardsRepository
{
    private function __construct() {}

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public static function listForAgent(int $agentId): array
    {
        $items = Connection::fetchAll(
            'SELECT p.id AS program_id, p.program_code, p.name AS program_name,
                    p.description AS program_description, p.status AS program_status,
                    rp.period_key, rp.status AS period_status,
                    q.id AS qualification_id, q.qualification_status,
                    q.frozen_rank, q.frozen_approved_quantity, q.evaluated_at,
                    a.id AS award_id, a.gift_title_snapshot, a.gift_description_snapshot,
                    a.gift_quantity, a.estimated_value_snapshot, a.currency_code_snapshot,
                    a.award_status, a.assigned_at, a.approved_at,
                    COALESCE(ev.paid_total, 0) AS paid_total,
                    COALESCE(ev.delivered_total, 0) AS delivered_total,
                    ev.last_event_at
               FROM abh_reward_programs p
               INNER JOIN abh_reporting_periods rp ON rp.id = p.reporting_period_id
               LEFT JOIN abh_reward_qualifications q
                      ON q.program_id = p.id AND q.agent_id = :agent_id
               LEFT JOIN abh_reward_awards a
                      ON a.qualification_id = q.id AND a.agent_id = :agent_id
               LEFT JOIN (
                    SELECT award_id,
                           SUM(CASE WHEN event_type = "PAYMENT" THEN amount ELSE 0 END) AS paid_total,
                           SUM(CASE WHEN event_type = "DELIVERY" THEN quantity ELSE 0 END) AS delivered_total,
                           MAX(created_at) AS last_event_at
                      FROM abh_reward_fulfilment_events
                     GROUP BY award_id
               ) ev ON ev.award_id = a.id
              WHERE p.status IN ("ACTIVE", "DRAFT", "PAUSED", "CLOSED")
              ORDER BY rp.period_key DESC, p.created_at DESC, p.id DESC',
            ['agent_id' => $agentId]
        );

        foreach ($items as &$item) {
            $item['program_id'] = (int) $item['program_id'];
            $item['qualification_id'] = $item['qualification_id'] === null ? null : (int) $item['qualification_id'];
            $item['award_id'] = $item['award_id'] === null ? null : (int) $item['award_id'];
            $item['frozen_rank'] = $item['frozen_rank'] === null ? null : (int) $item['frozen_rank'];
            $item['frozen_approved_quantity'] = $item['frozen_approved_quantity'] === null ? null : (int) $item['frozen_approved_quantity'];
            $item['gift_quantity'] = $item['gift_quantity'] === null ? null : (int) $item['gift_quantity'];
            $item['paid_total'] = (string) $item['paid_total'];
            $item['delivered_total'] = (int) $item['delivered_total'];
            $item['payment_status'] = self::paymentStatus($item);
            $item['delivery_status'] = self::deliveryStatus($item);
            $item['display_status'] = self::displayStatus($item);
        }
        unset($item);

        return ['items' => $items, 'total' => count($items)];
    }

    /** @param array<string,mixed> $item */
    private static function paymentStatus(array $item): string
    {
        if ($item['award_id'] === null || $item['award_status'] !== 'APPROVED') return 'NOT_STARTED';
        if ($item['estimated_value_snapshot'] === null || (float) $item['estimated_value_snapshot'] <= 0) return 'VALUE_NOT_SET';
        $paid = (float) $item['paid_total'];
        $value = (float) $item['estimated_value_snapshot'];
        if ($paid <= 0) return 'UNPAID';
        return $paid >= $value ? 'PAID' : 'PARTIALLY_PAID';
    }

    /** @param array<string,mixed> $item */
    private static function deliveryStatus(array $item): string
    {
        if ($item['award_id'] === null || $item['award_status'] !== 'APPROVED') return 'NOT_STARTED';
        $delivered = (int) $item['delivered_total'];
        $quantity = (int) $item['gift_quantity'];
        if ($delivered <= 0) return 'NOT_DELIVERED';
        return $delivered >= $quantity ? 'DELIVERED' : 'PARTIALLY_DELIVERED';
    }

    /** @param array<string,mixed> $item */
    private static function displayStatus(array $item): string
    {
        if ($item['program_status'] === 'DRAFT') return 'UPCOMING';
        if ($item['period_status'] === 'OPEN' && $item['qualification_id'] === null) return 'IN_PROGRESS';
        if ($item['qualification_id'] === null) return 'AWAITING_EVALUATION';
        if ($item['qualification_status'] === 'INELIGIBLE') return 'NOT_QUALIFIED';
        if ($item['award_id'] === null) return 'ELIGIBLE';
        if ($item['award_status'] === 'PENDING_APPROVAL') return 'PENDING_APPROVAL';
        if ($item['award_status'] !== 'APPROVED') return (string) $item['award_status'];

        $paid = (float) $item['paid_total'];
        $value = $item['estimated_value_snapshot'] === null ? null : (float) $item['estimated_value_snapshot'];
        $delivered = (int) $item['delivered_total'];
        $quantity = (int) $item['gift_quantity'];

        if ($value !== null && $paid >= $value && $value > 0 && $delivered >= $quantity) return 'PAID_AND_DELIVERED';
        if ($value !== null && $paid > 0 && $paid < $value) return 'PARTIALLY_PAID';
        if ($value !== null && $value > 0 && $paid >= $value) return 'PAID';
        if ($delivered > 0 && $delivered < $quantity) return 'PARTIALLY_DELIVERED';
        if ($quantity > 0 && $delivered >= $quantity) return 'DELIVERED';
        return 'APPROVED';
    }
}
