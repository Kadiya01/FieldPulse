-- ---------------------------------------------------------------------------
-- 011 — agent_sites                       [ADDITIVE: required by spec §12]
--
-- §12 verifies position "against assigned center coordinates", but the §4
-- agents table carries no location. This holds the assignment(s) so a geofence
-- can be evaluated against an authoritative centre rather than against anything
-- the client supplied.
--
-- Kept separate from agents (rather than as three columns on it) because an
-- agent can be assigned to more than one site per period, and because a site
-- reassignment is then an insert/retire instead of a destructive UPDATE on the
-- agent row.
--
-- center_latitude/center_longitude are DECIMAL, never FLOAT: coordinate
-- comparison and bounding-box filters must be exact, and DECIMAL(10,7) gives
-- ~1 cm resolution, comfortably below GPS accuracy.
--
-- A submission with no active site assignment yields NO_GPS-equivalent
-- handling (see Verification\Geofence) rather than being auto-verified.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS agent_sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    center_latitude DECIMAL(10,7) NOT NULL,
    center_longitude DECIMAL(10,7) NOT NULL,
    radius_m INT UNSIGNED NOT NULL DEFAULT 250,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sites_agent_active (agent_id, is_active),
    KEY idx_sites_bounds (center_latitude, center_longitude),
    CONSTRAINT fk_sites_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
