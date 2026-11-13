# ABH cPanel Preview — Automatic Deployment

## Purpose

GitHub Actions is configured to deploy the ABH application to the preview cPanel site only:

- Preview URL: `https://monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng`
- Preview document root: `/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/`
- Preview private directory: `/home/abhgloba/abhpreview_private_storage/`
- Preview database: `abhgloba_abhpreview`

Production is excluded. Do not create production FTP secrets, production destination variables or production database credentials for this workflow.

## What the workflow does

1. Pushes to `feature/abh-monthly-enrollment` run lint, tests and the frontend build.
2. The validation job packages a deployment artifact containing:
   - built frontend files from `dist/`;
   - API entry points from `public_html/api/`;
   - `.htaccess` and `.user.ini` from `public_html/`;
   - private `app/`, `bin/`, `migrations/` and `workers/` code.
3. The deploy job runs only when `ABH_PREVIEW_DEPLOY_ENABLED=true` and the deployment-readiness file exists with all required confirmations.
4. Two separate FTPS accounts upload the public bundle and private bundle to their respective restricted directory roots.
5. The workflow never uploads `.env`, uploads evidence, logs or runtime data. It does not execute SQL migrations, change cPanel Cron Jobs or deploy to production.

Pull requests run validation only. A manual workflow run requires the `deploy_preview` checkbox and the same deployment-enable variable.

## Deployment locks — keep them off for now

The API entry points no longer perform independent upward searches. They now load the shared `public_html/api/v1/_bootstrap.php` resolver. On the preview host, that resolver requires the canonical preview document root and the dedicated `/home/abhgloba/abhpreview_private_storage/app/bootstrap.php` path. It rejects a missing staging bootstrap, a mismatched preview document root, a private directory inside the public document root, and a staging path that resolves through a symlink. It does not fall back to `/home/abhgloba/private_storage` on the preview host. Other local/non-preview environments retain the conventional sibling `private_storage` lookup.

GitHub Actions now runs PHP 8.2 syntax checks and five automated bootstrap-isolation tests, including the critical case where the staging bootstrap is missing. The latest validation run passed these tests. This is automated code-level evidence, not yet proof from a live cPanel request.

**The application behavior is still the legacy FieldPulse product, not the completed ABH Monthly Enrollment System.** The deployment remains locked until the ABH Agent Website and Admin Website replace the legacy behavior, the exact staging configuration is verified on the live preview host, and the ABH release gate passes.

The deployment is therefore locked by two independent checks:

1. The repository Actions Variable `ABH_PREVIEW_DEPLOY_ENABLED` must be `true`.
2. The file `docs/ABH-PREVIEW-DEPLOYMENT-READY.md` must exist and contain these exact lines:

```text
BOOTSTRAP_PATH_VERIFIED: true
ABH_RELEASE_GATE: PASS
STAGING_DATABASE: abhgloba_abhpreview
```

**Do not create the readiness file or enable deployment yet.** It is a sign-off record, not a placeholder. Add it only after the PHP bootstrap is proven to load the preview private directory and never production, the ABH application replaces the old FieldPulse behavior, and the ABH release gate passes.

The workflow defaults to FTPS dry-run unless the repository Actions Variable `ABH_PREVIEW_DRY_RUN` is set to `false`. The post-deployment HTTPS smoke check runs only after a real upload.

## cPanel FTP accounts — two restricted roots

Create two FTP accounts in cPanel → Files → FTP Accounts. Do not use the primary cPanel account FTP login, and do not create an account whose home is the entire `/home/abhgloba/` directory.

### Public preview FTP account

Set the cPanel **Directory** field to this path relative to the cPanel home directory:

```text
public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng
```

Confirm that the resulting access root is exactly:

```text
/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/
```

This account must not be able to access the parent `public_html/`, other domains or the account home.

### Private preview FTP account

Set the second account's **Directory** field to:

```text
abhpreview_private_storage
```

Confirm that its resulting access root is exactly:

```text
/home/abhgloba/abhpreview_private_storage/
```

