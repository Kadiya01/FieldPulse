# FieldPulse — Production Audit (Phase 0 Baseline)

Status: **Phase 0 complete — GATE PASS**
Date: 2026-09-29
Scope: read-only audit plus an executable baseline. **No application source was modified.**

---

## 1. Purpose

Establish a verified baseline of the existing implementation *before* any behaviour
changes, and record every inconsistency found between the PWA client and the PHP API.

This document is the input to Phase 1 (API contract) and Phase 2 (authentication and
signing model). Findings recorded here are **not** fixed in Phase 0.

---

## 2. Executable baseline

All seven required checks were executed. None are blocked.

| # | Check | Command | Result | Count |
|---|---|---|---|---|
| 1 | PHP lint | `php -l` over all production files | **PASS** | 82 files, 0 failures |
| 2 | Selftest | `php private_storage/bin/selftest.php` | **PASS** | 63 passed, 0 failed (1 364 ms) |
| 3 | Healthcheck | `php private_storage/bin/healthcheck.php` | **PASS** | 0 failures, 2 warnings |
| 4 | Integration | `php private_storage/bin/integration.php` | **PASS** | 15 passed, 0 failed (20 734 ms) |
| 5 | Frontend tests | `npm test` | **PASS** | 1 file, 3 tests passed (46.58 s) |
| 6 | Lint | `npm run lint` | **PASS** | 0 errors, 7 warnings |
| 7 | Build | `npm run build` | **PASS** | 1 898 modules, 14.45 s |

**Totals: 81 assertions passed, 0 failed, 0 blocked.**

### 2.1 Environment used

The host had no PHP and no `node_modules`. Both were provisioned to obtain the baseline.

| Component | Version | Location |
|---|---|---|
| PHP | 8.2.34 NTS x64 | `%LOCALAPPDATA%\Temp\opencode\php\php82` (no system install) |
| MySQL | **8.0.40** | `%LOCALAPPDATA%\Temp\opencode\mysql`, port **3307**, isolated datadir |
| Database | `fieldpulse_test` | dedicated; **no production database or credentials involved** |
| Node | 26.4.0 / npm 11.18.0 | pre-existing |

`npm ci` installed **439 packages, 0 vulnerabilities**.

### 2.2 Environment configuration required to run the suites

Two settings were needed. Both are **environment only** — the repository was not edited.

1. **`OPENSSL_CONF`** must point at PHP's bundled
   `extras/ssl/openssl.cnf`. Without it `openssl_pkey_new()` fails on Windows PHP
   builds and **5 signature tests fail** (63 → 58 passed). The selftest detects
   and explains this itself. It is an environment prerequisite, not a code defect.
2. **`post_max_size = 8M`** in the local runtime. See finding **F13**, which is a
   genuine repository defect: the shipped `public_html/.user.ini` sets `6M` and
   would fail the repository's own `healthcheck.php` on a real host.

### 2.3 Healthcheck warnings (non-blocking)

- `max_execution_time = 0` (unlimited) — expected on the CLI SAPI; the check
  explicitly notes this is fine here and to verify `php.ini` on the web SAPI.
- `could not resolve public_html` — see finding **F14**, an off-by-one in the
  healthcheck's document-root guess. Cosmetic; storage is in fact outside the root.

### 2.4 Verification that Phase 0 changed no source

`git status --short` returned **empty** after all checks. The only new files are
`.env` (gitignored, line 13) and `dist/` (gitignored, line 18), plus `node_modules/`.
No tracked file was modified, and no git history was rewritten.

---

## 3. Current implementation

### 3.1 Backend — mature, passing

