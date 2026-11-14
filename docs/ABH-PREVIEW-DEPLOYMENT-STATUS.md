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
- FTPS dry-run comparison of the public and private deployment bundles passed in GitHub Actions run #2: https://github.com/Kadiya01/FieldPulse/actions/runs/43615732629
- A real temporary write/read/delete round trip passed for each restricted FTP account.
- The public temporary marker was served with the expected content by the preview HTTPS hostname, proving that the public FTP root maps to the preview website document root.
- Both temporary marker files were deleted successfully.

Webroot probe: https://github.com/Kadiya01/FieldPulse/actions/runs/43617301156

The probe's safety check confirmed the deployment switch remained disabled and the dry-run setting remained enabled (using the workflow's fail-closed defaults where repository variables were not present in the Actions environment).

## 2. Deployment boundary

**No application deployment has occurred.**

The checks above only wrote and deleted uniquely named temporary text files in the two dedicated preview directories. They did not:

- upload application files;
- overwrite or delete application files;
- create or modify a database table;
- execute SQL migrations;
- create or change cPanel Cron Jobs;
- access the production FTP account, production private storage or production database.

The main workflow continues to require both an explicit deployment-enable variable and a signed-off readiness file. Do not bypass that gate.

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

Migrations 001–006 passed disposable MySQL 8.0 and MariaDB 11.4 CI compatibility tests (98 checks per engine before the authentication migration). Migration 007 adds ABH browser-key binding, opaque agent sessions, replay nonces and a dedicated login-attempt ledger; it is awaiting its next schema-compatibility run. These tests use disposable CI databases; they do not apply migrations to cPanel. No migration has been applied to the cPanel staging database.

## 4. Application readiness — remaining blocker

Status: **NOT READY FOR APPLICATION DEPLOYMENT**

The first ABH application slice is now implemented and CI-verified:

- `src/pages/RegisterPage.tsx` collects full name, Nigerian phone, password, IMEI, device type and optional email/location. It does not ask for username or NIN.
- Registration creates a non-extractable P-256 browser key, sends only the public JWK, and proves possession before the server stores the device in PENDING status.
- The form calls the dedicated `/api/v1/abh/agents/register.php` endpoint. The legacy registration route remains unchanged.
- `AbhAgentRegistrationController` and `AbhAgentRepository` write only to ABH-owned tables; the account, password hash, pending device key, audit event and admin notifications are created transactionally.
- Migration 006 adds unique normalized phone and globally unique IMEI constraints plus hashed ABH registration throttling.
- Migration 007 adds browser device UUID/JWK columns, ABH-only access/refresh session records, one-time nonces and a login-attempt ledger.
- ABH agent login now uses phone/password plus proof of the registered browser key. Pending/rejected/suspended agents and non-active devices are denied. Refresh tokens rotate; reuse revokes the token family and device.
- ABH requests are authenticated by a dedicated `AbhAuthenticator`; ABH tokens are not sent to legacy FieldPulse authentication tables.
- The authenticated app temporarily blocks legacy FieldPulse screens until ABH dashboard, submission and admin APIs are implemented.
- GitHub Actions run #62 is the last previously verified release run; Phase 2 commits and migration 007 still require a new successful CI run before this section can be marked verified.

The overall build is still **NOT READY** for deployment as the complete ABH Monthly Enrollment System:

- ABH agent authentication code is implemented but migration 007 and the new contract tests are still awaiting CI verification.
- ABH admin authentication, agent approval/rejection/suspension, and device authorization are not yet connected to the new ABH tables.
- The old capture/queue/review/rewards route tree still exists; the ABH enrollment reporting and management pages are not yet complete.
- ABH submissions, evidence, reviews, target management, final monthly leaderboard, reward management and payment/delivery flows still need their ABH-specific controllers and end-to-end tests.

Do not upload this intermediate build as the completed ABH system. The registration foundation is ready for the next development phase, but the main deployment gate must remain locked.

## 5. Required work before application deployment

1. Finish CI verification and runtime tests for ABH phone/password login, browser-key proof, revocable sessions and replay protection. Pending, rejected and suspended agents must be denied; IMEI remains device metadata, not a password.
2. Implement ABH admin authentication, agent approval, suspension, device authorization and role separation against the ABH tables.
3. Implement ABH enrollment submission, private evidence storage, correction/resubmission and admin review against `abh_enrollment_submissions`, revisions, evidence and reviews.
4. Implement monthly targets, approved totals, leaderboard finalization, reward eligibility, management award decisions, payment/delivery and audit records.
5. Complete agent/admin browser end-to-end tests and the required PHP/database release gate.
6. Create the deployment readiness sign-off only after the preview bootstrap and staging configuration are verified, and the complete ABH application release gate passes.

## 6. Current deployment controls

Keep the following policy:

- `ABH_PREVIEW_DEPLOY_ENABLED=false`
- `ABH_PREVIEW_DRY_RUN=true`

Do not upload the legacy FieldPulse build to the ABH preview URL. The hosting/FTPS path is now verified; the remaining work is the actual ABH application implementation and its release evidence.
