# ABH-only SQL migrations

This directory is intentionally separate from `../migrations/`, which belongs
to the legacy FieldPulse application and its existing checksum ledger.

ABH migration files must use the numbered filename form:

- `001_identity_access_periods.sql`
- `002_submissions_reviews.sql`
- `003_targets_performance_snapshots.sql`
- `004_reward_programmes_gifts_awards.sql`
- `005_audit_notifications.sql`
- `006_registration_uniqueness_and_throttling.sql`
- `007_agent_sessions_and_device_keys.sql`
- `008_admin_sessions_and_permissions.sql`

Migration 001 creates the ABH identity, administrator-role and monthly reporting-period tables. Migration 002 creates independent enrollment reports, immutable revisions, evidence metadata, review events and adjustment records. Migration 003 creates organisation and individual monthly targets, agent totals and month-close snapshots. Migration 004 creates reward programmes, versioned eligibility rules, qualification results, the management gift catalogue, assigned awards and separate payment/delivery fulfilment events. Migration 005 adds audit events and notifications. Migration 006 adds unique normalized-phone and global registered-device IMEI constraints, plus the ABH-only hashed registration-attempt ledger. Migration 007 adds browser UUID/public-key binding to ABH devices, opaque agent access/refresh session hashes, one-time authentication nonces and a separate agent login-attempt ledger. Migration 008 adds isolated administrator access/refresh sessions, administrator sign-in throttling, a locked one-time first-admin bootstrap guard, and seeded system roles and permission assignments.

Migrations 001–008 passed disposable CI schema compatibility tests on MySQL 8.0 and MariaDB 11.4. Migration 008 passed 132 checks on each engine in GitHub Actions run #43625507763. These checks run against disposable CI databases only; no migration has been applied to the cPanel staging database.

The dedicated runner is `bin/abh_migrate.php`; its separate ledger is
`abh_schema_migrations`. The runner is bound to the ABH preview private
directory, preview URL, preview `.env`, database name and database user. It
requires both `--apply` and the exact staging database confirmation flag to
execute SQL. `--status` and `--dry-run` are inspection modes.

Never edit a migration after it has been applied. Additional changes use the
next available three-digit number. Never copy this migration set into the
legacy `migrations/` directory.
