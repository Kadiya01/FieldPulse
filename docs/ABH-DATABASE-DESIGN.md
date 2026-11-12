# ABH Monthly Enrollment System — Database Design (Phase 2)

Status: design proposal only. No database was connected, inspected live, migrated or modified.

## 1. Design decision

The existing FieldPulse database is a useful source of implementation patterns, but it is not the ABH schema contract. Its submission lifecycle and aggregation vocabulary are verification-oriented (for example RECEIVED, QUEUED, PROCESSING, VERIFIED and REQUIRES_REVIEW), and its reward snapshots are weekly. ABH requires an explicit monthly review and reward workflow.

Do not point the ABH application at the production FieldPulse database. Build and test an ABH schema independently in the staging database.

## 2. Proposed data areas

### Identity and access
- `agents`: agent profile, contact details, registered device metadata, account status and timestamps.
- `agent_credentials` (or equivalent secure credential columns): password hash and credential lifecycle; never store a plaintext password.
- `admin_users`: separate administrative identities.
- `admin_roles` and `admin_user_roles`: permission assignment for reviewers, supervisors and administrators.
- Device IMEI is descriptive/administrative metadata. It is not a password and must not be accepted as the only authentication factor.

### Monthly activity and review
- `enrollment_submissions`: one separately submitted activity report, with agent, reporting month, claimed quantity, submission reference, idempotency key, current review state and timestamps. An agent may create multiple separate reports during the same open month.
- `submission_revisions`: immutable versions of quantity/details when an agent corrects a submission.
- `submission_evidence`: evidence metadata and private storage key; never expose a filesystem path or store evidence under the public document root.
- `submission_reviews`: append-only review decisions, reviewer, decision, reason/note and timestamp.
- `submission_adjustments`: controlled correction/reversal of an already approved quantity, with reason, actor and links to the original decision.

Recommended review states: PENDING_REVIEW, CORRECTION_REQUESTED, APPROVED, REJECTED. A correction creates a new revision; it must not erase the prior submission or review history.

### Monthly performance and targets
- `reporting_periods`: calendar month start/end, timezone, OPEN/CLOSED state and close metadata.
- `monthly_targets`: target amount and scope (organisation or individual agent), effective reporting period, creator/updater and audit metadata. ABH requires both organisation-wide and individual monthly targets. Team/location targets are not part of the initial policy; they may be added later only with ABH approval.
- `monthly_agent_totals`: derived/cacheable totals by agent and month, updated only through the approved-review/reversal service transaction.
- `monthly_leaderboard_snapshots`: immutable final rank and approved quantity at period close, including display-name/status snapshots needed for historical display.

Only approved quantities count. Organisation-wide progress and each agent’s individual progress are calculated independently against their respective monthly targets. Each agent’s target is configured independently and may differ from other agents. Do not automatically substitute the organisation-wide target when an individual target is missing; unless ABH explicitly enables a fallback rule later, display “Not configured”. The progress formula is approved quantity divided by the applicable target, multiplied by 100. If the target is missing or zero, display “Not configured”; do not divide by zero.

### Rewards and fulfilment
- `reward_programs`: monthly programme, active dates, description and status.
- `reward_rules`: rank bands, minimum approved quantity, eligibility conditions, reward label/value/currency and rule version.
- `reward_qualifications`: period, agent, frozen rank, approved total, rule version and qualification outcome.
- `reward_fulfilment_events`: separate approval, payment and delivery records, actor, timestamp, reference and notes.

A published qualification is not proof of payment. Payment and delivery are separately recorded events. Closing a month freezes the leaderboard and reward qualification basis.

### Governance and communication
- `audit_events`: actor, action, target entity, before/after summary, reason, request correlation ID and timestamp.
- `notifications`: recipient, event, message, read state and timestamp.


## 3. Proposed table-level schema (review draft)

This is a logical schema proposal for MySQL/MariaDB with InnoDB. Names and columns are recommendations, not an instruction to run SQL yet. Use BIGINT unsigned primary keys unless an existing project convention requires otherwise; use UTC `DATETIME` values for event timestamps and `DATE` for calendar boundaries.

### Identity and access tables

