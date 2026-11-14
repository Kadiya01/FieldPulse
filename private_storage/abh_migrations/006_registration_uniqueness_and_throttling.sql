-- ABH Monthly Enrollment System
-- Migration 006: enforce unique phone/device identities and registration throttling.
-- Safe to apply after migrations 001-005. It affects only ABH-owned objects.

ALTER TABLE abh_agents
    DROP INDEX idx_abh_agents_phone_normalized,
    ADD UNIQUE KEY uq_abh_agents_phone_normalized (phone_normalized);

ALTER TABLE abh_agent_devices
    DROP INDEX idx_abh_agent_devices_imei,
    ADD UNIQUE KEY uq_abh_agent_devices_imei (imei_value);

CREATE TABLE abh_registration_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_registration_attempts_identifier_time (identifier_hash, attempted_at),
    KEY idx_abh_registration_attempts_ip_time (ip_hash, attempted_at),
    KEY idx_abh_registration_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
