# FieldPulse — cPanel deployment

Target: PHP 8.2, MySQL 8.0.3+ or MariaDB 10.3+, cPanel with Cron. No Composer,
no Redis, no process supervisor, no shell access beyond the account's own
document root.

---

## 1. Layout

```
<account-root>/
├── public_html/            ← the only web-exposed directory
│   ├── .htaccess           ← document root lockdown + SPA fallback
│   ├── .user.ini           ← PHP limits (FPM/LiteSpeed only)
│   ├── index.html          ← built PWA shell (copied from dist/, never authored here)
│   ├── sw.js               ← service worker (copied from dist/)
│   ├── manifest.webmanifest← web app manifest (copied from dist/)
│   ├── pwa-*.png           ← launcher icons (copied from public/)
│   ├── assets/             ← content-hashed JS/CSS (copied from dist/)
│   └── api/v1/…            ← nine thin entry points, no logic
└── private_storage/        ← 0640, never web-exposed
    ├── .env                ← 0600, the only file holding secrets
    ├── app/                ← PSR-4 FieldPulse\
    ├── bin/                ← operator CLI
    ├── migrations/         ← forward-only SQL
    ├── workers/            ← cron entry point
    └── fieldpulse/         ← uploaded evidence (created at runtime)
        ├── quarantine/     ← between receipt and verification
        └── processed/{verified,review,rejected}/
```

`private_storage` must sit **beside** `public_html`, not inside it. The entry
points locate it by walking upward for `private_storage/app/bootstrap.php`, so
the two can be siblings at any depth.

If your host only gives you one directory, put `private_storage` above the
document root and symlink it in — or, better, do not deploy at all until it can
be separated.

---

## 2. Install

```bash
cd ~/public_html
# upload the FieldPulse tree so that private_storage is a SIBLING of public_html
cp .env.example private_storage/.env
chmod 750 private_storage
chmod 640 private_storage/.env
chmod 640 private_storage/migrations/*.sql
```

Generate the signing secret:

```bash
php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"
```

Paste it into `JWT_SECRET` in `.env`. **Rotating this invalidates every access
token and forces re-login for every agent**, so treat it as a planned operation,
not a fix for a suspected leak.

Edit `.env`:

| Key | Value | Notes |
|---|---|---|
| `APP_URL` | `https://yourdomain` | No trailing slash. Used for Origin enforcement. |
| `APP_TIMEZONE` | `Africa/Lagos` | Reporting periods. Storage stays UTC. |
| `DB_NAME` / `DB_USER` | `cpaneluser_fieldpulse` | cPanel prefixes both. |
| `DB_PASSWORD` | from cPanel MySQL | |
| `DB_SOCKET` | usually set on cPanel | Wins over host/port when present. |
| `JWT_SECRET` | 96 hex chars | Required; the app refuses to boot without it. |
| `MAX_UPLOAD_BYTES` | `5242880` | Must be ≤ `upload_max_filesize` in `.user.ini`. |
| `ALLOWED_ORIGINS` | empty | Only if the PWA is on another origin. |
| `TRUST_PROXY_HEADERS` | `false` | Only behind a proxy you control. |

`ALLOWED_ORIGINS` and `TRUST_PROXY_HEADERS` are the two settings most likely to
be set wrongly:

- **`TRUST_PROXY_HEADERS=true` on a bare host** hands rate limiting and the audit
  log an attacker-controlled `X-Forwarded-For`. The attacker then rotates their
  apparent IP per request and the rate limiter never engages.
- **A wide `ALLOWED_ORIGINS`** disables the CSRF check on `refresh.php`, which is
  the one endpoint authenticated by an automatic cookie. List exact origins, or
  leave it empty.

---

## 3. Database

```bash
php private_storage/bin/healthcheck.php
php private_storage/bin/migrate.php
php private_storage/bin/migrate.php --status   # verify
```

`migrate.php` is forward-only. Editing an already-applied file is a hard error,
reported as a checksum mismatch, not a silent no-op.

It takes `--env=/path/to/.env` so a support matrix can apply the same migrations
to more than one server from one command. Pointing a run at a different engine
by editing the deployment's `.env` instead would mean a matrix that dies halfway
leaves the deployment configured against the wrong database.

