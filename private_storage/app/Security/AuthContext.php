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
    /**
     * @param array<string,mixed> $agent
     * @param array<string,mixed>|null $device Null for a bootstrap session:
     *        login is pure credentials, so the session that precedes device
     *        registration has no device. See Authenticator::authenticateBootstrap().
     * @param array<string,mixed> $claims
     */
    public function __construct(
        private readonly array $agent,
        private readonly ?array $device,
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

    /**
     * True when this session has no device bound.
     *
     * A bootstrap session. It can reach the bearer-only registration route and
     * nothing else: with no device there is no key, so it cannot produce a
     * signature that any signed route would accept.
     */
    public function isBootstrap(): bool
    {
        return $this->device === null;
    }

    /**
     * @throws \LogicException when there is no device
     */
    private function requireDevice(): array
    {
        if ($this->device === null) {
            /*
             * A programming error, not an attack: every caller of these is on a
             * signed route, and the kernel admits bootstrap sessions only to the
             * one bearer-only route. Throwing here turns a mistake into a loud
             * 500 rather than a request that silently proceeds with device
             * fields reading as empty strings.
             */
            throw new \LogicException(
                'AuthContext has no device; a bootstrap session cannot reach a device-bound accessor.'
            );
        }

        return $this->device;
    }

    public function deviceId(): int
    {
        return (int) $this->requireDevice()['id'];
    }

    public function deviceUuid(): string
    {
        return (string) $this->requireDevice()['device_uuid'];
    }

    public function deviceStatus(): string
    {
        return (string) $this->requireDevice()['status'];
    }

    /**
     * @return array<string,mixed>
     * @throws \LogicException when there is no device
     */
    public function device(): array
    {
        return $this->requireDevice();
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
