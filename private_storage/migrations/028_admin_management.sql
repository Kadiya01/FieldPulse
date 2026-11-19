-- ---------------------------------------------------------------------------
-- 028 - admin_management                 [ADDITIVE: admin dashboard & targets]
--
-- Phase 4 needs three pieces of state that the enrollment schema does not
-- carry yet:
--
--   1. Why an account decision was taken. A suspension or rejection without a
--      reason produces an audit trail that cannot answer the only question
--      anyone later asks of it, and an agent whose device stopped working
--      cannot see why. Both columns are nullable so existing rows stay valid.
--
--   2. Monthly targets that are not tied to one agent. An organisation target
--      and a location target are management inputs; the legacy
--      agents.monthly_target remains the per-agent default for backwards
--      compatibility. Precedence is resolved in code, not stored twice:
--      AGENT (period row) > agents.monthly_target > LOCATION > ORGANIZATION.
--
--   3. A unique period+scope key so setting a target twice is an update, not a
--      duplicate. scope_key is '' for ORGANIZATION and the agent id (as text)
--      for AGENT, so the unique index covers all three scopes.
--
-- No target value is invented here: target defaults to 0, which every reader
-- treats as "not configured" rather than as a 0% achievement.
-- ---------------------------------------------------------------------------

ALTER TABLE agents
    ADD COLUMN status_reason VARCHAR(500) NULL AFTER status,
    ADD COLUMN status_changed_at DATETIME NULL AFTER status_reason;

CREATE TABLE IF NOT EXISTS monthly_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_start_date DATE NOT NULL,
    scope_type ENUM('ORGANIZATION','LOCATION','AGENT') NOT NULL,
    scope_key VARCHAR(191) NOT NULL DEFAULT '',
    target INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monthly_target_scope (period_start_date, scope_type, scope_key),
    KEY idx_monthly_targets_period (period_start_date, scope_type),
    KEY idx_monthly_targets_creator (created_by),
    CONSTRAINT fk_monthly_target_creator FOREIGN KEY (created_by) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