### Supported versions

| Engine | Supported | Tested |
|---|---|---|
| MySQL | 8.0.3+ | **8.0.40** |
| MariaDB | 10.11+ | **10.11.9** |

`php private_storage/bin/db_matrix.php` is what establishes the right-hand
column: for each engine it applies every migration from empty, runs the
healthcheck, and runs the database-facing suites, and exits non-zero if any cell
is not green.

```bash
php private_storage/bin/db_matrix.php                       # both engines
php private_storage/bin/db_matrix.php --engine=mariadb       # one cell
php private_storage/bin/db_matrix.php --quick               # migrate + healthcheck only
```

The MariaDB cell reads its own environment file, named by
`FIELDPULSE_MARIADB_ENV`. There is no default, because a default would be a
guess and a silently skipped cell reads as a pass.

**On older MariaDB.** The queue takes the native `FOR UPDATE SKIP LOCKED` path
on 10.6+ and a portable fallback below it, and the schema avoids generated
columns, MySQL-only JSON operators and spatial types, so 10.3–10.10 will very
likely work. That has not been tested, so it is not supported here — the floor is
10.11 because that is the oldest release the matrix has actually run. If you need
an older one, add it as a matrix cell and let the result decide.

Migrations 001–008 are the contract schema. 009–015 are additive and exist
because the spec's requirements are only satisfiable with extra columns:

| Migration | Why it exists |
|---|---|
| 009 `request_nonces` | §7 replay protection needs durable nonce state |
| 011 `agent_sites` | §12 geofence needs operator-set centres; `agents` has no location |
| 012 `login_attempts` | §6 rate limiting across PHP processes |
| 013 `pairing_codes` | Out-of-band device binding (see §Security model) |
| 014 `agent_role` | §13 review decisions need a supervisor role |
| 015 review provenance | Who reviewed what, when, and why |
| 017 `add_username_password` | §6 username/password credentials; `refresh_tokens.device_id` becomes nullable |
| 018 `retire_auth_challenges` | Drops the challenge table; §6 is now credentials, not PoP-on-login

---

## 4. Verify the install

```bash
php private_storage/bin/selftest.php          # offline: crypto, pHash, geo, periods
php private_storage/bin/healthcheck.php       # live: extensions, DB, schema, storage
```

`selftest.php` needs no database and catches the classes of bug that are
invisible until they matter: a broken DER encoding, an off-by-one at the Monday
boundary, a DCT that is not scale-invariant. Run it after every deploy.

`healthcheck.php` checks the live environment: PHP version, required extensions,
`upload_max_filesize` and `post_max_size` against `MAX_UPLOAD_BYTES` (the single
most common misconfiguration), `memory_limit`, `max_execution_time`,
`max_input_time`, storage permissions, the resolved document root, and the
presence of every expected table.

`post_max_size` must **exceed** `upload_max_filesize`, and it does (6M vs 8M).
If they are equal, the surplus of the request over the limit — the multipart
boundaries and the JSON payload — is discarded and `$_FILES` arrives empty, which
surfaces as `UPLOAD_ERR_INI_SIZE` with nothing to inspect rather than as a clear
error.

It also reads `.user.ini` directly. That matters because the values in it are
applied per-request by the web SAPI and are *not* visible to a CLI run, so a
healthcheck that only asked PHP would report the `php.ini` values and miss a
document root whose limits had been silently overridden.

**A clean run must print `PASS` with no warnings.** Any warning means a
misconfiguration is being tolerated rather than fixed; treat a warning as a
failed deploy. `--env=/path/to/.env` checks a different deployment than the
default `.env`.

---

## 5. Frontend build

The PWA is a Vite app. It is **not** served from the repository root: the build
output in `dist/` is what belongs in `public_html`.

```bash
npm ci
npm run lint
npm test
npm run build
```

Then publish it. Use the deploy script, not a `cp`:

```bash
php private_storage/bin/deploy.php
```

