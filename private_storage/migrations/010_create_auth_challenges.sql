-- ---------------------------------------------------------------------------
-- 010 — auth_challenges                   [ADDITIVE: zero-password PoP flow]
--
-- The confirmed model is "IMEI identifies the agent, the device P-256 key
-- proves possession". That proof needs a server-issued nonce, which needs a
-- store. agent_id is deliberately NULLable: a challenge for an unrecognised
-- IMEI is still issued and stored, so the challenge endpoint's response cannot
-- be used to enumerate which IMEIs belong to real agents.
--
-- consumed_at + attempts make the challenge strictly single-use and cap the
-- number of forged signatures tried against one challenge.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS auth_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    challenge CHAR(64) NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    device_uuid CHAR(36) NULL,
    purpose VARCHAR(20) NOT NULL DEFAULT 'LOGIN',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_challenges_challenge (challenge),
    KEY idx_challenges_expires (expires_at),
    KEY idx_challenges_agent (agent_id, created_at),
    CONSTRAINT fk_challenges_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
