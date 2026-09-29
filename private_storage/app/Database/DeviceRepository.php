<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Security\DeviceStatus;
use FieldPulse\Support\Json;

/**
 * devices access.
 */
final class DeviceRepository extends Repository
{
    /** @return array<string,mixed>|null */
    public function findByUuid(string $deviceUuid): ?array
    {
        return $this->one('SELECT * FROM devices WHERE device_uuid = :u', ['u' => $deviceUuid]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM devices WHERE id = :id', ['id' => $id]);
    }

    /**
     * Load the device and its owning agent in one round trip. This is the query
     * behind every authenticated request: it is the single place where agent
     * status AND device status are resolved together, so no code path can check
     * one without the other.
     *
     * @return array{device:array<string,mixed>,agent:array<string,mixed>}|null
     */
    public function findAuthorisedPair(string $deviceUuid): ?array
    {
        $row = $this->one(
            'SELECT
                d.id            AS device_id,
                d.agent_id      AS device_agent_id,
                d.device_uuid   AS device_uuid,
                d.public_key_jwk,
                d.status        AS device_status,
                d.imei          AS device_imei,
                a.id            AS agent_id,
                a.agent_code    AS agent_code,
                a.full_name     AS full_name,
                a.status        AS agent_status,
                a.imei          AS agent_imei,
                a.role          AS agent_role
             FROM devices d
             INNER JOIN agents a ON a.id = d.agent_id
             WHERE d.device_uuid = :u',
            ['u' => $deviceUuid]
        );

        if ($row === null) {
            return null;
        }

        return [
            'device' => [
                'id'             => (int) $row['device_id'],
                'agent_id'       => (int) $row['device_agent_id'],
                'device_uuid'    => (string) $row['device_uuid'],
                'public_key_jwk' => $row['public_key_jwk'],
                'status'         => (string) $row['device_status'],
                'imei'           => $row['device_imei'],
            ],
            'agent' => [
                'id'         => (int) $row['agent_id'],
                'agent_code' => (string) $row['agent_code'],
                'full_name'  => (string) $row['full_name'],
                'status'     => (string) $row['agent_status'],
                'imei'       => $row['agent_imei'],
                // Kernel::assertOperator() reads $context->agent()['role'] and
                // falls back to 'AGENT' when the key is absent, so omitting this
                // projected every authenticated caller as a plain agent. Every
                // SUPERVISOR then received 403 on reviews.index and
                // reviews.decide, and no human could ever overturn a verdict.
                // The queue still listed correctly, because it filters on
                // submissions.status rather than on disposition — so the failure
                // presented as a populated queue where every action errored.
                'role'       => (string) $row['agent_role'],
            ],
        ];
    }

    public function create(int $agentId, string $deviceUuid, array $publicJwk, ?string $imei, string $status = DeviceStatus::ACTIVE): int
    {
        $this->exec(
            'INSERT INTO devices (agent_id, device_uuid, public_key_jwk, status, registered_at, last_seen_at, imei, created_at, updated_at)
             VALUES (:agent_id, :uuid, :jwk, :status, UTC_TIMESTAMP(), NULL, :imei, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'agent_id' => $agentId,
                'uuid'     => $deviceUuid,
                'jwk'      => Json::encode($publicJwk),
                'status'   => $status,
                'imei'     => $imei,
            ]
        );

        return (int) Connection::lastInsertId();
    }

    /**
     * Move a device to a new status, keeping revoked_at consistent with it.
     *
     * The invariant is: revoked_at IS NOT NULL exactly when status = 'REVOKED'.
     * VerificationService::deviceStatus() records both fields as evidence,
     * because a device revoked between submission and processing is materially
     * different from one that stayed active — and a stale revoked_at left over
     * from an earlier revocation would make that evidence lie.
     */
    public function setStatus(int $deviceId, string $status): void
    {
        if (!in_array($status, [DeviceStatus::PENDING, DeviceStatus::ACTIVE, DeviceStatus::REVOKED, DeviceStatus::DISABLED], true)) {
            throw new \InvalidArgumentException('Unknown device status: ' . $status);
        }

        // Two statements rather than a CASE comparing :s to a bound constant:
        // native prepared statements reject a repeated named parameter, and the
        // intent reads more clearly spelled out than encoded in a flag compare.
        $sql = $status === DeviceStatus::REVOKED
            ? 'UPDATE devices
                  SET status = :s, revoked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                WHERE id = :id'
            : 'UPDATE devices
                  SET status = :s, revoked_at = NULL, updated_at = UTC_TIMESTAMP()
                WHERE id = :id';

        $this->exec($sql, ['s' => $status, 'id' => $deviceId]);
    }

    /**
     * Revoke every device for an agent. Used when an agent is suspended, so a
     * valid but un-revoked access token cannot outlive the suspension decision.
     *
     * Two distinct placeholders for the same value: native prepared statements
     * (EMULATE_PREPARES = false) reject a repeated named parameter.
     */
    public function revokeAllForAgent(int $agentId): int
    {
        return $this->exec(
            'UPDATE devices
                SET status = :new_status,
                    revoked_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE agent_id = :agent_id AND status <> :old_status',
            [
                'new_status' => DeviceStatus::REVOKED,
                'agent_id'   => $agentId,
                'old_status' => DeviceStatus::REVOKED,
            ]
        );
    }

    /**
     * Number of devices an agent may currently use.
     */
    public function countActiveDevicesForAgent(int $agentId): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM devices WHERE agent_id = :a AND status = :s',
            ['a' => $agentId, 's' => DeviceStatus::ACTIVE]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForAgent(int $agentId): array
    {
        return $this->all(
            'SELECT id, device_uuid, status, registered_at, last_seen_at, imei
             FROM devices WHERE agent_id = :a ORDER BY created_at DESC',
            ['a' => $agentId]
        );
    }

    /**
     * Devices for an agent that are allowed to sign requests.
     *
     * @return list<array<string,mixed>>
     */
    public function listActiveForAgent(int $agentId): array
    {
        return $this->all(
            'SELECT * FROM devices WHERE agent_id = :a AND status = :s',
            ['a' => $agentId, 's' => DeviceStatus::ACTIVE]
        );
    }

    public function isImeiBoundElsewhere(string $imei, int $agentId): bool
    {
        $row = $this->one('SELECT id FROM devices WHERE imei = :imei AND agent_id <> :a', ['imei' => $imei, 'a' => $agentId]);

        return $row !== null;
    }
}