| Area | Detail |
|---|---|
| Language | PHP 8.2, **zero third-party dependencies** (no Composer, no vendor tree) |
| Classes | 63 in `private_storage/app` + 8 CLI tools + 1 worker + 10 entry points |
| Schema | 16 forward-only migrations, InnoDB / `utf8mb4_unicode_ci` |
| Crypto | hand-rolled HS256 JWT, JWK→DER→OpenSSL P-256, nonce replay protection |
| Queue | MySQL-backed (`processing_jobs`), cron-driven, `locked_by` stale-lock recovery |
| Pipeline | integrity → decode → pHash → EXIF → period → geofence → duplicate → timestamp → weekly cap |
| Tests | 63 selftest assertions (9 groups) + 15 integration tests (7 groups) |

Comment density is unusually high and documents real resolved post-mortems —
notably migration `016`, which exists solely to repair four schema/code mismatches
where a missing `devices.revoked_at` column meant **no submission could ever be
verified while every test remained green**.

### 3.2 Frontend — prototype, non-functional against the API

| Area | Detail |
|---|---|
| Stack | React 19, TypeScript, Vite 8, Dexie 4, vite-plugin-pwa, Tailwind 4 |
| Size | 1 162 LOC across 11 TypeScript files |
| Tests | 1 file, 3 assertions — all crypto-internals, none covering the network layer |
| Offline | Dexie-backed queue, BroadcastChannel cross-tab lock, exponential backoff with jitter |
| Build | 1 898 modules; Workbox `generateSW`, 12 precache entries (619.56 KiB) |

**The client compiles, lints, and passes its tests, and is nonetheless incapable of
completing a single authenticated request against this backend.** The failures are
contract-level, not syntactic, so no existing frontend gate detects them.

---

## 4. Inconsistencies

Ordered by severity. Severity reflects production impact, not effort.

### Critical — the client and server cannot communicate

**F1 — Complete authentication-model divergence.**
The client collects a username and password and posts them:
`src/pages/LoginPage.tsx:30-34` → `POST /auth/login.php {username, password}`.
The server has **no passwords anywhere**. `LoginController.php:55-58` requires
`{imei, device_uuid, challenge, signature}` over a proof-of-possession challenge.
The zero-password model is "IMEI identifies, device key proves, operator pairing
code binds". `imei` appears **0 times** in `src/`. `POST /auth/challenge` is
never called by the client, so the challenge→sign→login round trip is entirely absent.
Severity: the login flow cannot succeed under any input.

**F2 — Signature encoding mismatch (base64 vs base64url).**
`src/crypto/keys.ts:60` returns signatures via `btoa()` — standard base64, which
may contain `+`, `/`, and `=` padding. `Support/Str.php:48-52` enforces
`^[A-Za-z0-9_-]+$` and returns `null` for anything else, which
`SignatureVerifier::verify()` converts to a 401.
A 64-byte ES256 signature is 88 base64 characters ending in `=`, so **every
signature is rejected**. Severity: total failure of all signed requests.

**F3 — Signature header name mismatch.**
`src/api/client.ts:65` sends `X-Device-Signature`.
`Security/Authenticator.php:141` requires `X-Request-Signature`.
(`X-Device-UUID`, `X-Request-Timestamp`, and `X-Request-Nonce` are already correct.)
Severity: total failure of all signed requests.

**F4 — Multipart submission contract mismatch.**
`src/sync/coordinator.ts:115-126` appends flat form fields
(`submission_uuid`, `agent_id`, `device_uuid`, `count_claimed`, `client_latitude`,
`client_longitude`, `client_captured_at`, `photo`).
`Domain/SubmitController.php:51-60` requires a single `payload` JSON part plus a
`file` part, and rejects unknown keys. Field naming, part structure, and the
mandatory `file_sha256` are all absent.
Compounding this, `src/api/client.ts:29-39` sets the multipart body digest to
`SHA256("")`, while `Http/Request.php:218-235` signs the verbatim `payload`
field. `docs/API.md:71-79` documents this exact trap in bold; the code does it
anyway. Severity: no upload can ever be accepted.

### High

