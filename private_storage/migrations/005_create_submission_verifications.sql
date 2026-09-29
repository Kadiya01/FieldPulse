-- ---------------------------------------------------------------------------
-- 005 — submission_verifications
--
-- Per spec §4. One row per submission (UNIQUE submission_id) holding the
-- evidence behind the disposition. This table is the audit answer to "why was
-- this verified?", so the *_status columns are plain VARCHAR(30) rather than
-- ENUM: adding a new finding reason must never require a table rewrite on a
-- shared host with a long-running cPanel process holding metadata locks.
--
-- CASCADE on submission_id: the verdict is meaningless without the submission.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS submission_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,

    auth_status VARCHAR(30) NOT NULL,
    device_status VARCHAR(30) NOT NULL,
    client_gps_status VARCHAR(30) NOT NULL,
    exif_status VARCHAR(30) NOT NULL,
    timestamp_status VARCHAR(30) NOT NULL,
    geofence_status VARCHAR(30) NOT NULL,
    exact_duplicate_status VARCHAR(30) NOT NULL,
    perceptual_duplicate_status VARCHAR(30) NOT NULL,

    final_disposition ENUM('VERIFIED','REQUIRES_REVIEW','REJECTED') NOT NULL,
    review_reason VARCHAR(255) NULL,

    -- Extemporaneous detail, kept out of the fixed columns so the disposition
    -- reason stays a short stable string for the PWA.
    evidence_json JSON NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uniq_verifications_submission (submission_id),
    KEY idx_verifications_disposition (final_disposition, created_at),
    KEY idx_verifications_reason (review_reason),

    CONSTRAINT fk_verifications_submission FOREIGN KEY (submission_id) REFERENCES submissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
