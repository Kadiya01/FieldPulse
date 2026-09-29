<?php

declare(strict_types=1);

namespace FieldPulse\Http;

/**
 * Canonical, closed set of client-facing error codes.
 *
 * Codes are part of the public contract: the PWA switches on them. Messages are
 * human-facing and may change. The distinction matters because a generic
 * message plus a stable code keeps the response non-informative about internals
 * while remaining programmable for the client.
 */
final class ErrorCode
{
    /* 400 */
    public const VALIDATION_FAILED   = 'VALIDATION_FAILED';
    public const MALFORMED_REQUEST   = 'MALFORMED_REQUEST';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const INVALID_JSON        = 'INVALID_JSON';
    public const UNSUPPORTED_MEDIA   = 'UNSUPPORTED_MEDIA_TYPE';

    /* 401 */
    public const UNAUTHENTICATED     = 'UNAUTHENTICATED';
    public const TOKEN_EXPIRED       = 'TOKEN_EXPIRED';
    public const TOKEN_INVALID       = 'TOKEN_INVALID';
    public const SIGNATURE_INVALID   = 'SIGNATURE_INVALID';
    public const REPLAY_DETECTED     = 'REPLAY_DETECTED';
    public const CLOCK_SKEW          = 'CLOCK_SKEW';
    public const REFRESH_INVALID     = 'REFRESH_INVALID';
    public const DEVICE_KEY_INVALID  = 'DEVICE_KEY_INVALID';
    public const UNKNOWN_DEVICE      = 'UNKNOWN_DEVICE';

    /* 403 */
    public const FORBIDDEN           = 'FORBIDDEN';
    public const AGENT_INACTIVE      = 'AGENT_INACTIVE';
    public const DEVICE_NOT_ACTIVE   = 'DEVICE_NOT_ACTIVE';
    public const DEVICE_REVOKED      = 'DEVICE_REVOKED';
    public const RATE_LIMITED        = 'RATE_LIMITED';
    public const ORIGIN_NOT_ALLOWED  = 'ORIGIN_NOT_ALLOWED';

    /* 404 */
    public const NOT_FOUND           = 'NOT_FOUND';
    public const UNKNOWN_DEVICE_UUID = 'UNKNOWN_DEVICE_UUID';
    public const UNKNOWN_SUBMISSION  = 'UNKNOWN_SUBMISSION';
    public const UNKNOWN_AGENT       = 'UNKNOWN_AGENT';
    public const UNKNOWN_SITE        = 'UNKNOWN_SITE';

    /* 409 */
    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    public const STATE_CONFLICT       = 'STATE_CONFLICT';

    /* 413 */
    public const FILE_TOO_LARGE      = 'FILE_TOO_LARGE';

    /* 415 */
    public const UNSUPPORTED_MEDIA_TYPE_FILE = 'UNSUPPORTED_FILE_TYPE';

    /* 422 */
    public const IMAGE_INVALID       = 'IMAGE_INVALID';
    public const FILE_MISSING        = 'FILE_MISSING';
    public const UPLOAD_ERROR        = 'UPLOAD_ERROR';
    public const NOT_UPLOADED_FILE   = 'NOT_UPLOADED_FILE';
    public const FILE_HASH_MISMATCH  = 'FILE_HASH_MISMATCH';

    /* 429 */
    public const TOO_MANY_REQUESTS   = 'TOO_MANY_REQUESTS';

    /* 500 / 503 */
    public const INTERNAL_ERROR      = 'INTERNAL_ERROR';
    public const STORAGE_UNAVAILABLE = 'STORAGE_UNAVAILABLE';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
