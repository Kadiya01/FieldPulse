-- ---------------------------------------------------------------------------
-- 015 — review provenance and verification version
--
-- Additive migration.
--
-- 005 recorded what the automated pipeline decided but not who overturned it,
-- which makes §13 unreconcilable: a supervisor changing a VERIFIED count is the
-- highest-value action in the system and must leave a named, timestamped trace.
--
--   reviewed_by_agent_id  the supervisor, FK to agents so a deleted account
--                         cannot leave an orphaned attribution
--   reviewed_at           when the human decision was made
--   review_note           why, free text; the reason code is a machine value,
--                         this is the human explanation
--   verification_version  which version of the decision matrix produced the
--                         automated disposition, so a verdict can be explained
--                         after the rules have been changed
--
-- Distinguishing "auto-flagged" from "human-reviewed" needs no new status: a row
-- with final_disposition = 'REQUIRES_REVIEW' and reviewed_at IS NULL is pending,
-- and a non-NULL reviewed_at means a supervisor has ruled on it.
-- ---------------------------------------------------------------------------

ALTER TABLE submission_verifications
    ADD COLUMN verification_version VARCHAR(20) NULL AFTER submission_id,
    ADD COLUMN reviewed_by_agent_id BIGINT UNSIGNED NULL AFTER final_disposition,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_agent_id,
    ADD COLUMN review_note VARCHAR(1000) NULL AFTER reviewed_at;

-- The review queue filters on final_disposition and orders by age; this index
-- serves the "reviewed recently" audit query without touching the table.
CREATE INDEX idx_verifications_reviewed ON submission_verifications (reviewed_at);

-- ON DELETE SET NULL: deactivating a supervisor must not delete the verdicts
-- they signed, which are financial records.
ALTER TABLE submission_verifications
    ADD CONSTRAINT fk_verifications_reviewer
        FOREIGN KEY (reviewed_by_agent_id) REFERENCES agents (id) ON DELETE SET NULL;
