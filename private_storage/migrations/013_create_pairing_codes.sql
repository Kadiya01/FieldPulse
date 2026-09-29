-- ---------------------------------------------------------------------------
-- 013 — pairing_codes                   [ADDITIVE: out-of-band device binding]
--
-- Why this table exists
-- --------------------
-- The zero-password model is "IMEI identifies the agent, device key proves
-- possession". That only holds if the device key is bound to the agent BEFORE
-- the agent can log in. If a client were allowed to register its own public key
-- during login, then the IMEI alone would remain the only secret and anyone
-- holding it could generate a keypair, sign a challenge with it, and
-- impersonate the agent — the signature would verify perfectly and prove
-- nothing.
--
-- The missing factor is therefore supplied by the operator, once per device:
--
--   bin/pair_device.php --code AG-001
--     -> prints a one-time pairing code, valid PAIRING_CODE_TTL
--   agent enters IMEI + pairing code in the PWA
--     -> POST /api/v1/device/register.php binds the generated public key
--   subsequent logins need no code, only the device key
--
-- code_hash is SHA-256(code) so a database dump does not yield usable pairing
-- codes. attempts/max_attempts bound online guessing of a 10-digit code.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pairing_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    label VARCHAR(100) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    consumed_by_device_id BIGINT UNSIGNED NULL,
    created_by VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pairing_code_hash (code_hash),
    KEY idx_pairing_agent (agent_id, consumed_at, expires_at),
    KEY idx_pairing_expires (expires_at),
    CONSTRAINT fk_pairing_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
    CONSTRAINT fk_pairing_device FOREIGN KEY (consumed_by_device_id) REFERENCES devices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
