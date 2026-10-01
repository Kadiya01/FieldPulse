<?php

declare(strict_types=1);

namespace FieldPulse\Http;

/**
 * The only exception type allowed to reach the HTTP boundary.
 *
 * Carrying an explicit status + code + message means the kernel can render a
 * response without inspecting the exception class tree, and an unexpected
 * exception can never leak a stack trace to a field agent on a metered
 * connection.
 *
 * `context` is server-side only: it is logged, never serialised to the client.
 */
class ApiException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * Cookies to set alongside the error response.
     *
     * Needed by the refresh endpoint: a rejected refresh must actively clear the
     * HttpOnly cookie, otherwise a revoked session lives on in the browser and
     * retries forever. Nothing else is allowed to attach a cookie to an error.
     *
     * @var list<array<string,mixed>>
     */
    private array $cookies = [];

    /** @param array<string,mixed> $context */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    /**
     * @param array{name:string,value:string,expires:int,path:string,secure:bool,httponly:bool,samesite:string} $cookie
     */
    public function withCookie(array $cookie): self
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @param array<string,mixed> $context */
    public static function validation(string $message, array $context = []): self
    {
        return new self(422, ErrorCode::VALIDATION_FAILED, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function unauthenticated(string $code, string $message, array $context = []): self
    {
        return new self(401, $code, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function forbidden(string $code, string $message, array $context = []): self
    {
        return new self(403, $code, $message, $context);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Context keys that are safe to send to the client, always, debug or not.
     *
     * WHY THIS EXISTS
     *
     * Until Phase 4, `details` was populated from context() and rendered only
     * when APP_DEBUG was true. That meant that with the shipped configuration —
     * APP_DEBUG=false, which Config::validate() requires in production — a
     * client never received `details.field`, and src/api/client.ts, which reads
     * it into ApiError.field, silently always got null. A validation failure
     * said "accuracy_m must be a finite number" in prose while reporting no
     * machine-readable field, so the PWA could not attach the message to the
     * input that caused it and could not tell the agent which figure to retake.
     *
     * The old coupling was not wrong to be cautious, it was aimed at the wrong
     * thing: context() exists to carry server-side diagnostics, and most of it
     * (paths, driver errors, row ids) must never leave the server. So the two
     * concerns are separated here — the allowlist below names the keys that are
     * part of the client contract, and only those are ever serialised.
     *
     * Every entry has to be something the client could have computed itself or
     * needs in order to retry correctly. Adding a key here is a public API
     * change, which is the intended friction.
     *
     * @var list<string>
     */
    private const CLIENT_SAFE_KEYS = [
        'field',          // which input failed
        'detected',       // what finfo actually saw, for a media-type refusal
        'max_bytes',      // the ceiling that was applied
        'max_pixels',
        'max_dimension',
        'min_dimension',
        'max_m',          // the accuracy ceiling
        'min',            // a lower bound, e.g. accuracy >= 0
    ];

    /**
     * The subset of context() that is part of the client contract.
     *
     * Filtered by allowlist, not by denylist: a new key added to a context call
     * somewhere in the app defaults to server-side-only, which is the correct
     * direction for a mistake to fail in.
     *
     * @return array<string,mixed>
     */
    public function clientDetails(): array
    {
        $safe = [];

        foreach (self::CLIENT_SAFE_KEYS as $key) {
            if (array_key_exists($key, $this->context)) {
                $safe[$key] = $this->context[$key];
            }
        }

        return $safe;
    }
}
