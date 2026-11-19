-- ---------------------------------------------------------------------------
-- 027 - abh_monthly_enrollment       [ADDITIVE: profile, targets, reporting]
--
-- Phase 3 needs three pieces of state the weekly-only schema never carried:
--
--   1. Agent profile fields shown on the agent dashboard and editable by the
--      agent (email and location). Both are optional because existing accounts
--      cannot be required to have data they were never asked for.
--   2. A monthly target that an administrator sets. No target value is invented
--      here: the default is 0, which the dashboard renders as "not configured"
--      rather than as a 0% achievement.
--   3. A reporting month on submissions. The existing monthly summary derived
--      the month from server_received_at, which is wrong for a report submitted
--      late or for work done in a prior month. reporting_month is nullable so
--      historical rows keep working through the server_received_at fallback.
--
-- notifications is the agent-facing history of approval/rejection and account
-- status changes. It is deliberately append-only from the application's point
-- of view: read_at is the only mutable column.
-- ---------------------------------------------------------------------------

ALTER TABLE agents
    ADD COLUMN email VARCHAR(191) NULL AFTER phone,
    ADD COLUMN location VARCHAR(191) NULL AFTER email,
    ADD COLUMN monthly_target INT UNSIGNED NOT NULL DEFAULT 0 AFTER location;

CREATE UNIQUE INDEX uniq_agents_email ON agents (email);

ALTER TABLE submissions
    ADD COLUMN reporting_month DATE NULL AFTER client_captured_at,
    ADD KEY idx_sub_agent_reporting_month (agent_id, reporting_month, status);

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(64) NOT NULL,
    title VARCHAR(191) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    entity_type VARCHAR(64) NULL,
    entity_id BIGINT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_agent_created (agent_id, created_at),
    KEY idx_notifications_agent_unread (agent_id, read_at),
    CONSTRAINT fk_notifications_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
