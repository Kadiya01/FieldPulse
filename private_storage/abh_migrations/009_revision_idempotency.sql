-- A corrected report is a new immutable revision on the same submission.
-- A per-submission idempotency key makes retries safe without allowing an agent
-- to overwrite a previously submitted revision.
ALTER TABLE abh_submission_revisions
    ADD COLUMN idempotency_key CHAR(36) NULL AFTER revision_no,
    ADD UNIQUE KEY uq_abh_submission_revisions_submission_idempotency (submission_id, idempotency_key);
