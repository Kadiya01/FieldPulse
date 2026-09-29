-- ---------------------------------------------------------------------------
-- 007 — agent_performance_summary
--
-- Per spec §4/§5. period_start_date is the Monday 00:00:00 of the week in the
-- configured business timezone (default Africa/Lagos), NOT a week_number/year
-- pair: a week_number has no year of its own and collides across year
-- boundaries. See Domain\PeriodResolver.
--
-- Every counter is a full recompute from `submissions` rather than an
-- increment, so re-processing a job or replaying a day of cron can never
-- double-count. Counters that are not part of the contract's totals are stored
-- as BIGINT UNSIGNED and driven to 0 rather than NULL.
--
-- DESC on the leading count is honoured by MySQL 8 as a real descending index
-- (allowing an index-ordered LIMIT for leaderboard reads) and accepted-and-
-- ignored by MariaDB, where the optimiser still satisfies the same ORDER BY.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS agent_performance_summary (
    agent_id BIGINT UNSIGNED NOT NULL,
    period_start_date DATE NOT NULL,
    total_verified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_submissions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_rejected BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_pending BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (agent_id, period_start_date),
    KEY idx_perf_lead (period_start_date, total_verified_count DESC),
    KEY idx_perf_agent_period (agent_id, period_start_date),
    CONSTRAINT fk_summary_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
