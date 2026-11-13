# ABH Monthly Enrollment System — SQL Migration Plan

## Status and scope

**Plan document only; this file contains no executable SQL.** The first executable ABH migration is stored separately in `private_storage/abh_migrations/001_identity_access_periods.sql` and has passed disposable CI schema tests on MySQL 8.0 and MariaDB 11.4. No cPanel, staging or production database was connected or migrated.

The first implementation target is the dedicated staging database:

- Database: `abhgloba_abhpreview`
- Application private directory: `/home/abhgloba/abhpreview_private_storage/`
- Preview document root: `/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/`

Production database `abhgloba_fieldpulse` and production private directory `/home/abhgloba/private_storage/` are explicitly excluded.

## 1. Migration isolation

Do not add ABH migration files to the legacy FieldPulse migration sequence. The old migration runner may create legacy tables and apply product assumptions that are not part of the ABH schema.

The proposed ABH migration mechanism is:

- Store ABH migration files in `private_storage/abh_migrations/`.
- Use a dedicated runner: `private_storage/bin/abh_migrate.php`.
- Maintain a separate ledger table: `abh_schema_migrations`.
- Prefix every ABH table with `abh_`.
- Do not rename, drop or alter existing legacy tables as part of the first ABH migration.
- The preview migration runner must verify the connected database name is exactly `abhgloba_abhpreview` before it can apply a migration. A missing, unreadable or different database name must stop the process.
- The runner must support `--status` and `--dry-run`; applying migrations requires a separately verified staging backup and explicit approval.
- Each applied file is checksum-recorded. Never edit an applied migration; create a new forward migration instead.

This isolates both the migration sequence and the table names from the older product. The application must also be configured to use only the staging database on the preview host.

## 2. Proposed migration sequence

| Order | Proposed file | Tables / purpose |
|---|---|---|
| 001 | `001_identity_access_periods.sql` | Created. Agents, credentials, registered devices, admin users, roles, permissions, role joins and monthly reporting periods. CI-tested on disposable MySQL 8.0 and MariaDB 11.4 databases; not applied to cPanel staging. |
| 002 | `002_submissions_reviews.sql` | Created; CI compatibility test pending. Enrollment submissions, immutable revisions, private evidence metadata, review decisions and approved-quantity adjustments. |
| 003 | `003_abh_targets_performance_snapshots.sql` | Organisation and individual targets, monthly agent totals, leaderboard snapshots and organisation snapshots |
| 004 | `004_abh_reward_programmes_gifts_awards.sql` | Monthly reward programmes, eligibility-rule versions, qualification records, management gift catalogue, assigned awards and payment/delivery events |
| 005 | `005_abh_audit_notifications.sql` | Append-only administrative audit events, agent notifications and supporting indexes |

The order is a proposal. Foreign keys must only reference tables created in an earlier migration or earlier in the same migration. Migration 001 has passed the disposable schema test on both target engine families. The CI test verified creation of all nine tables, InnoDB engines, expected foreign keys, default PENDING agent status, and rejection of duplicate agent codes and normalized admin emails. Migration 002 has now been added and extends the same two-engine CI suite with submission, revision, evidence, review and adjustment relationships. Its new CI run is pending. These tests do not connect to the cPanel database.

## 3. Table and data rules

### Identity and access

- Use separate agent and admin identities.
- Store password hashes only; use PHP's password hashing and verification APIs.
- Keep IMEI/device details as registration metadata, not an authentication secret.
- Agent statuses: `PENDING`, `APPROVED`, `REJECTED`, `SUSPENDED`.
- Seed permission keys and role definitions, but do not seed real staff accounts or passwords.
- Proposed least-privilege roles: `AGENT_MANAGER`, `SUBMISSION_REVIEWER`, `TARGET_MANAGER`, `REWARD_MANAGER`, `AWARD_APPROVER`, `FULFILMENT_OFFICER`, `PERIOD_CLOSER` and `SYSTEM_ADMINISTRATOR`. A staff member may have more than one role if management assigns it.

### Reporting periods and submissions

- One period row represents one calendar month in `Africa/Lagos`.
- Use `DATE` for period boundaries and UTC `DATETIME(6)` for event timestamps.
- Each agent may submit multiple independent reports in an open period.
- Each report has a unique reference and agent-scoped idempotency key.
- A correction creates another revision of that report. It never overwrites the earlier revision.
- Evidence metadata stores an opaque private storage key, SHA-256, MIME type and byte size. Evidence files stay outside the public document root.
- Only the current approved revision contributes to totals.
- A correction request or rejection contributes zero until a revision is approved.
- An authorised admin may reopen a rejected report with a reason. The reopen action is append-only in the review and audit histories; any changed quantity/details require a new revision.

### Targets and performance

- Every month supports one organisation target and optional individual agent targets.
- Organisation and individual progress are calculated independently.
- Missing individual target displays `Not configured`; it never silently falls back to the organisation target.
- Use a database uniqueness design that enforces one organisation target per period despite SQL NULL uniqueness behavior. One safe option is a non-null scope-owner key where the organisation uses reserved owner ID 0 and each agent uses their positive agent ID.
- Monthly totals are derived/cacheable and must be rebuildable from approved revisions plus the adjustment ledger.
- Leaderboard snapshots are immutable after period close.
- The primary tie-break is the earliest time an agent reached their final cumulative net approved quantity.
- Proposed exact-tie default: equal quantity and equal qualifying timestamp share rank using competition ranking. The next rank is skipped.

### Rewards and management decisions

