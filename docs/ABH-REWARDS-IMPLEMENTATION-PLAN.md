# ABH Rewards Workflow — Implementation Plan

Branch: `feature/abh-monthly-enrollment`

## Purpose

Complete the reward lifecycle without changing the legacy FieldPulse reward routes or tables. ABH rewards are based on approved monthly performance. Qualification is calculated by the system; management retains control over winner approval and gift assignment. Payment and physical delivery are separate recorded events.

## Non-negotiable rules

- All persistence uses `abh_reward_*`, `abh_reporting_periods`, frozen leaderboard snapshots and ABH administrator identity tables.
- Every administrator mutation checks the specific ABH permission on the server. Hiding a UI control is not authorization.
- A reward programme is tied to exactly one reporting period. Closed-period rankings and approved quantities are the source for reward evaluation.
- Rule edits create a new rule version; previously evaluated qualification snapshots are not silently rewritten.
- Qualification preview is read-only. It must not create winners, assign gifts, approve awards or record payment/delivery.
- An award cannot be approved by the same administrator who assigned it.
- Gift availability is checked and decremented under a transaction/row lock when an award is approved; duplicate assignment or approval must not consume stock twice.
- Payment and delivery are separate append-only events with idempotency keys. A payment event must not imply delivery, and a delivery event must not imply payment.
- Audit event and notification writes occur in the same transaction as the state change.
- No reward amount, eligibility, winner or fulfilment status is hardcoded in the dashboard.

## Delivery slices

### R1 — Programme and rule management
- [x] Add ABH-admin-only API endpoints to list and create reward programmes.
- [x] Require an existing OPEN reporting period; new programmes start in DRAFT.
- [x] Add ABH-admin-only rule listing and version creation, with immutable prior versions and one active version per rule key.
- [x] Validate strict request fields, programme/rule keys, rank boundaries and minimum quantities.
- [x] Write audit events in the same transaction as programme/rule changes.
- [x] Add typed frontend API methods and contract tests.
- [x] Add the administrator programme/rule management screen.
- [x] Add explicit activation; require at least one active rule and an OPEN reporting period, with an audit event.
- [x] Add audited programme editing, restricted to DRAFT programmes in OPEN periods.
- [x] Run full CI and resolve any failures (GitHub Actions run #339 passed frontend, PHP, migration-safety, MySQL 8.0 and MariaDB 11.4 checks; deployment remained skipped).

- List programmes by month and status.
- Create/update a programme and its description for an existing reporting period.
- Add versioned eligibility rules supporting rank range and minimum approved quantity.
- Validate rank ranges, quantities, duplicate rule keys and period/programme state.
- Require `rewards.manage`; audit every create/update/version operation.

### R2 — Qualification preview and evaluation
- [x] Preview eligibility against frozen leaderboard snapshots only (read-only GET endpoint).
- [x] Show eligible and ineligible agents with frozen rank, approved quantity and matched rule version.
- [x] Add explicit evaluation and immutable per-programme/agent qualification snapshots, including the tested rule versions and source values.
- [x] Restrict preview/evaluation to ACTIVE programmes in CLOSED periods with active rules.
- [x] Make evaluation idempotent; duplicate requests do not insert duplicate qualifications.
- [x] Write qualification audit and agent notifications in the evaluation transaction.
- [x] Add reward-manager UI, typed client methods and contract tests.
- [x] Run full CI and resolve any failures (GitHub Actions run #339 passed frontend, PHP, migration-safety, MySQL 8.0 and MariaDB 11.4 checks; deployment remained skipped).

Rule selection is deterministic: the first matching active rule ordered by `rule_key` ascending is recorded as the qualification's selected rule. All active rule versions tested are retained in the qualification snapshot.

### R3 — Gift catalogue, award assignment and approval
- [x] Create and maintain programme-specific gifts, stock, estimated values and active/inactive status, with audit records.
- [x] Assign gifts only to evaluated eligible agents and preserve gift title, description, quantity and value snapshots.
- [x] Require `rewards.manage` to create/update/assign and `rewards.approve` to approve.
- [x] Enforce separation of duties between assigning and approving administrators.
- [x] Check and decrement finite stock while locking the award and gift rows in one transaction.
- [x] Notify the agent and audit assignment/approval in the same transaction.
- [x] Run CI and resolve all failures (GitHub Actions run #43764701273 passed frontend lint/tests, PHP lint, migration safety/planning, MySQL 8.0 and MariaDB 11.4 checks; cPanel deployment remained skipped).

### R4 — Payment and delivery fulfilment
- [x] Record payment and delivery as distinct append-only event types; neither implies the other.
- [x] Validate positive payment amount/currency and delivery quantity, plus optional reference and note.
- [x] Enforce award quantity/value ceilings and currency consistency under an award row lock.
- [x] Add per-award UUID idempotency keys and reject key reuse with changed event data.
- [x] Write audit and agent notification records in the same transaction as each event.
- [x] Expose the immutable event history in the administrator reward interface.
- [x] Run CI and resolve all failures (GitHub Actions run #43764701273 passed frontend lint/tests, PHP lint, migration safety/planning, MySQL 8.0 and MariaDB 11.4 checks; cPanel deployment remained skipped).

### R5 — Agent reward view and release gate
- [x] Add a signed-in-agent-only reward endpoint. The server derives the agent ID from the authenticated ABH session; callers cannot request another agent's records.
- [x] Show available programme status, the agent's own frozen qualification outcome/rank/approved quantity, assigned gift snapshots and award approval state.
- [x] Show payment and delivery progress independently, including partial payment and partial delivery; expose no administrator controls in the agent portal.
- [x] Add typed client methods, agent portal loading/error/empty states, refresh control and contract tests for route authentication, agent scoping, status derivation, closed-period assignment guard, row locks and idempotent replay/conflict behavior.
- [x] Extend disposable database checks for reward permission grants, separate PAYMENT/DELIVERY totals and duplicate fulfilment idempotency-key rejection. MySQL 8.0 and MariaDB 11.4 both passed 139 schema checks in GitHub Actions run #43770806923.
- [ ] Complete true concurrent multi-connection stock-allocation and application-level lifecycle integration tests on both database engines; current contract tests verify row-lock code paths and database tests verify the schema/idempotency constraint.
- [ ] Complete browser-to-preview-database runtime verification, administrator account/role management, and leaderboard month-selection/frozen-history checks before signing off the full release gate.
- [ ] Keep cPanel deployment disabled until all remaining release-gate checks and isolated staging runtime verification pass.

## Acceptance gate

A reward release is not complete until an administrator can configure a programme and versioned rules, preview and evaluate eligibility from frozen monthly results, assign and independently approve gifts, record payment and delivery separately, and an agent can view only their own outcomes. All state changes must be auditable and safe under retries and concurrent requests.
