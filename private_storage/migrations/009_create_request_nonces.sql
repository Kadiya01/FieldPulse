-- ---------------------------------------------------------------------------
-- 009 — request_nonces                    [ADDITIVE: required by spec §7]
--
-- §7 mandates replay protection with a 15-minute nonce window, but the §4
-- table set has nowhere to record a consumed nonce. Without this table a
-- captured signed request could be replayed for the lifetime of its access
-- token.
--
-- The nonce is stored as SHA-256(device_uuid || ':' || nonce) rather than
-- raw: the column is then safe to inspect in a support query, and the raw
-- client nonce never needs to be durable.
--
-- UNIQUE (device_id, nonce_sha256) is the enforcement point. The writer uses
-- INSERT and treats duplicate-key as REPLAY_DETECTED — a SELECT-then-INSERT
-- check would leave a race window between the two statements.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS request_nonces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    nonce_sha256 CHAR(64) NOT NULL,
    request_path VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_nonce_device (device_id, nonce_sha256),
    KEY idx_nonce_expires (expires_at),
    CONSTRAINT fk_nonces_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