- A reporting month may contain multiple reward programmes/categories.
- Each programme has its own versioned eligibility rules and gift catalogue.
- The system calculates eligibility from configured rules and displays the eligible agents.
- Management manually selects final winners and assigns the gifts. The application never selects winners or gifts automatically.
- The gift catalogue is editable through the Admin Website. Each award preserves a snapshot of the gift name, description, quantity and value at the time it was assigned.
- The proposed schema supports multiple gift lines for one qualified agent. A one-gift limit, if ABH wants one, must be an explicit programme setting.
- Eligibility, winner selection, award assignment, award approval, payment and delivery are separate states/events. An assigned award is not automatically approved, paid or delivered.
- Optional minimum approved quantity is configured per eligibility rule. A null minimum means there is no minimum-count condition.

## 4. Technical conventions

- Target engines: MySQL 8.x and MariaDB 10.11+; verify the exact cPanel engine version before staging migration.
- Use InnoDB, foreign keys and `utf8mb4`.
- Use `BIGINT UNSIGNED` for primary/foreign keys and enrollment quantities unless a reviewed table requires otherwise.
- Use `DECIMAL(15,2)` for monetary values, with explicit currency codes. Never use floating-point money.
- Prefer `VARCHAR` status columns with application validation over database ENUMs that require disruptive schema edits when a state is added.
- Use `DATETIME(6)` UTC for event timestamps; use `DATE` for calendar boundaries.
- Use unique constraints for agent codes, normalized usernames/emails where required, period keys, submission references, idempotency keys, revision numbers, programme codes per period, qualification per programme+agent and snapshot period+agent.
- Use restrictive foreign-key delete behavior for operational history. Do not cascade-delete submissions, evidence metadata, reviews, adjustments, totals, snapshots, awards, fulfilment events or audit events.
- Use JSON only for versioned eligibility conditions and audit snapshots; validate their structure in application code and add a schema version.

## 5. Transaction boundaries

1. **Create submission:** validate authenticated agent, open period, activity date, quantity and evidence. Store evidence safely, then insert the submission and first revision with a unique idempotency key. A retry must return the existing receipt, not add a second report.
2. **Request correction / reject / reopen:** lock the submission row, validate its current state and reviewer permission, append the review event and update the current state in one transaction. Store a reason for rejection, correction and reopening.
3. **Resubmit correction:** append a new revision and return that report to `PENDING_REVIEW`. Keep old revisions, evidence and reviews.
4. **Approve:** lock the submission and current revision, ensure the revision has not already been approved, append the approval event, update the monthly total and any derived organisation total in one transaction. A concurrent second approval must not increment totals.
5. **Reverse or adjust:** require a dedicated permission and reason. Append an adjustment ledger row, recompute affected totals, and audit the action in one transaction. Never modify the original approved revision.
6. **Close month:** lock the reporting period, verify it is open, build ranking and organisation snapshots, freeze the exact target and total basis, and mark the period closed in one idempotent transaction. Repeating the close must not duplicate snapshots.
7. **Qualify agents:** calculate qualifications using the frozen monthly figures and the relevant version of each programme's rules. Use a unique programme+agent key to make recalculation idempotent.
8. **Assign and approve awards:** an authorised reward manager selects a qualified agent and a gift. In one transaction, snapshot the gift details and reserve/decrement available stock if stock tracking is enabled. Award approval is a separate permission-checked action.
9. **Record fulfilment:** append payment and delivery events against the approved award. Never infer payment or delivery from assignment or approval.

## 6. Acceptance gates before any migration

### Design gate

- [ ] ABH reviews the table names, foreign keys, unique constraints and status transitions.
- [ ] Management confirms the proposed exact-tie, multiple-programme, multiple-gift, rejection-reopening and staff-role defaults.
- [ ] Reward qualification remains separate from management's winner and gift decisions.
- [ ] Target policy and submission frequency/activity-date decisions already recorded in the database design are preserved.

### Local migration gate

- [x] Migration 001 was applied by the disposable CI schema test to fresh MySQL 8.0 and MariaDB 11.4 service databases; the initial identity/access/period tables and tested constraints passed.
- [ ] The dedicated ABH runner applies the full migration sequence to a new empty local test database.
- [ ] Re-running the runner reports no pending migrations and makes no changes.
- [ ] The checksum ledger detects an edited applied migration.
- [ ] All foreign keys and unique constraints are tested, including agent-scoped idempotency.
- [ ] Approval, duplicate approval, reversal, month close, reward qualification, award assignment and fulfilment transaction tests pass.
- [x] The initial migration has been tested from empty disposable databases on MySQL 8.0 and MariaDB 11.4.
- [ ] The full ABH migration sequence is tested from empty databases on both supported engines.

### Staging-only gate

- [ ] Confirm the live cPanel database engine/version and exact database name.
- [ ] Take and verify a staging backup of `abhgloba_abhpreview`.
- [ ] Prove the runtime DB connection reports `abhgloba_abhpreview`; abort on any other database name.
- [ ] Prove the preview PHP bootstrap resolves only `/home/abhgloba/abhpreview_private_storage/`.
- [ ] Review the migration plan and backup restoration procedure.
- [ ] Obtain explicit approval before applying migrations.

**No staging or production migration is authorised by this plan.** The first migration has only been tested in disposable CI databases. Production must never be used to test the ABH migration sequence.

## 7. Items deliberately not decided by the developer

The following remain management decisions, not hidden implementation assumptions:

- Whether exact leaderboard ties share rank (the proposed default is yes).
- Whether more than one reward programme/category may exist in a month (the proposed default is yes).
- Whether an eligible agent may receive multiple gift lines in one month (the proposed schema permits it).
- Whether an authorised admin may reopen a rejected submission (the proposed default is yes, with a mandatory reason and full history).
- Which named staff members receive the proposed admin roles.
- The actual monthly eligibility rules, exclusions, gift catalogue, gift values and award quantities.

The schema is designed to support the flexible defaults. Final UI enforcement should follow management's confirmed policy.