**F5 — Status-code mismatch: 201 vs 202.**
`src/sync/coordinator.ts:143` treats `201` as success. `SubmitController.php:264`
returns **202** for a new submission and 200 only for an idempotent replay.
A successful submission therefore falls through to the generic 5xx branch and is
scheduled for retry — with the correct `submission_uuid` it is de-duplicated
server-side, so the visible symptom is endless retrying and a permanently
`RETRY_WAIT` queue. The 5 MB `MAX_UPLOAD_BYTES` is never exceeded, so the file
survives, but the agent never sees the submission as accepted.

**F6 — `agent_id` is a hardcoded placeholder.**
`src/pages/CapturePage.tsx:93` writes `agent_id: 'current_agent'`,
annotated `// Would come from auth state`. There is no session state to source it from.

**F7 — No verdict polling; asynchronous verification incomplete end-to-end.**
`Domain/SubmissionStatusController.php` is fully implemented and returns `pending`,
`disposition`, `reasons`, and `awaiting_review`. `SubmitController` returns
`links.self` and `estimated_review_seconds`. **The client never polls.** An agent
cannot learn whether a capture became `VERIFIED`, `REQUIRES_REVIEW`, or `REJECTED`.
The entire pipeline described in `docs/VERIFICATION.md` is invisible in the UI, so
the product's central purpose is not delivered.

**F8 — Device registration contract mismatch.**
`src/pages/LoginPage.tsx:44-54` posts `{device_uuid, public_jwk}` with a bearer token.
`Domain/DeviceController.php:68-73` requires `{imei, public_key_jwk, challenge, signature}`
— note `public_key_jwk`, not `public_jwk` — plus a 10-digit `pairing_code` on first
pairing, and validates the JWK against an allowlist of
`kty, crv, x, y, kid, alg, use` (`Security/Jwk.php:38`). WebCrypto's
`exportKey('jwk')` additionally returns `key_ops` and `ext`, which would be rejected.
The flow order is also inverted: registration must precede first login.

### Medium

**F9 — Access token is memory-only; no refresh on boot.**
`src/auth/session.ts:1` holds the token in a module-scoped variable. Any page reload
loses it. Nothing calls `/auth/refresh` at startup, so the user is signed out on
every reload with no recovery path, despite a valid HttpOnly refresh cookie existing.

**F10 — Unbounded 401 recursion and multi-tab token destruction.**
`src/api/client.ts:77` re-invokes `authenticatedFetch` on 401 with no retry-once
guard (`docs/API.md:307` specifies "retry **once**"), risking infinite recursion.
More seriously, concurrent refreshes across two tabs present an already-rotated
token; `Security/TokenService::rotate()` treats reuse as theft and **revokes the
entire token family**. The `BroadcastChannel` in `coordinator.ts` coordinates sync,
not refresh. Severity: opening two tabs can sign the agent out everywhere.

**F11 — No route guard; `auth_failure` event never handled.**
`src/main.tsx:14` renders `CapturePage` at `/` unconditionally. No listener exists
for the `auth_failure` CustomEvent dispatched at `session.ts:16`, so the app has no
path back to login.

**F12 — `accuracy_m` is never sent.**
Accepted by `SubmitController.php:57` and documented at `API.md:195`, but absent
from `Submission` (`src/db/db.ts:3-24`) and from the capture flow. GPS accuracy is
therefore unavailable to the `GPS_UNRELIABLE` check in `Geo/Geofence.php`.

### Repository and documentation

**F13 — Shipped `.user.ini` fails the project's own healthcheck (confirmed).**
`public_html/.user.ini:22-23` sets `upload_max_filesize = 6M` and `post_max_size = 6M`.
`bin/healthcheck.php:110` requires `post_max_size >= MAX_UPLOAD_BYTES + 2MB`
= 7 340 032 bytes (7M). `6M` = 6 291 456 bytes, which is **below** that threshold.
This was observed as a real `[FAIL]` on first run, resolved only by correcting the
local runtime. On a genuine cPanel deployment, shipping the repository's own
`.user.ini` makes `healthcheck.php` report a hard failure. **Not a local artifact.**

