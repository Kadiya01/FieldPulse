-- ---------------------------------------------------------------------------
-- 022 - agent_rewards                     [ADDITIVE: published entitlements]
--
-- Why this table exists
-- ---------------------
-- When a period closes, the frozen ranking (021) is mapped onto the tier
-- schedule (020) and each qualifying agent gets one row here. That row is the
-- entitlement: the organisation's own record that, for the week that ended on
-- period_start_date, this agent stood at this rank and is owed this.
--
-- PUBLICATION IS NOT PAYMENT
-- --------------------------
-- status walks PENDING -> APPROVED -> PAID, and each step is a different act
-- by a different party:
--
--   PENDING    written automatically at close and published for the agent to
--              see. It means "here is what you are owed", nothing more.
--   APPROVED   an operator has confirmed the figure. Still no money moved.
--   PAID       the organisation has confirmed it settled up.
--   VOID       an operator has cancelled the entitlement, with a reason.
--
-- Nothing in FieldPulse can move money, so PAID can only ever be written by an
-- operator recording something the organisation did outside this system. The
-- agent-facing UI must not read PENDING or APPROVED as "paid".
--
-- WHO APPROVED: operator, not recipient
-- -------------------------------------
-- approved_by_operator_id / paid_by_operator_id / voided_by_operator_id all
-- reference agents(id) — the deployment has exactly one identity concept, an
-- agent row carrying a SUPERVISOR or ADMIN role (see 014). They are NOT named
-- *_by_agent_id because these actions are taken by operators, never by the
-- reward recipient; a column called approved_by_agent_id next to agent_id would
-- read as though the payee had approved their own payment.
--
-- THE FREEZE IS BOUND BY A FOREIGN KEY
-- -------------------------------------
-- FOREIGN KEY (period_start_date, agent_id) REFERENCES reward_rankings means a
-- reward cannot exist for an agent and week that was never frozen. This is the
-- structural half of the cutoff rule: entitlements are always and only a
-- function of the frozen ranking, so later changes to live performance
-- summaries have nowhere to flow to.
--
-- Idempotency
-- -----------
-- UNIQUE (agent_id, period_start_date) makes closePeriod() safe to run
-- concurrently and repeatedly: the second run upserts instead of duplicating,
-- and the upgrade guard in RewardService never lets an APPROVED or PAID row
-- fall back to PENDING.
--
-- reward_amount / currency are NULL whenever the tier band (020) has not been
-- filled in by the organisation. NULL means "no figure decided yet" and is
-- published as such; nothing here may substitute a default.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS agent_rewards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_start_date DATE NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    rank INT UNSIGNED NOT NULL,
    total_verified_count BIGINT UNSIGNED NOT NULL DEFAULT 0,

    tier_id BIGINT UNSIGNED NULL,
    tier_label VARCHAR(60) NULL,
    reward_amount DECIMAL(12,2) NULL,
    currency CHAR(3) NULL,

    status ENUM('PENDING','APPROVED','PAID','VOID') NOT NULL DEFAULT 'PENDING',
    published_at DATETIME NOT NULL,

    approved_at DATETIME NULL,
    approved_by_operator_id BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    paid_by_operator_id BIGINT UNSIGNED NULL,

    voided_at DATETIME NULL,
    voided_by_operator_id BIGINT UNSIGNED NULL,
    void_reason VARCHAR(500) NULL,

    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_reward_agent_period (agent_id, period_start_date),
    KEY idx_rewards_period_status (period_start_date, status),
    KEY idx_rewards_period_rank (period_start_date, rank),
    -- Exactly the shape of fk_reward_ranking below: InnoDB wants an index whose
    -- leading columns follow the FK's column order, and uniq_reward_agent_period
    -- is the other way round.
    KEY idx_rewards_period_agent (period_start_date, agent_id),

    CONSTRAINT fk_reward_tier FOREIGN KEY (tier_id) REFERENCES reward_tiers (id) ON DELETE SET NULL,
    CONSTRAINT fk_reward_ranking FOREIGN KEY (period_start_date, agent_id)
        REFERENCES reward_rankings (period_start_date, agent_id) ON DELETE CASCADE,
    CONSTRAINT fk_reward_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
    CONSTRAINT fk_reward_approver FOREIGN KEY (approved_by_operator_id) REFERENCES agents (id) ON DELETE SET NULL,
    CONSTRAINT fk_reward_payer FOREIGN KEY (paid_by_operator_id) REFERENCES agents (id) ON DELETE SET NULL,
    CONSTRAINT fk_reward_voider FOREIGN KEY (voided_by_operator_id) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
