-- ---------------------------------------------------------------------------
-- 020 - reward_tiers                      [ADDITIVE: rank-tier reward schedule]
--
-- Why this table exists
-- ---------------------
-- FieldPulse ranks an agent's frozen weekly standing; it does not decide what
-- that standing is worth. That decision belongs to the organisation, which
-- means the schedule has to be data and not code: an operator edits a row, and
-- no migration is needed to change what a rank earns.
--
-- The table is deliberately policy-NEUTRAL on shape as well as amount:
--
--   reward_amount  NULL   until the organisation decides what a band pays
--   currency       NULL   until it decides in what
--
-- NULL is meaningful here, not a placeholder for 0. A band with NULL amount is
-- "ranks here are recognised; what they are recognised with has not been set".
-- RewardService copies whatever is on the band onto the entitlement at close,
-- and an entitlement with NULL amount publishes as a tier with no figure
-- attached rather than inventing one. Nothing in the codebase may default it.
--
-- Rank bands, not per-submission payouts: reward_rankings (021) freezes a
-- ranked order at period close and this table maps a rank interval onto it.
-- That is what makes the reward a function of the frozen standing alone.
--
-- Provisional seed
-- ----------------
-- The four bands below are PLACEHOLDERS and say so. They exist so the engine
-- has something to match a rank against on a fresh install; every one of them
-- carries NULL amount and NULL currency, so a fresh install grants no money
-- until an operator fills them in. Replace them freely: bands are ordinary
-- rows, and uniq_tier_band is the only constraint (no two bands may share a
-- [min_rank, max_rank] interval).
--
-- Ranks outside every active band receive no entitlement at all. That is
-- deliberate: absence of a band is "not rewarded", not "rewarded with nothing".
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reward_tiers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    min_rank INT UNSIGNED NOT NULL,
    max_rank INT UNSIGNED NOT NULL,
    tier_label VARCHAR(60) NOT NULL,
    reward_amount DECIMAL(12,2) NULL,
    currency CHAR(3) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tier_band (min_rank, max_rank),
    KEY idx_tier_active_rank (is_active, min_rank)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE leans on uniq_tier_band rather than a subquery over the table
-- being written: a re-run of this file inserts nothing, and the constraint that
-- stops it is the same one that keeps two bands from overlapping.
INSERT IGNORE INTO reward_tiers (min_rank, max_rank, tier_label, reward_amount, currency, is_active, sort_order, created_at, updated_at)
    SELECT 1, 1, 'Provisional band 1', NULL, NULL, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
UNION ALL
    SELECT 2, 3, 'Provisional band 2', NULL, NULL, 1, 2, UTC_TIMESTAMP(), UTC_TIMESTAMP()
UNION ALL
    SELECT 4, 10, 'Provisional band 3', NULL, NULL, 1, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP()
UNION ALL
    SELECT 11, 25, 'Provisional band 4', NULL, NULL, 1, 4, UTC_TIMESTAMP(), UTC_TIMESTAMP();
