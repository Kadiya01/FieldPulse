-- ABH Monthly Enrollment System
-- Migration 007: cryptographic device binding and isolated agent sessions.
-- Existing ABH devices remain unbound until the agent re-registers from the
-- browser that owns its private key. This migration never changes legacy tables.

ALTER TABLE abh_agent_devices
    ADD COLUMN device_uuid CHAR(36) NULL AFTER imei_value,
    ADD COLUMN public_key_jwk JSON NULL AFTER device_uuid,
    ADD COLUMN last_seen_at DATETIME(6) NULL AFTER verified_by_admin_id,
    ADD UNIQUE KEY uq_abh_agent_devices_device_uuid (device_uuid);

CREATE TABLE abh_agent_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    access_token_hash CHAR(64) NOT NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    token_family CHAR(36) NOT NULL,
    access_expires_at DATETIME(6) NOT NULL,
    refresh_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_reason VARCHAR(40) NULL,
    rotated_to_session_id BIGINT UNSIGNED NULL,
    last_used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_sessions_access_hash (access_token_hash),
    UNIQUE KEY uq_abh_sessions_refresh_hash (refresh_token_hash),
    KEY idx_abh_sessions_agent_device (agent_id, device_id, revoked_at),
    KEY idx_abh_sessions_family (token_family),
    KEY idx_abh_sessions_refresh_expiry (refresh_expires_at),
    CONSTRAINT fk_abh_sessions_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_sessions_device
        FOREIGN KEY (device_id) REFERENCES abh_agent_devices (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_sessions_rotated_to
        FOREIGN KEY (rotated_to_session_id) REFERENCES abh_agent_sessions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_auth_nonces (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id BIGINT UNSIGNED NOT NULL,
    nonce VARCHAR(128) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_auth_nonces_device_nonce (device_id, nonce),
    KEY idx_abh_auth_nonces_expiry (expires_at),
    CONSTRAINT fk_abh_auth_nonces_device
        FOREIGN KEY (device_id) REFERENCES abh_agent_devices (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_auth_attempts_identifier_time (identifier_hash, attempted_at),
    KEY idx_abh_auth_attempts_ip_time (ip_hash, attempted_at),
    KEY idx_abh_auth_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
