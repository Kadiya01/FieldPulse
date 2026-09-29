<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Http\ApiException;
use FieldPulse\Support\Str;

/**
 * EC P-256 JWK handling.
 *
 * The device's private key never leaves the handset: the PWA generates it with
 * `crypto.subtle.generateKey({name:'ES256'}, false, ['sign','verify'])` so the
 * CryptoKey is non-extractable, and only the public JWK is registered here.
 *
 * openssl_verify() needs a PEM, and there is no PHP API that imports a raw
 * uncompressed EC point, so the DER SubjectPublicKeyInfo is assembled by hand
 * for prime256v1:
 *
 *   SEQUENCE {
 *     SEQUENCE { OID 1.2.840.10045.2.1, OID 1.2.840.10045.3.1.7 }
 *     BIT STRING { 0x00, 0x04 || X || Y }        ; uncompressed point
 *   }
 *
 * Byte-for-byte this is what a browser-generated P-256 key produces, which is
 * verified by the self-test against a key generated here in PHP.
 */
final class Jwk
{
    /** OID 1.2.840.10045.2.1 — id-ecPublicKey */
    private const OID_EC_PUBLIC_KEY = "\x2a\x86\x48\xce\x3d\x02\x01";
    /** OID 1.2.840.10045.3.1.7 — prime256v1 / secp256r1 */
    private const OID_PRIME256V1   = "\x2a\x86\x48\xce\x3d\x03\x01\x07";

    private const COORDINATE_BYTES = 32;

    /** Fields permitted in a submitted public JWK. */
    private const ALLOWED_MEMBERS = ['kty', 'crv', 'x', 'y', 'kid', 'alg', 'use'];

    private function __construct()
    {
    }

    /**
     * Validate a client-supplied public JWK and return it in normalised form.
     *
     * @param  array<array-key,mixed> $jwk
     * @return array{kty:string,crv:string,x:string,y:string,alg:string,use:string,kid?:string}
     * @throws ApiException on any deviation from a P-256 public key
     */
    public static function validatePublicJwk(array $jwk): array
    {
        foreach (array_keys($jwk) as $member) {
            if (!in_array($member, self::ALLOWED_MEMBERS, true)) {
                // 'd' is the dangerous one: uploading a private key must be a
                // hard rejection, not a silently ignored extra field.
                throw new ApiException(
                    422,
                    \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID,
                    $member === 'd'
                        ? 'A private key must never be sent to the server.'
                        : 'Unexpected JWK member: ' . (string) $member
                );
            }
        }

        if (($jwk['kty'] ?? null) !== 'EC') {
            throw new ApiException(422, \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID, 'Only EC keys are supported.');
        }

        if (($jwk['crv'] ?? null) !== 'P-256') {
            throw new ApiException(422, \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID, 'Only the P-256 curve is supported.');
        }

        if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
            throw new ApiException(422, \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID, 'Key use must be "sig".');
        }

        if (isset($jwk['alg']) && $jwk['alg'] !== 'ES256') {
            throw new ApiException(422, \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID, 'Key algorithm must be ES256.');
        }

        $x = self::coordinate($jwk['x'] ?? null, 'x');
        $y = self::coordinate($jwk['y'] ?? null, 'y');

