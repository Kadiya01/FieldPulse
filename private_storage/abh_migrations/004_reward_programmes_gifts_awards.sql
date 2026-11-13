-- ABH Monthly Enrollment System
-- Migration 004: reward programmes, eligibility rules, qualifications,
-- management gift catalogue, assigned awards and fulfilment events.
-- The system records eligibility only. Management selects winners and gifts.

CREATE TABLE abh_reward_programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    program_code VARCHAR(48) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description VARCHAR(2000) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reward_programs_period_code (reporting_period_id, program_code),
    UNIQUE KEY uq_abh_reward_programs_id_period (id, reporting_period_id),
    KEY idx_abh_reward_programs_status (reporting_period_id, status),
    KEY idx_abh_reward_programs_created_by (created_by_admin_id),
    CONSTRAINT fk_abh_reward_programs_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_programs_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reward_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id BIGINT UNSIGNED NOT NULL,
    rule_key VARCHAR(64) NOT NULL,
    rule_version SMALLINT UNSIGNED NOT NULL,
    rank_from INT UNSIGNED NULL,
    rank_to INT UNSIGNED NULL,
    minimum_approved_quantity BIGINT UNSIGNED NULL,
    eligibility_conditions_json JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reward_rules_program_key_version (program_id, rule_key, rule_version),
    UNIQUE KEY uq_abh_reward_rules_program_id (program_id, id),
    KEY idx_abh_reward_rules_program_active (program_id, is_active),
    KEY idx_abh_reward_rules_created_by (created_by_admin_id),
    CONSTRAINT fk_abh_reward_rules_program
        FOREIGN KEY (program_id) REFERENCES abh_reward_programs (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_rules_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reward_qualifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id BIGINT UNSIGNED NOT NULL,
    rule_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    frozen_rank INT UNSIGNED NOT NULL,
    frozen_approved_quantity BIGINT UNSIGNED NOT NULL,
    qualification_status VARCHAR(24) NOT NULL DEFAULT 'ELIGIBLE',
    eligibility_snapshot_json JSON NOT NULL,
    evaluated_at DATETIME(6) NOT NULL,
    reviewed_by_admin_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reward_qualifications_program_agent (program_id, agent_id),
    UNIQUE KEY uq_abh_reward_qualifications_award_link (id, program_id, agent_id, reporting_period_id),
    KEY idx_abh_reward_qualifications_period_status (reporting_period_id, qualification_status),
    KEY idx_abh_reward_qualifications_rule (program_id, rule_id),
    KEY idx_abh_reward_qualifications_agent (agent_id),
    KEY idx_abh_reward_qualifications_reviewed_by (reviewed_by_admin_id),
    CONSTRAINT fk_abh_reward_qualifications_program_period
        FOREIGN KEY (program_id, reporting_period_id)
        REFERENCES abh_reward_programs (id, reporting_period_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_qualifications_program_rule
        FOREIGN KEY (program_id, rule_id)
        REFERENCES abh_reward_rules (program_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_qualifications_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_qualifications_reviewed_by
        FOREIGN KEY (reviewed_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reward_gifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id BIGINT UNSIGNED NOT NULL,
    gift_code VARCHAR(48) NOT NULL,
    gift_title VARCHAR(160) NOT NULL,
    gift_description VARCHAR(2000) NULL,
    available_quantity BIGINT UNSIGNED NULL,
    estimated_value DECIMAL(15,2) NULL,
    currency_code CHAR(3) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reward_gifts_program_code (program_id, gift_code),
    UNIQUE KEY uq_abh_reward_gifts_id_program (id, program_id),
    KEY idx_abh_reward_gifts_program_status (program_id, status),
    KEY idx_abh_reward_gifts_created_by (created_by_admin_id),
    KEY idx_abh_reward_gifts_updated_by (updated_by_admin_id),
    CONSTRAINT fk_abh_reward_gifts_program
        FOREIGN KEY (program_id) REFERENCES abh_reward_programs (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_gifts_created_by
        FOREIGN KEY (created_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_gifts_updated_by
        FOREIGN KEY (updated_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reward_awards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qualification_id BIGINT UNSIGNED NOT NULL,
    program_id BIGINT UNSIGNED NOT NULL,
    gift_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    gift_title_snapshot VARCHAR(160) NOT NULL,
    gift_description_snapshot VARCHAR(2000) NULL,
    gift_quantity BIGINT UNSIGNED NOT NULL,
    estimated_value_snapshot DECIMAL(15,2) NULL,
    currency_code_snapshot CHAR(3) NULL,
    assigned_by_admin_id BIGINT UNSIGNED NOT NULL,
    approved_by_admin_id BIGINT UNSIGNED NULL,
    award_status VARCHAR(24) NOT NULL DEFAULT 'PENDING_APPROVAL',
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    approved_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reward_awards_qualification_gift (qualification_id, gift_id),
    KEY idx_abh_reward_awards_agent_period (agent_id, reporting_period_id),
    KEY idx_abh_reward_awards_status (award_status),
    KEY idx_abh_reward_awards_assigned_by (assigned_by_admin_id),
    KEY idx_abh_reward_awards_approved_by (approved_by_admin_id),
    CONSTRAINT fk_abh_reward_awards_qualification
        FOREIGN KEY (qualification_id, program_id, agent_id, reporting_period_id)
        REFERENCES abh_reward_qualifications (id, program_id, agent_id, reporting_period_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_awards_gift_program
        FOREIGN KEY (gift_id, program_id)
        REFERENCES abh_reward_gifts (id, program_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_awards_assigned_by
        FOREIGN KEY (assigned_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_awards_approved_by
        FOREIGN KEY (approved_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reward_fulfilment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    award_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    quantity BIGINT UNSIGNED NULL,
    amount DECIMAL(15,2) NULL,
    currency_code CHAR(3) NULL,
    reference_no VARCHAR(100) NULL,
    note VARCHAR(2000) NULL,
    recorded_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_reward_fulfilment_award_created (award_id, created_at),
    KEY idx_abh_reward_fulfilment_reference (reference_no),
    KEY idx_abh_reward_fulfilment_admin (recorded_by_admin_id),
    CONSTRAINT fk_abh_reward_fulfilment_award
        FOREIGN KEY (award_id) REFERENCES abh_reward_awards (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_reward_fulfilment_admin
        FOREIGN KEY (recorded_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