| Table | Main columns | Important rules |
|---|---|---|
| `abh_agents` | `id`, `agent_code`, `full_name`, `phone`, nullable `email`, `location_text`, `profile_photo_key`, `status`, `registered_at`, `approved_at`, `approved_by_admin_id`, `created_at`, `updated_at` | Unique agent code; index phone, email and status. Status values: PENDING, APPROVED, REJECTED, SUSPENDED. Never hard-delete an agent with activity history. |
| `abh_agent_credentials` | `agent_id`, `password_hash`, `password_changed_at`, `must_reset_password`, `last_login_at` | One credential row per agent; password hash only. Authentication is separate from IMEI. |
| `abh_agent_devices` | `id`, `agent_id`, `device_label`, `imei_value`, `status`, `registered_at`, `verified_at`, `verified_by_admin_id` | Device metadata and authorization history; never use IMEI as password. Restrict access to full IMEI and consider masked display. |
| `abh_admin_users` | `id`, `full_name`, `email`, `password_hash`, `status`, `last_login_at`, timestamps | Separate admin identity from agent identity; unique normalized email. |
| `abh_admin_roles` | `id`, `role_key`, `role_name` | Unique role key. Seed only approved roles. |
| `abh_admin_permissions` | `id`, `permission_key`, `description` | Unique permission key. |
| `abh_admin_user_roles` | `admin_user_id`, `role_id` | Composite primary key; many-to-many assignment. |
| `abh_admin_role_permissions` | `role_id`, `permission_id` | Composite primary key; permissions are checked server-side on every privileged action. |

### Reporting, submissions and review tables

| Table | Main columns | Important rules |
|---|---|---|
| `abh_reporting_periods` | `id`, `period_key` (YYYY-MM), `starts_on`, `ends_on`, `timezone`, `status`, `closed_at`, `closed_by_admin_id` | Unique period key; status OPEN or CLOSED. Use Africa/Lagos for period boundaries. A closed period rejects new submissions and changes except a separately authorized, audited correction process. |
| `abh_enrollment_submissions` | `id`, `reference_no`, `agent_id`, `reporting_period_id`, `current_revision_id`, `status`, `idempotency_key`, `submitted_at`, timestamps | Unique reference and idempotency key; index agent+period+status. Agent ID comes only from authenticated session. |
| `abh_submission_revisions` | `id`, `submission_id`, `revision_no`, `claimed_quantity`, `activity_date`, `agent_note`, `created_at`, `created_by_agent_id` | Unique submission+revision number. Append-only; correction creates a new revision rather than overwriting the old one. Activity date may be any date inside that submission's current open reporting month; validate against the period boundaries on the server. |
| `abh_submission_evidence` | `id`, `revision_id`, `storage_key`, `original_filename`, `mime_type`, `byte_size`, `sha256`, `uploaded_at` | Store file outside public web root. Keep only opaque storage key and verified metadata in DB. Validate file type, size and content on upload. |
| `abh_submission_reviews` | `id`, `submission_id`, `revision_id`, `reviewer_admin_id`, `decision`, `reason_code`, `review_note`, `created_at` | Append-only. Decisions include APPROVED, REJECTED and CORRECTION_REQUESTED. A reviewer can decide a revision only once; enforce with unique revision+reviewer+decision policy or a transaction/state guard appropriate to the final workflow. |
| `abh_submission_adjustments` | `id`, `submission_id`, `approved_revision_id`, `quantity_delta`, `reason`, `created_by_admin_id`, `created_at` | Used only for authorized post-approval correction/reversal. Never silently edit an approved revision; every adjustment is reasoned and audited. |

Submission status belongs to the parent submission and reflects the current revision: PENDING_REVIEW, CORRECTION_REQUESTED, APPROVED or REJECTED. When an agent resubmits after correction, create a new revision and return the parent to PENDING_REVIEW. Preserve all prior reviews and evidence.

### Targets, totals and historical ranking

| Table | Main columns | Important rules |
|---|---|---|
| `abh_monthly_targets` | `id`, `reporting_period_id`, `scope_type`, `agent_id` nullable, `target_quantity`, `created_by_admin_id`, `updated_by_admin_id`, timestamps | Initial scope types: ORGANISATION and AGENT. ORGANISATION requires null agent; AGENT requires an agent ID. Unique one target per period+scope (and agent for AGENT). Target must be positive. |
| `abh_monthly_agent_totals` | `reporting_period_id`, `agent_id`, `approved_quantity`, `approved_submission_count`, `updated_at` | Composite primary key. Derived cache only, never authoritative; rebuild from approved current revisions plus authorized adjustments. |
| `abh_monthly_leaderboard_snapshots` | `id`, `reporting_period_id`, `agent_id`, `rank_position`, `approved_quantity`, `reached_final_total_at`, `agent_code_snapshot`, `display_name_snapshot`, `created_at` | Unique period+agent; index period+rank. Immutable after period close. For equal final approved quantities, rank the agent earlier if their cumulative net approved quantity first reached that final quantity earlier in the month. Derive this timestamp from the approval and adjustment event history and freeze it at close. |
| `abh_monthly_organisation_snapshots` | `reporting_period_id`, `approved_quantity`, `target_quantity`, `progress_percent`, `agent_count`, `closed_at` | One immutable organisation summary per period close, including the organisation target used at close. |

