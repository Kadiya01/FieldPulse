# ABH cPanel Preview — Automatic Deployment

## Purpose and scope

This workflow prepares automatic deployment from the `feature/abh-monthly-enrollment` branch to the ABH preview site only:

- Preview document root: `/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/`
- Preview private directory: `/home/abhgloba/abhpreview_private_storage/`
- Preview database: `abhgloba_abhpreview`

Production is explicitly excluded. Do not add production FTP credentials, production paths or production database credentials to this workflow.

## How it works

1. A push to `feature/abh-monthly-enrollment` runs lint, unit tests and the frontend build.
2. The deployment job can run only after validation succeeds and all readiness switches are deliberately enabled.
3. The frontend build in `dist/` is uploaded to the preview document root.
4. The private `app/`, `bin/`, `migrations/` and `workers/` directories are uploaded through a separate FTP account restricted to the preview private directory.
5. The workflow never executes SQL migrations, edits `.env`, changes cPanel Cron Jobs, or deploys to production.

Pull requests run checks only. A manual workflow run also requires the `deploy_preview` checkbox and the same readiness switches.

## Important: deployment is intentionally locked at first

The following repository Actions Variables must exist and have these exact values before an automatic deployment can happen:

| Variable | Initial value | Set to enable deployment |
|---|---|---|
| `ABH_PREVIEW_DEPLOY_ENABLED` | `false` or unset | `true` |
| `ABH_PREVIEW_BOOTSTRAP_READY` | `false` or unset | `true`, only after PHP entry points are proven to load the preview private directory and never production |
| `ABH_PREVIEW_ABH_RELEASE_READY` | `false` or unset | `true`, only after the deployed application is actually the ABH Monthly Enrollment System and its release gate passes |
| `ABH_PREVIEW_DRY_RUN` | `true` or unset | Keep `true` for a dry run; set to `false` only when ready to upload |

**Do not enable the bootstrap or ABH release switch yet.** The current code still contains legacy FieldPulse path discovery and application behavior. In particular, existing PHP entry points search for a directory named `private_storage`, while the preview private directory is named `abhpreview_private_storage`. That could resolve the production private directory. The bootstrap must first be changed to use an explicit, validated preview path. Also, ABH application code must replace the legacy product behavior before publishing it.

With all readiness switches enabled and secrets configured, every successful push to the feature branch automatically deploys to the preview site. The workflow defaults to dry-run unless `ABH_PREVIEW_DRY_RUN=false`.

## cPanel FTP accounts — least privilege

Create two dedicated FTP accounts in cPanel. Do not use the main cPanel account login and do not create an FTP account rooted at the whole account home directory.

### 1. Public preview FTP account

In cPanel → FTP Accounts, create an account whose **Directory** field is:

```text
public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng
```

cPanel interprets this as a path relative to the account home directory, so its resulting access root must be:

```text
/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/
```

This account must not be able to navigate to other domains or the account home directory. Its only purpose is to upload the built frontend into the preview document root.

### 2. Private preview FTP account

Create a second FTP account whose **Directory** field is:

```text
abhpreview_private_storage
```

Its resulting access root must be:

```text
/home/abhgloba/abhpreview_private_storage/
```

This account must not be able to access `/home/abhgloba/private_storage/` or any production directory. It uploads application code only.

Use explicit FTPS (FTP over TLS), not unencrypted FTP. In cPanel's **Configure FTP Client** panel, use the server hostname and port shown in its Manual Settings; do not assume the website domain is the correct TLS hostname. The workflow defaults to port 21; override it with the Actions Variable below if cPanel shows another FTPS port. If explicit FTPS is not available, stop and ask the hosting provider before choosing a different transport.

## GitHub configuration

### Create the deployment environment

In the repository, open Settings → Environments and create an environment named:

```text
abh-preview
```

Restrict deployments for this environment to the `feature/abh-monthly-enrollment` branch. Environment approval rules may be added later if a human approval should be required before each deployment.

### Add environment secrets

Under the `abh-preview` environment, add these secrets. Never put their values in source files or chat.

| Secret | Value |
|---|---|
| `ABH_PREVIEW_FTP_SERVER` | FTPS hostname from cPanel/hosting provider |
| `ABH_PREVIEW_PUBLIC_FTP_USERNAME` | Username of the public preview FTP account |
| `ABH_PREVIEW_PUBLIC_FTP_PASSWORD` | Password of the public preview FTP account |
| `ABH_PREVIEW_PRIVATE_FTP_USERNAME` | Username of the private preview FTP account |
| `ABH_PREVIEW_PRIVATE_FTP_PASSWORD` | Password of the private preview FTP account |

### Add repository Actions Variables

Under Settings → Secrets and variables → Actions → Variables, add:

| Variable | Initial value |
|---|---|
| `ABH_PREVIEW_DEPLOY_ENABLED` | `false` |
| `ABH_PREVIEW_BOOTSTRAP_READY` | `false` |
| `ABH_PREVIEW_ABH_RELEASE_READY` | `false` |
| `ABH_PREVIEW_DRY_RUN` | `true` |
| `ABH_PREVIEW_FTP_PORT` | `21` (or the confirmed FTPS port) |

The readiness variables are deliberate safety interlocks. Do not switch them on simply to make a workflow run.

## Deployment boundaries

- `dist/` goes to the public preview FTP account's root.
- `public_html/.htaccess`, `public_html/.user.ini` and `public_html/api/` are excluded from frontend upload and remain managed separately.
- Private application code is uploaded by folder to the private preview FTP account.
- `.env`, uploaded evidence, logs, runtime data and database files are not part of the upload set.
- Migration SQL files may be uploaded to the preview private directory, but this workflow never executes them.
- cPanel Cron Jobs are not created, modified or removed by this workflow.
- No production FTP login, database login or production filesystem path belongs in GitHub Actions.

## First deployment procedure

1. Keep all readiness switches false while the application is being redesigned.
2. Fix and test the PHP bootstrap so every public entry point resolves only `/home/abhgloba/abhpreview_private_storage/app/bootstrap.php` on preview. Add a test that fails if it resolves the production path.
3. Complete the ABH application and pass its CI/release acceptance checks.
4. Create the two restricted FTPS accounts and configure the GitHub environment secrets.
5. Set `ABH_PREVIEW_BOOTSTRAP_READY=true` and `ABH_PREVIEW_ABH_RELEASE_READY=true` only after their respective checks pass.
6. Set `ABH_PREVIEW_DEPLOY_ENABLED=true`, leave dry-run enabled, and push a harmless commit to inspect the planned file changes in the Actions log.
7. Confirm the planned targets are the preview document root and preview private directory only. Then set `ABH_PREVIEW_DRY_RUN=false` and push the approved commit.
8. Verify the preview website and its API responses. Do not run database migrations automatically; migration execution requires a separate staging backup and explicit go-ahead.

If the hosting provider does not support explicit FTPS, stop and choose a verified transport before adding credentials. Do not silently downgrade to plain FTP.
