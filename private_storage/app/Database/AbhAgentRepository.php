<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\RequestId;

/**
 * Persistence for ABH agents.
 *
 * This repository deliberately uses only abh_* tables. It does not read or
 * mutate the legacy FieldPulse agents, devices, or audit tables.
 */
final class AbhAgentRepository
{
    private function __construct()
    {
    }

    /**
     * Create the agent, credentials, registered device and audit event as one
     * transaction. A partially created identity must never be visible.
     *
     * @return array{id:int,agent_code:string,full_name:string}
     */
    public static function createPending(
        string $fullName,
        string $phone,
        ?string $email,
        ?string $location,
        string $imei,
        string $deviceType,
        string $passwordHash
    ): array {
        $agentCode = 'ABH-' . strtoupper(bin2hex(random_bytes(5)));

        return Connection::transaction(
            static function (\PDO $pdo) use (
                $agentCode,
                $fullName,
                $phone,
                $email,
                $location,
                $imei,
                $deviceType,
                $passwordHash
            ): array {
                $agent = $pdo->prepare(
                    'INSERT INTO abh_agents
                     (agent_code, full_name, phone, phone_normalized, email, email_normalized,
                      location_text, status, registered_at)
                     VALUES
                     (:agent_code, :full_name, :phone, :phone_normalized, :email, :email_normalized,
                      :location_text, "PENDING", UTC_TIMESTAMP(6))'
                );
                $agent->execute([
                    'agent_code' => $agentCode,
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'phone_normalized' => $phone,
                    'email' => $email,
                    'email_normalized' => $email,
                    'location_text' => $location,
                ]);

                $agentId = (int) $pdo->lastInsertId();

                $credential = $pdo->prepare(
                    'INSERT INTO abh_agent_credentials (agent_id, password_hash, password_changed_at)
                     VALUES (:agent_id, :password_hash, UTC_TIMESTAMP(6))'
                );
                $credential->execute([
                    'agent_id' => $agentId,
                    'password_hash' => $passwordHash,
                ]);

                $device = $pdo->prepare(
                    'INSERT INTO abh_agent_devices
                     (agent_id, device_type, imei_value, status, registered_at)
                     VALUES (:agent_id, :device_type, :imei_value, "PENDING", UTC_TIMESTAMP(6))'
                );
                $device->execute([
                    'agent_id' => $agentId,
                    'device_type' => $deviceType,
                    'imei_value' => $imei,
                ]);

                $audit = $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_agent_id, action_key, entity_type, entity_id,
                      after_json, request_id, created_at)
                     VALUES
                     ("AGENT", :actor_agent_id, "agent.self_registered", "agent", :entity_id,
                      :after_json, :request_id, UTC_TIMESTAMP(6))'
                );
                $audit->execute([
                    'actor_agent_id' => $agentId,
                    'entity_id' => $agentId,
                    'after_json' => json_encode([
                        'status' => 'PENDING',
                        'registration_channel' => 'public_self_service',
                        'device_type' => $deviceType,
                    ], JSON_THROW_ON_ERROR),
                    'request_id' => RequestId::current(),
                ]);

                // Notify every currently active administrator. If initial
                // administrator provisioning has not happened yet, the rows
                // are still created and the pending agent remains visible in
                // the admin directory after the first administrator is seeded.
                $notification = $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_admin_user_id, event_key, title, message, created_at)
                     SELECT "ADMIN", id, "agent.registration", "New agent registration",
                            "A new agent application is waiting for review.", UTC_TIMESTAMP(6)
                       FROM abh_admin_users
                      WHERE status = "ACTIVE"'
                );
                $notification->execute();

                return [
                    'id' => $agentId,
                    'agent_code' => $agentCode,
                    'full_name' => $fullName,
                ];
            }
        );
    }
}
