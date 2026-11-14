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

The five ABH migrations passed disposable MySQL 8.0 and MariaDB 11.4 CI compatibility tests. Those tests did not apply migrations to cPanel. No migration has been applied to the cPanel staging database.

## 4. Application readiness — remaining blocker

Status: **NOT READY FOR APPLICATION DEPLOYMENT**

The current feature branch still contains the legacy FieldPulse application contract in important paths:

- `src/pages/RegisterPage.tsx` still asks for a username and NIN.
- `private_storage/app/Domain/AgentRegistrationController.php` still writes to the legacy `agents` table and requires username/NIN.
- `src/pages/LoginPage.tsx` still authenticates through the legacy FieldPulse login flow.
- The React route tree still exposes the legacy capture/queue/review/rewards experience.
- The new `abh_*` tables exist only in the ABH migration set; no ABH-specific application controllers currently use them.

Therefore the present build is not yet the ABH Monthly Enrollment Agent Website and Admin Website requested by the product requirements. Uploading it as if it were the completed ABH system would put the wrong application on the preview URL.

## 5. Required work before application deployment

1. Implement the ABH-specific phone/password registration and authentication flow against `abh_agents` and `abh_agent_credentials`; IMEI remains device metadata, not a password.
2. Implement ABH agent approval, suspension, device authorization and role separation against the ABH tables.
3. Implement ABH enrollment submission, private evidence storage, correction/resubmission and admin review against `abh_enrollment_submissions`, revisions, evidence and reviews.
4. Implement monthly targets, approved totals, leaderboard finalization, reward eligibility, management award decisions, payment/delivery and audit records.
5. Complete agent/admin browser end-to-end tests and the required PHP/database release gate.
6. Create the deployment readiness sign-off only after the live preview bootstrap and staging configuration are verified, and the ABH application release gate passes.

## 6. Current deployment controls

Keep the following policy:

- `ABH_PREVIEW_DEPLOY_ENABLED=false`
- `ABH_PREVIEW_DRY_RUN=true`

Do not upload the legacy FieldPulse build to the ABH preview URL. The hosting/FTPS path is now verified; the remaining work is the actual ABH application implementation and its release evidence.
