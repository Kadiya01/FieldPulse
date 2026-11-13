# ABH-only SQL migrations

This directory is intentionally separate from `../migrations/`, which belongs
to the legacy FieldPulse application and its existing checksum ledger.

ABH migration files must use the numbered filename form:

- `001_identity_access_periods.sql`
- `002_submissions_reviews.sql`
- `003_targets_snapshots.sql`

Migration 001 creates the identity, admin access and monthly reporting-period tables. Migration 002 creates independent enrollment reports, immutable revisions, evidence metadata, review events and adjustment records. Migration 003 creates organisation and individual monthly targets, agent totals and month-close snapshots. Migrations 001, 002 and 003 have passed the disposable CI schema suite on MySQL 8.0 and MariaDB 11.4. Additional changes use the next available three-digit number. Never edit a migration after it has been applied.

The dedicated runner is `bin/abh_migrate.php`; its separate ledger is
`abh_schema_migrations`. The runner is bound to the ABH preview private
directory, preview URL, preview `.env`, database name and database user. It
requires both `--apply` and the exact staging database confirmation flag to
execute SQL. `--status` and `--dry-run` are inspection modes.

These SQL files are migration drafts under development. Migrations 001 and 002 have passed disposable MySQL 8.0 and MariaDB 11.4 CI tests. Migration 003 is pending the same test. None of these files has been applied to the cPanel staging database. Never copy this migration set into the legacy `migrations/` directory.