**F14 — Healthcheck document-root resolution is off by one (confirmed).**
`bin/healthcheck.php:145` computes `dirname(storageRoot) . '/public_html'`. Storage
resolves to `private_storage/fieldpulse`, so the guess is
`private_storage/public_html`, which does not exist — the real document root is a
sibling of `private_storage`. The documented layout therefore always emits the
"could not resolve public_html" warning and **never performs the security check
that storage is outside the document root**. Warning, not failure; still a real gap.

**F15 — `docs/API.md` contradicts the implementation in four places.**

| Location | Documented | Actual |
|---|---|---|
| `API.md:58-61` | `X-Device-Id`, `X-Timestamp`, `X-Nonce`, `X-Signature` | `X-Device-UUID`, `X-Request-Timestamp`, `X-Request-Nonce`, `X-Request-Signature` (`Authenticator.php:129-141`) |
| `API.md:13` | all success is `{ "data": { … } }` | true for submit / submission status / leaderboard; **flat** for challenge, login, refresh, register |
| `API.md:170` | `"public_key": "…PEM…"` | `public_key_jwk`, a JWK **object** (`DeviceController.php:69`) |
| `API.md:264` | `period` accepts "any day in the week — normalised to its Monday" | a non-Monday is **rejected** with 422 (`LeaderboardController.php:95-101`) |

**F16 — README is the unmodified Vite template.**
32 lines of React+TypeScript+Vite boilerplate. Nothing describes FieldPulse, its
architecture, the security model, or how to run the four backend suites. This is the
repository's front page.

**F17 — Client compression ceiling is undocumented.**
`src/camera/compress.ts:44` caps output at 2 MB; `.env.example:80` sets
`MAX_UPLOAD_BYTES=5 MB`. The client is stricter, so this is safe, but the divergence
is nowhere documented and would surprise anyone tuning the server limit.

### Refuted during this audit

**F18 — `photo_blob: undefined` pruning *works*.** An earlier read suggested
`coordinator.ts:148` might leak photo blobs, since Dexie `update()` is often assumed
not to delete on `undefined`. This was empirically tested against Dexie 4.4 with
`fake-indexeddb`: the key is removed. **No defect.** Recorded so the hypothesis is
not re-investigated in a later phase. (Note: `null` does *not* delete — it stores
`null` — so the current `undefined` is the correct choice.)

### Verified accurate — not defects

- **The queue claim is consistent across MariaDB versions.**
  `JobRepository::claimBatch()` uses `FOR UPDATE SKIP LOCKED` when the server
  reports support (MySQL 8.0.1+, MariaDB 10.6+) and falls back to an atomic
  `UPDATE ... ORDER BY ... LIMIT` worker-token claim otherwise. **Phase 7 closed
  this gate**: MariaDB 10.11.9 was run through `bin/db_matrix.php` with both
  strategies, so the fallback is exercised rather than merely present. The
  supported floor moved from 10.3 to 10.11 in `DEPLOYMENT.md` because 10.11 is
  the oldest release actually tested; `migrate.php` warns below it.
- **`ST_Distance_Sphere` is absent on MySQL 8.0.40**; `Haversine.php:14` documents
  the PHP fallback, and the healthcheck reports it as `[ok]`. Working as designed.
- **Service-worker registration works.** The build emits `dist/registerSW.js` and
  injects `<link rel="manifest">` into `dist/index.html`; 12 precache entries.
- **Workbox excludes the API** (`NetworkOnly` on `/^\/api\/v1\//`), so no POST is
  cached. Correct.
- **`.htaccess` suffix allowlist is method-agnostic**, so the `HEAD` reachability
  probe at `coordinator.ts:75` is *not* blocked by it. The probe is a needless
  unauthenticated dependency, but not a broken one.

