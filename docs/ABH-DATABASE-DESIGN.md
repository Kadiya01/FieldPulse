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
- `enrollment_submissions`: one submitted activity record, with agent, reporting month, claimed quantity, submission reference, idempotency key, current review state and timestamps.
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

## 4. Existing FieldPulse structures: reuse assessment

| Existing structure/pattern | Assessment for ABH |
|---|---|
| Agent account and password-hash handling | Reusable pattern after checking the full registration and approval lifecycle. |
| Agent status enum | Conceptually reusable, but ABH must explicitly test PENDING and REJECTED access restrictions. |
| IMEI/device metadata | Reusable only as administrative metadata, never as the sole login factor. |
| `submissions` / `submission_verifications` | Do not reuse their verification state machine as ABH review status. Map data only after a field-by-field audit. |
| `agent_performance_summary` | Not a historical source of truth; it is a live recompute. ABH needs monthly approved totals and immutable close snapshots. |
| `reward_tiers`, `reward_rankings`, `agent_rewards` | Useful patterns for rank bands and separation of qualification from payment, but the current design is weekly and must be replaced or adapted for monthly periods and ABH rules. |
| Existing audit and notification patterns | Candidate for reuse after confirming coverage, actor identity and immutable event history. |

## 5. Migration approach

- Keep existing migrations unchanged; the legacy migrator checks file checksums.
- Create a new, clearly named ABH migration series or a separate ABH schema/database.
- Prefer additive, reversible changes. Avoid destructive renames or status rewrites.
- First migration should create the ABH core tables and constraints; later migrations add targets, monthly snapshots, rewards and notifications.
- Test migrations from an empty staging database and test rollback/restore using a staging backup.
- Before any staging migration, verify the DB name from the application configuration at runtime and reject any name other than the exact staging database.
- Do not run a migration from cPanel until the schema has been reviewed and a verified staging backup exists.

## 6. Acceptance tests before implementation

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

## 7. Open policy values (must be configured, not invented)

- Target policy selected: both organisation-wide and individual monthly targets are required. Team/location targets are optional future scope and require separate ABH approval.
- Whether an organisation-wide target may be used as a fallback when an individual target is missing (default: no; display “Not configured”).
- Whether ranking ties share a rank or use a documented tie-break rule.
- Whether rewards require a minimum approved quantity in addition to rank.
- Reward values, currency, qualification rules and any exclusions.
- Who may approve submissions, reverse approvals, close months and record payment/delivery.
