# Release gate

How a release is certified, and the record of the last certification.

The gate exists because the individual suites were never the problem. The
problem was the *sequence*: suites that are only valid against a fresh database,
a browser tier that needs a real deployment, a database matrix that is only
meaningful when every engine starts from empty. `bin/release_gate.php` runs the
whole thing in order, against a fresh database, from a single command.

## The five tiers

The gate is deliberately ordered so that an early failure is fast and loud, and
nothing downstream is polluted by a partially applied earlier tier.

| Tier | What it runs | Why it is in this order |
|---|---|---|
| 1 — frontend | `npm run lint`, `npm test`, `npm run build` (each with one retry) | Fast feedback; a build failure proves nothing about the backend |
| 2 — application | fresh reset + migrate of `private_storage/.env`, then `healthcheck`, `selftest`, `auth`, `contract`, `integrity`, `integration`, `verification`, `queue` | The PHP suites, in dependency order, against a database that is guaranteed empty |
| 3 — deployment | `deploy_test.php --httpd=<binary>` | Requires a real Apache binary; the `--httpd` tier is skipped-with-a-failure, never skipped-with-a-pass |
| 4 — browser | fresh reset + migrate again, then `npm run test:e2e:chromium` | The real Playwright suite across an offline-capture restart and the rewards lifecycle |
| 5 — database matrix | drop tables on the MySQL and MariaDB cells, then `db_matrix.php` | Both engines must pass from empty on every certification, not just the default `.env` |

Everything after tier 1 re-reads its own `private_storage/.env.*` file, so a
machine can point the matrix at any two running engines without editing the
deployment `.env`.

## Running it

```bash
export OPENSSL_CONF=/path/to/php/extras/ssl/openssl.cnf   # Windows PHP
php private_storage/bin/release_gate.php \
  --httpd="C:\path\to\apache\bin\httpd.exe"
```

Exit code 0 and the exact final line:

```
FIELDPULSE RELEASE CANDIDATE: PASS
```

Any failed cell prints `... NOT READY` and exits non-zero. The gate does not
stop at the first failure — every tier runs and is reported, so one run tells
you everything that is wrong, not just the first thing.

## What a PASS on one machine means

The gate certifies *the code as it is on this machine, against these database
engines, at this time*. The engine versions are read back from the live servers,
not asserted from a table, so the verdict re-states what was actually sitting
behind the network socket. A PASS from another process, another set of engines,
or an earlier date is not inherited; re-run the gate.

## Last certification

Date: **2026-10-08**
Platform: Windows, git-bash, PHP 8.2 NTS, Node 26.4.0 / npm 11.18.0
Engines (live `SELECT VERSION()`): **MySQL 8.0.40**, **MariaDB 11.4.13**
HTTP: Apache 2.4 (`bin/httpd.exe` from a real Apache 2.4 install)

```
Tier 1 — frontend
  [ok]   frontend: lint
  [ok]   frontend: unit tests          # 83 passed
  [ok]   frontend: build

Tier 2 — application
  [ok]   application: healthcheck
  [ok]   application: selftest          # 68 assertions
  [ok]   application: auth
  [ok]   application: contract
  [ok]   application: integrity
  [ok]   application: integration
  [ok]   application: verification
  [ok]   application: queue

Tier 3 — deployment
  [ok]   deployment: httpd              # 11 files published, 3 protected entries verified

Tier 4 — browser (real Playwright suite)
  [ok]   browser: chromium e2e          # 5/5, incl. offline capture across a restart

Tier 5 — supported database matrix
  [ok]   database: matrix              # 2 engine(s) certified: MySQL 8.0.40, MariaDB 11.4.13

FIELDPULSE RELEASE CANDIDATE: PASS
```

## Bugs the gate found, and the fixes

The gate is meant to find genuine defects; the fixes below are the ones it
earned during this certification.

- **`db_matrix.php` ran its child suites from the wrong directory.** The suites
  are invoked with the repository root as the working directory, but the child
  process started in `private_storage/`, so `FIELDPULSE_*_ENV` values that are
  documented as repository-root-relative never resolved and the matrix could not
  find its environment files. Fixed to `dirname(__DIR__, 2)`.
- **`app/Console/Cli.php` masked every exception it meant to report.** The
  exception handler was a `static` closure referencing `$entryScript` without a
  `use` clause, so any handled exception produced `Undefined variable
  $entryScript` — which placed the real stack trace one fatal behind. Fixed with
  `use ($entryScript)`. In this repository the two fixes surface as one: the
  matrix was passing-by-luck before, and failing-by-obfuscation after.
- **A reload mid-upload deadlocked the sync queue.** When the queue POSTs a
  submission and the page is reloaded or closed before the response arrives, the
  in-flight request is lost with its document. The stranded row stayed `SYNCING`
  forever, and — critically — the lease still held by the dead realm blocked the
  restarted app for its full TTL with **nothing scheduled to retry afterwards**.
  The coordinator now wakes once at lease expiry when it loses a lock race, so
  `recoverStrandedSubmissions()` runs and the queue finishes the upload. Verified
  in the browser tier: the offline-capture-restart spec now reaches `SENT` in
  every run.