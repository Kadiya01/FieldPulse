-- ABH Monthly Enrollment System
-- Migration 003: targets, monthly approved totals and close snapshots.
-- This migration only creates new abh_* tables. It does not alter legacy tables.

CREATE TABLE abh_monthly_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    scope_type VARCHAR(16) NOT NULL,
    scope_owner_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NULL,
    target_quantity BIGINT UNSIGNED NOT NULL,
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_monthly_targets_period_scope_owner (reporting_period_id, scope_owner_id),
    KEY idx_abh_monthly_targets_agent (agent_id),
    KEY idx_abh_monthly_targets_created_by (created_by_admin_id),
    KEY idx_abh_monthly_targets_updated_by (updated_by_admin_id),
    CONSTRAINT fk_abh_monthly_targets_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_targets_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_targets_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_targets_updated_by
        FOREIGN KEY (updated_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scope owner 0 is reserved for the organisation. For an AGENT target,
-- scope_owner_id must equal agent_id. The application validates that pairing;
-- the unique key prevents duplicate organisation or agent targets per month.
CREATE TABLE abh_monthly_agent_totals (
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    approved_quantity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    approved_submission_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (reporting_period_id, agent_id),
    CONSTRAINT fk_abh_monthly_agent_totals_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_agent_totals_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_monthly_leaderboard_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    rank_position INT UNSIGNED NOT NULL,
    approved_quantity BIGINT UNSIGNED NOT NULL,
    reached_final_total_at DATETIME(6) NULL,
    agent_code_snapshot VARCHAR(32) NOT NULL,
    display_name_snapshot VARCHAR(160) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_monthly_leaderboard_period_agent (reporting_period_id, agent_id),
    KEY idx_abh_monthly_leaderboard_period_rank (reporting_period_id, rank_position),
    CONSTRAINT fk_abh_monthly_leaderboard_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_leaderboard_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_monthly_organisation_snapshots (
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    approved_quantity BIGINT UNSIGNED NOT NULL,
    target_quantity BIGINT UNSIGNED NULL,
    progress_percent DECIMAL(9,2) NULL,
    agent_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    closed_at DATETIME(6) NOT NULL,
    closed_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (reporting_period_id),
    KEY idx_abh_monthly_organisation_snapshots_closed_by (closed_by_admin_id),
    CONSTRAINT fk_abh_monthly_organisation_snapshots_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_monthly_organisation_snapshots_closed_by
        FOREIGN KEY (closed_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
