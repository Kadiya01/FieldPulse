# ABH Preview Deployment Status

Last verified: 2026-11-15  
Repository: `Kadiya01/FieldPulse`  
Branch: `feature/abh-monthly-enrollment`  
Preview URL: https://monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/

## 1. Hosting and transport verification

Status: **INFRASTRUCTURE PRECHECK PASSED**

Evidence:

- cPanel public FTP root verified as `/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/`.
- cPanel private FTP root verified as `/home/abhgloba/abhpreview_private_storage/`.
- Both restricted FTP accounts authenticate over explicit FTPS on port 21.
- The correct FTPS server hostname is `nano.yahmis.com`. Using the FTP subdomain caused a strict TLS hostname mismatch; strict verification was retained and the hostname was corrected.
- FTPS dry-run comparison of public and private deployment bundles passed in GitHub Actions run #2: https://github.com/Kadiya01/FieldPulse/actions/runs/43615732629
- A real temporary write/read/delete round trip passed for each restricted FTP account.
- The public temporary marker was served with expected content by the preview HTTPS hostname, proving that the public FTP root maps to the preview website document root.
- Both temporary marker files were deleted successfully.

Webroot probe: https://github.com/Kadiya01/FieldPulse/actions/runs/43617301156

The probe confirmed that the deployment switch remained disabled and dry-run remained enabled. These checks did not deploy application files.

## 2. Deployment boundary

**No application deployment has occurred.**

The infrastructure checks only wrote and deleted uniquely named temporary text files in the two dedicated preview directories. They did not upload application files, overwrite or delete application files, create or modify cPanel database tables, execute SQL migrations, create or change cPanel Cron Jobs, or access the production FTP account, production private storage or production database.

The main workflow requires both an explicit deployment-enable variable and a signed-off readiness file. Do not bypass that gate.

## 3. Staging database target

The dedicated ABH staging database target is:

```text
abhgloba_abhpreview
```

The dedicated migration runner is bound to:

- Database: `abhgloba_abhpreview`
- Database user: `abhgloba_abhpreview`
- Preview URL: `https://monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng`
- Private root: `/home/abhgloba/abhpreview_private_storage`

