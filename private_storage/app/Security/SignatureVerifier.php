<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Logger;

/**
 * Device-bound request signature verification (§7).
 *
 * Verified per request: access token subject, device UUID, request signature,
 * timestamp, nonce. The public key is read from devices.public_key_jwk, so a
 * device cannot sign with a key it does not own and cannot delegate signing to
 * another device by re-using a UUID.
 *
 * Failure modes are deliberately collapsed into indistinguishable 401s for the
 * client (with the precise cause kept server-side) so that this endpoint cannot
 * be used as an oracle to test which key material exists.
 */
final class SignatureVerifier
{
    /** @var array<string,\OpenSSLAsymmetricKey> */
    private static array $keyCache = [];

    /**
     * @param array<string,mixed> $deviceRow A row from `devices`.
     * @param string $signedBody   Exact bytes covered by the signature: the
     *                             verbatim `payload` field for multipart
     *                             requests, or the raw body for JSON requests.
     *
     * @throws ApiException on any verification failure
     */
    public static function verify(
        array $deviceRow,
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $signedBody,
        string $signatureB64
    ): void {
        $jwk = self::decodeStoredJwk($deviceRow);
        $key = self::resolveKey($jwk, (string) $deviceRow['device_uuid']);
        $raw = \FieldPulse\Support\Str::base64UrlDecode($signatureB64);

        if ($raw === null) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed X-Request-Signature.');
        }

        // DER-encoded ECDSA signature, which is what openssl_verify accepts.
        // WebCrypto's subtle.sign does NOT produce DER: it produces IEEE P1363,
        // r || s concatenated, exactly 64 bytes for P-256. A browser signature
        // therefore fails to parse here as DER and openssl_verify returns -1 —
        // not 0, the "signature does not match" case — for every request. The
        // PHP suites never caught this because they sign with openssl_sign(),
        // which emits DER, while only a real browser key can produce the raw
        // form. openssl_verify() takes a string, so a non-DER string is not a
        // type error, it is a silent -1, which is exactly the shape of a much
        // more interesting bug.
        $signed = self::signedBytes($method, $path, $signedBody, $timestamp, $nonce);
        $result = @openssl_verify($signed, $raw, $key, OPENSSL_ALGO_SHA256);

        if ($result === -1 && strlen($raw) === 64) {
            // Retry once with the raw P1363 signature transcribed to DER. The
            // 64-byte guard keeps this from ever re-encoding a real DER
            // signature: those are 70-72 bytes, and a legitimate DER signature
            // cannot parse as P1363, so it never reaches the -1 branch.
            $der = self::p1363ToDer($raw);
            if ($der !== null) {
                $result = @openssl_verify($signed, $der, $key, OPENSSL_ALGO_SHA256);
            }
        }