        $normalised = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => Str::base64UrlEncode($x),
            'y'   => Str::base64UrlEncode($y),
            'alg' => 'ES256',
            'use' => 'sig',
        ];

        if (isset($jwk['kid']) && is_string($jwk['kid']) && preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $jwk['kid']) === 1) {
            $normalised['kid'] = $jwk['kid'];
        }

        // The decisive check: a point that is not on the curve is rejected by
        // OpenSSL when the PEM is parsed, so a bogus key can never be stored.
        if (self::toPem($normalised) === null) {
            throw new ApiException(
                422,
                \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID,
                'Public key is not a valid point on the P-256 curve.'
            );
        }

        return $normalised;
    }

    private static function coordinate(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID, 'JWK coordinate "' . $name . '" is missing.');
        }

        $raw = Str::base64UrlDecode($value);

        if ($raw === null || strlen($raw) !== self::COORDINATE_BYTES) {
            throw new ApiException(
                422,
                \FieldPulse\Http\ErrorCode::DEVICE_KEY_INVALID,
                'JWK coordinate "' . $name . '" must be exactly 32 bytes, base64url encoded.'
            );
        }

        return $raw;
    }

    /**
     * Assemble the DER SubjectPublicKeyInfo and return PEM, or null when OpenSSL
     * rejects the point.
     *
     * @param array{kty:string,crv:string,x:string,y:string} $jwk
     */
    public static function toPem(array $jwk): ?string
    {
        $x = Str::base64UrlDecode((string) $jwk['x']);
        $y = Str::base64UrlDecode((string) $jwk['y']);

        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        $algorithm = Str::derElement(0x06, self::OID_EC_PUBLIC_KEY)
            . Str::derElement(0x06, self::OID_PRIME256V1);

        $point = "\x04" . $x . $y;                     // uncompressed: 0x04 || X || Y
        $bitString = "\x00" . $point;                  // 0 unused-bits byte, then the point

        $spki = Str::derElement(0x30,
            Str::derElement(0x30, $algorithm)
            . Str::derElement(0x03, $bitString)
        );

        $pem = Str::toPem($spki, 'PUBLIC KEY');

        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }

        return $pem;
    }

    /**
     * @param array{kty:string,crv:string,x:string,y:string} $jwk
     */
    public static function publicKey(array $jwk): ?\OpenSSLAsymmetricKey
    {
        $pem = self::toPem($jwk);
        if ($pem === null) {
            return null;
        }

        $key = @openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /**
     * RFC 7638-style thumbprint over the required members, in lexicographic
     * order. Used as the device key identifier and as a cache key, so two
     * devices can never collide on the same identifier.
     *
     * @param array<string,mixed> $jwk
     */
    public static function thumbprint(array $jwk): string
    {
        $canonical = json_encode([
            'crv' => (string) ($jwk['crv'] ?? ''),
            'kty' => (string) ($jwk['kty'] ?? ''),
            'x'   => (string) ($jwk['x'] ?? ''),
            'y'   => (string) ($jwk['y'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) hash('sha256', (string) $canonical, true)), '+/', '-_'), '=');
    }

    /**
     * Extract the public JWK from a key pair, e.g. one generated in PHP for the
     * self-test. Returns null when the key is not an EC P-256 key.
     */
    public static function fromPublicKey(\OpenSSLAsymmetricKey $key): ?array
    {
        $details = @openssl_pkey_get_details($key);

        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return null;
        }

        $ec = $details['ec'];

        // openssl_pkey_get_details() hands back the raw big-endian coordinate
        // bytes with no zero padding, so a coordinate whose leading byte is
        // 0x00 arrives 31 bytes long rather than 32. That happens for roughly
        // 1 key in 128. Rejecting on length threw "Generated key is not EC
        // P-256" intermittently during provisioning; padding the coordinate
        // instead is also the only correct reading, because JWK requires the
        // full field size. A 31-byte x would encode to 42 base64url characters
        // and every signature made with that key would then fail to verify.
        $x    = self::padCoordinate((string) ($ec['x'] ?? ''));
        $y    = self::padCoordinate((string) ($ec['y'] ?? ''));
        $curve = (string) ($ec['curve_name'] ?? '');

        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32 || $curve !== 'prime256v1') {
            return null;
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => Str::base64UrlEncode($x),
            'y'   => Str::base64UrlEncode($y),
            'alg' => 'ES256',
            'use' => 'sig',
        ];
    }

    /**
     * Left-pad a P-256 coordinate to the 32-byte field size.
     *
     * Returns null for anything that cannot be a coordinate, so a genuinely
     * malformed key still fails rather than being silently accepted.
     */
    private static function padCoordinate(string $raw): ?string
    {
        $length = strlen($raw);

        if ($length === 0 || $length > 32) {
            return null;
        }

        return str_pad($raw, 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Generate a P-256 key pair in PHP. Used by the self-test and by the
     * provisioning tool; the PWA generates its own key in WebCrypto.
     *
     * @return array{private:\OpenSSLAsymmetricKey,public:array<string,string>}
     */
    public static function generateKeyPair(): array
    {
        $key = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'      => 'prime256v1',
        ]);

        if ($key === false) {
            // Drain the whole error queue. A single openssl_error_string() call
            // pops one error and is often the least useful one, so a failure
            // here used to report something as vague as "Unable to generate an
            // EC P-256 key pair: " with no cause at all. The usual real cause
            // is a missing openssl.cnf, which surfaces as
            // "configuration file routines::no such file".
            throw new \RuntimeException(
                'Unable to generate an EC P-256 key pair. ' . self::drainOpenSslErrors()
            );
        }

        $publicJwk = self::fromPublicKey($key);
        if ($publicJwk === null) {
            throw new \RuntimeException(
                'Generated key is not EC P-256. ' . self::drainOpenSslErrors()
            );
        }

        return [
            // The handle from openssl_pkey_new() is already the private key and
            // is what openssl_sign() wants. Serialising to PEM and re-reading it
            // used to fail outright: openssl_pkey_get_details()['key'] is the
            // *public* PEM for an EC key, so feeding it to openssl_pkey_get_private()
            // raised "DECODER routines::unsupported".
            'private' => $key,
            'public'  => $publicJwk,
        ];
    }

    /**
     * Empty OpenSSL's error queue and return it as one readable string.
     *
     * The queue is a stack of everything that has gone wrong since the last
     * read, so it is drained rather than sampled. Also mentions the
     * openssl.cnf hint, because a missing configuration file is by far the most
     * common cause of openssl_pkey_new() returning false with the extension
     * loaded and no warning printed.
     */
    private static function drainOpenSslErrors(): string
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        if ($errors === []) {
            return 'OpenSSL reported no detail (is the openssl extension loaded?).';
        }

        $hint = extension_loaded('openssl')
            ? ' If this mentions a missing configuration file, point OPENSSL_CONF at openssl.cnf.'
            : ' The openssl extension is not loaded.';

        return 'OpenSSL errors: ' . implode(' | ', $errors) . '.' . $hint;
    }
}
