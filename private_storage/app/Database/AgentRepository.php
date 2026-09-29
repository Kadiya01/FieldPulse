<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * agents access.
 *
 * Note the absence of any "find by code then compare" helper: in a zero-password
 * deployment the agent_code is not a credential, and the only lookup that
 * carries security weight is by IMEI, which is always followed by a proof of
 * possession check in Security\ChallengeService.
 */
final class AgentRepository extends Repository
{
    public const ACTIVE    = 'ACTIVE';
    public const INACTIVE  = 'INACTIVE';
    public const SUSPENDED = 'SUSPENDED';

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
     * The lookup behind the zero-password login: IMEI -> agent.
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
        if (!in_array($status, [self::ACTIVE, self::INACTIVE, self::SUSPENDED], true)) {
            throw new \InvalidArgumentException('Unknown agent status: ' . $status);
        }

        $this->exec(
            'UPDATE agents SET status = :s, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['s' => $status, 'id' => $agentId]
        );
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
