<?php

declare(strict_types=1);

namespace FieldPulse\Security;

/**
 * Device lifecycle states, mirroring the ENUM in migration 002.
 *
 * The distinction that matters:
 *   PENDING  registered, key bound, but not yet used for anything privileged
 *   ACTIVE   may authenticate and submit
 *   REVOKED  operator action; the key is permanently distrusted
 *   DISABLED administratively off, re-enableable
 *
 * Only ACTIVE passes §7's authorisation step.
 */
final class DeviceStatus
{
    public const PENDING  = 'PENDING';
    public const ACTIVE   = 'ACTIVE';
    public const REVOKED  = 'REVOKED';
    public const DISABLED = 'DISABLED';

    private function __construct()
    {
    }

    public static function canSubmit(string $status): bool
    {
        return $status === self::ACTIVE;
    }

    /**
     * Map a non-ACTIVE status onto the specific client-facing error, so the PWA
     * can tell the agent to call support vs. re-pair.
     */
    public static function denialCode(string $status): \FieldPulse\Http\ErrorCode
    {
        return match ($status) {
            self::REVOKED  => \FieldPulse\Http\ErrorCode::DEVICE_REVOKED,
            self::PENDING  => \FieldPulse\Http\ErrorCode::DEVICE_NOT_ACTIVE,
            self::DISABLED => \FieldPulse\Http\ErrorCode::DEVICE_NOT_ACTIVE,
            default        => \FieldPulse\Http\ErrorCode::DEVICE_NOT_ACTIVE,
        };
    }
}
