-- ---------------------------------------------------------------------------
-- 002 — devices
--
-- Per spec §4. public_key_jwk holds an EC P-256 public JWK ({kty,crv,x,y});
-- the private key never leaves the handset's non-extractable WebCrypto key.
--
-- Additive column:
--   imei  the handset serial observed at registration, so that a device row
--         remains attributable even if an agent is later re-provisioned to a
--         different handset.
--
-- ON DELETE RESTRICT on agent_id: an agent with an audit trail must not be
-- deletable. Deactivate with status instead.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    device_uuid CHAR(36) NOT NULL,
    public_key_jwk JSON NOT NULL,
    status ENUM('PENDING','ACTIVE','REVOKED','DISABLED') NOT NULL DEFAULT 'PENDING',
    registered_at DATETIME NOT NULL,
    last_seen_at DATETIME NULL,
    imei VARCHAR(20) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_devices_device_uuid (device_uuid),
    UNIQUE KEY uniq_devices_imei (imei),
    KEY idx_devices_agent_created (agent_id, created_at),
    KEY idx_devices_agent_status (agent_id, status),
    CONSTRAINT fk_devices_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