        if ($result !== 1) {
            Logger::warning('signature.verification_failed', [
                'device_uuid' => $deviceRow['device_uuid'],
                'path'        => $path,
                // openssl_error_string() reports the last entry in the queue
                // and does not clear it, so this is frequently a stale message
                // from an earlier OpenSSL call in the same request. It says
                // "PEM routines::no start line" while the PEM built from the
                // JWK is in fact fine, which sends the investigation entirely
                // the wrong way. $result is the trustworthy signal: 0 means the
                // signature simply does not match, -1 means OpenSSL failed.
                'openssl'     => (string) openssl_error_string(),
                'verify'      => $result,
                'signed_sha256'  => hash('sha256', $signed),
                'sig_bytes'      => strlen($raw),
                'nonce'          => $nonce,
            ]);

            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Request signature verification failed.');
        }
    }

    /**
     * Transcribe an IEEE P1363 ECDSA signature (r || s, both fixed-width) into
     * the DER form openssl_verify expects.
     *
     * @param  string $raw  A 64-byte string when the caller intends P1363.
     * @return string|null  The DER encoding, or null when $raw is not 64 bytes.
     */
    public static function p1363ToDer(string $raw): ?string
    {
        if (strlen($raw) !== 64) {
            return null;
        }

        $encode = static function (string $int): string {
            $trimmed = ltrim($int, "\x00");
            if ($trimmed === '') {
                return "\x02\x01\x00";
            }
            if ((ord($trimmed[0]) & 0x80) !== 0) {
                $trimmed = "\x00" . $trimmed;
            }
            return "\x02" . chr(strlen($trimmed)) . $trimmed;
        };

        $r = $encode(substr($raw, 0, 32));
        $s = $encode(substr($raw, 32, 32));

        return "\x30" . chr(strlen($r) + strlen($s)) . $r . $s;
    }

    /**
     * Build the exact byte sequence the client signed.
     */
    public static function signedBytes(
        string $method,
        string $path,
        string $signedBody,
        int $timestamp,
        string $nonce
    ): string {
        return CanonicalPayload::build($method, $path, $timestamp, $nonce, $signedBody);
    }

    /**
     * @param  array<string,mixed> $deviceRow
     * @return array<string,mixed>
     */
    private static function decodeStoredJwk(array $deviceRow): array
    {
        $stored = $deviceRow['public_key_jwk'] ?? null;

        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        if (!is_array($stored)) {
            throw new ApiException(401, ErrorCode::DEVICE_KEY_INVALID, 'Device key is unreadable.');
        }

        return $stored;
    }

    /**
     * @param array<string,mixed> $jwk
     */
    private static function resolveKey(array $jwk, string $deviceUuid): \OpenSSLAsymmetricKey
    {
        // Cache per request only, keyed by the thumbprint of the key just read
        // from the database: a key rotation must take effect immediately, so the
        // cache must never be able to outlive a change in devices.public_key_jwk.
        $thumbprint = Jwk::thumbprint($jwk);

        if (isset(self::$keyCache[$thumbprint])) {
            return self::$keyCache[$thumbprint];
        }

        $key = Jwk::publicKey($jwk);

        if ($key === null) {
            Logger::warning('device.key_unreadable', ['device_uuid' => $deviceUuid]);
            throw new ApiException(401, ErrorCode::DEVICE_KEY_INVALID, 'Device key is invalid.');
        }

        self::$keyCache[$thumbprint] = $key;

        return $key;
    }

    public static function clearKeyCache(): void
    {
        self::$keyCache = [];
    }

    /**
     * Confirm a freshly supplied public key is actually controlled by whoever
     * presented it, by checking a signature over an arbitrary message.
     *
     * Kept as a general primitive rather than a login-specific one: the
     * challenge→sign→login round trip is gone, so this is no longer how a
     * session starts, but verifying that a key controls its own private half is
     * a property worth being able to assert anywhere a JWK is accepted. Without
     * it, a caller could register someone else's public JWK and then
     * impersonate them.
     *
     * @param  array<string,mixed> $jwk
     * @throws ApiException
     */
    public static function assertProofOfPossession(array $jwk, string $message, string $signatureB64): void
    {
        $key = Jwk::publicKey($jwk);

        if ($key === null) {
            throw new ApiException(422, ErrorCode::DEVICE_KEY_INVALID, 'Public key is not a valid P-256 point.');
        }

        $raw = \FieldPulse\Support\Str::base64UrlDecode($signatureB64);

        if ($raw === null) {
            throw new ApiException(401, ErrorCode::SIGNATURE_INVALID, 'Malformed proof-of-possession signature.');
        }

        if (@openssl_verify($message, $raw, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new ApiException(
                401,
                ErrorCode::SIGNATURE_INVALID,
                'The supplied signature does not match the supplied public key.'
            );
        }
    }

    /**
     * Record a successful verification so the audit trail shows the device was
     * actually seen (and not merely referenced by a JWT).
     */
    public static function touchDevice(int $deviceId): void
    {
        try {
            Connection::execute(
                'UPDATE devices SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id',
                ['id' => $deviceId]
            );
        } catch (\Throwable $e) {
            // A bookkeeping write must never fail an otherwise valid request.
            Logger::warning('device.touch_failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);
        }
    }
}
