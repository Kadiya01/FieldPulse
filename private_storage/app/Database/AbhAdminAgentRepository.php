<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\RequestId;

/**
 * Administrator-only agent and device management.
 * Mutations, notifications, session revocation and audit records commit together.
 */
final class AbhAdminAgentRepository
{
    private function __construct()
    {
    }

    /** @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int} */
    public static function listAgents(string $status, string $search, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($status !== 'ALL') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(a.full_name LIKE :search_name OR a.agent_code LIKE :search_code OR a.phone LIKE :search_phone OR a.email LIKE :search_email OR a.location_text LIKE :search_location)';
            $pattern = '%' . $search . '%';
            $params['search_name'] = $pattern;
            $params['search_code'] = $pattern;
            $params['search_phone'] = $pattern;
            $params['search_email'] = $pattern;
            $params['search_location'] = $pattern;
        }
        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $total = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_agents a ' . $clause,
            $params
        );
        $rows = Connection::fetchAll(
            'SELECT a.id, a.agent_code, a.full_name, a.phone, a.email, a.location_text,
                    a.status, a.status_reason, a.registered_at, a.approved_at,
                    (SELECT COUNT(*) FROM abh_agent_devices d WHERE d.agent_id = a.id) AS device_count,
                    (SELECT COUNT(*) FROM abh_agent_devices d WHERE d.agent_id = a.id AND d.status = "PENDING") AS pending_device_count,
                    (SELECT COUNT(*) FROM abh_agent_devices d WHERE d.agent_id = a.id AND d.status = "ACTIVE") AS active_device_count
               FROM abh_agents a ' . $clause . '
              ORDER BY CASE a.status WHEN "PENDING" THEN 0 WHEN "ACTIVE" THEN 1 ELSE 2 END,
                       a.registered_at DESC, a.id DESC
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{agent:array<string,mixed>,devices:list<array<string,mixed>>}|null */
    public static function agentDetails(int $agentId): ?array
    {
        $agent = Connection::fetchOne(
            'SELECT id, agent_code, full_name, phone, email, location_text, status,
                    status_reason, registered_at, approved_at, approved_by_admin_id
               FROM abh_agents WHERE id = :id LIMIT 1',
            ['id' => $agentId]
        );
        if ($agent === null) {
            return null;
        }

        $devices = Connection::fetchAll(
            'SELECT id, device_type, imei_value, status, registered_at, verified_at, revoked_at, last_seen_at
               FROM abh_agent_devices
              WHERE agent_id = :agent_id
              ORDER BY registered_at DESC, id DESC',
            ['agent_id' => $agentId]
        );

        return ['agent' => $agent, 'devices' => $devices];
    }

    /**
     * @return array{agent_id:int,old_status:string,new_status:string}
     */
    public static function decideAgent(int $adminId, int $agentId, string $decision, ?string $reason): array
    {
        $result = Connection::transaction(
            static function (\PDO $pdo) use ($adminId, $agentId, $decision, $reason): array {
                $stmt = $pdo->prepare(
                    'SELECT id, status, status_reason, approved_at
                       FROM abh_agents WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['id' => $agentId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    return ['error' => 'not_found'];
                }

                $before = (string) $row['status'];
                $allowed = match ($decision) {
                    'APPROVE', 'REJECT' => $before === 'PENDING',
                    'SUSPEND' => $before === 'ACTIVE',
                    'REACTIVATE' => $before === 'SUSPENDED',
                    default => false,
                };
                if (!$allowed) {
                    return ['error' => 'state_conflict'];
                }

                $after = match ($decision) {
                    'APPROVE', 'REACTIVATE' => 'ACTIVE',
                    'REJECT' => 'REJECTED',
                    'SUSPEND' => 'SUSPENDED',
                };

                $update = $pdo->prepare(
                    'UPDATE abh_agents
                        SET status = :status,
                            status_reason = :reason,
                            approved_at = CASE WHEN :approved_at = 1 THEN COALESCE(approved_at, UTC_TIMESTAMP(6)) ELSE approved_at END,
                            approved_by_admin_id = CASE WHEN :approved_by = 1 THEN COALESCE(approved_by_admin_id, :admin_id) ELSE approved_by_admin_id END,
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :id'
                );
                $approved = $decision === 'APPROVE';
                $update->execute([
                    'status' => $after,
                    'reason' => $reason,
                    'approved_at' => $approved ? 1 : 0,
                    'approved_by' => $approved ? 1 : 0,
                    'admin_id' => $adminId,
                    'id' => $agentId,
                ]);

                if (in_array($after, ['REJECTED', 'SUSPENDED'], true)) {
                    $pdo->prepare(
                        'UPDATE abh_agent_sessions
                            SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                                revoked_reason = CASE WHEN revoked_reason IS NULL THEN :reason ELSE revoked_reason END
                          WHERE agent_id = :agent_id AND revoked_at IS NULL'
                    )->execute([
                        'reason' => $after === 'REJECTED' ? 'AGENT_REJECTED' : 'AGENT_SUSPENDED',
                        'agent_id' => $agentId,
                    ]);
                }

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                      reason, before_json, after_json, request_id, created_at)
                     VALUES ("ADMIN", :admin_id, :action, "agent", :agent_id,
                             :reason, :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'action' => 'agent.' . strtolower($decision),
                    'agent_id' => $agentId,
                    'reason' => $reason,
                    'before_json' => json_encode(['status' => $before], JSON_THROW_ON_ERROR),
                    'after_json' => json_encode(['status' => $after], JSON_THROW_ON_ERROR),
                    'request_id' => RequestId::current(),
                ]);

                $title = match ($decision) {
                    'APPROVE' => 'Agent application approved',
                    'REJECT' => 'Agent application not approved',
                    'SUSPEND' => 'Agent account suspended',
                    'REACTIVATE' => 'Agent account reactivated',
                };
                $message = match ($decision) {
                    'APPROVE' => 'Your ABH agent account has been approved. Your registered device must also be authorised before sign in.',
                    'REJECT' => 'Your ABH agent application was not approved. Contact the administrator if you need clarification.',
                    'SUSPEND' => 'Your ABH agent account has been suspended. Contact the administrator for assistance.',
                    'REACTIVATE' => 'Your ABH agent account has been reactivated. Sign in again to continue.',
                };
                $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_agent_id, event_key, title, message, created_at)
                     VALUES ("AGENT", :agent_id, :event_key, :title, :message, UTC_TIMESTAMP(6))'
                )->execute([
                    'agent_id' => $agentId,
                    'event_key' => 'agent.' . strtolower($decision),
                    'title' => $title,
                    'message' => $message,
                ]);

                return ['agent_id' => $agentId, 'old_status' => $before, 'new_status' => $after];
            }
        );

        self::throwDecisionError($result);

        return $result;
    }

    /** @return array{device_id:int,agent_id:int,old_status:string,new_status:string} */
    public static function decideDevice(int $adminId, int $deviceId, string $decision, ?string $reason): array
    {
        $result = Connection::transaction(
            static function (\PDO $pdo) use ($adminId, $deviceId, $decision, $reason): array {
                $stmt = $pdo->prepare(
                    'SELECT d.id, d.agent_id, d.status AS device_status, a.status AS agent_status
                       FROM abh_agent_devices d
                       INNER JOIN abh_agents a ON a.id = d.agent_id
                      WHERE d.id = :id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['id' => $deviceId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    return ['error' => 'not_found'];
                }

                $before = (string) $row['device_status'];
                if ($decision === 'APPROVE') {
                    if ($before !== 'PENDING' || $row['agent_status'] !== 'ACTIVE') {
                        return ['error' => 'state_conflict'];
                    }
                    $after = 'ACTIVE';
                } elseif ($decision === 'REVOKE') {
                    if ($before !== 'ACTIVE') {
                        return ['error' => 'state_conflict'];
                    }
                    $after = 'REVOKED';
                } else {
                    return ['error' => 'state_conflict'];
                }

                $pdo->prepare(
                    'UPDATE abh_agent_devices
                        SET status = :status,
                            verified_at = CASE WHEN :is_approved = 1 THEN UTC_TIMESTAMP(6) ELSE verified_at END,
                            verified_by_admin_id = CASE WHEN :is_approved = 1 THEN :admin_id ELSE verified_by_admin_id END,
                            revoked_at = CASE WHEN :is_revoked = 1 THEN UTC_TIMESTAMP(6) ELSE NULL END,
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :id'
                )->execute([
                    'status' => $after,
                    'is_approved' => $after === 'ACTIVE' ? 1 : 0,
                    'admin_id' => $adminId,
                    'is_revoked' => $after === 'REVOKED' ? 1 : 0,
                    'id' => $deviceId,
                ]);

                if ($after === 'REVOKED') {
                    $pdo->prepare(
                        'UPDATE abh_agent_sessions
                            SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                                revoked_reason = CASE WHEN revoked_reason IS NULL THEN "DEVICE_REVOKED" ELSE revoked_reason END
                          WHERE device_id = :device_id AND revoked_at IS NULL'
                    )->execute(['device_id' => $deviceId]);
                }

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                      reason, before_json, after_json, request_id, created_at)
                     VALUES ("ADMIN", :admin_id, :action, "agent_device", :device_id,
                             :reason, :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'action' => 'device.' . strtolower($decision),
                    'device_id' => $deviceId,
                    'reason' => $reason,
                    'before_json' => json_encode(['status' => $before], JSON_THROW_ON_ERROR),
                    'after_json' => json_encode(['status' => $after], JSON_THROW_ON_ERROR),
                    'request_id' => RequestId::current(),
                ]);

                $message = $after === 'ACTIVE'
                    ? 'Your registered device has been authorised. You can now sign in on this browser.'
                    : 'Your registered device has been revoked. Contact the administrator to register a replacement.';
                $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_agent_id, event_key, title, message, created_at)
                     VALUES ("AGENT", :agent_id, :event_key, :title, :message, UTC_TIMESTAMP(6))'
                )->execute([
                    'agent_id' => $row['agent_id'],
                    'event_key' => 'device.' . strtolower($decision),
                    'title' => $after === 'ACTIVE' ? 'Device authorised' : 'Device access revoked',
                    'message' => $message,
                ]);

                return [
                    'device_id' => $deviceId,
                    'agent_id' => (int) $row['agent_id'],
                    'old_status' => $before,
                    'new_status' => $after,
                ];
            }
        );

        self::throwDecisionError($result);

        return $result;
    }

    /** @param array<string,mixed> $result */
    private static function throwDecisionError(array $result): void
    {
        if (($result['error'] ?? null) === 'not_found') {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'The requested ABH record was not found.');
        }
        if (($result['error'] ?? null) === 'state_conflict') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The requested status change is not allowed from the current state.');
        }
    }
}
