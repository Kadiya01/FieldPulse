-- ---------------------------------------------------------------------------
-- 026 - restore_monthly_periods       [ADDITIVE: period discriminator support]
--
-- Why this migration exists
-- -------------------------
-- FieldPulse was originally scoped to weekly performance summaries, frozen
-- weekly rankings, and weekly reward entitlements. The monthly enrollment
-- period restores the planned period discriminator:
--
--   period_type ENUM('WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'WEEKLY'
--
-- applied consistently across:
--   1. agent_performance_summary
--   2. reward_rankings
--   3. agent_rewards
--
-- Preserving historical records:
-- -----------------------------
-- Adding `period_type` with DEFAULT 'WEEKLY' and explicitly backfilling ensures
-- all historical weekly records remain intact as WEEKLY.
--
-- Collision prevention:
-- ---------------------
-- Primary keys and unique constraints are updated to include `period_type`.
-- Because a month starts on the 1st (e.g. 2026-10-01) and a week can also start
-- on the 1st of a month, (agent_id, period_start_date) would collide without
-- `period_type`. Including `period_type` in the compound keys ensures weekly
-- and monthly records are completely independent.
--
-- Foreign key preservation:
-- -------------------------
-- In InnoDB, agent_rewards carries fk_reward_ranking referencing
-- reward_rankings (period_start_date, agent_id). To update the unique constraint
-- on the parent table (reward_rankings), we first drop fk_reward_ranking from
-- agent_rewards, update the unique key on reward_rankings to include period_type,
-- and re-add fk_reward_ranking referencing (period_start_date, agent_id, period_type).
-- ---------------------------------------------------------------------------

-- Step 1: Drop foreign key on agent_rewards referencing reward_rankings
ALTER TABLE agent_rewards DROP FOREIGN KEY fk_reward_ranking;

-- Step 2: Update agent_performance_summary
ALTER TABLE agent_performance_summary
    ADD COLUMN period_type ENUM('WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'WEEKLY' AFTER period_start_date;

UPDATE agent_performance_summary
    SET period_type = 'WEEKLY'
    WHERE period_type IS NULL OR period_type = '';

ALTER TABLE agent_performance_summary
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (agent_id, period_start_date, period_type);

ALTER TABLE agent_performance_summary
    DROP INDEX idx_perf_lead,
    ADD KEY idx_perf_lead (period_start_date, period_type, total_verified_count DESC);

ALTER TABLE agent_performance_summary
    DROP INDEX idx_perf_agent_period,
    ADD KEY idx_perf_agent_period (agent_id, period_start_date, period_type);

-- Step 3: Update reward_rankings
ALTER TABLE reward_rankings
    ADD COLUMN period_type ENUM('WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'WEEKLY' AFTER period_start_date;

UPDATE reward_rankings
    SET period_type = 'WEEKLY'
    WHERE period_type IS NULL OR period_type = '';

ALTER TABLE reward_rankings
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (period_start_date, `rank`, period_type);

ALTER TABLE reward_rankings
    DROP INDEX uniq_ranking_agent,
    ADD UNIQUE KEY uniq_ranking_agent (period_start_date, agent_id, period_type);

ALTER TABLE reward_rankings
    DROP INDEX idx_ranking_agent,
    ADD KEY idx_ranking_agent (agent_id, period_start_date, period_type);

-- Step 4: Update agent_rewards
ALTER TABLE agent_rewards
    ADD COLUMN period_type ENUM('WEEKLY', 'MONTHLY') NOT NULL DEFAULT 'WEEKLY' AFTER period_start_date;

UPDATE agent_rewards
    SET period_type = 'WEEKLY'
    WHERE period_type IS NULL OR period_type = '';

ALTER TABLE agent_rewards
    DROP INDEX uniq_reward_agent_period,
    ADD UNIQUE KEY uniq_reward_agent_period (agent_id, period_start_date, period_type);

ALTER TABLE agent_rewards
    DROP INDEX idx_rewards_period_status,
    ADD KEY idx_rewards_period_status (period_start_date, period_type, status);

ALTER TABLE agent_rewards
    DROP INDEX idx_rewards_period_rank,
    ADD KEY idx_rewards_period_rank (period_start_date, period_type, `rank`);

ALTER TABLE agent_rewards
    DROP INDEX idx_rewards_period_agent,
    ADD KEY idx_rewards_period_agent (period_start_date, agent_id, period_type);

-- Step 5: Restore foreign key on agent_rewards with composite period_type reference
ALTER TABLE agent_rewards
    ADD CONSTRAINT fk_reward_ranking FOREIGN KEY (period_start_date, agent_id, period_type)
        REFERENCES reward_rankings (period_start_date, agent_id, period_type) ON DELETE CASCADE;
