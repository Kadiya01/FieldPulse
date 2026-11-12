# ABH Monthly Enrollment System — Migration Baseline

## Product direction

This branch is being redesigned as the ABH Monthly Enrollment System. It is not a continuation of the old FieldPulse weekly field-verification product.

The system has two applications:
- Agent Website: agent registration, approval-aware access, monthly enrollment submissions, evidence, performance, leaderboard, rewards, notifications and history.
- Admin Website: operational dashboard, agent management, submission review, target configuration, monthly rankings, reward configuration and award/payment records.

## Non-negotiable business rules

1. Self-registration creates an account in Pending Approval.
2. Pending, rejected and suspended agents cannot submit enrollment activity.
3. IMEI is device-registration metadata, not a password or sole authentication factor.
4. The server derives the submitting agent identity from the authenticated session.
5. A submission records quantity, reporting month, evidence, submission time, reference and review history.
6. Submission review supports Pending Review, Approved, Rejected and Correction Requested. Corrections must preserve the previous version and audit history.
7. Only approved quantities contribute to monthly totals, target progress and leaderboard positions.
8. Approval and any reversal must be transactional, permission-checked and audited. Duplicate counting must be prevented.
9. Target progress is approved enrollments divided by the applicable monthly target, multiplied by 100. A missing or zero target is shown as not configured, not as an infinite percentage.
10. Monthly rankings and reward decisions must be preserved when a reporting period is closed.
11. Reward eligibility is calculated from the configured period rules. Winner approval and payment/delivery are separate recorded actions.
12. Administrative actions, including agent status changes, review decisions, target changes and reward decisions, must have an audit trail.

## Staging isolation

The approved preview environment is:
- Preview host: monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng
- Staging database: abhgloba_abhpreview
- Private staging directory: /home/abhgloba/abhpreview_private_storage/

Production remains out of scope:
- Production host: monthlyperformance.abhglobalinformationtechnologysolutions.com.ng
- Production database: abhgloba_fieldpulse
- Production private directory: /home/abhgloba/private_storage/

Do not point staging code, credentials, uploads, scheduled jobs or deployment actions at production resources. Do not commit secrets or environment files.

## Implementation phases and acceptance gates

### Phase 1 — Safety and baseline
- Keep the feature branch isolated from production.
- Run CI checks only; no automatic FTP or production deployment.
- Document the product rules and staging boundaries.

Gate: workflow contains no deployment action, no production URL and no deployment secrets.

### Phase 2 — Data model and migration plan
- Inspect the existing schema and identify reusable tables without assuming old workflow semantics are correct.
- Design explicit agent status, monthly submission/revision, review decision, target, period snapshot, reward qualification and audit records.
- Define unique constraints and transaction boundaries for approvals and reversals.
- Prepare reversible, additive migrations and a staging-only backup/restore procedure.

Gate: schema review and migration tests pass against the staging database only.

### Phase 3 — Agent Website
- Implement registration and approval-aware authentication.
- Implement monthly submission, evidence upload, correction/resubmission and history.
- Implement agent dashboard, performance, leaderboard and rewards views.

Gate: automated tests prove access restrictions, agent data isolation, evidence validation and no double counting.

### Phase 4 — Admin Website
- Implement dashboard from real database values.
- Implement agent management, review queue, targets, period closing, leaderboard snapshots and reward administration.

Gate: role/permission tests, audit tests and transactional review tests pass.

### Phase 5 — Staging deployment and acceptance
- Configure a dedicated staging-only deployment after paths and credentials are verified.
- Confirm the PHP application bootstrap resolves only the staging private directory.
- Run end-to-end tests on the preview host.

Gate: staging acceptance checklist passes. Production deployment requires a separate explicit approval and plan.

## Current operating rule

Until the staging bootstrap and deployment path are proven safe, make no cPanel uploads, database migrations, cron changes or production changes.
