<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * Per-request correlation id.
 *
 * Generated server-side, echoed in the X-Request-Id response header and in
 * every log line, so an agent-reported failure can be traced without ever
 * trusting a client-supplied id.
 */
final class RequestId
{
    private static ?string $id = null;

    private function __construct()
    {
    }

    public static function current(): string
    {
        if (self::$id === null) {
            self::$id = 'req_' . bin2hex(random_bytes(8));
        }

        return self::$id;
    }

    public static function set(?string $id): void
    {
        // Only accept our own shape, and only as a correlation hint.
        if ($id !== null && preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $id) === 1) {
            self::$id = $id;
            return;
        }

        self::$id = null;
    }
}
