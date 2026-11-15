<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;

/** Administrator account and single-role assignment management. */
final class AbhAdminUserRepository
{
    private function __construct() {}

    /** @return array{users:list<array<string,mixed>>,roles:list<array<string,mixed>>} */
    public static function listing(): array
    {
        $users = Connection::fetchAll(
            'SELECT a.id, a.full_name, a.email, a.status, a.last_login_at, a.created_at,
                    r.role_key, r.role_name
               FROM abh_admin_users a
               LEFT JOIN abh_admin_user_roles ur ON ur.admin_user_id = a.id
               LEFT JOIN abh_admin_roles r ON r.id = ur.role_id
              ORDER BY a.full_name ASC, a.id ASC'
        );
        $roles = Connection::fetchAll(
            'SELECT r.id, r.role_key, r.role_name, r.description,
                    GROUP_CONCAT(p.permission_key ORDER BY p.permission_key SEPARATOR ",") AS permissions
               FROM abh_admin_roles r
               LEFT JOIN abh_admin_role_permissions rp ON rp.role_id = r.id
               LEFT JOIN abh_admin_permissions p ON p.id = rp.permission_id
              GROUP BY r.id, r.role_key, r.role_name, r.description
              ORDER BY r.role_name ASC'
        );
        foreach ($roles as &$role) {
            $role['permissions'] = $role['permissions'] === null || $role['permissions'] === ''
                ? [] : explode(',', (string) $role['permissions']);
        }
        unset($role);
        return ['users' => $users, 'roles' => $roles];
    }

    /** @return array<string,mixed> */
    public static function create(int $actorId, string $name, string $email, string $password, string $roleKey): array
    {
        return Connection::transaction(static function (\PDO $pdo) use ($actorId, $name, $email, $password, $roleKey): array {
            $role = $pdo->prepare('SELECT id, role_key FROM abh_admin_roles WHERE role_key = :key LIMIT 1 FOR UPDATE');
            $role->execute(['key' => $roleKey]);
            $roleRow = $role->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($roleRow)) return ['error' => 'role_not_found'];

            $insert = $pdo->prepare(
                'INSERT INTO abh_admin_users
                 (full_name, email, email_normalized, password_hash, status, must_reset_password, created_at)
                 VALUES (:name, :email, :normalized, :hash, "ACTIVE", 0, UTC_TIMESTAMP(6))'
            );
            $insert->execute([
                'name' => $name, 'email' => $email, 'normalized' => strtolower($email),
                'hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $id = (int) $pdo->lastInsertId();
            $assign = $pdo->prepare(
                'INSERT INTO abh_admin_user_roles (admin_user_id, role_id, assigned_by_admin_id)
                 VALUES (:id, :role_id, :actor)'
            );
            $assign->execute(['id' => $id, 'role_id' => (int) $roleRow['id'], 'actor' => $actorId]);
            self::audit($pdo, $actorId, 'admin.user.created', $id, [
                'full_name' => $name, 'email' => strtolower($email), 'role' => $roleKey, 'status' => 'ACTIVE',
            ]);
            return ['id' => $id, 'full_name' => $name, 'email' => strtolower($email), 'role_key' => $roleKey, 'status' => 'ACTIVE'];
        });
    }

    /** @return array<string,mixed> */
    public static function update(int $actorId, int $targetId, string $status, string $roleKey): array
    {
        return Connection::transaction(static function (\PDO $pdo) use ($actorId, $targetId, $status, $roleKey): array {
            $target = $pdo->prepare(
                'SELECT a.id, a.status, r.role_key
                   FROM abh_admin_users a
                   LEFT JOIN abh_admin_user_roles ur ON ur.admin_user_id = a.id
                   LEFT JOIN abh_admin_roles r ON r.id = ur.role_id
                  WHERE a.id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute(['id' => $targetId]);
            $before = $target->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($before)) return ['error' => 'admin_not_found'];

            $role = $pdo->prepare('SELECT id, role_key FROM abh_admin_roles WHERE role_key = :key LIMIT 1 FOR UPDATE');
            $role->execute(['key' => $roleKey]);
            $roleRow = $role->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($roleRow)) return ['error' => 'role_not_found'];

            $wasSuper = (string) ($before['role_key'] ?? '') === 'SUPER_ADMIN'
                && (string) $before['status'] === 'ACTIVE';
            $willBeSuper = $roleKey === 'SUPER_ADMIN' && $status === 'ACTIVE';
            if ($wasSuper && !$willBeSuper) {
                $count = (int) $pdo->query(
                    'SELECT COUNT(DISTINCT a.id)
                       FROM abh_admin_users a
                       INNER JOIN abh_admin_user_roles ur ON ur.admin_user_id = a.id
                       INNER JOIN abh_admin_roles r ON r.id = ur.role_id
                      WHERE a.status = "ACTIVE" AND r.role_key = "SUPER_ADMIN"'
                )->fetchColumn();
                if ($count <= 1) return ['error' => 'last_super_admin'];
            }
            if ($targetId === $actorId && !$willBeSuper) return ['error' => 'self_lockout'];

            $pdo->prepare('UPDATE abh_admin_users SET status = :status WHERE id = :id')
                ->execute(['status' => $status, 'id' => $targetId]);
            $pdo->prepare('DELETE FROM abh_admin_user_roles WHERE admin_user_id = :id')
                ->execute(['id' => $targetId]);
            $pdo->prepare(
                'INSERT INTO abh_admin_user_roles (admin_user_id, role_id, assigned_by_admin_id)
                 VALUES (:id, :role_id, :actor)'
            )->execute(['id' => $targetId, 'role_id' => (int) $roleRow['id'], 'actor' => $actorId]);

            // Role/status changes take effect immediately, including in already-open browser sessions.
            $pdo->prepare(
                'UPDATE abh_admin_sessions
                    SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                        revoked_reason = CASE WHEN revoked_reason IS NULL THEN "ADMIN_ACCESS_CHANGED" ELSE revoked_reason END
                  WHERE admin_user_id = :id AND revoked_at IS NULL'
            )->execute(['id' => $targetId]);

            self::audit($pdo, $actorId, 'admin.user.updated', $targetId, [
                'previous_status' => (string) $before['status'],
                'previous_role' => (string) ($before['role_key'] ?? ''),
                'status' => $status, 'role' => $roleKey,
            ]);
            return ['id' => $targetId, 'status' => $status, 'role_key' => $roleKey, 'sessions_revoked' => true];
        });
    }

    /** @param array<string,mixed> $after */
    private static function audit(\PDO $pdo, int $actorId, string $action, int $entityId, array $after): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO abh_audit_events
             (actor_type, actor_admin_user_id, action_key, entity_type, entity_id, after_json, created_at)
             VALUES ("ADMIN", :actor, :action, "admin_user", :entity, :after, UTC_TIMESTAMP(6))'
        );
        $stmt->execute([
            'actor' => $actorId, 'action' => $action, 'entity' => $entityId,
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }
}
