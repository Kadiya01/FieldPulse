<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * agents access.
 *
 * Every account signs in with a username and password stored as a password
 * hash. The IMEI remains an administrative device attribute and is never an
 * authentication factor: it is printed on the handset and box and can be
 * copied, so it cannot stand in for a secret.
 */
final class AgentRepository extends Repository
{
    public const ACTIVE    = 'ACTIVE';
    public const INACTIVE  = 'INACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const DELETED   = 'DELETED';
    public const PENDING   = 'PENDING';
    public const REJECTED  = 'REJECTED';

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
     * $passwordHash must already be a Credentials::hash() output. This method
     * never hashes anything itself so that password hashing has one audited
     * entry point rather than being duplicated across controllers.
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
     * A row enrolled under the retired IMEI-only model has password_hash NULL,
     * and login treats that exactly like an unknown username.
     */
    public function hasPasswordCredential(int $agentId): bool
    {
        $value = Connection::fetchValue(
            'SELECT password_hash FROM agents WHERE id = :id',
            ['id' => $agentId]
        );

        return is_string($value) && $value !== '';
    }

    public function usernameExists(string $username): bool
    {
        return $this->one(
            'SELECT id FROM agents WHERE username = :username LIMIT 1',
            ['username' => $username]
        ) !== null;
    }

    /**
     * Administrative lookup by device IMEI. Never used for authentication.
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

    public function registrationExists(string $nin, string $imei): bool
    {
        return $this->one(
            'SELECT id FROM agents WHERE nin = :nin OR imei = :imei LIMIT 1',
            ['nin' => $nin, 'imei' => $imei]
        ) !== null;
    }

    public function createSelfRegistered(
        string $code,
        string $fullName,
        string $nin,
        string $phone,
        string $imei,
        string $deviceType,
        string $username,
        string $passwordHash,
        ?string $email = null,
        ?string $location = null
    ): int {
        $this->exec(
            'INSERT INTO agents
                (agent_code, full_name, nin, phone, email, location, username, password_hash,
                 password_updated_at, role, status, imei, device_type, self_registered,
                 created_at, updated_at)
             VALUES
                (:code, :name, :nin, :phone, :email, :location, :username, :password_hash,
                 UTC_TIMESTAMP(), :role, :status, :imei, :device_type, 1,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'code'          => $code,
                'name'          => $fullName,
                'nin'           => $nin,
                'phone'         => $phone,
                'email'         => $email,
                'location'      => $location,
                'username'      => $username,
                'password_hash' => $passwordHash,
                'role'          => 'AGENT',
                'status'        => self::PENDING,
                'imei'          => $imei,
                'device_type'   => $deviceType,
            ]
        );

        return (int) Connection::lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one(
            'SELECT * FROM agents WHERE email = :email LIMIT 1',
            ['email' => $email]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByNin(string $nin): ?array
    {
        return $this->one('SELECT * FROM agents WHERE nin = :nin LIMIT 1', ['nin' => $nin]);
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * Update the fields an agent may maintain themselves.
     *
     * NIN, IMEI, role and status are deliberately absent: those are identity and
     * administrative facts, not profile preferences, and letting the profile
     * endpoint write them would turn a self-service form into a privilege change.
     */
    public function updateProfile(int $agentId, ?string $email, ?string $location, ?string $phone): void
    {
        $this->exec(
            'UPDATE agents
                SET email = :email, location = :location, phone = :phone, updated_at = UTC_TIMESTAMP()
              WHERE id = :id',
            ['email' => $email, 'location' => $location, 'phone' => $phone, 'id' => $agentId]
        );
    }

    /**
     * Administrative profile correction.
     *
     * The same three fields an agent may maintain themselves, plus nothing
     * else: NIN, IMEI, role and status remain separate actions so an edit of a
     * typo cannot quietly become a privilege change.
     */
    public function updateAdminProfile(int $agentId, ?string $email, ?string $location, ?string $phone): void
    {
        $this->updateProfile($agentId, $email, $location, $phone);
    }