It must not be able to access `/home/abhgloba/private_storage/` or any production directory.

Each FTP account's home is its own deployment target, so the workflow uploads to `./` for each account. This is why the accounts must be restricted to those exact directories.

Use explicit FTPS (FTP over TLS), not unencrypted FTP. In cPanel's **Configure FTP Client** panel, use the server hostname and port from its Manual Settings. Do not assume the website domain is the correct TLS hostname. If cPanel/hosting does not support explicit FTPS, stop and confirm the supported secure transport before adding any credentials.

## GitHub configuration

### Create the environment

In the repository, open Settings → Environments and create:

```text
abh-preview
```

Restrict deployment branches to `feature/abh-monthly-enrollment`.

### Add environment secrets

Add these secrets to the `abh-preview` environment. Use the full FTP usernames shown in cPanel. Never paste their values into source files or chat.

| Secret | Value |
|---|---|
| `ABH_PREVIEW_PUBLIC_FTP_SERVER` | Server hostname for the public FTP account |
| `ABH_PREVIEW_PUBLIC_FTP_USERNAME` | Full username of the public preview FTP account |
| `ABH_PREVIEW_PUBLIC_FTP_PASSWORD` | Password of the public preview FTP account |
| `ABH_PREVIEW_PRIVATE_FTP_SERVER` | Server hostname for the private FTP account |
| `ABH_PREVIEW_PRIVATE_FTP_USERNAME` | Full username of the private preview FTP account |
| `ABH_PREVIEW_PRIVATE_FTP_PASSWORD` | Password of the private preview FTP account |

The public and private FTP server hostnames may be the same if cPanel provides the same server hostname for both accounts.

### Add repository Actions Variables

Under Settings → Secrets and variables → Actions → Variables, add:

| Variable | Initial value |
|---|---|
| `ABH_PREVIEW_DEPLOY_ENABLED` | `false` |
| `ABH_PREVIEW_DRY_RUN` | `true` |
| `ABH_PREVIEW_FTP_PORT` | `21` or the FTPS port confirmed in cPanel |

Repository-level variables are used for the job's deployment gate. FTP credentials belong in the `abh-preview` environment secrets.

## First deployment procedure

1. Leave `ABH_PREVIEW_DEPLOY_ENABLED=false` while the ABH Agent Website and Admin Website are being completed.
2. Keep the shared bootstrap resolver and its automated tests passing. Before activation, verify through a request to the live preview host that it loads only `/home/abhgloba/abhpreview_private_storage/app/bootstrap.php` and returns a service-unavailable response rather than falling back if that file is absent.
3. Complete the ABH Agent Website and Admin Website implementation and pass the ABH release gate.
4. Verify the preview `.htaccess`, `.user.ini`, document root, private storage and staging database isolation.
5. Create the two restricted FTPS accounts and add the six environment secrets.
6. Add `docs/ABH-PREVIEW-DEPLOYMENT-READY.md` with the three exact sign-off lines only after all checks above pass.
7. Set `ABH_PREVIEW_DEPLOY_ENABLED=true` and leave `ABH_PREVIEW_DRY_RUN=true`. Push the approved commit or run the workflow manually with `deploy_preview=true`. Inspect the Actions log and confirm the two FTP users see only their assigned preview roots.
8. When the dry-run file plan is correct, set `ABH_PREVIEW_DRY_RUN=false` and run the workflow manually or push the approved release commit.
9. Confirm the preview homepage returns HTTP 200 and `.env` returns HTTP 403 or 404. Check the API routes separately.

Database migrations require a separate staging backup and explicit approval. cPanel Cron Jobs remain unchanged unless a separate task specifically authorizes a staging cron configuration.

## Safety rules

- Never use an FTP account scoped to the entire cPanel home directory.
- Never store FTP passwords, database passwords or `.env` files in Git.
- Never deploy from the default `main` branch using this workflow.
- Never enable deployment while the bootstrap or ABH release gate is incomplete.
- Never run SQL migrations from the GitHub Actions workflow.
- Never change production DNS, SSL, files, database or Cron Jobs as part of preview deployment.
