# ABH Preview Deployment Status

Last verified: 2026-11-14  
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

The infrastructure checks only wrote and deleted uniquely named temporary text files in the two dedicated preview directories. They did not upload application files, overwrite or delete application files, create or modify database tables, execute SQL migrations, create or change cPanel Cron Jobs, or access the production FTP account, production private storage or production database.

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

Migrations 001–008 passed disposable MySQL 8.0 and MariaDB 11.4 CI compatibility tests. Migration 008 passed 132 checks on each engine in GitHub Actions run #43625507763. These tests ran against disposable CI databases; they did not apply migrations to cPanel. **No migration has been applied to the cPanel staging database.**

## 4. Implemented application slices

The ABH-specific registration, agent authentication and initial administrator/agent approval slices are implemented and CI-verified.

### Agent registration and authentication

- `src/pages/RegisterPage.tsx` collects full name, Nigerian phone, password, IMEI, device type and optional email/location. It does not ask for username or NIN.
- Registration creates a non-extractable P-256 browser key, sends only the public JWK, and proves possession before the server stores the device in PENDING status.
- Registration uses the dedicated `/api/v1/abh/agents/register.php` endpoint. The legacy FieldPulse registration route remains unchanged.
- The ABH repository transaction creates the account, password hash, pending device key, audit event and active-admin notifications in ABH-owned tables.
- Migration 006 adds normalized-phone and global registered-device IMEI uniqueness plus hashed registration throttling.
- Migration 007 adds browser device UUID/JWK columns, ABH-only access/refresh session records, one-time replay nonces and an agent login-attempt ledger.
- Agent login uses phone/password plus proof of the registered browser key. The account and device must both be ACTIVE. Pending, rejected, suspended and device-revoked accounts are denied.
- Refresh tokens rotate; reuse revokes the token family and bound device. ABH requests use the dedicated `AbhAuthenticator`; ABH tokens are not sent to legacy FieldPulse authentication tables.
- Session restoration uses a cross-tab Web Lock to prevent concurrent use of the same single-use refresh cookie. If the browser's private key is lost, its session is ended and an administrator must authorize a replacement device.
- The authenticated app temporarily blocks legacy FieldPulse capture, queue, review and reward screens until their ABH-specific counterparts are implemented.

### Administrator authentication and agent/device review

- Migration 008 adds administrator-only opaque access/refresh sessions, an administrator login-attempt ledger, a locked first-admin bootstrap guard, and seeded system roles and permissions.
- Administrator sign-in and refresh use separate `abh_admin_*` tables, cookies, in-memory tokens, Web Locks and BroadcastChannel. Agent credentials and legacy FieldPulse admin credentials are not accepted.
- The first SUPER_ADMIN can be created only when `ABH_ADMIN_BOOTSTRAP_KEY` is configured in the private `.env` file. The database guard permits only one first-admin creation, including concurrent requests. Remove the environment key after successful setup; the guard also blocks later bootstrap attempts.
- There is no public administrator self-registration.
- The independent `/admin` portal provides administrator sign-in, first-admin setup, role-aware agent application search and review, and agent/device details.
- Agent approve/reject/suspend/reactivate decisions require the relevant permission and valid state transition. Rejection and suspension require a reason.
- Device approval and revocation are independent of the agent account decision. Device approval requires the agent account to be ACTIVE. Revoking a device or suspending/rejecting an agent revokes active agent sessions.
- Agent/device status changes, corresponding notifications and audit entries are committed together in database transactions. Administrator roles and permissions are read from the ABH database on every private admin request.

### Latest CI evidence

GitHub Actions run #43625507763 passed:

- 11 frontend test files, including 9 ABH administrator contract tests.
- Frontend lint completed with warnings but no errors.
- PHP syntax checks passed.
- 5 bootstrap isolation checks passed.
- 19 migration-planning checks passed.
- Frontend production build completed.
- 132 MySQL 8.0 schema compatibility checks passed.
- 132 MariaDB 11.4 schema compatibility checks passed.
- The cPanel deployment job was **skipped** by the deployment safety guard.

Run: https://github.com/Kadiya01/FieldPulse/actions/runs/43625507763

## 5. Overall application readiness

Status: **NOT READY FOR APPLICATION DEPLOYMENT**

The registration, agent authentication and initial administrator/agent/device approval slices are not the complete ABH Monthly Enrollment System. No live preview application deployment, cPanel migration, or browser-to-staging-database end-to-end runtime test has been performed.

The following work remains:

- Complete the agent website dashboard, monthly enrollment submission form, private evidence upload, submission history, correction/resubmission and notifications.
- Complete administrator submission review, rejection/correction requests and audited approval or adjustment.
- Complete organisation/individual/team targets, monthly totals, leaderboard computation and period-close/finalization.
- Complete reward programmes, automatic qualification, management award decisions, payment/delivery records and their admin screens.
- Complete administrator account/role management beyond first-admin provisioning.
- Add end-to-end tests across the agent and admin websites, then run runtime checks against the isolated preview database.
- Produce a deployment readiness sign-off only after preview configuration, bootstrap, migration status and the full application release gate are verified.

Do not upload this intermediate build as the completed ABH system.

## 6. Current deployment controls

Keep both controls unchanged:

- `ABH_PREVIEW_DEPLOY_ENABLED=false`
- `ABH_PREVIEW_DRY_RUN=true`

Production remains untouched. Do not apply migrations or upload application files to cPanel until the remaining ABH pages, APIs, runtime tests and release sign-off are complete.
