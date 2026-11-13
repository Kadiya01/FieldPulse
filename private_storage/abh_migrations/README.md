# ABH-only SQL migrations

This directory is intentionally separate from `../migrations/`, which belongs
to the legacy FieldPulse application and its existing checksum ledger.

ABH migration files must use the numbered filename form:

- `001_identity_access_periods.sql`
- `002_submissions_reviews.sql`
- `003_targets_snapshots.sql`

Additional changes use the next available three-digit number. Never edit a
migration after it has been applied.

The dedicated runner is `bin/abh_migrate.php`; its separate ledger is
`abh_schema_migrations`. The runner is bound to the ABH preview private
directory, preview URL, preview `.env`, database name and database user. It
requires both `--apply` and the exact staging database confirmation flag to
execute SQL. `--status` and `--dry-run` are inspection modes.

**No executable ABH migration has been approved or added yet.** Do not place
draft SQL here until the ABH schema review, local migration tests and staging
backup/approval gates are complete. Never copy this migration set into the
legacy `migrations/` directory.
