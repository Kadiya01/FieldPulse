-- ---------------------------------------------------------------------------
-- 003 — refresh_tokens
--
-- Per spec §6: only the SHA-256 hash of a refresh token is persisted. The raw
-- value exists only in the HttpOnly cookie held by the device.
--
-- token_family groups every token descended from one login, which is what makes
-- reuse detection possible (§6 rotation): presenting an already-rotated token
-- is proof the cookie leaked, and revokes the whole family.
--
-- Additive columns: token_family, replaced_by_id, user_agent_hash.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_family CHAR(36) NOT NULL,
    replaced_by_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_refresh_token_hash (token_hash),
    KEY idx_refresh_agent_device (agent_id, device_id),
    KEY idx_refresh_family (token_family),
    KEY idx_refresh_expires (expires_at),
    KEY idx_refresh_active (token_hash, revoked_at, expires_at),
    CONSTRAINT fk_refresh_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_refresh_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT,
    CONSTRAINT fk_refresh_replaced_by FOREIGN KEY (replaced_by_id) REFERENCES refresh_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
