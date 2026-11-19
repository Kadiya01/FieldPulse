-- ABH Monthly Enrollment System
-- Migration 010: explicit month-finalisation permission and safe snapshot precision.

INSERT INTO abh_admin_permissions (permission_key, description)
VALUES (
    'periods.close',
    'Close monthly reporting periods and freeze monthly performance snapshots.'
)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Operations managers can configure targets and close completed reporting months.
-- SUPER_ADMIN receives the full catalogue through the existing grant model;
-- explicitly grant this new permission here because 008 ran before it existed.
INSERT IGNORE INTO abh_admin_role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM abh_admin_roles r
  INNER JOIN abh_admin_permissions p ON p.permission_key = 'periods.close'
 WHERE r.role_key IN ('SUPER_ADMIN', 'OPERATIONS_MANAGER');

-- A BIGINT approved quantity divided by a target as small as 1 can yield
-- more than nine integer digits in the percentage. Preserve the full ratio.
ALTER TABLE abh_monthly_organisation_snapshots
    MODIFY progress_percent DECIMAL(24,2) NULL;