---

## 5. Why every existing gate passes while the product is broken

This is the most important structural finding of Phase 0.

- `selftest.php` **never opens a database connection** and tests pure logic only.
- `npm test` covers `keys.ts` internals only — 3 assertions, no network layer.
- `npm run lint` reports syntax and unused variables, not contract conformance.
- `npm run build` type-checks the client against **its own** types, never against
  the PHP controllers.

The backend and frontend are validated against **themselves**. Nothing in the
repository asserts that the two agree. This is precisely the gap that let
migration `016`'s production outage survive a fully green run, and it is the gap
that hides F1–F8 today.

**Dependency for later phases:** the contract tests written in Phase 1 must assert
the wire format — canonical signing string, header names, multipart byte-identity,
status codes, and response envelope — not merely that each side is internally
consistent.

---

## 6. Phase dependencies

### Phase 1 — API contract
Resolve **F4, F5, F6, F12, F15**, and the `X-Request-Signature` half of **F3**.
Add contract tests that bind the client to the real server contract.
**Blocked by nothing.** This is the prerequisite for every other phase, because the
client cannot be tested end-to-end while F1–F4 stand.

### Phase 2 — Authentication and signing
Resolve **F1, F2, F3, F8, F9, F10, F11**.
Requires Phase 1: the challenge/login/register contract and the canonical signing
string must be pinned first, or the signing tests have no fixed reference.

### Phase 3 — Product completeness
Resolve **F7** (verdict polling) and the offline story.
Requires Phase 2: polling is bearer-authenticated and needs a working session.

### Phase 4 — Repository hygiene
Resolve **F13, F14, F16, F17**. Independent of Phases 1–3; can run in parallel.

### Phase 7 — Deployment, security and compatibility gate (closed)
The MariaDB compatibility gate deferred from Phase 4 was executed here, not
merely planned. Two defects were found and fixed:

- **`public_html/.htaccess` returned 403 for every file with a real extension**
  on Apache 2.4.55 — the whole site, offline. The suffix allowlist tested `%1`,
  a backreference captured by an earlier `RewriteCond`, which evaluated as
  though empty. Nothing in the repository could see it, because the file is only
  read by an Apache that was never running. Now uses `%{REQUEST_FILENAME}`, and
  `bin/deploy_test.php` asserts the status codes against a real Apache.
- **`DELETE ... LIMIT n OFFSET 0` in two repositories.** MySQL rejects it, so
  `process_queue.php --prune` aborted on the first expired row while the queue
  looked healthy. Both now use the bounded-delete helper.

A third defect was a *test* defect, and it had been hiding the first MariaDB
result: the suites' HTTP child process never received `--env`, so a "MariaDB"
run had its fixtures written to MySQL. `Config::boot()` also ignored an explicit
env file, so `--env` was silently a no-op. Both fixed; `db_matrix.php` is the
artifact that now proves the claim.

### Ordering constraint
**Do not begin Phase 1 until this gate is accepted.** F1–F4 are contract failures,
not style issues, and the existing test suite provably cannot detect them.

---

## 7. Reproduction

```bash
# Backend prerequisites (environment only)
export OPENSSL_CONF="<php-root>/extras/ssl/openssl.cnf"
# .env must exist with DB_* and a JWT_SECRET of >= 32 bytes

php -l <each production .php file>            # 82 files
php private_storage/bin/selftest.php          # 63 assertions
php private_storage/bin/migrate.php           # 16 migrations
php private_storage/bin/healthcheck.php       # environment + schema
php private_storage/bin/integration.php       # 15 assertions, isolated test DB

# Frontend
npm ci
npm test          # 3 tests
npm run lint      # 0 errors, 7 warnings
npm run build     # 1898 modules
```

`integration.php` refuses to run against `APP_ENV=production`, or against any
database whose name does not match `/test|dev|local|ci/i`, without `--force`.
**Do not use `--force` against anything but a disposable database.**