Migrations 001–011 passed disposable MySQL 8.0 and MariaDB 11.4 CI compatibility tests. Migration 009 adds per-submission revision idempotency for correction resubmissions. Migration 010 seeds the separate `periods.close` permission and widens the frozen organisation progress percentage. Migration 011 adds idempotency keys to reward fulfilment events. The complete schema suite passed **136 checks on each engine** in GitHub Actions run [#292](https://github.com/Kadiya01/FieldPulse/actions/runs/43630400560). These checks ran against disposable CI databases; they did not apply migrations to cPanel. **No migration has been applied to the cPanel staging database.**

## 4. Implemented application slices

The following ABH slices are implemented on the feature branch and passed the application and schema CI gates. These are code-level and disposable-database test results, not a deployed or runtime-verified website.

### Agent registration and authentication

- `src/pages/RegisterPage.tsx` collects full name, Nigerian phone, password, IMEI, device type and optional email/location. It does not ask for username or NIN.
- Registration uses a browser P-256 key pair, sends only the public JWK and proves possession before the server stores the device in PENDING status.
- Registration uses the dedicated `/api/v1/abh/agents/register.php` endpoint. The legacy FieldPulse registration route remains unchanged.
- The ABH repository transaction creates the account, password hash, pending device key, audit event and active-admin notifications in ABH-owned tables.
- Migration 006 adds normalized-phone and global registered-device IMEI uniqueness plus hashed registration throttling.
- Migration 007 adds browser device UUID/JWK columns, ABH-only access/refresh session records, one-time replay nonces and an agent login-attempt ledger.
- Agent login uses phone/password plus proof of the registered browser key. Both the agent account and device must be ACTIVE. Pending, rejected, suspended and device-revoked accounts are denied.
- Refresh tokens rotate; reuse revokes the token family and bound device. ABH requests use the dedicated `AbhAuthenticator`; ABH tokens are not sent to legacy FieldPulse authentication tables.
- Session restoration uses a cross-tab Web Lock to prevent concurrent use of the same single-use refresh cookie. If the browser's private key is lost, its session is ended and an administrator must authorize a replacement device.

### Administrator authentication and agent/device review

- Migration 008 adds administrator-only opaque access/refresh sessions, an administrator login-attempt ledger, a locked first-admin bootstrap guard, and seeded system roles and permissions.
- Administrator sign-in and refresh use separate `abh_admin_*` tables, cookies, in-memory tokens, Web Locks and BroadcastChannel. Agent credentials and legacy FieldPulse admin credentials are not accepted.
- The first SUPER_ADMIN can be created only when `ABH_ADMIN_BOOTSTRAP_KEY` is configured in the private `.env` file. The database guard permits only one first-admin creation, including concurrent requests. Remove the environment key after successful setup; the guard also blocks later bootstrap attempts.
- There is no public administrator self-registration.
- The independent `/admin` portal provides administrator sign-in, first-admin setup, role-aware agent application search and review, and agent/device details.
- Agent approve/reject/suspend/reactivate decisions require the relevant permission and valid state transition. Rejection and suspension require a reason.
- Device approval and revocation are independent of the agent account decision. Device approval requires the agent account to be ACTIVE. Revoking a device or suspending/rejecting an agent revokes active agent sessions.
- Agent/device status changes, corresponding notifications and audit entries are committed together in database transactions. Administrator roles and permissions are read from the ABH database on every private admin request.
- The administrator overview uses the selected reporting month and reads agent counts, submission states, approved enrollment totals, organisation and agent targets, top agents, reward programme/qualification/award states, and payment/delivery event counts from ABH tables. Sections are omitted unless the administrator has the matching read/management permission.
- Dashboard performance is based on approved monthly totals; the sum of agent-claimed quantities is shown separately and is never presented as approved performance. The route is protected by ABH administrator authentication and its permission allowlist.

### Monthly enrollment, evidence and performance

- Agents can submit a reporting month, activity date, claimed enrollment quantity, optional note and JPEG/PNG evidence through the dedicated signed ABH submissions route.
- The request signature covers the exact JSON payload; the evidence file is independently checked against its SHA-256 hash and inspected server-side for allowed image type and size.
- Evidence is stored in private storage outside the public webroot. The database records the revision, file metadata and hash, not a public file URL.
- Agent history is restricted to the authenticated agent. It returns the current revision and latest reviewer note without exposing private storage paths.
- The agent portal displays server-calculated approved quantity, pending/rejected counts, monthly target, target percentage and live or frozen monthly rank. These figures do not count unapproved submissions as performance.
- Migration 009 gives each immutable revision a per-submission idempotency key. Initial uploads and corrected revisions can be retried without silently creating duplicate records.
- An agent may correct only their own submission when its status is `CORRECTION_REQUESTED` and the original reporting period is still OPEN. The corrected report keeps its original reporting month, adds a new revision and evidence record, returns to PENDING_REVIEW, and notifies authorized reviewers. Earlier revisions and evidence are retained.
- The agent portal displays the reviewer’s correction note and provides a “Correct and resubmit” action.

### Administrator enrollment review

- Administrators with `submissions.read` can filter the queue by month and status and inspect agent details, revision number, quantity and activity date.
- Evidence preview is served through a separate authenticated API. The server checks the private path, rejects symlinks/path escapes, and revalidates file MIME, size and SHA-256 before returning bytes.
- Administrators with `submissions.review` can approve, reject or request correction. Rejection and correction require a reason.
- Review decisions lock the submission and commit the review event, status transition, audit record and agent notification together.
- Approval increments the agent’s approved monthly total exactly once. A second decision on an already reviewed submission is rejected; identical retries with the same review idempotency key return the existing result.
- Review decisions are blocked once the reporting period is CLOSED.

### Monthly target management and period finalisation

- Administrators with `targets.manage` can set organisation-wide and individual monthly targets for active agents. Target changes are audited, serialized against period closure and blocked when a period is CLOSED.
- Administrators with `periods.close` can read month-close readiness and finalize a completed reporting month. The month must have fully elapsed and there must be no PENDING_REVIEW or CORRECTION_REQUESTED submissions.
- Closure recomputes approved quantities from the approved submission/revision ledger and compares them with `abh_monthly_agent_totals`. A mismatch blocks closure for reconciliation.
- In one transaction, closure writes immutable per-agent leaderboard snapshots and an organisation snapshot, marks the period CLOSED, writes an audit event and notifies active agents. The frozen snapshot preserves the agent code and display name as they were at close time.
- Closed periods cannot accept new submissions, corrected revisions, review decisions or target edits. The admin target page displays the frozen organisation quantity, target and progress.

## 5. Latest CI evidence

GitHub Actions run [#339](https://github.com/Kadiya01/FieldPulse/actions/runs/43758247464) passed on branch commit `9a4d9d113918505ec6165965f68409aed0cc4ae5`:

- Frontend lint and unit/contract tests passed.
- PHP application/API syntax checks passed.
- Preview bootstrap path isolation passed.
- ABH migration safety/planning checks passed.
- Frontend production build and build-output verification passed.
- MySQL 8.0 schema compatibility passed.
- MariaDB 11.4 schema compatibility passed.
- The cPanel deployment job was skipped because deployment is not enabled and release readiness is not signed off.

The workflow uploaded a deployment artifact only. It did not upload application files to cPanel, execute cPanel migrations, or configure Cron Jobs.

## 6. Current reward implementation status

- R1: programme and versioned eligibility-rule management implemented.
- R2: read-only qualification preview and explicit, idempotent evaluation from frozen leaderboard snapshots implemented.
- R3: gift catalogue create/update/deactivate, eligible-agent gift assignment, independent award approval, finite-stock locking, snapshots, notifications and audit events are implemented and CI-verified.
- R4: separate append-only payment and delivery events, value/quantity/currency checks, UUID idempotency (including stable client retries and decimal normalization), notifications, audit events, derived fulfilment statuses and administrator history UI are implemented and CI-verified.
- R5: the agent-scoped reward API and portal, separate payment/delivery progress, and release-gate contract coverage are implemented. CI verified frontend/PHP checks and 139 disposable schema checks on each supported database engine. True concurrent multi-connection stock-allocation/application lifecycle integration tests and isolated staging runtime verification remain outstanding.

The reward implementation plan is maintained in `docs/ABH-REWARDS-IMPLEMENTATION-PLAN.md`. R1–R4 have passing CI evidence; this is not evidence of staging runtime verification.

## 7. Deployment readiness and controls

Status: **NOT READY FOR APPLICATION DEPLOYMENT**

No live preview application deployment, cPanel database migration, or browser-to-staging-database end-to-end runtime test has been performed. R3 and R4 passed GitHub Actions run [#43764701273](https://github.com/Kadiya01/FieldPulse/actions/runs/43764701273) on commit `d9be619e8949616f50e6ec388fd1ff4306d83a9c`. Frontend lint/tests, PHP lint, preview isolation, migration safety/planning, production build, MySQL 8.0 and MariaDB 11.4 schema checks passed. The cPanel deployment job was skipped. R5 agent reward views and contract coverage are implemented and passed GitHub Actions run [#43770806923](https://github.com/Kadiya01/FieldPulse/actions/runs/43770806923) on commit `a260b1fc4f0ffcc0875736d6738d980a6553e209`. The validation job passed frontend lint/tests, PHP lint, preview isolation, migration safety/planning and production build. Disposable MySQL 8.0 and MariaDB 11.4 schema tests each passed 139 checks, including reward permission grants and fulfilment idempotency. The cPanel deployment job was skipped. True concurrent multi-connection stock-allocation/application lifecycle integration tests, administrator account/role management, leaderboard month-selection/frozen-history checks and browser-to-staging-database runtime verification remain outstanding. Administrator account/role management beyond first-admin provisioning and remaining leaderboard month-selection/frozen-history end-to-end checks also require completion.

Keep both controls unchanged:

- `ABH_PREVIEW_DEPLOY_ENABLED=false`
- `ABH_PREVIEW_DRY_RUN=true`

Do not upload the application, apply cPanel migrations, or create/change Cron Jobs until the remaining R5 concurrency/integration tests, administrator/leaderboard gaps, complete release gate, and isolated staging runtime verification have passed. Production remains untouched.
