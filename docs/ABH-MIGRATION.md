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
11. Reward eligibility is calculated from the configured period rules. Management selects winners from the eligible list and assigns gifts; payment and delivery are separate recorded actions.
12. Administrative actions, including agent status changes, review decisions, target changes and reward decisions, must have an audit trail.

## Staging isolation

The approved preview environment is:
- Preview host: monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng
- Preview document root: /home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/
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
- Run CI checks on pushes and pull requests.
- Prepare a guarded cPanel deployment that can target only the preview host and the dedicated staging private directory.
- Keep the deployment disabled until the ABH application, private-path resolution and preview layout have passed the readiness gate.

Gate: validation runs independently; deployment is skipped unless the repository variable is explicitly enabled, a staging-readiness file exists, dedicated preview-only FTPS accounts are configured, and destination-path guards pass. No production deployment destination or credentials are supported by this workflow.

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
- Verify the application resolves only /home/abhgloba/abhpreview_private_storage/ for private code, configuration and uploads.
- Create two separate FTPS accounts: one restricted to the preview document root and one restricted to the staging private directory. Neither account may access the production tree.
- Configure the GitHub Actions variables and secrets described in docs/ABH-CPANEL-AUTO-DEPLOY.md.
- Add docs/ABH-PREVIEW-DEPLOYMENT-READY.md only after the ABH build, PHP path resolver, preview .htaccess/.user.ini, storage layout and staging database isolation have been tested.
- Enable automatic deployment only after the readiness file exists. A successful push then deploys the validated frontend/API files and private PHP application files to staging over FTPS.
- The workflow never executes SQL migrations, changes Cron Jobs, uploads .env, or touches production. Review and apply staging migrations separately after a verified staging backup.

Gate: preview homepage responds successfully, .env is not publicly readable, API routes return application responses, private files remain outside the document root, and the staging acceptance checklist passes. Production deployment requires a separate explicit approval and plan.

## Current operating rule

The cPanel deployment job is prepared but locked by default. No cPanel uploads, database migrations, cron changes or production changes are allowed until the ABH preview readiness gate is complete.
