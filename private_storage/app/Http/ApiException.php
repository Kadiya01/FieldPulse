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
}
