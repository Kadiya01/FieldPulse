<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;

/**
 * ABH administrator authentication persistence.
 * All statements use abh_admin_* tables and never legacy FieldPulse users.
 */
final class AbhAdminAuthRepository
{
    private function __construct()
    {
    }

    /**
     * Create the first and only bootstrap administrator. The singleton guard
     * row is locked until commit so concurrent setup requests cannot both win.
     *
     * @return array{id:int,full_name:string,email:string}|array{error:string}
     */
    public static function bootstrapFirstAdmin(string $fullName, string $email, string $passwordHash): array
    {
        return Connection::transaction(
            static function (\PDO $pdo) use ($fullName, $email, $passwordHash): array {
                $guard = $pdo->query(
                    'SELECT initialized_at FROM abh_admin_bootstrap_guard
                      WHERE singleton_id = 1 FOR UPDATE'
                )->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($guard)) {
                    return ['error' => 'guard_missing'];
                }
                if ($guard['initialized_at'] !== null) {
                    return ['error' => 'already_initialized'];
                }

                $existingAdmin = $pdo->query(
                    'SELECT id FROM abh_admin_users ORDER BY id ASC LIMIT 1 FOR UPDATE'
                )->fetchColumn();
                if ($existingAdmin !== false) {
                    $pdo->prepare(
                        'UPDATE abh_admin_bootstrap_guard
                            SET initialized_at = COALESCE(initialized_at, UTC_TIMESTAMP(6)),
                                admin_user_id = COALESCE(admin_user_id, :admin_id)
                          WHERE singleton_id = 1'
                    )->execute(['admin_id' => (int) $existingAdmin]);

                    return ['error' => 'already_initialized'];
                }

                $role = $pdo->query(
                    'SELECT id FROM abh_admin_roles WHERE role_key = "SUPER_ADMIN" LIMIT 1'
                )->fetchColumn();
                if ($role === false) {
                    return ['error' => 'role_missing'];
                }

                $insert = $pdo->prepare(
                    'INSERT INTO abh_admin_users
                     (full_name, email, email_normalized, password_hash, status, must_reset_password, created_at)
                     VALUES (:full_name, :email, :email_normalized, :password_hash, "ACTIVE", 0, UTC_TIMESTAMP(6))'
                );
                $insert->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'email_normalized' => $email,
                    'password_hash' => $passwordHash,
                ]);
                $adminId = (int) $pdo->lastInsertId();

                $assignment = $pdo->prepare(
                    'INSERT INTO abh_admin_user_roles (admin_user_id, role_id, assigned_by_admin_id)
                     VALUES (:admin_id, :role_id, NULL)'
                );
                $assignment->execute(['admin_id' => $adminId, 'role_id' => $role]);

