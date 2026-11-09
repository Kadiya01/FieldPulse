<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Agent-facing notification history.
 *
 * Notifications are append-only except for read_at. They are deliberately not
 * derived from submissions on read: a decision that was made last month must
 * still be explainable after the submission row has been archived, and a
 * status change has no submission row at all.
 */
final class NotificationRepository extends Repository
{
    public function create(
        int $agentId,
        string $type,
        string $title,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null
    ): int {
        $this->exec(
            'INSERT INTO notifications
                (agent_id, type, title, message, entity_type, entity_id, created_at)
             VALUES
                (:agent_id, :type, :title, :message, :entity_type, :entity_id, UTC_TIMESTAMP())',
            [
                'agent_id'    => $agentId,
                'type'        => $type,
                'title'       => $title,
                'message'     => $message,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ]
        );

        return (int) Connection::lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listForAgent(int $agentId, int $limit = 50, int $offset = 0): array
    {
        return $this->all(
            'SELECT id, type, title, message, entity_type, entity_id, read_at, created_at
               FROM notifications
              WHERE agent_id = :agent_id
              ORDER BY id DESC' . self::limitClause($limit, 100, $offset),
            ['agent_id' => $agentId]
        );
    }

    public function unreadCount(int $agentId): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM notifications WHERE agent_id = :agent_id AND read_at IS NULL',
            ['agent_id' => $agentId]
        );
    }

    public function markAllRead(int $agentId): int
    {
        return $this->exec(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
              WHERE agent_id = :agent_id AND read_at IS NULL',
            ['agent_id' => $agentId]
        );
    }

    public function markRead(int $agentId, int $notificationId): bool
    {
        return $this->exec(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
              WHERE id = :id AND agent_id = :agent_id AND read_at IS NULL',
            ['id' => $notificationId, 'agent_id' => $agentId]
        ) === 1;
    }
}
