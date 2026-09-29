<?php

declare(strict_types=1);

namespace FieldPulse\Security;

/**
 * Immutable, already-authorised request context.
 *
 * Constructed only by Security\Authenticator after the JWT signature, the agent
 * status and the device status have all been checked. Passing this object
 * around is a statement that those checks happened; a controller that receives
 * one is not expected to re-derive identity from the request.
 */
final class AuthContext
{
    /**
     * @param array<string,mixed> $agent
     * @param array<string,mixed> $device
     * @param array<string,mixed> $claims
     */
    public function __construct(
        private readonly array $agent,
        private readonly array $device,
        private readonly array $claims,
    ) {
    }

    public function agentId(): int
    {
        return (int) $this->agent['id'];
    }

    public function agentCode(): string
    {
        return (string) $this->agent['agent_code'];
    }

    public function agentStatus(): string
    {
        return (string) $this->agent['status'];
    }

    public function agentImei(): ?string
    {
        return $this->agent['imei'] === null ? null : (string) $this->agent['imei'];
    }

    public function deviceId(): int
    {
        return (int) $this->device['id'];
    }

    public function deviceUuid(): string
    {
        return (string) $this->device['device_uuid'];
    }

    public function deviceStatus(): string
    {
        return (string) $this->device['status'];
    }

    /** @return array<string,mixed> */
    public function device(): array
    {
        return $this->device;
    }

    /** @return array<string,mixed> */
    public function agent(): array
    {
        return $this->agent;
    }

    /** @return array<string,mixed> */
    public function claims(): array
    {
        return $this->claims;
    }
}
