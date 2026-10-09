<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * agents access.
 *
 * The credential lookup is by username, and the only thing that proves an agent
 * is who they claim to be is a password verified with password_verify() against
 * the bcrypt hash in password_hash.
 *
 * IMEI is not a credential and is not used to authenticate anything. It is
 * retained as an administrative attribute — useful for support and for matching
 * a handset to an ERP record — and deliberately reachable only through
 * findByImei(), which no authentication path calls. It is not a secret: it is
 * printed on the handset, printed on the box, and recycled between owners, and
 * a browser cannot read it at all.
 */
final class AgentRepository extends Repository
{
    public const ACTIVE    = 'ACTIVE';
    public const INACTIVE  = 'INACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const DELETED   = 'DELETED';

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM agents WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM agents WHERE agent_code = :c', ['c' => $code]);
    }

    /**
     * The lookup behind login: username -> agent.
     *
     * Case-insensitive on the caller's side, case-sensitively matched here.
     * MySQL's default utf8mb4_unicode_ci collation already compares
     * case-insensitively, so "Ada" and "ada" cannot both exist and a login is
     * unambiguous. Storing the case the operator chose is what makes the
     * account recognisable to the person who owns it.
     *
     * @return array<string,mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->one(
            'SELECT * FROM agents WHERE username = :u',
            ['u' => $username]
        );
    }

    /**
     * Give an agent a username and password.
     *
     * $passwordHash must already be a password_hash() output. This method never
     * hashes anything itself so that there is exactly one place in the codebase
     * that calls password_hash(), and it is a command run by an operator rather
     * than a request handler.
     */
    public function setCredentials(int $agentId, string $username, string $passwordHash): void
    {
        $this->exec(
            'UPDATE agents
                SET username = :u, password_hash = :h, password_updated_at = UTC_TIMESTAMP()
              WHERE id = :id',
            ['u' => $username, 'h' => $passwordHash, 'id' => $agentId]
        );
    }

    /**
     * Clear an agent's password credential without deleting the account.
     *
     * Setting password_hash to NULL makes the agent unable to log in, which is
     * the correct meaning of "revoke this credential" and is not the same as
     * deleting the agent, which would orphan their submissions.
     */
    public function clearCredentials(int $agentId): void
    {
        $this->exec(
            'UPDATE agents SET password_hash = NULL, password_updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $agentId]
        );
    }

    /**
     * True when the agent holds a usable password credential.
     *
     * Checked by the login controller so that a row enrolled under the previous
     * IMEI model, whose password_hash is NULL, fails as "no such user" rather
     * than reaching password_verify() with an empty hash.
     */
    public function hasPasswordCredential(int $agentId): bool
    {
        $value = Connection::fetchValue(
            'SELECT password_hash FROM agents WHERE id = :id',
            ['id' => $agentId]
        );

        return is_string($value) && $value !== '';
    }

    /**
     * The lookup that used to sit behind login. Retained for administrative
     * and support use only.
     *
     * @return array<string,mixed>|null
     */
    public function findByImei(string $imei): ?array
    {
        return $this->one(
            'SELECT * FROM agents WHERE imei = :imei AND imei IS NOT NULL',
            ['imei' => $imei]
        );
    }

    public function create(string $code, string $fullName, ?string $imei = null): int
    {
        $this->exec(
            'INSERT INTO agents (agent_code, full_name, status, imei, imei_enrolled_at, created_at, updated_at)
             VALUES (:code, :name, :status, :imei, :enrolled, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'code'     => $code,
                'name'     => $fullName,
                'status'   => self::ACTIVE,
                'imei'     => $imei,
                'enrolled' => $imei === null ? null : $this->now(),
            ]
        );

        return (int) Connection::lastInsertId();
    }

    public function setImei(int $agentId, ?string $imei): void
    {
        $this->exec(
            'UPDATE agents SET imei = :imei, imei_enrolled_at = :enrolled, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['imei' => $imei, 'enrolled' => $imei === null ? null : $this->now(), 'id' => $agentId]
        );
    }

    public function setStatus(int $agentId, string $status): void
    {
        if (!in_array($status, [self::ACTIVE, self::INACTIVE, self::SUSPENDED, self::DELETED], true)) {
            throw new \InvalidArgumentException('Unknown agent status: ' . $status);
        }

        $this->exec(
            'UPDATE agents SET status = :s, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['s' => $status, 'id' => $agentId]
        );
    }

    /**
     * Re-role an agent. The Kernel re-reads the role from the row on every
     * request (see Authenticator), so this change is effective immediately
     * and does not depend on any token expiring.
     */
    public function setRole(int $agentId, string $role): void
    {
        if (!in_array($role, ['AGENT', 'SUPERVISOR', 'ADMIN'], true)) {
            throw new \InvalidArgumentException('Unknown agent role: ' . $role);
        }

        $this->exec(
            'UPDATE agents SET role = :r, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['r' => $role, 'id' => $agentId]
        );
    }

    /**
     * The count that protects against locking the last ADMIN out.
     *
     * A demotion or suspension of an ACTIVE ADMIN is refused while this would
     * drop below one, because the whole account-management surface is gated on
     * the ADMIN role, and an ADMIN without ADMINs is a system nobody can ever
     * administer again.
     */
    public function countActiveAdmins(): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM agents WHERE role = :role AND status = :status',
            ['role' => 'ADMIN', 'status' => self::ACTIVE]
        );
    }

    /**
     * The account-management directory, newest-created first.
     *
     * Only the ADMIN surface needs this, so the slow EXISTS subquery for the
     * credential flag lives here rather than on the agent hot path.
     *
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(int $limit, int $offset): array
    {
        $sql = "SELECT a.id, a.agent_code, a.full_name, a.role, a.status,
                       a.username, a.created_at,
                       (SELECT COUNT(*) FROM devices d
                         WHERE d.agent_id = a.id
                           AND d.status = :dstatus) AS active_device_count,
                       (a.password_hash IS NOT NULL) AS has_credential
                  FROM agents a
                 ORDER BY a.id DESC"
            . self::limitClause($limit, 200, $offset);

        return $this->all($sql, ['dstatus' => \FieldPulse\Security\DeviceStatus::ACTIVE]);
    }

    public function countAll(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM agents');
    }

    public function isActive(int $agentId): bool
    {
        $status = $this->value('SELECT status FROM agents WHERE id = :id', ['id' => $agentId]);

        return $status === self::ACTIVE;
    }

    /**
     * True when an IMEI is already bound to a different agent. Used by the
     * provisioning tool to refuse ambiguous enrolments.
     */
    public function imeiBelongsToOtherAgent(string $imei, int $agentId): bool
    {
        $row = $this->one('SELECT id FROM agents WHERE imei = :imei AND id <> :id', ['imei' => $imei, 'id' => $agentId]);

        return $row !== null;
    }

    public function countActiveDevices(int $agentId): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM devices WHERE agent_id = :a AND status = :s',
            ['a' => $agentId, 's' => \FieldPulse\Security\DeviceStatus::ACTIVE]
        );
    }}