---

*Phase 0 produced no source change. All eighteen findings above are preserved for
Phase 1 and later; none were fixed here.*

---

## Phase 1 — submission API contract

**Status: GATE PASS**

### Fixed in Phase 1

| # | Defect | Where | How it was found |
|---|---|---|---|
| P1-1 | `Request::capture()` called `self::header('Origin')` — a static call to a non-static method. Fatal on the first request of every process, so **every** endpoint was 500. | `app/Http/Request.php:96` | `bin/contract.php`. `selftest.php` and `integration.php` both build a `Request` by hand and pass `origin` directly, so nothing else could reach this line. |
| P1-2 | The `202` body reported `status: "SENT"` — a value the client does not use and the database does not contain, on a response that means "queued". | `app/Domain/SubmitController.php` | Contract test asserting the documented `QUEUED`. |
| P1-3 | The `200` body had no `status` field, so an idempotent replay was indistinguishable from a fresh acceptance. | `app/Domain/SubmitController.php` | Contract test asserting `ALREADY_RECEIVED`. |
| P1-4 | Neither response body carried `submission_id`, so a client could not correlate an acceptance with the polling target. | `app/Domain/SubmitController.php` | Contract test. |
| P1-5 | The client sent `agent_id` and `device_uuid` in the payload — an ownership claim the server must reject, and one the local model had no business holding. | `src/db/db.ts`, `src/pages/CapturePage.tsx` | Client-side test asserting the payload allowlist. |
| P1-6 | The client signed the assembled multipart body. The server signs the verbatim `payload` part, so **every real upload was rejected** as `SIGNATURE_INVALID`. | `src/api/client.ts` | Reading `Http\Request::signedBody()`. `docs/API.md` also instructed the wrong thing. |
| P1-7 | The client treated only `201`/`200` as success. A `202` — the real first-acceptance code — fell through to the retry branch, so **no submission was ever marked delivered**. | `src/sync/coordinator.ts` | Contract test. |
| P1-8 | A `2xx` with an unparseable body marked the submission `SENT` and pruned the photo, destroying the only copy of evidence. | `src/sync/coordinator.ts` | Test: malformed `202` must not become `SENT`. |

### P1-1 deserves its own note

P1-1 is the reason `bin/contract.php` exists. The Phase 0 audit found eighteen
defects and every one of them had passed `selftest.php` and `integration.php`.

That is not a gap in those suites; it is what they are for. Both exercise
functions, and the fatal was in the one line that turns an HTTP request into a
function call. No amount of unit or integration coverage reaches a line that
every test bypasses by constructing its argument directly. The class of bug is
"the wiring", and the only way to see the wiring is to send real bytes through
it.

`is_uploaded_file()` is the second reason. It returns false for a file that was
not genuinely uploaded, so no amount of direct handler invocation can assert a
successful `202` — the storage call refuses. A real SAPI is the only thing that
populates `$_FILES` the way Apache and LiteSpeed do.

### Deferred to Phase 2 (unchanged from Phase 0)

`agent_id` allowlisting was tightened rather than removed: unknown keys are
still rejected with `422`, but the ownership model itself, the challenge/login
flow, the base64url signature encoding and the `X-Device-Signature` →
`X-Request-Signature` header rename are untouched.

### Phase 1 gate

```
php private_storage/bin/selftest.php      # 63 assertions
php private_storage/bin/integration.php   # 15 assertions
php private_storage/bin/contract.php      #  9 assertions  (new)
php private_storage/bin/healthcheck.php   # PASS, 2 pre-existing warnings
npm test                                  # 29 tests (was 3)
npm run lint                              # 0 errors, 7 warnings (unchanged)
npm run build                             # pass
```

---

## Phase 2 — authentication and signing

**Status: GATE PASS**

