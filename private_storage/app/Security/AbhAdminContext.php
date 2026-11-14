<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Authenticated ABH administrator identity and current DB-backed permissions.
 * The permission list is refreshed from role assignments on every request.
 */
final class AbhAdminContext
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        private readonly int $id,
        private readonly string $fullName,
        private readonly string $email,
        private readonly array $roles,
        private readonly array $permissions,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function fullName(): string
    {
        return $this->fullName;
    }

    public function email(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            throw new ApiException(
                403,
                ErrorCode::FORBIDDEN,
                'You do not have permission to perform this action.'
            );
        }
    }
}
