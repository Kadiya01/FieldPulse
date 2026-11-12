# ABH Monthly Enrollment System — cPanel Auto-Deployment

## Status

The GitHub Actions workflow is prepared for automatic deployment to the ABH preview/staging cPanel environment only. It remains locked until the ABH application is ready.

On pushes to the feature branch, validation runs first. A qualifying push then builds a deployment bundle containing:
- Public bundle: Vite dist/ plus PHP API entry points from public_html/api/.
- Private bundle: private_storage/app/, bin/, migrations/ and workers/.

The workflow does not package .env, uploaded evidence, logs, or runtime storage. The root .htaccess and .user.ini are excluded from upload and must be installed and verified separately in cPanel first.

## Safety switches

Automatic deployment requires all of the following:

1. The event is a push to feature/abh-monthly-enrollment, or a manual workflow run with its deploy_preview checkbox selected.
2. Repository variable ABH_PREVIEW_DEPLOY_ENABLED is true.
3. Repository variable ABH_PREVIEW_BOOTSTRAP_READY is true.
4. Repository variable ABH_PREVIEW_ABH_RELEASE_READY is true.
5. The repository contains docs/ABH-PREVIEW-DEPLOYMENT-READY.md.
6. The public and private FTP destination variables pass the staging path checks.
7. The separate public and private FTPS account credentials are configured.

ABH_PREVIEW_DRY_RUN defaults to true. In that mode, the FTP action prints the planned changes and does not upload files. The post-deployment HTTP check runs only when dry-run is set to false.

The deployment uploads private PHP files first and public files second. It uses explicit FTPS with certificate verification and does not use the dangerous clean-slate option. After a real deployment it checks that the preview homepage returns HTTP 200 and that /.env returns HTTP 403 or 404.

The workflow never executes SQL migrations, changes database users, edits Cron Jobs, uploads secrets, or deploys to production. Migration files may be uploaded to the staging private directory but must be reviewed and applied separately after a verified staging backup.

## cPanel account setup

Create two dedicated FTP accounts in cPanel. Do not use the main cPanel account login and do not create an FTP account rooted at the account home directory.

### Public preview FTP account

Set the cPanel FTP account's Directory field to:

public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng

Its effective access root must be:

/home/abhgloba/public_html/monthlyperformancepreview.abhglobalinformationtechnologysolutions.com.ng/

This account must not be able to navigate to other domains or the account home directory.

### Private preview FTP account

Set the second FTP account's Directory field to:

abhpreview_private_storage

Its effective access root must be:

/home/abhgloba/abhpreview_private_storage/

This account must not be able to access /home/abhgloba/private_storage/ or any production directory.

Use explicit FTPS (FTP over TLS), not unencrypted FTP. In cPanel's Configure FTP Client panel, use the server hostname and port shown in its Manual Settings. Do not assume the website domain is the correct TLS hostname. The workflow defaults to port 21; set the Actions Variable to another port only when cPanel confirms it. If explicit FTPS is unavailable, stop and ask the hosting provider before selecting another transport.

## GitHub configuration

### Create the deployment environment

In the repository, open Settings → Environments and create an environment named abh-preview. Restrict deployments for this environment to the feature/abh-monthly-enrollment branch. Approval rules may be added later if a human approval should be required before each deployment.

### Add environment secrets

Under the abh-preview environment, add:

| Secret | Value |
|---|---|
| ABH_PREVIEW_FTP_SERVER | FTPS hostname from cPanel/hosting provider |
| ABH_PREVIEW_PUBLIC_FTP_USERNAME | Username of the public preview FTP account |
| ABH_PREVIEW_PUBLIC_FTP_PASSWORD | Password of the public preview FTP account |
| ABH_PREVIEW_PRIVATE_FTP_USERNAME | Username of the private preview FTP account |
| ABH_PREVIEW_PRIVATE_FTP_PASSWORD | Password of the private preview FTP account |

Never put these values in source files, issues, or chat. Do not send FTP passwords to the assistant.

### Add repository Actions Variables

Under Settings → Secrets and variables → Actions → Variables, add:

| Variable | Initial value | When to change |
|---|---|---|
| ABH_PREVIEW_DEPLOY_ENABLED | false | Set true only when all deployment checks are complete |
| ABH_PREVIEW_BOOTSTRAP_READY | false | Set true only after PHP path resolution is tested against the preview private directory |
| ABH_PREVIEW_ABH_RELEASE_READY | false | Set true only after the ABH application and release checks pass |
| ABH_PREVIEW_DRY_RUN | true | Keep true for the first deployment plan; set false only when ready to upload |
| ABH_PREVIEW_PUBLIC_SERVER_DIR | FTP-visible preview document root, ending with / | Set after checking the public account's FTP root |
| ABH_PREVIEW_PRIVATE_SERVER_DIR | FTP-visible staging private directory, ending with / | Set after checking the private account's FTP root |
| ABH_PREVIEW_FTP_PORT | 21, or the port confirmed by cPanel | Set only to the confirmed explicit-FTPS port |

The destination variables must be paths as seen by each restricted FTP account. The public path must contain the preview hostname; the private path must contain abhpreview_private_storage. The workflow checks both.

## Readiness checks before enabling

Do not create docs/ABH-PREVIEW-DEPLOYMENT-READY.md or enable the readiness switches until all checks below are complete:

- The branch contains the ABH Agent Website and Admin Website, not legacy FieldPulse product behavior.
- Every API entry point resolves the application from /home/abhgloba/abhpreview_private_storage/app/bootstrap.php on preview and can never fall back to /home/abhgloba/private_storage/.
- The application resolves the actual preview document root correctly.
- Staging configuration reads only the abhgloba_abhpreview database. No production database fallback exists.
- The preview root .htaccess and PHP settings file are already installed and verified.
- Private storage is outside the document root and writable only by the application account.
- PHP version, extensions, and upload limits meet the application's requirements.
- Tests and build pass; a staging database backup is verified before any separately approved migration.
- Both FTP accounts are proven to be confined to their intended staging directories.

When all checks pass, add docs/ABH-PREVIEW-DEPLOYMENT-READY.md with the evidence and date. Then enable the readiness switches, keep dry-run true, and inspect the Actions plan. Only after confirming the planned changes are limited to the two preview directories should ABH_PREVIEW_DRY_RUN be set to false. After that, qualifying pushes automatically deploy to the preview host.

## Rollback

Use a reviewed revert commit on feature/abh-monthly-enrollment. The workflow will revalidate and publish that earlier state to the preview host. Do not manually delete the document root, use clean-slate deployment, or point this workflow at production.
