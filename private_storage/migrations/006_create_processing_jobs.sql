-- ---------------------------------------------------------------------------
-- 006 — processing_jobs
--
-- Per spec §4/§5. The database IS the queue: no Redis, no Supervisor, no
-- cloud queue, which is what makes this deployable on a basic cPanel plan.
--
-- Locking contract (see workers/process_queue.php):
--   PENDING    -> eligible when available_at <= NOW()
--   PROCESSING -> owned by locked_by until locked_at + lock_timeout
--   A PROCESSING row older than the timeout is returned to PENDING by stale-lock
--   recovery, so a killed cron run can never strand a submission.
--
-- UNIQUE (submission_id, job_type) makes enqueueing idempotent: a retried
-- submit cannot produce a second job.
--
-- Additive column: max_attempts, so a job's retry budget is recorded with the
-- job instead of being a global that a deploy can change underneath it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS processing_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    job_type VARCHAR(50) NOT NULL DEFAULT 'VERIFICATION',
    status ENUM('PENDING','PROCESSING','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    locked_by VARCHAR(100) NULL,
    last_error_code VARCHAR(100) NULL,
    last_error_message TEXT NULL,
    completed_at DATETIME NULL,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY idx_sub_job (submission_id, job_type),
    KEY idx_proc_status (status, available_at, locked_at),
    KEY idx_proc_claim (status, available_at, id),
    KEY idx_proc_locked_by (locked_by),
    CONSTRAINT fk_jobs_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
