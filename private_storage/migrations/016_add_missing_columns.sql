-- ---------------------------------------------------------------------------
-- 016 — columns the code already wrote but the schema never declared
--
-- Additive migration. Every column below is referenced by shipped PHP but was
-- absent from migrations 001-015, so each one raises ER_BAD_FIELD_ERROR (1054)
-- on a fresh install. The self-test could not catch any of them: it is pure
-- logic and never opens a database connection.
--
--   devices.revoked_at        VerificationService::deviceStatus() selects
--                             `status, revoked_at` on the verification hot path.
--                             Without it every job fails, retries to max_attempts
--                             and is parked in REQUIRES_REVIEW. No submission
--                             could ever be verified.
--
--   submissions.client_notes  SubmitController writes the agent's optional note
--                             inside the submit transaction, so the failure
--                             rolled back the ledger row and deleted the
--                             quarantined file. Documented in docs/API.md.
--                             Conditional on the field being sent, which makes
--                             it a partial outage rather than a clean one.
--
-- Also widened submission_verifications.verification_version from VARCHAR(20) to
-- match submissions.verification_version (VARCHAR(50), migration 004). The same
-- value is bound to both columns, so an operator setting VERIFICATION_VERSION to
-- more than 20 characters would have produced a write that succeeded on
-- submissions and failed on submission_verifications under strict mode.
-- ---------------------------------------------------------------------------

ALTER TABLE devices
    ADD COLUMN revoked_at DATETIME NULL AFTER status;

ALTER TABLE submissions
    ADD COLUMN client_notes VARCHAR(500) NULL AFTER count_claimed;

-- Index the revocation lookup: deviceStatus() reads status and revoked_at by
-- primary key, so no index is needed for that path. This one exists for the
-- audit question "which devices were revoked in this period", answered by
-- bin/prune-time reporting and by any future compliance export.
CREATE INDEX idx_devices_revoked ON devices (revoked_at);

ALTER TABLE submission_verifications
    MODIFY COLUMN verification_version VARCHAR(50) NULL;
