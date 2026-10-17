ALTER TABLE agents
    ADD COLUMN nin CHAR(11) NULL AFTER full_name,
    ADD COLUMN device_type VARCHAR(64) NULL AFTER imei,
    ADD COLUMN self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER device_type;

CREATE UNIQUE INDEX uniq_agents_nin ON agents (nin);
