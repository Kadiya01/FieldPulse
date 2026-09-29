-- ---------------------------------------------------------------------------
-- 012 — login_attempts                   [ADDITIVE: abuse control]
--
-- An IMEI is a 15-digit secret-equivalent (§1: the browser input is never proof
-- of anything), so the login and device-registration endpoints need their own
-- brute-force control. cPanel hosts do not ship Redis, so the counter lives in
-- MySQL.
--
-- identifier_hash = SHA-256(imei) and ip_hash = SHA-256(ip + daily-salt) so
-- the table holds no recoverable serial numbers or addresses; the throttle key
-- is still computable.
--
-- Successes are retained rather than deleted so that a sustained low-and-slow
-- campaign is visible, and pruned by bin/prune.php.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    endpoint VARCHAR(40) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    failure_code VARCHAR(64) NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_attempts_identifier (identifier_hash, endpoint, attempted_at),
    KEY idx_attempts_ip (ip_hash, endpoint, attempted_at),
    KEY idx_attempts_prune (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
