-- ABH Monthly Enrollment System
-- Migration 002: enrollment submissions, immutable revisions, private evidence
-- metadata, review decisions and authorised quantity adjustments.
-- All event timestamps are UTC. This migration only creates new abh_* tables.

CREATE TABLE abh_enrollment_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no VARCHAR(48) NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    reporting_period_id BIGINT UNSIGNED NOT NULL,
    current_revision_id BIGINT UNSIGNED NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'PENDING_REVIEW',
    idempotency_key CHAR(36) NOT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_enrollment_submissions_reference (reference_no),
    UNIQUE KEY uq_abh_enrollment_submissions_agent_idempotency (agent_id, idempotency_key),
    UNIQUE KEY uq_abh_enrollment_submissions_id_agent (id, agent_id),
    KEY idx_abh_enrollment_submissions_agent_period_status (agent_id, reporting_period_id, status),
    KEY idx_abh_enrollment_submissions_period_status_submitted (reporting_period_id, status, submitted_at),
    CONSTRAINT fk_abh_enrollment_submissions_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_enrollment_submissions_period
        FOREIGN KEY (reporting_period_id) REFERENCES abh_reporting_periods (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_submission_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    revision_no SMALLINT UNSIGNED NOT NULL,
    claimed_quantity INT UNSIGNED NOT NULL,
    activity_date DATE NOT NULL,
    agent_note VARCHAR(1000) NULL,
    created_by_agent_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_submission_revisions_submission_revision (submission_id, revision_no),
    UNIQUE KEY uq_abh_submission_revisions_submission_id (submission_id, id),
    KEY idx_abh_submission_revisions_submission_creator (submission_id, created_by_agent_id),
    CONSTRAINT fk_abh_submission_revisions_submission_agent
        FOREIGN KEY (submission_id, created_by_agent_id)
        REFERENCES abh_enrollment_submissions (id, agent_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The composite FK proves that the selected current revision belongs to
-- the same submission row. It is nullable during initial submission creation.
ALTER TABLE abh_enrollment_submissions
    ADD CONSTRAINT fk_abh_enrollment_submissions_current_revision
    FOREIGN KEY (id, current_revision_id)
    REFERENCES abh_submission_revisions (submission_id, id)
    ON UPDATE RESTRICT ON DELETE RESTRICT;

CREATE TABLE abh_submission_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_id BIGINT UNSIGNED NOT NULL,
    storage_key VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_submission_evidence_storage_key (storage_key),
    UNIQUE KEY uq_abh_submission_evidence_revision_hash (revision_id, sha256),
    CONSTRAINT fk_abh_submission_evidence_revision
        FOREIGN KEY (revision_id) REFERENCES abh_submission_revisions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_submission_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    review_cycle_no SMALLINT UNSIGNED NOT NULL,
    reviewer_admin_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(32) NOT NULL,
    reason_code VARCHAR(64) NULL,
    review_note VARCHAR(2000) NULL,
    idempotency_key CHAR(36) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_submission_reviews_idempotency (idempotency_key),
    KEY idx_abh_submission_reviews_submission_created (submission_id, created_at),
    KEY idx_abh_submission_reviews_revision_cycle (revision_id, review_cycle_no),
    KEY idx_abh_submission_reviews_reviewer_created (reviewer_admin_id, created_at),
    CONSTRAINT fk_abh_submission_reviews_submission_revision
        FOREIGN KEY (submission_id, revision_id)
        REFERENCES abh_submission_revisions (submission_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_submission_reviews_reviewer
        FOREIGN KEY (reviewer_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_submission_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    approved_revision_id BIGINT UNSIGNED NOT NULL,
    quantity_delta INT NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by_admin_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_submission_adjustments_submission_created (submission_id, created_at),
    KEY idx_abh_submission_adjustments_revision (approved_revision_id),
    KEY idx_abh_submission_adjustments_admin (created_by_admin_id),
    CONSTRAINT fk_abh_submission_adjustments_submission_revision
        FOREIGN KEY (submission_id, approved_revision_id)
        REFERENCES abh_submission_revisions (submission_id, id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_submission_adjustments_admin
        FOREIGN KEY (created_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
