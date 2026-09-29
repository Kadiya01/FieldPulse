-- ---------------------------------------------------------------------------
-- 004 — submissions
--
-- Column set is verbatim from spec §4. The ledger is the single source of truth
-- for aggregate counts, so the two trust-relevant timestamps are stored
-- separately and never overwritten:
--   client_captured_at  untrusted, client asserted
--   server_exif_captured_at  untrusted, read out of the file by the server
--   server_received_at  trusted, produced by this server
--
-- phash_band_1..4 are the 16-bit slices of the 64-bit DCT pHash (4 x 16 = 64),
-- indexed individually so candidate retrieval can use an index-only OR scan
-- instead of a full 64-bit comparison across every historical submission.
--
-- Additive index notes (beyond the §5 list):
--   idx_sub_agent_sha          duplicate lookups are always agent-scoped
--   idx_sub_agent_status_time  weekly-cap check and summary recompute
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    submission_uuid CHAR(36) NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED NOT NULL,

    count_claimed INT UNSIGNED NOT NULL,

    client_latitude DECIMAL(10,7) NULL,
    client_longitude DECIMAL(10,7) NULL,
    client_captured_at DATETIME NULL,

    file_path VARCHAR(255) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    file_mime VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    image_width INT UNSIGNED NULL,
    image_height INT UNSIGNED NULL,

    server_received_at DATETIME NOT NULL,

    server_exif_latitude DECIMAL(10,7) NULL,
    server_exif_longitude DECIMAL(10,7) NULL,
    server_exif_captured_at DATETIME NULL,

    phash_hex CHAR(16) NULL,
    phash_band_1 SMALLINT UNSIGNED NULL,
    phash_band_2 SMALLINT UNSIGNED NULL,
    phash_band_3 SMALLINT UNSIGNED NULL,
    phash_band_4 SMALLINT UNSIGNED NULL,

    status ENUM('RECEIVED','QUEUED','PROCESSING','VERIFIED','REQUIRES_REVIEW','REJECTED') NOT NULL DEFAULT 'QUEUED',

    verified_at DATETIME NULL,
    verification_version VARCHAR(50) NULL,
    aggregation_applied_at DATETIME NULL,

    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,

    PRIMARY KEY (id),

    UNIQUE KEY uniq_submissions_submission_uuid (submission_uuid),
    KEY idx_sub_agent_created (agent_id, created_at),
    KEY idx_sub_device_created (device_id, created_at),
    KEY idx_sub_status_created (status, created_at),
    KEY idx_sub_status_received (status, server_received_at),
    KEY idx_sub_file_sha256 (file_sha256),
    KEY idx_sub_phash_band_1 (phash_band_1),
    KEY idx_sub_phash_band_2 (phash_band_2),
    KEY idx_sub_phash_band_3 (phash_band_3),
    KEY idx_sub_phash_band_4 (phash_band_4),
    KEY idx_sub_agent_sha (agent_id, file_sha256),
    KEY idx_sub_agent_status_time (agent_id, status, server_received_at),
    KEY idx_sub_agent_uuid (agent_id, submission_uuid),

    CONSTRAINT fk_submissions_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_submissions_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
