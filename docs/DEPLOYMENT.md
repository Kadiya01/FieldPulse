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

Migrations 001–008 are the contract schema. 009–015 are additive and exist
because the spec's requirements are only satisfiable with extra columns:

| Migration | Why it exists |
|---|---|
| 009 `request_nonces` | §7 replay protection needs durable nonce state |
| 010 `auth_challenges` | §6 PoP challenge must survive across two requests |
| 011 `agent_sites` | §12 geofence needs operator-set centres; `agents` has no location |
| 012 `login_attempts` | §6 rate limiting across PHP processes |
| 013 `pairing_codes` | Out-of-band device binding (see §Security model) |
| 014 `agent_role` | §13 review decisions need a supervisor role |
| 015 review provenance | Who reviewed what, when, and why |

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
`upload_max_filesize` vs `MAX_UPLOAD_BYTES` (the single most common
misconfiguration), storage permissions, and the presence of every expected table.

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

Then copy the built output into the document root:

```bash
cp -r dist/. ~/public_html/
```

`dist/` holds `index.html`, `assets/`, `sw.js`, `manifest.webmanifest` and the
launcher icons. Two consequences worth knowing before you copy:

- **`public_html/.htaccess` and `.user.ini` are not in `dist/` and must not be
  overwritten by the copy.** The `cp -r dist/.` form cannot remove them, but a
  `rm -rf ~/public_html/*` beforehand would delete the document-root lockdown and
  the PHP limits along with the build.
- **The SPA fallback depends on this step.** `.htaccess` serves `index.html` for
  a client-side route such as `/queue` or `/login`, which is how the router
  resolves a deep link or a refresh. Without the copy, the root returns 403 and
  every deep link does too.

Verify after copying:

```bash
curl -sI https://YOUR-HOST/queue | head -1     # 200, not 403 or 404
curl -sI https://YOUR-HOST/api/v1/leaderboard.php | head -1   # 403 or 401, not HTML
```

The first must be `200`; the second must never be `200` with `text/html`, since
that would mean the API 404 is being answered with the PWA shell.

### Re-deploying

Asset filenames are content-hashed, so a new build changes them and the old files
are dead. Remove the previous `assets/` directory rather than merging into it, so
stale chunks are not served to a client whose service worker still references
them. `registerType: 'autoUpdate'` makes the new service worker take over on the
next load, but only if the old chunks are actually gone from disk.

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

Stale-lock recovery runs at the start of every tick, so a worker killed by a
timeout or an OOM cannot strand a submission — the next tick picks it up once
`locked_at` exceeds `JOB_LOCK_TIMEOUT_MIN`.

---

## 7. Operator tools

All in `private_storage/bin/`. All are safe to run by hand and safe to put in
cron. Run any of them with no arguments to see usage.

| Command | What it does |
|---|---|
| `healthcheck.php` | Live environment, schema, and storage check |
| `selftest.php` | Offline unit tests; `--filter=phash`, `--verbose` |
| `migrate.php` | Apply migrations; `--status` to inspect |
| `provision_agent.php` | Create an agent, role, IMEI binding, site |
| `pair_device.php` | Issue a one-time device pairing code |
| `prune.php` | TTL housekeeping; `--dry-run` first |
| `reaggregate.php` | Rebuild leaderboard summaries; `--period=`, `--all` |
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

An IMEI **identifies**; it never authenticates. Login requires a P-256 signature
from a key already bound to the agent, over a server-issued one-time challenge.
That key is bound by an operator-issued one-time pairing code, or from a handset
that already holds a valid session. Access tokens are HS256 JWTs, 15 minutes.
Refresh tokens are SHA-256 hashes in an HttpOnly/Secure/SameSite=Strict cookie,
rotated on every use, with reuse revoking the whole token family. Every
mutating request carries a device signature over
`METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256HEX(BODY)` with a durable one-time nonce
and ±300s skew. Nothing in the request body is trusted for authorisation.

---

## Troubleshooting

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