    public function setMonthlyTarget(int $agentId, int $target): void
    {
        if ($target < 0 || $target > 1000000) {
            throw new \InvalidArgumentException('Monthly target is out of range.');
        }

        $this->exec(
            'UPDATE agents SET monthly_target = :target, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['target' => $target, 'id' => $agentId]
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

    /**
     * Create an account from the admin surface.
     *
     * Unlike create(), every enrollment/profile field is optional because an
     * administrator may only know the person's name and the credential they are
     * handing over. The row still lands ACTIVE unless the caller explicitly
     * asks for another status, and the credential is written separately by the
     * controller inside the same transaction.
     *
     * @param array{
     *   agent_code:string,full_name:string,role:string,status:string,
     *   nin?:?string,phone?:?string,email?:?string,location?:?string,
     *   monthly_target?:int,imei?:?string,device_type?:?string,status_reason?:?string
     * } $data
     */
    public function createManaged(array $data): int
    {
        $this->exec(
            'INSERT INTO agents
                (agent_code, full_name, nin, phone, email, location, monthly_target,
                 role, status, status_reason, status_changed_at,
                 imei, imei_enrolled_at, device_type, self_registered,
                 created_at, updated_at)
             VALUES
                (:code, :name, :nin, :phone, :email, :location, :target,
                 :role, :status, :reason, UTC_TIMESTAMP(),
                 :imei, :enrolled, :device_type, 0,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'code'        => $data['agent_code'],
                'name'        => $data['full_name'],
                'nin'         => $data['nin'] ?? null,
                'phone'       => $data['phone'] ?? null,
                'email'       => $data['email'] ?? null,
                'location'    => $data['location'] ?? null,
                'target'      => (int) ($data['monthly_target'] ?? 0),
                'role'        => $data['role'],
                'status'      => $data['status'],
                'reason'      => $data['status_reason'] ?? null,
                'imei'        => $data['imei'] ?? null,
                'enrolled'    => isset($data['imei']) && $data['imei'] !== null ? $this->now() : null,
                'device_type' => $data['device_type'] ?? null,
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

    public function setStatus(int $agentId, string $status, ?string $reason = null): void
    {
        if (!in_array($status, [self::ACTIVE, self::INACTIVE, self::SUSPENDED, self::DELETED, self::PENDING, self::REJECTED], true)) {
            throw new \InvalidArgumentException('Unknown agent status: ' . $status);
        }

        $this->exec(
            'UPDATE agents
                SET status = :s,
                    status_reason = :reason,
                    status_changed_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id',
            ['s' => $status, 'reason' => $reason, 'id' => $agentId]
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
     * @param  array{q?:?string,status?:?string,role?:?string,location?:?string,self_registered?:?bool} $filters
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = $this->adminFilters($filters);

        $sql = "SELECT a.id, a.agent_code, a.full_name, a.nin, a.phone,
                       a.email, a.location, a.monthly_target,
                       a.self_registered, a.role, a.status, a.status_reason, a.status_changed_at,
                       a.username, a.created_at, a.updated_at,
                       (SELECT COUNT(*) FROM devices d
                         WHERE d.agent_id = a.id
                           AND d.status = :dstatus) AS active_device_count,
                       (a.password_hash IS NOT NULL) AS has_credential
                  FROM agents a"
            . $where
            . ' ORDER BY a.id DESC'
            . self::limitClause($limit, 200, $offset);

        return $this->all($sql, $params + ['dstatus' => \FieldPulse\Security\DeviceStatus::ACTIVE]);
    }

    /** @param array{q?:?string,status?:?string,role?:?string,location?:?string,self_registered?:?bool} $filters */
    public function countAll(array $filters = []): int
    {
        [$where, $params] = $this->adminFilters($filters);

        return (int) $this->value('SELECT COUNT(*) FROM agents a' . $where, $params);
    }

    /**
     * @param  array{q?:?string,status?:?string,role?:?string,location?:?string,self_registered?:?bool} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function adminFilters(array $filters): array
    {
        $where  = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $where[] = '(a.agent_code LIKE :q_code OR a.full_name LIKE :q_name OR a.email LIKE :q_email OR a.nin LIKE :q_nin)';
            $like = '%' . $q . '%';
            $params['q_code']  = $like;
            $params['q_name']  = $like;
            $params['q_email'] = $like;
            $params['q_nin']   = $like;
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $filters['status'];
        }

        if (($filters['role'] ?? null) !== null && $filters['role'] !== '') {
            $where[] = 'a.role = :role';
            $params['role'] = $filters['role'];
        }

        if (($filters['location'] ?? null) !== null && $filters['location'] !== '') {
            $where[] = 'a.location = :location';
            $params['location'] = $filters['location'];
        }

        if (array_key_exists('self_registered', $filters) && $filters['self_registered'] !== null) {
            $where[] = 'a.self_registered = :self_registered';
            $params['self_registered'] = $filters['self_registered'] ? 1 : 0;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Distinct non-empty locations for the admin filter dropdown.
     *
     * @return list<string>
     */
    public function distinctLocations(): array
    {
        $rows = $this->all(
            "SELECT DISTINCT location FROM agents
              WHERE location IS NOT NULL AND location <> ''
              ORDER BY location"
        );

        return array_map(static fn (array $row): string => (string) $row['location'], $rows);
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