                $pdo->prepare(
                    'UPDATE abh_admin_bootstrap_guard
                        SET initialized_at = UTC_TIMESTAMP(6), admin_user_id = :admin_id
                      WHERE singleton_id = 1 AND initialized_at IS NULL'
                )->execute(['admin_id' => $adminId]);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, action_key, entity_type, entity_id, after_json, created_at)
                     VALUES ("SYSTEM", "admin.bootstrap.created", "admin_user", :admin_id,
                             :after_json, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'after_json' => json_encode([
                        'status' => 'ACTIVE',
                        'role' => 'SUPER_ADMIN',
                        'bootstrap' => true,
                    ], JSON_THROW_ON_ERROR),
                ]);

                return ['id' => $adminId, 'full_name' => $fullName, 'email' => $email];
            }
        );
    }

    /** @return array<string,mixed>|null */
    public static function findLoginIdentity(string $email): ?array
    {
        $row = Connection::fetchOne(
            'SELECT id, full_name, email, email_normalized, password_hash, status
               FROM abh_admin_users
              WHERE email_normalized = :email
              LIMIT 1',
            ['email' => $email]
        );

        if ($row === null) {
            return null;
        }

        return self::loadAdmin((int) $row['id']) + [
            'password_hash' => $row['password_hash'],
            'status' => $row['status'],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findByAccessToken(string $rawAccessToken): ?array
    {
        $row = Connection::fetchOne(
            'SELECT s.id AS session_id, s.admin_user_id, a.status
               FROM abh_admin_sessions s
               INNER JOIN abh_admin_users a ON a.id = s.admin_user_id
              WHERE s.access_token_hash = :hash
                AND s.revoked_at IS NULL
                AND s.access_expires_at > UTC_TIMESTAMP(6)
              LIMIT 1',
            ['hash' => hash('sha256', $rawAccessToken)]
        );

        if ($row === null) {
            return null;
        }

        $admin = self::loadAdmin((int) $row['admin_user_id']);
        $admin['status'] = $row['status'];
        $admin['session_id'] = (int) $row['session_id'];

        return $admin;
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_in:int,refresh_expires_at:string,admin:array{id:int,full_name:string,email:string,roles:list<string>,permissions:list<string>}}
     */
    public static function issueSession(array $admin): array
    {
        return Connection::transaction(
            static function (\PDO $pdo) use ($admin): array {
                $issued = self::insertSession($pdo, $admin, Uuid::v4());
                $pdo->prepare(
                    'UPDATE abh_admin_users SET last_login_at = UTC_TIMESTAMP(6) WHERE id = :id'
                )->execute(['id' => $admin['id']]);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id, after_json, created_at)
                     VALUES ("ADMIN", :actor_admin_id, "admin.auth.login", "admin_user", :entity_admin_id, :after_json, UTC_TIMESTAMP(6))'
                )->execute([
                    'actor_admin_id' => $admin['id'],
                    'entity_admin_id' => $admin['id'],
                    'after_json' => json_encode(['status' => 'ACTIVE'], JSON_THROW_ON_ERROR),
                ]);

                return $issued;
            }
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function rotate(string $rawRefreshToken): array
    {
        $hash = hash('sha256', $rawRefreshToken);
        $result = Connection::transaction(
            static function (\PDO $pdo) use ($hash): array {
                $stmt = $pdo->prepare(
                    'SELECT s.*, a.status AS admin_status
                       FROM abh_admin_sessions s
                       INNER JOIN abh_admin_users a ON a.id = s.admin_user_id
                      WHERE s.refresh_token_hash = :hash
                      LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['hash' => $hash]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($row)) {
                    return ['error' => 'invalid'];
                }

                if ($row['revoked_at'] !== null) {
                    if ($row['revoked_reason'] === 'ROTATED') {
                        $pdo->prepare(
                            'UPDATE abh_admin_sessions
                                SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                                    revoked_reason = CASE WHEN revoked_reason IS NULL THEN "REUSE_DETECTED" ELSE revoked_reason END
                              WHERE token_family = :family'
                        )->execute(['family' => $row['token_family']]);

                        $pdo->prepare(
                            'INSERT INTO abh_audit_events
                             (actor_type, actor_admin_user_id, action_key, entity_type, entity_id, after_json, created_at)
                             VALUES ("ADMIN", :actor_admin_id, "admin.auth.refresh_reuse_detected", "admin_user", :entity_admin_id,
                                     :after_json, UTC_TIMESTAMP(6))'
                        )->execute([
                            'actor_admin_id' => $row['admin_user_id'],
                            'entity_admin_id' => $row['admin_user_id'],
                            'after_json' => json_encode(['reason' => 'REUSE_DETECTED', 'token_family' => $row['token_family']], JSON_THROW_ON_ERROR),
                        ]);

                        return ['error' => 'reuse'];
                    }

                    return ['error' => 'invalid'];
                }

                $expiry = Clock::parseSql((string) $row['refresh_expires_at']);
                if ($expiry === null || $expiry->getTimestamp() <= Clock::timestamp()) {
                    $pdo->prepare(
                        'UPDATE abh_admin_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "EXPIRED"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'expired'];
                }

                if ($row['admin_status'] !== 'ACTIVE') {
                    $pdo->prepare(
                        'UPDATE abh_admin_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "ADMIN_INACTIVE"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'admin_inactive'];
                }

                $admin = self::loadAdminWithPdo($pdo, (int) $row['admin_user_id']);
                if ($admin['roles'] === []) {
                    $pdo->prepare(
                        'UPDATE abh_admin_sessions
                            SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "NO_ROLE"
                          WHERE token_family = :family AND revoked_at IS NULL'
                    )->execute(['family' => $row['token_family']]);

                    return ['error' => 'no_role'];
                }

                $issued = self::insertSession($pdo, $admin, (string) $row['token_family']);
                $newHash = hash('sha256', $issued['refresh_token']);
                $newIdStmt = $pdo->prepare(
                    'SELECT id FROM abh_admin_sessions WHERE refresh_token_hash = :hash LIMIT 1'
                );
                $newIdStmt->execute(['hash' => $newHash]);
                $newId = (int) $newIdStmt->fetchColumn();

                $pdo->prepare(
                    'UPDATE abh_admin_sessions
                        SET revoked_at = UTC_TIMESTAMP(6), revoked_reason = "ROTATED",
                            rotated_to_session_id = :new_id, last_used_at = UTC_TIMESTAMP(6)
                      WHERE id = :old_id AND revoked_at IS NULL'
                )->execute(['new_id' => $newId, 'old_id' => $row['id']]);

                return $issued;
            }
        );

        if (isset($result['error'])) {
            $code = (string) $result['error'];
            $status = $code === 'admin_inactive' || $code === 'no_role' ? 403 : 401;
            $errorCode = $code === 'admin_inactive' || $code === 'no_role'
                ? ErrorCode::FORBIDDEN
                : ErrorCode::REFRESH_INVALID;
            $message = $code === 'admin_inactive'
                ? 'This administrator account is not active.'
                : ($code === 'no_role'
                    ? 'This administrator account has no active role assignment.'
                    : 'Administrator refresh session is not valid.');
            throw new ApiException($status, $errorCode, $message);
        }

        return $result;
    }

    public static function revokeRefresh(string $rawRefreshToken): void
    {
        $row = Connection::fetchOne(
            'SELECT token_family FROM abh_admin_sessions
              WHERE refresh_token_hash = :hash LIMIT 1',
            ['hash' => hash('sha256', $rawRefreshToken)]
        );
        if ($row === null) {
            return;
        }

        Connection::execute(
            'UPDATE abh_admin_sessions
                SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP(6)),
                    revoked_reason = CASE WHEN revoked_reason IS NULL THEN "LOGOUT" ELSE revoked_reason END
              WHERE token_family = :family',
            ['family' => $row['token_family']]
        );
    }

    /** @return array{id:int,full_name:string,email:string,roles:list<string>,permissions:list<string>} */
    private static function loadAdmin(int $adminId): array
    {
        $row = Connection::fetchOne(
            'SELECT id, full_name, email FROM abh_admin_users WHERE id = :id LIMIT 1',
            ['id' => $adminId]
        );
        if ($row === null) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Administrator account is unavailable.');
        }

        $roles = Connection::fetchOne(
            'SELECT GROUP_CONCAT(DISTINCT r.role_key ORDER BY r.role_key SEPARATOR ",") AS role_keys
               FROM abh_admin_user_roles ur
               INNER JOIN abh_admin_roles r ON r.id = ur.role_id
              WHERE ur.admin_user_id = :id',
            ['id' => $adminId]
        );
        $permissions = Connection::fetchOne(
            'SELECT GROUP_CONCAT(DISTINCT p.permission_key ORDER BY p.permission_key SEPARATOR ",") AS permission_keys
               FROM abh_admin_user_roles ur
               INNER JOIN abh_admin_role_permissions rp ON rp.role_id = ur.role_id
               INNER JOIN abh_admin_permissions p ON p.id = rp.permission_id
              WHERE ur.admin_user_id = :id',
            ['id' => $adminId]
        );

        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'roles' => self::splitList($roles['role_keys'] ?? null),
            'permissions' => self::splitList($permissions['permission_keys'] ?? null),
        ];
    }

    /** @return array{id:int,full_name:string,email:string,roles:list<string>,permissions:list<string>} */
    private static function loadAdminWithPdo(\PDO $pdo, int $adminId): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.full_name, a.email,
                    GROUP_CONCAT(DISTINCT r.role_key ORDER BY r.role_key SEPARATOR ",") AS role_keys,
                    GROUP_CONCAT(DISTINCT p.permission_key ORDER BY p.permission_key SEPARATOR ",") AS permission_keys
               FROM abh_admin_users a
               LEFT JOIN abh_admin_user_roles ur ON ur.admin_user_id = a.id
               LEFT JOIN abh_admin_roles r ON r.id = ur.role_id
               LEFT JOIN abh_admin_role_permissions rp ON rp.role_id = r.id
               LEFT JOIN abh_admin_permissions p ON p.id = rp.permission_id
              WHERE a.id = :id
              GROUP BY a.id, a.full_name, a.email'
        );
        $stmt->execute(['id' => $adminId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Administrator account is unavailable.');
        }

        return [
            'id' => (int) $row['id'],
            'full_name' => (string) $row['full_name'],
            'email' => (string) $row['email'],
            'roles' => self::splitList($row['role_keys'] ?? null),
            'permissions' => self::splitList($row['permission_keys'] ?? null),
        ];
    }

    /** @return list<string> */
    private static function splitList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $value), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @param array<string,mixed> $admin
     * @return array<string,mixed>
     */
    private static function insertSession(\PDO $pdo, array $admin, string $family): array
    {
        $config = Config::instance();
        $accessToken = Str::randomToken(32);
        $refreshToken = Str::randomToken(48);
        $accessTtl = $config->int('security.access_ttl');
        $refreshTtl = $config->int('security.refresh_ttl');
        $accessExpiry = Clock::sql(Clock::shift($accessTtl));
        $refreshExpiry = Clock::sql(Clock::shift($refreshTtl));

        $stmt = $pdo->prepare(
            'INSERT INTO abh_admin_sessions
             (admin_user_id, access_token_hash, refresh_token_hash, token_family,
              access_expires_at, refresh_expires_at, created_at)
             VALUES
             (:admin_id, :access_hash, :refresh_hash, :family, :access_expiry, :refresh_expiry, UTC_TIMESTAMP(6))'
        );
        $stmt->execute([
            'admin_id' => $admin['id'],
            'access_hash' => hash('sha256', $accessToken),
            'refresh_hash' => hash('sha256', $refreshToken),
            'family' => $family,
            'access_expiry' => $accessExpiry,
            'refresh_expiry' => $refreshExpiry,
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtl,
            'refresh_expires_at' => $refreshExpiry,
            'admin' => [
                'id' => (int) $admin['id'],
                'full_name' => (string) $admin['full_name'],
                'email' => (string) $admin['email'],
                'roles' => $admin['roles'],
                'permissions' => $admin['permissions'],
            ],
        ];
    }
}
