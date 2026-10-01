<?php

declare(strict_types=1);

namespace FieldPulse\Config;

use FieldPulse\Support\Env;

/**
 * The single configuration schema for the application.
 *
 * Every operational threshold referenced anywhere in the codebase is declared
 * here, so a host administrator can retune behaviour by editing .env alone —
 * no code changes, and therefore no new code paths to audit.
 *
 * @return array<string,mixed>
 */
return [
    'app' => [
        'env'     => Env::get('APP_ENV', 'production'),
        'debug'   => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
        'name'    => Env::get('APP_NAME', 'FieldPulse'),
        'url'     => rtrim((string) Env::get('APP_URL', ''), '/'),
        'version' => FIELDPULSE_VERSION,
    ],

    /*
     * Database timestamps are always UTC. Business timezone is used only for
     * computing reporting periods (Monday-boundary weeks) and for display.
     */
    'timezone' => [
        'business'   => Env::get('APP_TIMEZONE', 'Africa/Lagos'),
        'storage'    => 'UTC',
        'period_day' => 1, // 1 = Monday start of week
    ],

    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => (int) Env::get('DB_PORT', '3306'),
        'name'    => (string) Env::get('DB_NAME', 'fieldpulse'),
        'user'    => (string) Env::get('DB_USER', ''),
        'pass'    => (string) Env::get('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'socket'  => Env::get('DB_SOCKET', ''),
    ],

    'storage' => [
        'root'            => FIELDPULSE_PRIVATE_ROOT . '/fieldpulse',
        'max_upload_bytes' => (int) Env::get('MAX_UPLOAD_BYTES', '5242880'), // 5 MB, per spec §10
        'allowed_mimes'   => ['image/jpeg', 'image/png'],
        'dir_perms'       => 0750,
        'file_perms'      => 0640,
    ],

    'security' => [
        // HS256 signing secret. Must be >= 32 bytes of entropy.
        'jwt_secret'       => (string) Env::get('JWT_SECRET', ''),
        'jwt_issuer'       => (string) Env::get('JWT_ISSUER', 'fieldpulse'),
        'jwt_audience'     => (string) Env::get('JWT_AUDIENCE', 'fieldpulse-pwa'),
        'access_ttl'       => (int) Env::get('ACCESS_TOKEN_TTL', '900'),          // 15 minutes
        'refresh_ttl'      => (int) Env::get('REFRESH_TOKEN_TTL_DAYS', '30') * 86400,
        'cookie_name'      => (string) Env::get('REFRESH_COOKIE_NAME', 'fp_refresh'),
        'cookie_secure'    => true,
        'cookie_samesite'  => 'Strict',
        'cookie_path'      => '/api/v1/auth',

        // §7 replay protection.
        'clock_skew'        => (int) Env::get('CLOCK_SKEW_SECONDS', '300'),
        'nonce_ttl'         => (int) Env::get('NONCE_TTL_SECONDS', '900'),         // 15 minutes
        'pairing_code_ttl'  => (int) Env::get('PAIRING_CODE_TTL_SECONDS', '1800'),   // 30 minutes
        'signature_algorithm' => 'ES256',                                          // ECDSA P-256 + SHA-256
        'canonical_separator' => "\n",
        'max_signature_attempts' => 3,   // re-read tolerant; no brute-force surface

        // Comma-separated list of additional trusted origins. APP_URL is always
        // trusted implicitly. Used only for the CSRF check on mutating requests.
        'allowed_origins' => (string) Env::get('ALLOWED_ORIGINS', ''),

        // X-Forwarded-For / X-Forwarded-Proto are attacker-controlled unless a
        // proxy you control sets them. Leave false unless FieldPulse genuinely
        // sits behind one.
        'trust_proxy_headers' => filter_var(Env::get('TRUST_PROXY_HEADERS', 'false'), FILTER_VALIDATE_BOOL),
    ],

    'auth' => [
        /*
         * Login is username + password (see Security\Credentials), and a device
         * is registered afterwards from the bootstrap session login returns.
         * imei_pattern survives only for the administrative pairing CLI and for
         * validating legacy rows: it is not an authentication input.
         */
        'imei_pattern'      => '/^\d{14,16}$/',
        'login_rate_limit'  => (int) Env::get('LOGIN_RATE_LIMIT', '10'),
        'login_rate_window' => (int) Env::get('LOGIN_RATE_WINDOW', '900'),
        'register_rate_limit'  => (int) Env::get('REGISTER_RATE_LIMIT', '5'),
        'register_rate_window' => (int) Env::get('REGISTER_RATE_WINDOW', '3600'),

        /*
         * How much friction adding a device costs. Registration is gated on a
         * login-issued bootstrap token in every case; this decides whether it
         * ALSO requires an operator-issued one-time pairing code, which is the
         * only out-of-band factor standing between a stolen password and a
         * key an attacker controls.
         *
         *   ALWAYS          every new device needs a code. Safest; an agent
         *                   replacing a lost handset needs a new code.
         *   FIRST_DEVICE_ONLY  the first device needs a code, later ones do not.
         *                   The default: one enrolment per agent, then friction
         *                   only when the agent is adding a second device.
         *   NEVER           any new device, no code. Password-only: treat a
         *                   compromised password as a compromised account.
         *
         * An unrecognised value is treated as ALWAYS rather than ignored, so a
         * typo here fails closed. See DeviceController::policy().
         */
        'device_pairing_policy' => strtoupper((string) Env::get('DEVICE_PAIRING_POLICY', 'FIRST_DEVICE_ONLY')),
    ],

    'upload' => [
        'min_dimension'  => (int) Env::get('MIN_IMAGE_DIMENSION', '64'),
        'max_dimension'  => (int) Env::get('MAX_IMAGE_DIMENSION', '12000'),
        'max_pixels'     => (int) Env::get('MAX_IMAGE_PIXELS', '40000000'),
    ],

    /*
     * Perceptual duplicate detection (§11).
     * hamming_threshold: <= this distance  => PERCEPTUAL_DUPLICATE (reject)
     * possible_margin:   <= threshold+margin => POSSIBLE_DUPLICATE (review)
     */
    'phash' => [
        'algorithm'        => 'dct-ii-32x32-8x8',
        'resize'           => 32,
        'hash_size'        => 8,
        'hamming_threshold' => (int) Env::get('PHASH_HAMMING_THRESHOLD', '8'),
        'possible_margin'   => (int) Env::get('PHASH_POSSIBLE_MARGIN', '4'),
        'candidate_limit'   => (int) Env::get('PHASH_CANDIDATE_LIMIT', '500'),
        'band_bits'        => 16,
    ],

    'geofence' => [
        'default_radius_m' => (int) Env::get('GEOFENCE_DEFAULT_RADIUS_M', '250'),
        'earth_radius_m'   => 6371008.8,           // IUGG mean Earth radius
        'bbox_padding_m'   => (int) Env::get('GEOFENCE_BBOX_PADDING_M', '500'),
        'gps_accuracy_m'   => (int) Env::get('GPS_ACCURACY_TOLERANCE_M', '100'),

        /*
         * Ceiling for the client-reported accuracy_m claim (§10).
         *
         * Distinct from gps_accuracy_m above, which is how far a reported
         * accuracy may extend before the *centre* of the geofence test is
         * discounted. This one bounds the number the client may store: a fix
         * whose 95% confidence radius is larger than this is not field evidence
         * of a location at all, and accepting it would mean writing an
         * arbitrarily weak claim into the ledger and letting the reviewer find
         * out later.
         *
         * 500 m is comfortably above a cold-start fix on consumer hardware and
         * comfortably below "I have no idea where I am". Overridable because a
         * genuinely indoor deployment may need to raise it, and because a test
         * suite needs to be able to assert the boundary.
         */
        'gps_accuracy_max_m' => (int) Env::get('GPS_ACCURACY_MAX_M', '500'),
    ],

    'timestamps' => [
        'max_future_skew'  => (int) Env::get('TS_MAX_FUTURE_SKEW', '900'),
        'max_past_skew'    => (int) Env::get('TS_MAX_PAST_SKEW', '604800'),   // 7 days
    ],

    'limits' => [
        'count_claimed_min'  => (int) Env::get('COUNT_CLAIMED_MIN', '1'),
        'count_claimed_max'  => (int) Env::get('COUNT_CLAIMED_MAX', '500'),
        'weekly_cap'         => (int) Env::get('WEEKLY_CAP', '2500'),
    ],

    'worker' => [
        'max_attempts'       => (int) Env::get('JOB_MAX_ATTEMPTS', '5'),
        'lock_timeout'       => (int) Env::get('JOB_LOCK_TIMEOUT_MIN', '10'),
        'batch_size'         => (int) Env::get('JOB_BATCH_SIZE', '10'),
        'max_runtime'        => (int) Env::get('WORKER_MAX_RUNTIME', '240'),
        'backoff_base'       => 60,
        'backoff_cap'        => 3600,
    ],

    'aggregation' => [
        'period_type' => 'weekly_monday',
    ],

    'verification' => [
        'version' => Env::get('VERIFICATION_VERSION', 'v1.0.0'),
    ],

    /*
     * Retention windows, used by bin/prune.php. Audit history is the only
     * independent record of who reviewed what, so it is deliberately the longest
     * of the three; the others are operational noise.
     */
    'ops' => [
        'job_retention_days'          => (int) Env::get('JOB_RETENTION_DAYS', '7'),
        'audit_retention_days'        => (int) Env::get('AUDIT_RETENTION_DAYS', '730'),
        'login_attempt_retention_days' => (int) Env::get('LOGIN_ATTEMPT_RETENTION_DAYS', '30'),
    ],
];
