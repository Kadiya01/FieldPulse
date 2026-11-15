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
- Create and maintain programme-specific gifts, stock and estimated values.
- Assign gifts only to evaluated eligible agents.
- Require `rewards.manage` to assign and `rewards.approve` to approve.
- Enforce separation of duties between assigning and approving administrators.
- Preserve gift title, description, quantity and value snapshots on each award.

### R4 — Payment and delivery fulfilment
- Record partial or complete payment and delivery as distinct append-only events.
- Validate event quantity/amount, currency, reference and note.
- Enforce remaining award quantity/value and idempotency under transaction locks.
- Derive display status from award approval and recorded fulfilment totals; never overwrite history.

### R5 — Agent reward view and release gate
- Show an agent only their own programmes, qualification outcomes and awards.
- Distinguish upcoming, in progress, eligible, pending approval, approved, partially paid, paid, partially delivered and delivered.
- Test role permissions, closed-period immutability, duplicate requests, concurrent stock allocation and payment/delivery idempotency on MySQL and MariaDB.
- Keep cPanel deployment disabled until the complete reward lifecycle passes CI and staging runtime verification.

## Acceptance gate

A reward release is not complete until an administrator can configure a programme and versioned rules, preview and evaluate eligibility from frozen monthly results, assign and independently approve gifts, record payment and delivery separately, and an agent can view only their own outcomes. All state changes must be auditable and safe under retries and concurrent requests.