The script runs all four frontend commands itself and stops at the first failure,
then copies `dist/` into `public_html` file by file and **verifies afterwards
that `.htaccess`, `.user.ini` and `api/` were not touched**. If any of them
changed, or was missing both before and after, it exits non-zero.

Use these when you need to:

```bash
php private_storage/bin/deploy.php --dry-run      # print the plan, change nothing
php private_storage/bin/deploy.php --skip-frontend # publish an existing dist/
php private_storage/bin/deploy.php --skip-build    # run the gate, publish nothing
php private_storage/bin/deploy.php --docroot=/home/USER/public_html
```

### Why not `cp -r dist/. ~/public_html/`

That command happens to be safe — it cannot delete files it does not overwrite
— but the next person's `rm -rf ~/public_html/*` will not be. Deleting
`.htaccess` takes the entire document-root lockdown with it: no CSP, no dotfile
denial, no suffix allowlist, no SPA fallback, and every API path answered with
the PWA shell. `deploy.php` makes that a non-event rather than a warning in a
document.

It also sweeps `assets/` for files that are not in the build. Asset filenames
are content-hashed, so the previous build's chunks are dead; `registerType:
'autoUpdate'` makes the new service worker take over on the next load, but only
if the old chunks are actually gone from disk.

`.htaccess`, `.user.ini` and `DATABASE` are **not** in `dist/` — the build does
not produce them — so there is nothing to merge. `deploy.php` lists them as
protected and proves afterwards that they are intact.

### Verifying the publish

```bash
curl -sI https://YOUR-HOST/queue | head -1                  # 200
curl -sI https://YOUR-HOST/sw.js | head -1                 # 200
curl -sI https://YOUR-HOST/.env | head -1                  # 403
curl -sI https://YOUR-HOST/assets/does-not-exist.js | head -1   # 404, never 200 HTML
curl -sI https://YOUR-HOST/api/v1/leaderboard.php | head -1 # 200/401/403, never text/html
```

A missing chunk must stay a 404. Answering it with 200 and a page of HTML is
the specific failure the SPA fallback is restricted to avoid, because that is
what the service worker and the browser both have to diagnose.

Or run the suite, which asserts all of the above plus a staged account, a real
Apache, and the storage and cron checks:

```bash
php private_storage/bin/deploy_test.php
php private_storage/bin/deploy_test.php --httpd=/usr/sbin/httpd   # adds the status-code tier
```

`deploy_test.php` builds a throwaway account with the real cPanel sibling
layout, publishes into it with `deploy.php`, and then checks the result over
HTTP. Pass `--httpd` to also boot a real Apache and assert exact status codes
for the routing `.htaccess` owns. Without it, the suite prints a warning saying
that tier did not run rather than quietly passing.

---

## 6. Cron

cPanel → Cron Jobs. Three entries:

```
* * * * *    /usr/local/bin/php /home/USER/private_storage/workers/process_queue.php
0   * * * *  /usr/local/bin/php /home/USER/private_storage/workers/process_queue.php --prune
0   3 * * *  /usr/local/bin/php /home/USER/private_storage/bin/selftest.php >> /home/USER/fieldpulse-selftest.log 2>&1
```

The worker must run **every minute**: it is what moves a submission from
`quarantine/` to a disposition, and the PWA tells agents to expect a verdict
within about two minutes.

Use the absolute PHP path from cPanel's selector, not `php` — the cron
environment has a minimal `PATH` and `php` frequently resolves to a different
version than the one the web server uses.

**Overlapping ticks are safe and expected.** The database is the queue, the claim
statement takes row locks, and each worker identifies its own claimed rows with a
unique token. A tick that runs past 60 seconds will overlap the next one; that is
by design, not a fault.

That is asserted rather than asserted-to: `bin/queue.php` releases several real
worker subprocesses against a barrier so they contend for the same rows, and
`bin/deploy_test.php` runs it as part of the Phase 7 gate. It covers both claim
strategies — native `SKIP LOCKED` and the portable fallback — and both are
exercised by the MariaDB cell of the support matrix.

Stale-lock recovery runs at the start of every tick, so a worker killed by a
timeout or an OOM cannot strand a submission — the next tick picks it up once
`locked_at` exceeds `JOB_LOCK_TIMEOUT_MIN`.

Check a tick without waiting for cron:

```bash
php private_storage/workers/process_queue.php --stats   # queue depth by status
php private_storage/workers/process_queue.php --once    # drain once, print a summary
php private_storage/workers/process_queue.php --prune   # the hourly maintenance run
```

All three exit `0`, including on an empty queue. A cron entry that exits
non-zero when there is nothing to do mails the owner every minute.

`--prune` is the one that most often breaks, because it is the only path that
issues `DELETE`. MySQL rejects `DELETE ... LIMIT n OFFSET 0` with *you have an
error in your SQL syntax*; both `RefreshTokenRepository::pruneExpired()` and
`NonceGuard::prune()` had that shape, so maintenance aborted on the first
expired row while the queue itself looked perfectly healthy. Both now use the
bounded-delete helper, which emits syntax each engine accepts.

### Closing reward periods

The same minute tick also closes any reward period that has come due: once a
week's UTC window plus `REWARD_CLOSE_GRACE_HOURS` has passed, the worker freezes
that week's verified ranking and publishes one entitlement per ranked agent, in
`status = 'PENDING'`. This is deliberately not driven by anyone opening the
rewards screen — publication is a cron responsibility, and the read-path close is
only an idempotent fallback for a deployment whose cron has stopped.

So the worker must run for rewards as well as verification: a stopped worker
means no new entitlements, and `PENDING` is what an agent sees as "published but
not yet paid". Set `REWARD_AUTO_CLOSE=false` only if periods are closed some
other way (`bin/close_rewards.php`).

---

## 7. Operator tools

All in `private_storage/bin/`. All are safe to run by hand and safe to put in
cron. Run any of them with no arguments to see usage.

| Command | What it does |
|---|---|
| `healthcheck.php` | Live environment, schema, and storage check |
| `selftest.php` | Offline unit tests; `--filter=phash`, `--verbose` |
| `migrate.php` | Apply migrations; `--status` to inspect |
| `provision_agent.php` | Provision an agent: code, name, optional role, optional site |
| `pair_device.php` | Issue a one-time device pairing code |
| `prune.php` | TTL housekeeping; `--dry-run` first |
| `reaggregate.php` | Rebuild leaderboard summaries; `--period=`, `--all` |
| `close_rewards.php` | Close due reward periods; `--period=`, `--all`, `--dry-run` |
| `../workers/process_queue.php` | Run the verification queue; `--stats`, `--prune` |

Two of these are worth explaining because they are the ones you will reach for
only when something is already wrong.

`prune.php --dry-run` prints what it would delete without deleting it. Run it
first; the counts tell you whether a retention window is set to something
absurd before it quietly deletes a year of audit history.

`reaggregate.php` recomputes leaderboard summaries from `submissions`. Run it
after changing a threshold in `.env`, or after restoring a database from backup
— summary rows written before the restore will not match the ledger. Because
every counter is a full recompute rather than an increment, running it twice
changes nothing, so it is safe to run speculatively.

```bash
php private_storage/bin/prune.php --dry-run
php private_storage/bin/reaggregate.php --period=2026-09-28
php private_storage/bin/reaggregate.php --all
```

---

## 8. Permissions

```bash
chmod 750 private_storage
chmod 640 private_storage/.env
find private_storage/fieldpulse -type d -exec chmod 750 {} \;
find private_storage/fieldpulse -type f -exec chmod 640 {} \;
```

The application refuses to start if the storage root is world-writable
(`0o002`). On a shared host that means another account could swap an evidence
file, so this is a correctness check, not a hardening preference.

---

## 9. Security model in one paragraph

Login is a **username and password** (bcrypt). An IMEI may be recorded against an
agent by `provision_agent.php --imei=` as an administrative inventory note, and
it is deliberately not an authentication input: it is not accepted by any
endpoint, not required to register a device, and cannot be read by a browser at
all — a handset does not expose it to web code, so anything typed into a field is
a string a user copied from a box. The factor that does stand between a stolen
password and a new device is the one-time pairing code (`Security\PairingCode`),
which is delivered by whoever supervises the agent and is not guessable from
anything the handset exposes.

Every login failure is byte-identical, and the password hash is verified even
when the username does not exist, so neither the body nor the response time is
an account-enumeration oracle.

Login returns a deliberately weak **bootstrap** JWT (`scope=bootstrap`,
`device_uuid=null`). The only route it may reach is
`/api/v1/device/register.php`, which
binds a browser-generated non-extractable ECDSA P-256 public JWK to the agent and
upgrades the session to a device-bound token. Binding is authorised by an
operator-issued one-time pairing code, and it revokes the bootstrap refresh
family — until a device is bound, the agent can only prove a password.

Access tokens are HS256 JWTs, 15 minutes. Refresh tokens are SHA-256 hashes in
an HttpOnly/Secure/SameSite=Strict cookie, rotated on every use, with reuse
revoking the whole token family. Every mutating request carries a device
signature over `METHOD
PATH
TIMESTAMP
NONCE
SHA256HEX(BODY)` with a durable
one-time nonce and ±300s skew. Nothing in the request body is trusted for
authorisation.

### What the document root refuses

`public_html/.htaccess` is the only thing standing between a misconfigured host
and a readable account. It is asserted by `bin/deploy_test.php` against a real
Apache, so it cannot regress unnoticed:

| Request | Result |
|---|---|
| `/.env`, `/.git/config`, `/api/.htaccess`, `/.DS_Store`, `/index.html~` | 403 |
| `/shell.php.bak` (undeclared suffix) | 403 |
| `/assets/index-missing.js` | 404, never 200 HTML |
| `/api/v1/renamed-endpoint.php` (a path deliberately not in the route table) | the API's own 404, never the SPA shell |
| `/queue`, `/leaderboard`, `/capture/17` | 200, the shell |
| `/` with no `index.html` deployed | 403 — see below |

`api/` has its own short-circuit ahead of the SPA fallback, so a renamed or
missing endpoint keeps returning the API's own 404 instead of a 200 of HTML that
a client would try to parse as JSON.

Two properties of that file are load-bearing and easy to undo by accident:

- **The suffix allowlist tests `%{REQUEST_FILENAME}`, not a backreference.** An
  earlier version captured the path into `%1` with a `RewriteCond` and then
  tested `%1` in the conditions below. On Apache 2.4.55 the negated test
  evaluated as though `%1` were empty, so every request for a file with a real
  extension was answered 403 — `/index.html`, every hashed asset, every API entry
  point. The whole site was offline and nothing in the repository could see it,
  because the file is only ever read by an Apache that was never running.
  `deploy_test.php` asserts there is no backreference inside any `RewriteCond`.

- **The suffix allowlist only applies to paths that name a file.** `/queue` has
  no extension and is a client-side route, not a file. Testing it as one returned
  403 for every deep link on a deployed host, while `vite dev` served the same
  URLs happily.

Responses carry `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: no-referrer`, `Permissions-Policy` granting only camera and
geolocation to self, a CSP with no `unsafe-inline` and no `unsafe-eval`, and HSTS.

The CSP is strict *because* the build is strict: `dist/` contains no `eval`, no
`new Function`, no inline `<script>`, no inline `<style>` and no inline event
handler. That is a property of the build, not an assumption, and
`deploy_test.php` re-checks it on every run — a dev-only `eval()` would start
violating the policy in production where nothing else would announce it.

`index.html`, `sw.js` and `manifest.webmanifest` are served `no-store`. A cached
`index.html` pins clients to the previous build's content-hashed asset URLs, and a
cached service worker cannot be replaced by a new one.

---

## Troubleshooting

**Nothing in `.htaccess` takes effect — dotfiles are downloadable, `/queue`
404s, no CSP header.** The host is not honouring per-directory configuration.
Every denial this document relies on lives in `.htaccess`, so if that file is
ignored the document root serves `private_storage` if it was uploaded inside it,
and there is no second line of defence.

Confirm with `php private_storage/bin/healthcheck.php`, which verifies the
*deployed* PHP limits in `.user.ini` rather than trusting the copy in the
repository, and check the host has `AllowOverride All` for the document root. On
cPanel that is **Domains → Domain → Apache Handlers → Override All**; the label
varies by cPanel theme. To prove whether the rules are actually in force rather
than merely present, run
`php private_storage/bin/deploy_test.php --httpd=/usr/sbin/httpd` against your
own Apache version and confirm the status-code tier reports no warning — that
tier requests `/.env` and `/shell.php.bak` over a real socket and asserts 403.

If you cannot get `AllowOverride All`, `.htaccess` is inert and this application
cannot be deployed on that host as configured.

**Everything returns 403, including `/index.html`.** The suffix allowlist in
`.htaccess` is denying valid build output. This is the failure described above:
the allowlist must test `%{REQUEST_FILENAME}` and must not use a backreference
captured by an earlier `RewriteCond`. Run
`php private_storage/bin/deploy_test.php --httpd=/usr/sbin/httpd` to confirm on
your own Apache version — the behaviour differs between releases.

**`/` returns 403 but the build was deployed.** `index.html` is not in the
document root. `DirectoryIndex index.html` is stated in `.htaccess` rather than
inherited, so a missing build is a 403 and not a directory listing. Check
`php private_storage/bin/deploy.php --dry-run`, and confirm the deploy actually
ran rather than stopping at a failing lint or test.

**`deploy.php` exits non-zero after copying.** It detected that `.htaccess`,
`.user.ini` or `api/` changed or went missing. Read the message, restore the
document root, and do not serve traffic until it is verified — a document root
without `.htaccess` has no CSP, no dotfile denial and no SPA fallback.

**Uploads fail with a generic 500, not `FILE_TOO_LARGE`.** PHP rejected the
request before the app saw it. `upload_max_filesize` in `.user.ini` is lower than
`MAX_UPLOAD_BYTES`, or `.user.ini` is not being read (mod_php ignores it — set
the values in `.htaccess` with `php_value`, or in php.ini).

**Every request returns `SERVICE_UNAVAILABLE`.** The entry point could not find
`private_storage/app/bootstrap.php`. It must be a sibling of `public_html`.

**`CLOCK_SKEW` on every request.** The phone's clock is wrong, or PHP's
`date.timezone` is unset. `bootstrap.php` forces UTC, but the *client* timestamp
in the signature header is the agent's device clock. This is worth a prompt in
the PWA; do not widen `CLOCK_SKEW_SECONDS` to paper over it.

**Verification jobs die with "Allowed memory size exhausted".** Raise
`memory_limit` to 256M. A 40 MP photo needs roughly 160 MB for the GD decode
plus the thumbnail.

**Submissions sit in `quarantine/` forever.** The worker is not running. Check
the cron log with `php private_storage/workers/process_queue.php` by hand, then
`--stats` to see queue depth.

**The hourly `--prune` cron mails an error while the queue looks fine.** Only
`--prune` issues `DELETE`, so a maintenance failure is invisible to every other
signal. Run it by hand and read the SQL error. A `DELETE ... LIMIT n OFFSET 0` is
not portable — MySQL rejects it — so any bounded delete must go through the
repository helper.

**The healthcheck warns about `post_max_size`.** It must exceed
`upload_max_filesize`, not merely meet it. Set them in `public_html/.user.ini`;
the healthcheck reads that file directly, because the web SAPI applies it
per-request and a CLI run cannot see it.

**Everything is `REQUIRES_REVIEW` with `SITE_UNASSIGNED`.** The agent has no
`agent_sites` row, so §12 has nothing to check against. That is the intended
behaviour — the absence of a check must not read as a pass. Re-run
`bin/provision_agent.php` with `--site-lat/--site-lng`, or insert the row.

**Leaderboard is empty but submissions are verified.** The summary is written
when a submission is dispositioned. If you restored from a backup, run
`bin/reaggregate.php --all`.

**`401 UNAUTHENTICATED` immediately after a successful login.** The access token
lifetime is 15 minutes by design; the PWA must refresh on a 401 and retry once.
If the refresh cookie is not being stored, the cookie `Path` is `/api/v1/auth`,
so it is sent to `refresh.php` but not to `submit.php` — that is intended, and
submit uses the bearer header instead.
