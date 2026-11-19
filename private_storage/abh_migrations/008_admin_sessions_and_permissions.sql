-- ABH Monthly Enrollment System
-- Migration 008: isolated administrator sessions and permission catalogue.
-- No public administrator self-registration is introduced by this migration.

-- A locked singleton prevents two concurrent first-admin setup requests.
CREATE TABLE abh_admin_bootstrap_guard (
    singleton_id TINYINT UNSIGNED NOT NULL,
    initialized_at DATETIME(6) NULL,
    admin_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT chk_abh_admin_bootstrap_singleton CHECK (singleton_id = 1),
    CONSTRAINT fk_abh_admin_bootstrap_admin
        FOREIGN KEY (admin_user_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO abh_admin_bootstrap_guard (singleton_id) VALUES (1);

CREATE TABLE abh_admin_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    access_token_hash CHAR(64) NOT NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    token_family CHAR(36) NOT NULL,
    access_expires_at DATETIME(6) NOT NULL,
    refresh_expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoked_reason VARCHAR(40) NULL,
    rotated_to_session_id BIGINT UNSIGNED NULL,
    last_used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_admin_sessions_access_hash (access_token_hash),
    UNIQUE KEY uq_abh_admin_sessions_refresh_hash (refresh_token_hash),
    KEY idx_abh_admin_sessions_admin_active (admin_user_id, revoked_at),
    KEY idx_abh_admin_sessions_family (token_family),
    KEY idx_abh_admin_sessions_refresh_expiry (refresh_expires_at),
    CONSTRAINT fk_abh_admin_sessions_admin
        FOREIGN KEY (admin_user_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_admin_sessions_rotated_to
        FOREIGN KEY (rotated_to_session_id) REFERENCES abh_admin_sessions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_admin_auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_admin_auth_identifier_time (identifier_hash, attempted_at),
    KEY idx_abh_admin_auth_ip_time (ip_hash, attempted_at),
    KEY idx_abh_admin_auth_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO abh_admin_roles (role_key, role_name, description, is_system_role)
VALUES
    ('SUPER_ADMIN', 'Super Administrator', 'Full access to ABH administration.', 1),
    ('AGENT_REVIEWER', 'Agent Reviewer', 'Reviews agent applications and registered devices.', 1),
    ('REWARD_MANAGER', 'Reward Manager', 'Manages reward programmes and award decisions.', 1),
    ('OPERATIONS_MANAGER', 'Operations Manager', 'Manages operational targets and audit review.', 1)
ON DUPLICATE KEY UPDATE
    role_name = VALUES(role_name),
    description = VALUES(description),
    is_system_role = VALUES(is_system_role);

INSERT INTO abh_admin_permissions (permission_key, description)
VALUES
    ('agents.read', 'View agent applications and agent records.'),
    ('agents.approve', 'Approve pending agent applications.'),
    ('agents.reject', 'Reject pending agent applications.'),
    ('agents.suspend', 'Suspend or reactivate agent accounts.'),
    ('devices.read', 'View registered agent devices.'),
    ('devices.approve', 'Authorize pending agent devices.'),
    ('devices.revoke', 'Revoke authorized agent devices.'),
    ('submissions.read', 'View monthly agent submissions.'),
    ('submissions.review', 'Approve, reject or request correction of submissions.'),
    ('targets.manage', 'Create and change reporting targets.'),
    ('rewards.manage', 'Create and change reward programmes and gifts.'),
    ('rewards.approve', 'Approve reward awards and fulfilment.'),
    ('admin.users.manage', 'Create and manage administrator accounts and roles.'),
    ('audit.read', 'View administrator and agent audit events.')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

-- The super-administrator role receives the complete permission catalogue.
INSERT IGNORE INTO abh_admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM abh_admin_roles r
 CROSS JOIN abh_admin_permissions p
 WHERE r.role_key = 'SUPER_ADMIN';

-- Operational roles are deliberately narrower than SUPER_ADMIN.
INSERT IGNORE INTO abh_admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM abh_admin_roles r
  JOIN abh_admin_permissions p
    ON p.permission_key IN (
        'agents.read', 'agents.approve', 'agents.reject', 'agents.suspend',
        'devices.read', 'devices.approve', 'devices.revoke',
        'submissions.read', 'submissions.review'
    )
 WHERE r.role_key = 'AGENT_REVIEWER';

INSERT IGNORE INTO abh_admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM abh_admin_roles r
  JOIN abh_admin_permissions p
    ON p.permission_key IN ('rewards.manage', 'rewards.approve')
 WHERE r.role_key = 'REWARD_MANAGER';

INSERT IGNORE INTO abh_admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM abh_admin_roles r
  JOIN abh_admin_permissions p
    ON p.permission_key IN ('agents.read', 'submissions.read', 'targets.manage', 'audit.read')
 WHERE r.role_key = 'OPERATIONS_MANAGER';
