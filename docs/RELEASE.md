# Release certification

The release gate is the single command that certifies a FieldPulse build. It is
[`private_storage/bin/release_gate.php`](../private_storage/bin/release_gate.php).

```bash
php private_storage/bin/release_gate.php --httpd=/path/to/apache/bin/httpd
```

It exits `0` and prints `FIELDPULSE RELEASE CANDIDATE: PASS` only when every
mandatory check is green. Any other outcome prints `NOT PASS` and exits `1`.

## What it runs

| Group | Check | Command | Passes when |
|---|---|---|---|
| Frontend | `build` | `npm run build` | exit 0 |
| Frontend | `lint` | `npm run lint` | exit 0 |
| Frontend | `unit` | `npm test` | exit 0 |
| Application | `healthcheck` | `bin/healthcheck.php --env=…` | reports `PASS` |
| Application | `selftest` | `bin/selftest.php --env=…` | exit 0 |
| Application | `auth` | `bin/auth.php --env=…` | exit 0 |
| Application | `contract` | `bin/contract.php --env=…` | exit 0 |
| Application | `integrity` | `bin/integrity.php --env=…` | exit 0 |
| Application | `integration` | `bin/integration.php --env=…` | exit 0 |
| Application | `verification` | `bin/verification.php --env=…` | exit 0 |
| Application | `queue` | `bin/queue.php --env=…` | exit 0 |
| Deployment | `deploy_test` | `bin/deploy_test.php --httpd=…` | exit 0 **and** the Apache `.htaccess` status-code tier ran |
| Browser | `e2e` | `npm run test:e2e:chromium` | exit 0 |
| Database | `db_matrix` | `bin/db_matrix.php` | exit 0 and both MySQL and MariaDB certified |

## What it refuses to do

A gate that reports PASS while a required check was skipped is worse than no
gate, because it turns a gap into a certification:

- a run that skipped the browser suite (`--skip-e2e`) is **NOT PASS**;
- a deployment run whose Apache tier did not run — no `--httpd`, or an older
  `deploy_test.php` that downgrades a missing binary to a warning — is **NOT
  PASS**;
- a matrix run that certifies fewer than two engines, or does not certify both a
  MySQL and a MariaDB engine, is **NOT PASS**;
- a run that did not reset its test databases (`--no-reset`) is **NOT PASS**,
  because the database suites are order-dependent (see below).

## The fresh-database requirement

`bin/queue.php` and the verification suites are deliberately not idempotent: a
second run against a used database builds a byte-different but perceptually
identical fixture, and the duplicate detector — working correctly — rejects it.
The gate therefore **drops and recreates** each database named by an environment
file, then migrates it, before the checks that depend on it. The primary suite
run, the deployment tier and the browser suite each start from a fresh schema.

Because that reset is destructive, the reset refuses to touch a database whose
`APP_ENV` is not `testing`, `development` or `local` unless `--force` is given.

## Certified engines

The database cell is certified against the server that actually answers
`SELECT VERSION()`, not against the label on the environment file.

| Engine | Supported | Certified by the gate |
|---|---|---|
| MySQL | 8.0.3+ | **8.0.40** |
| MariaDB | 10.11+ | **11.4.13** |

MariaDB is certified only when `FIELDPULSE_MARIADB_ENV` (or the gitignored
`private_storage/.env.mariadb`) points at a real MariaDB server. Without it,
`db_matrix.php` reports the cell as **NOT CERTIFIED**, and the gate does not
pass. The MySQL cell is declared the same way through `FIELDPULSE_MYSQL_ENV` /
`private_storage/.env.mysql`.

The supported floor and the certified version are not the same claim. MariaDB
10.11 is the supported floor; 11.4.13 is the version the matrix has been run
against. MariaDB 10.11 was **not** independently certified in this environment
and is not presented as if it were.

## Running it by hand instead

The gate is a convenience over the individual suites, not a replacement for
understanding them. To reproduce it step by step, start each database-dependent
block from a freshly migrated schema and run the Apache tier with a real binary —
the ordering rules are in [README.md](../README.md#verification) and
[VERIFICATION.md](VERIFICATION.md).