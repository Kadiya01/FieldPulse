-- ---------------------------------------------------------------------------
-- 021 - reward_rankings                   [ADDITIVE: the frozen weekly standing]
--
-- Why this table exists
-- ---------------------
-- agent_performance_summary (007) is a live recompute. It is rewritten whenever
-- a submission is dispositioned, and ReviewRepository and bin/reaggregate.php
-- will both rewrite HISTORICAL periods without asking. That is correct for a
-- leaderboard, which is supposed to move, and fatal for a reward, which is not:
-- an entitlement computed by reading the summary on demand can change after the
-- agent has been told what they are owed.
--
-- So closing a period copies the standing out of the summary and into this
-- table exactly once. After that:
--
--   reward_rankings   is written only at close, and never updated afterwards
--   agent_rewards     (022) is written only FROM a row in this table
--
-- The second half is enforced by a foreign key, not by convention: 022 carries
-- FK (period_start_date, agent_id) -> reward_rankings. A reward row cannot
-- exist without the frozen ranking that justifies it, so no future code path
-- can quietly re-derive a reward from live summary numbers and call it a day.
--
-- Why rank is the primary key
-- ---------------------------
-- PRIMARY KEY (period_start_date, rank) makes the rank itself unique within a
-- period, so "two agents frozen at rank 1" is a constraint violation rather
-- than a data question someone has to answer later. It also gives the close
-- query an index-ordered scan when it walks the frozen order back out.
--
-- Ranking order is the one defined in LeaderboardRepository::ORDER_BY:
-- total_verified_count DESC, total_pending ASC, agent_code ASC. That single
-- constant feeds both the board and the self-rank count, so the rank frozen
-- here is the same number the agent was shown.
--
-- agent_status_at_close is a snapshot, not a live reference: an agent made
-- inactive the day after closing still earned the week they worked, and the
-- record has to say what their status was at the moment of closing rather than
-- being silently rewritten when it later changes.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reward_rankings (
    period_start_date DATE NOT NULL,
    rank INT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    total_verified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_pending BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_rejected BIGINT UNSIGNED NOT NULL DEFAULT 0,
    agent_status_at_close ENUM('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
    snapshot_at DATETIME NOT NULL,
    PRIMARY KEY (period_start_date, rank),
    UNIQUE KEY uniq_ranking_agent (period_start_date, agent_id),
    KEY idx_ranking_agent (agent_id, period_start_date),
    CONSTRAINT fk_ranking_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