The organisation approved total is the sum of qualifying agent totals for that period; do not add the organisation target itself into agent totals. The organisation target and individual targets are separate measures. If an agent has no individual target, show “Not configured”; do not silently borrow the organisation target.

### Rewards, audit and notifications

| Table | Main columns | Important rules |
|---|---|---|
| `abh_reward_programs` | `id`, `reporting_period_id`, `name`, `description`, `status`, `created_by_admin_id`, timestamps | One or more explicitly configured programmes per period; define whether multiple programmes are allowed before implementation. |
| `abh_reward_rules` | `id`, `program_id`, `rule_version`, `rank_from`, `rank_to`, `minimum_approved_quantity`, `reward_title`, `reward_value`, `currency_code`, `conditions_json`, `is_active` | Version rules; do not hardcode reward amounts or positions. Validate overlapping rank bands and currency before publication. |
| `abh_reward_qualifications` | `id`, `program_id`, `rule_id`, `agent_id`, `reporting_period_id`, `frozen_rank`, `frozen_approved_quantity`, `qualification_status`, `reviewed_by_admin_id`, `reviewed_at` | Unique programme+agent. Qualification/winner approval is not payment. Store frozen values used to decide eligibility. |
| `abh_reward_fulfilment_events` | `id`, `qualification_id`, `event_type`, `amount`, `currency_code`, `reference_no`, `note`, `recorded_by_admin_id`, `created_at` | Append-only events such as APPROVED, PAID and DELIVERED; require the appropriate permission and supporting reference where applicable. |
| `abh_audit_events` | `id`, `actor_type`, `actor_id`, `action_key`, `entity_type`, `entity_id`, `reason`, `before_json`, `after_json`, `request_id`, `created_at` | Append-only; do not log passwords, session tokens, raw evidence or unnecessary personal data. |
| `abh_notifications` | `id`, `recipient_type`, `recipient_id`, `event_key`, `title`, `message`, `read_at`, `created_at` | Index recipient+read state+created time; notification content must not expose another agent’s data. |

### Leaderboard tie-break policy

ABH selected a time-based tie-breaker. When two or more agents finish the month with the same approved quantity, the agent whose cumulative net approved quantity first reached that final quantity earlier in the reporting month ranks higher. The qualifying timestamp must be derived from the ordered approval/reversal/adjustment history, stored as `reached_final_total_at` in the monthly leaderboard snapshot, and frozen when the month closes. If the qualifying timestamps are identical, the design still needs an explicit secondary tie rule before implementation; do not silently rely on database row order.

### Core relationship map

- One agent has one credential record, may have multiple registered device records, and may submit multiple independent reports in the same reporting month.
- One reporting period has many submissions and targets, and at most one organisation target plus one individual target per agent.
- One submission has one or more immutable revisions; each revision may have multiple evidence files and review decisions.
- Only the currently approved revision contributes to the agent's monthly total. An authorized adjustment changes the counted quantity through a separate ledger entry.
- One reporting period has one final leaderboard snapshot per agent and one organisation summary snapshot.
- One reward programme has versioned rules and qualification records; each qualification may have multiple fulfilment events.
- Admin users perform reviews, target changes, period closure and reward fulfilment; each privileged action creates an audit event.

### Design points requiring confirmation before SQL migrations

1. Submission frequency decision: agents may submit multiple separate reports during an open month. Each report has its own reference, idempotency key, evidence, review lifecycle and revision history. A correction revises that specific report; it does not replace or merge other reports from the same agent.
2. Activity-date policy selected: agents may report any activity date within the current open reporting month, including earlier dates in that same month. The server must reject dates before the period start, after the period end, or submissions against a closed period. No seven-day limit applies.
3. Secondary tie-break rule if agents have both the same final approved quantity and the same `reached_final_total_at` timestamp.
4. Whether a reward programme can contain multiple reward categories or only one set of rank awards per month.
5. Whether reward qualification is automatic with a separate admin approval, or whether admins manually select winners from eligible agents.
6. Whether a rejected submission is terminal or can be reopened by an admin.

