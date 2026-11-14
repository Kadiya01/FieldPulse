<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Recipient-scoped ABH notification inboxes. Caller identity is always taken
 * from the authenticated request context, never from client-supplied fields.
 */
final class AbhNotificationRepository
{
    private function __construct()
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,unread_count:int,limit:int,offset:int} */
    public static function listForAgent(int $agentId, int $limit, int $offset): array
    {
        $total = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_notifications
              WHERE recipient_type = "AGENT" AND recipient_agent_id = :agent_id',
            ['agent_id' => $agentId]
        );
        $unread = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_notifications
              WHERE recipient_type = "AGENT"
                AND recipient_agent_id = :agent_id
                AND read_at IS NULL',
            ['agent_id' => $agentId]
        );
        $items = Connection::fetchAll(
            'SELECT id, event_key, title, message, read_at, created_at
               FROM abh_notifications
              WHERE recipient_type = "AGENT" AND recipient_agent_id = :agent_id
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['agent_id' => $agentId]
        );

        return ['items' => $items, 'total' => $total, 'unread_count' => $unread, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,unread_count:int,limit:int,offset:int} */
    public static function listForAdmin(int $adminId, int $limit, int $offset): array
    {
        $total = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_notifications
              WHERE recipient_type = "ADMIN" AND recipient_admin_user_id = :admin_id',
            ['admin_id' => $adminId]
        );
        $unread = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_notifications
              WHERE recipient_type = "ADMIN"
                AND recipient_admin_user_id = :admin_id
                AND read_at IS NULL',
            ['admin_id' => $adminId]
        );
        $items = Connection::fetchAll(
            'SELECT id, event_key, title, message, read_at, created_at
               FROM abh_notifications
              WHERE recipient_type = "ADMIN" AND recipient_admin_user_id = :admin_id
              ORDER BY created_at DESC, id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['admin_id' => $adminId]
        );

        return ['items' => $items, 'total' => $total, 'unread_count' => $unread, 'limit' => $limit, 'offset' => $offset];
    }

    public static function markAgentRead(int $agentId, ?int $notificationId): int
    {
        return self::markRead('AGENT', $agentId, $notificationId);
    }

    public static function markAdminRead(int $adminId, ?int $notificationId): int
    {
        return self::markRead('ADMIN', $adminId, $notificationId);
    }

    private static function markRead(string $recipientType, int $recipientId, ?int $notificationId): int
    {
        return Connection::transaction(
            static function (\PDO $pdo) use ($recipientType, $recipientId, $notificationId): int {
                $where = $recipientType === 'AGENT'
                    ? 'recipient_type = "AGENT" AND recipient_agent_id = :recipient_id'
                    : 'recipient_type = "ADMIN" AND recipient_admin_user_id = :recipient_id';
                $params = ['recipient_id' => $recipientId];
                if ($notificationId !== null) {
                    $where .= ' AND id = :notification_id';
                    $params['notification_id'] = $notificationId;
                }
                $stmt = $pdo->prepare(
                    'UPDATE abh_notifications
                        SET read_at = UTC_TIMESTAMP(6)
                      WHERE ' . $where . ' AND read_at IS NULL'
                );
                $stmt->execute($params);

                if ($notificationId !== null && $stmt->rowCount() === 0) {
                    $owner = $pdo->prepare(
                        'SELECT COUNT(*)
                           FROM abh_notifications
                          WHERE id = :notification_id AND ' . $where
                    );
                    $owner->execute($params);
                    if ((int) $owner->fetchColumn() === 0) {
                        throw new ApiException(404, ErrorCode::NOT_FOUND, 'Notification not found.');
                    }
                }

                return $stmt->rowCount();
            }
        );
    }
}
