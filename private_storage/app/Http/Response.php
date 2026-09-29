<?php

declare(strict_types=1);

namespace FieldPulse\Http;

use FieldPulse\Support\Json;
use FieldPulse\Support\RequestId;

/**
 * JSON response writer.
 *
 * Guarantees applied to every response leaving the API:
 *   - Content-Type: application/json; charset=utf-8
 *   - Cache-Control: no-store  (authenticated field data must never be cached
 *     by an intermediary; a shared cache replaying a response to another agent
 *     is a data leak)
 *   - X-Content-Type-Options: nosniff
 *   - X-Request-Id for support correlation
 *   - Referrer-Policy: no-referrer (the URL carries no secrets, but a leaked
 *     referrer to a third party would still identify an agent session)
 */
final class Response
{
    /** @param array<string,string> $headers @param array<string,string> $cookies */
    private function __construct(
        private readonly int $status,
        private readonly array $payload,
        private readonly array $headers = [],
        private readonly array $cookies = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self($status, $data, $headers);
    }

    /**
     * The uniform error envelope: { "error": { "code", "message", "request_id" } }.
     *
     * `details` is omitted entirely unless debug is on, so a field agent on a
     * metered connection never receives stack traces or SQL fragments.
     *
     * @param array<string,mixed> $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): self
    {
        $body = [
            'error' => [
                'code'       => $code,
                'message'    => $message,
                'request_id' => RequestId::current(),
            ],
        ];

        if ($details !== []) {
            $body['error']['details'] = $details;
        }

        return new self($status, $body);
    }

    /**
     * @param array{name:string,value:string,expires:int,path:string,secure:bool,httponly:bool,samesite:string} $cookie
     */
    public function withCookie(array $cookie): self
    {
        return new self(
            $this->status,
            $this->payload,
            $this->headers,
            $this->cookies + [$cookie['name'] => $cookie],
        );
    }

    /**
     * An expired cookie that clears the browser copy. The attributes must match
     * the ones used when setting it or the browser will keep the original.
     *
     * @return array{name:string,value:string,expires:int,path:string,secure:bool,httponly:bool,samesite:string}
     */
    public static function expiredRefreshCookie(string $name, string $path, bool $secure, string $samesite): array
    {
        return [
            'name'     => $name,
            'value'    => '',
            'expires'  => time() - 3600,
            'path'     => $path,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ];
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('X-Request-Id: ' . RequestId::current());

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }

            foreach ($this->cookies as $cookie) {
                $parts = [
                    rawurlencode($cookie['name']) . '=' . rawurlencode($cookie['value']),
                    'Path=' . $cookie['path'],
                    'Expires=' . gmdate('D, d M Y H:i:s \G\M\T', $cookie['expires']),
                    'Max-Age=' . max(0, $cookie['expires'] - time()),
                ];

                if ($cookie['secure']) {
                    $parts[] = 'Secure';
                }

                if ($cookie['httponly']) {
                    $parts[] = 'HttpOnly';
                }

                if ($cookie['samesite'] !== '') {
                    $parts[] = 'SameSite=' . $cookie['samesite'];
                }

                header('Set-Cookie: ' . implode('; ', $parts), false);
            }
        }

        echo Json::encode($this->payload);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
