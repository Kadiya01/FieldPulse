-- ABH Monthly Enrollment System
-- Migration 001: identity, access control and monthly reporting periods.
-- This migration creates only new abh_* tables. It does not alter legacy tables.
-- Event timestamps are stored in UTC; period boundaries use Africa/Lagos.

CREATE TABLE abh_admin_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_key VARCHAR(64) NOT NULL,
    role_name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    is_system_role TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_admin_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_admin_permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_admin_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(254) NOT NULL,
    email_normalized VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    must_reset_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_admin_users_email_normalized (email_normalized),
    KEY idx_abh_admin_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_admin_user_roles (
    admin_user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by_admin_id BIGINT UNSIGNED NULL,
    assigned_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (admin_user_id, role_id),
    KEY idx_abh_admin_user_roles_role (role_id),
    KEY idx_abh_admin_user_roles_assigned_by (assigned_by_admin_id),
    CONSTRAINT fk_abh_admin_user_roles_admin
        FOREIGN KEY (admin_user_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_admin_user_roles_role
        FOREIGN KEY (role_id) REFERENCES abh_admin_roles (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_admin_user_roles_assigned_by
        FOREIGN KEY (assigned_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_admin_role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (role_id, permission_id),
    KEY idx_abh_admin_role_permissions_permission (permission_id),
    CONSTRAINT fk_abh_admin_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES abh_admin_roles (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_admin_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES abh_admin_permissions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_agents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_code VARCHAR(32) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(24) NOT NULL,
    phone_normalized VARCHAR(24) NOT NULL,
    email VARCHAR(254) NULL,
    email_normalized VARCHAR(254) NULL,
    location_text VARCHAR(255) NULL,
    profile_photo_key VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    registered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    approved_at DATETIME(6) NULL,
    approved_by_admin_id BIGINT UNSIGNED NULL,
    status_reason VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_agents_agent_code (agent_code),
    UNIQUE KEY uq_abh_agents_email_normalized (email_normalized),
    KEY idx_abh_agents_phone_normalized (phone_normalized),
    KEY idx_abh_agents_status (status),
    KEY idx_abh_agents_registered_at (registered_at),
    KEY idx_abh_agents_approved_by (approved_by_admin_id),
    CONSTRAINT fk_abh_agents_approved_by
        FOREIGN KEY (approved_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_agent_credentials (
    agent_id BIGINT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_changed_at DATETIME(6) NULL,
    must_reset_password TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (agent_id),
    CONSTRAINT fk_abh_agent_credentials_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_agent_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_id BIGINT UNSIGNED NOT NULL,
    device_label VARCHAR(100) NULL,
    device_type VARCHAR(64) NULL,
    imei_value VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    registered_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    verified_at DATETIME(6) NULL,
    verified_by_admin_id BIGINT UNSIGNED NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_agent_devices_agent_imei (agent_id, imei_value),
    KEY idx_abh_agent_devices_agent_status (agent_id, status),
    KEY idx_abh_agent_devices_imei (imei_value),
    KEY idx_abh_agent_devices_verified_by (verified_by_admin_id),
    CONSTRAINT fk_abh_agent_devices_agent
        FOREIGN KEY (agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_agent_devices_verified_by
        FOREIGN KEY (verified_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_reporting_periods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_key CHAR(7) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
    status VARCHAR(12) NOT NULL DEFAULT 'OPEN',
    closed_at DATETIME(6) NULL,
    closed_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_abh_reporting_periods_period_key (period_key),
    KEY idx_abh_reporting_periods_status_start (status, starts_on),
    KEY idx_abh_reporting_periods_closed_by (closed_by_admin_id),
    CONSTRAINT fk_abh_reporting_periods_closed_by
        FOREIGN KEY (closed_by_admin_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
