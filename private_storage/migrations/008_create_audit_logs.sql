-- ---------------------------------------------------------------------------
-- 008 — audit_logs
--
-- Append-only record of every privileged or security-relevant action:
-- logins, token reuse, device registration/revocation, admin review
-- decisions, and worker-level verification outcomes.
--
-- actor_agent_id is ON DELETE SET NULL: an audit row must outlive the agent it
-- refers to, otherwise destroying a record would destroy the evidence.
--
-- No ip_address index by default — this table is append-only and queried by
-- time, not by address; an index on a growing table is not worth the write cost
-- on shared hosting.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_agent_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_actor_created (actor_agent_id, created_at),
    KEY idx_audit_action_created (action, created_at),
    KEY idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_agent_id) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
