<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Canonical string construction for request signing (§7).
 *
 *   METHOD \n
 *   PATH \n
 *   TIMESTAMP \n
 *   NONCE \n
 *   SHA256HEX(BODY)
 *
 * Two deliberate departures from the contract, both documented in
 * docs/CONTRACT.md § Deviations:
 *
 *  1. Newline delimiters instead of bare concatenation. `POST` + `/submit.php`
 *     + `1700000000` and `POST/s` + `ubmit.php1700000000` produce the same byte
 *     string under `+`; the newline-delimited form does not. Path segments and
 *     timestamps are attacker-influenced, so this is a signature-substitution
 *     boundary, not a cosmetic detail.
 *
 *  2. The signed body for submit.php is the verbatim `payload` multipart
 *     field, not the raw HTTP body. `php://input` is empty for
 *     multipart/form-data because PHP has already consumed the stream to
 *     populate $_POST/$_FILES, so SHA256(REQUEST_BODY) is not computable
 *     server-side for a file upload. The client therefore places a single
 *     canonical JSON string in the `payload` field and signs that exact byte
 *     sequence; the uploaded file's SHA-256 is inside that payload and is
 *     verified against the received bytes, so the file is still covered by the
 *     signature through a checked digest rather than an unchecked copy.
 *
 * The query string is deliberately excluded: a client-supplied path variable
 * must never be able to reach business logic unsigned. No security-relevant
 * value may therefore be read from the query string on a signed endpoint.
 */
final class CanonicalPayload
{
    private function __construct()
    {
    }

    /**
     * @param string $method  Uppercase HTTP method.
     * @param string $path    Request path only, no query string, no leading slash normalisation.
     * @param int    $timestamp Unix seconds.
     * @param string $nonce   Client nonce, at least 16 bytes of entropy.
     * @param string $body    Exact bytes that are hashed (canonical payload field, or raw JSON).
     */
    public static function build(
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $body
    ): string {
        $separator = Config::instance()->str('security.canonical_separator');

        /*
         * Reject the separator inside every field BEFORE building.
         *
         * This is the whole point of using a delimiter, and it is only a defence
         * if the delimiter cannot appear in the data. A percent-encoded newline
         * in the request path is decoded to a real newline by the time it
         * reaches $path, and an attacker who controls it can then shift the
         * fields: a signature that was computed for one (method, path) pair
         * verifies for a different one. Enforcing it here — inside the builder —
         * rather than at the call sites means every caller is protected by
         * construction instead of by remembering.
         *
         * assertNonceFormat() already refuses newlines, but the path never had
         * that check, and defence that lives only at the edge erodes the moment
         * a second caller appears.
         */
        foreach (['method' => $method, 'path' => $path, 'nonce' => $nonce] as $name => $value) {
            if (str_contains($value, $separator)) {
                throw new ApiException(
                    400,
                    ErrorCode::SIGNATURE_INVALID,
                    'Illegal ' . $name . ' for a signed request.'
                );
            }
        }

        if (str_contains("\r", $method . $path . $nonce)) {
            throw new ApiException(400, ErrorCode::SIGNATURE_INVALID, 'Illegal header value for a signed request.');
        }

        // Exactly five fields, five separators, and NO leading separator. A
        // leading one would make the string six fields and would silently
        // disagree with any client that builds the documented form.
        return strtoupper($method)
            . $separator
            . $path
            . $separator
            . $timestamp
            . $separator
            . $nonce
            . $separator
            . self::bodyDigest($body);
    }

    /**
     * SHA-256 of the body bytes, lowercase hex — the value that actually enters
     * the canonical string.
     */
    public static function bodyDigest(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * Enforce header shape before any value is used in a comparison.
     *
     * @throws ApiException
     */
    public static function assertNonceFormat(string $nonce): void
    {
        // 16 bytes of entropy, base64url or hex; capped so the value can never
        // become a storage or index liability.
        if (strlen($nonce) < 22 || strlen($nonce) > 128) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed X-Request-Nonce.');
        }

        if (preg_match('/^[A-Za-z0-9_\-]+$/', $nonce) !== 1) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed X-Request-Nonce.');
        }
    }

    /**
     * Clock skew gate (§7). Uses the server clock only; the client's clock is
     * never trusted for anything but this bounded comparison.
     *
     * @throws ApiException
     */
    public static function assertFreshTimestamp(int $timestamp, int $now, int $allowedSkew): void
    {
        $skew = abs($now - $timestamp);

        if ($skew > $allowedSkew) {
            throw new ApiException(
                401,
                ErrorCode::CLOCK_SKEW,
                'Request timestamp is outside the accepted window.',
                ['skew_seconds' => $skew]
            );
        }
    }
}
