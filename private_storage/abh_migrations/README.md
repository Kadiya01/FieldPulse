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

Migration 001 creates the identity, admin access and monthly reporting-period tables. Migration 006 adds unique normalized-phone and global registered-device IMEI constraints, plus the ABH-only hashed registration-attempt ledger. Migration 007 adds browser UUID/public-key binding to ABH devices, opaque access/refresh session hashes, one-time authentication nonces and a separate login-attempt ledger. Migration 008 adds administrator-only sessions and sign-in throttling, a locked one-time bootstrap guard, and seeded roles and permission assignments. Migration 002 creates independent enrollment reports, immutable revisions, evidence metadata, review events and adjustment records. Migration 003 creates organisation and individual monthly targets, agent totals and month-close snapshots. Migration 004 creates reward programmes, versioned eligibility rules, qualification results, the management gift catalogue, assigned awards and separate payment/delivery fulfilment events. Migrations 001–007 passed the disposable CI schema suite on MySQL 8.0 and MariaDB 11.4; migration 008 is being validated. Additional changes use the next available three-digit number. Never edit a migration after it has been applied.

The dedicated runner is `bin/abh_migrate.php`; its separate ledger is
`abh_schema_migrations`. The runner is bound to the ABH preview private
directory, preview URL, preview `.env`, database name and database user. It
requires both `--apply` and the exact staging database confirmation flag to
execute SQL. `--status` and `--dry-run` are inspection modes.

These SQL files are migration drafts under development. Migrations 001–007 have passed disposable MySQL 8.0 and MariaDB 11.4 CI tests. Migration 008 is awaiting the current schema test run. None has been applied to the cPanel staging database. Never copy this migration set into the legacy `migrations/` directory.
