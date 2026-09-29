-- ---------------------------------------------------------------------------
-- 001 — agents
--
-- Per spec §4, with two additive columns required by the confirmed
-- zero-password credential model (IMEI identifies the agent; the device P-256
-- key proves possession):
--   imei            the enrolled handset serial, set by `bin/provision_agent.php`
--   imei_enrolled_at when that binding was made (audit value)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS agents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_code VARCHAR(64) NOT NULL,
    full_name VARCHAR(191) NOT NULL,
    status ENUM('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
    imei VARCHAR(20) NULL,
    imei_enrolled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_agents_agent_code (agent_code),
    UNIQUE KEY uniq_agents_imei (imei),
    KEY idx_agents_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
