-- ABH Monthly Enrollment System
-- Migration 005: immutable audit events and private notifications.
-- Actor/recipient references are explicit and foreign-keyed; SYSTEM audit events
-- have no user FK. No passwords, tokens, raw evidence or unnecessary personal
-- information should be placed in audit JSON or notification content.

CREATE TABLE abh_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type VARCHAR(16) NOT NULL,
    actor_admin_user_id BIGINT UNSIGNED NULL,
    actor_agent_id BIGINT UNSIGNED NULL,
    action_key VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    reason VARCHAR(2000) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    request_id CHAR(36) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_audit_actor_admin_created (actor_admin_user_id, created_at),
    KEY idx_abh_audit_actor_agent_created (actor_agent_id, created_at),
    KEY idx_abh_audit_entity_created (entity_type, entity_id, created_at),
    KEY idx_abh_audit_action_created (action_key, created_at),
    KEY idx_abh_audit_request_id (request_id),
    CONSTRAINT fk_abh_audit_events_actor_admin
        FOREIGN KEY (actor_admin_user_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_audit_events_actor_agent
        FOREIGN KEY (actor_agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_abh_audit_events_actor
        CHECK (
            (actor_type = 'ADMIN' AND actor_admin_user_id IS NOT NULL AND actor_agent_id IS NULL)
            OR (actor_type = 'AGENT' AND actor_agent_id IS NOT NULL AND actor_admin_user_id IS NULL)
            OR (actor_type = 'SYSTEM' AND actor_admin_user_id IS NULL AND actor_agent_id IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE abh_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_type VARCHAR(16) NOT NULL,
    recipient_admin_user_id BIGINT UNSIGNED NULL,
    recipient_agent_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(100) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(2000) NOT NULL,
    read_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_abh_notifications_agent_unread_created (recipient_agent_id, read_at, created_at),
    KEY idx_abh_notifications_admin_unread_created (recipient_admin_user_id, read_at, created_at),
    KEY idx_abh_notifications_event_created (event_key, created_at),
    CONSTRAINT fk_abh_notifications_recipient_admin
        FOREIGN KEY (recipient_admin_user_id) REFERENCES abh_admin_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_abh_notifications_recipient_agent
        FOREIGN KEY (recipient_agent_id) REFERENCES abh_agents (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_abh_notifications_recipient
        CHECK (
            (recipient_type = 'ADMIN' AND recipient_admin_user_id IS NOT NULL AND recipient_agent_id IS NULL)
            OR (recipient_type = 'AGENT' AND recipient_agent_id IS NOT NULL AND recipient_admin_user_id IS NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