## 3. Important constraints and transaction rules

1. Use InnoDB and foreign keys for relational integrity.
2. Give every submission a unique server-validated idempotency/reference key to prevent duplicate posting on retries.
3. Store reporting month as a real period relationship, not a free-form month string.
4. The server derives agent identity from the authenticated session; ignore any agent ID supplied by the browser.
5. Approving a submission, recording its approved quantity and updating its monthly aggregate must happen in one database transaction.
6. Reversals must be a separate authorized, reasoned, audited transaction; never silently edit an approved quantity.
7. Prevent two concurrent reviewers from approving the same pending revision twice (row lock or conditional state transition).
8. Enforce agent ownership on every read and write. Admin access is role/permission checked.
9. Closing a reporting period must be idempotent and create immutable monthly ranking and reward-basis snapshots.
10. Do not cascade-delete historical submissions, evidence metadata, reviews, audit events, rankings or reward records when an agent is suspended or retired.
11. Evidence files are stored outside the public web root. Database rows hold opaque storage keys and verified metadata, not public URLs.
12. Use Africa/Lagos for business reporting periods and UTC for stored event timestamps.

## 5. Existing FieldPulse structures: reuse assessment

| Existing structure/pattern | Assessment for ABH |
|---|---|
| Agent account and password-hash handling | Reusable pattern after checking the full registration and approval lifecycle. |
| Agent status enum | Conceptually reusable, but ABH must explicitly test PENDING and REJECTED access restrictions. |
| IMEI/device metadata | Reusable only as administrative metadata, never as the sole login factor. |
| `submissions` / `submission_verifications` | Do not reuse their verification state machine as ABH review status. Map data only after a field-by-field audit. |
| `agent_performance_summary` | Not a historical source of truth; it is a live recompute. ABH needs monthly approved totals and immutable close snapshots. |
| `reward_tiers`, `reward_rankings`, `agent_rewards` | Useful patterns for rank bands and separation of qualification from payment, but the current design is weekly and must be replaced or adapted for monthly periods and ABH rules. |
| Existing audit and notification patterns | Candidate for reuse after confirming coverage, actor identity and immutable event history. |

## 6. Migration approach

- Keep existing migrations unchanged; the legacy migrator checks file checksums.
- Create a new, clearly named ABH migration series or a separate ABH schema/database.
- Prefer additive, reversible changes. Avoid destructive renames or status rewrites.
- First migration should create the ABH core tables and constraints; later migrations add targets, monthly snapshots, rewards and notifications.
- Test migrations from an empty staging database and test rollback/restore using a staging backup.
- Before any staging migration, verify the DB name from the application configuration at runtime and reject any name other than the exact staging database.
- Do not run a migration from cPanel until the schema has been reviewed and a verified staging backup exists.

## 7. Acceptance tests before implementation

- Pending, rejected and suspended agents cannot submit or view another agent's data.
- A browser cannot submit on behalf of another agent by changing an agent ID.
- Retrying a submission cannot create a duplicate.
- Only an approved revision contributes to monthly totals.
- A correction request does not count; the corrected revision requires a fresh review.
- Concurrent approval attempts produce one approval and one aggregate increment.
- Reversal requires permission and reason, updates totals exactly once and preserves history.
- A closed month retains its rank and reward basis after later agent/profile changes.
- Reward qualification does not mark an award as paid or delivered.
- Every privileged action has an attributable audit event.

## 8. Open policy values (must be configured, not invented)

- Target policy selected: both organisation-wide and individual monthly targets are required. Team/location targets are optional future scope and require separate ABH approval.
- Whether an organisation-wide target may be used as a fallback when an individual target is missing (default: no; display “Not configured”).
- Primary tie-break decision recorded: for equal final approved quantities, the agent who reached that final quantity first ranks higher. A secondary rule is still required if the qualifying timestamps are identical.
- Whether rewards require a minimum approved quantity in addition to rank.
- Reward values, currency, qualification rules and any exclusions.
- Who may approve submissions, reverse approvals, close months and record payment/delivery.