Phase 0's F1 and F8 are the two findings that made the product unusable: the
client could not log in, and could not register a device even if it could. Both
are resolved. The authentication model is now username/password plus a
browser-generated device key, and the challenge→sign→login round trip is gone.

### Fixed in Phase 2

| # | Defect | Where | How it was found |
|---|---|---|---|
| P2-1 | The server had no passwords anywhere. Login required `{imei, device_uuid, challenge, signature}`, so the client's username/password POST could not succeed under any input. | `app/Domain/LoginController.php` | `bin/auth.php` over real HTTP. |
| P2-2 | The client posted `public_jwk`; the server read `public_key_jwk`. A 422 naming a field that does not exist, which reads as a server bug rather than a client typo. | `src/api/client.ts` | Test asserting the field names the server validates. |
| P2-3 | Registration is reachable only by a bootstrap token, and the bootstrap family survived it. A captured bootstrap cookie could still mint tokens and enrol another device indefinitely. | `app/Domain/DeviceController.php`, `RefreshTokenRepository::revokeUnboundForAgent()` | Test: the pre-registration cookie must return `401`. |
| P2-4 | Concurrent 401s each ran their own refresh. The refresh token is single-use and reuse revokes the whole family, so two tabs refreshing at once signed the user out. | `src/api/client.ts` | Test: two concurrent 401s must spend the cookie once. Verified failing by disabling the single-flight guard. |
| P2-5 | The access token was not restored on reload, so every page load left the client unable to sign anything. | `src/main.tsx`, `restoreSession()` | Test: restore from the cookie alone. |
| P2-6 | `Jwt::assertClaims()` accepted `device_uuid: null` on a device-scoped token, so a bootstrap-shaped token could claim a bound scope. | `app/Security/Jwt.php` | `bin/auth.php`. |
| P2-7 | `app/Security/Jwk.php` used `ApiException` without importing it — a fatal on the first malformed key. | `app/Security/Jwk.php` | `bin/auth.php`. |
| P2-8 | `DeviceStatus::denialCode()` returned an `ErrorCode` object where a `string` was declared, a `TypeError` on every revoked or suspended device. | `app/Security/DeviceStatus.php` | `bin/auth.php`. |
| P2-9 | `LeaderboardRepository::selfRow()` was passed an unused `$where` string where an array was expected; every logged-in leaderboard request was a `500`. | `app/Database/LeaderboardRepository.php` | `bin/auth.php`. |
| P2-10 | `bin/auth.php` deleted fixtures in one block, so the first foreign-key violation aborted the rest and the suite silently leaked its entire fixture set — 340 agents across a few runs, with no warning in the results. | `bin/auth.php` | Row counts before and after. |

### P2-10 deserves its own note

The suite reported `43 passed, 0 failed` for several runs while leaking
everything it created. The teardown deleted agents before their children, one
`review_jobs` table name did not exist, and the first rejection aborted the
remainder — so the rows stayed and the next run's results looked identical.

Nothing about a passing result distinguishes a suite that cleans up from one
that does not. A cleanup that can lose everything to a single bad row is not a
cleanup routine, so every delete is now isolated and counted, the order follows
the actual foreign-key graph, and a non-zero count is reported. `bin/purge_fixtures.php`
clears what earlier runs left behind.

### Deferred to Phase 3

The base64url signature encoding (F2) is fixed on the client and the nonce and
clock-skew paths are covered, but the leaderboard polling and the verification
UI remain as recorded in F7. No verification logic was changed in Phase 2.

### Phase 2 gate

```
php private_storage/bin/selftest.php      # 63 assertions
php private_storage/bin/integration.php   # 15 assertions
php private_storage/bin/contract.php      #  9 assertions
php private_storage/bin/auth.php          # 44 assertions  (new)
php private_storage/bin/healthcheck.php   # PASS, 2 pre-existing warnings
npm test                                  # 40 tests (was 3)
npm run lint                              # 0 errors
npx tsc --noEmit                          # clean
npm run build                             # pass
```
