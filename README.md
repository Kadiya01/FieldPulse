# FieldPulse

Responsive web application for agent activity reporting, verification, weekly
standing, and rewards.

Field agents report the enrollment activity they perform in the field. Every
report a device sends is a *claim* until a server-side pipeline has checked it,
and only verified work counts toward an agent's weekly standing. At period
close that verified weekly standing is frozen, and the organisation rewards
agents from that frozen standing — never from a client assertion, and never
from a standing that has since moved on.

Offline capture and installability are features of this web application. They
are not what the product is: FieldPulse is a responsive web app, not a native
mobile application.

---

## Table of contents

- [The problem](#the-problem)
- [The solution](#the-solution)
- [Features](#features)
- [Architecture](#architecture)
- [The offline and verification flow](#the-offline-and-verification-flow)
- [Security model](#security-model)
- [What this is not evidence of](#what-this-is-not-evidence-of)
- [What FieldPulse does not do](#what-fieldpulse-does-not-do)
- [Verification](#verification)
- [Deploying to cPanel](#deploying-to-cpanel)
- [Documentation](#documentation)
- [Limitations](#limitations)
- [Repository layout](#repository-layout)

---

## The problem

Organisations with field agents have to decide, every week, who did what and
what they are owed for it. The existing options fail in the same way:

- A paper form is reliable and unverifiable. It arrives days late, and a number
  on paper has no provenance anyone can check afterwards, so the weekly reward
  decision rests on whoever wrote it down.
- A form that requires a connection fails at exactly the wrong moment. The agent
  is standing in front of the thing being counted, with the phone in one hand,
  and the app refuses because there is no bar of signal. The activity is not
  recorded — or it is recalled later from memory, and a number recalled from
  memory is not something to reward on either.

The gap in the middle is the actual problem: reporting that works offline *and*
a verification step that can be trusted later, so that a weekly standing can be
computed and rewarded without asking anyone to take a number on faith. Most
attempts collapse one of those two into the other — either they upload whatever
the client asserts and call it verified, or they make verification so strict
that a legitimate agent working a noisy site is treated as a fraud.

## The solution

FieldPulse separates the report from the verification, and is explicit about the
boundary. It does not perform enrollment; it records what agents report having
done, checks it, and turns the checked result into a standing that an
organisation can reward from.

**On the handset.** Capture works with no network at all. The image, the count,
and the claimed location and time are written to IndexedDB with a local queue
state that says only one truthful thing: *not yet uploaded*. When a network
appears, the queue syncs. The upload's server response is also a receipt, not a
verdict.

**On the server.** A separate worker picks the submission up and runs fourteen
steps, thirteen of which are checks, against the file itself and against
server-side facts the client cannot influence: the SHA-256 of the bytes as
stored, whether GD can decode them, the EXIF block, the perceptual hash, the
agent's assigned sites, the server's own receive time, and the agent's weekly
cap. Only then does the row get a disposition — `VERIFIED`, `REQUIRES_REVIEW`,
or `REJECTED`.

**At period close.** When a weekly period's grace window has elapsed, closing it
freezes the verified weekly ranking into an immutable row and writes each
agent's reward entitlement from that frozen row. The entitlement is *published*
so the agent can see it; publication is not payment. An operator approves it,
and only the organisation's own confirmation marks it paid. Later corrections to
live performance summaries cannot move an entitlement that has already been
published from a frozen ranking.

The UI never collapses these into one status. A queued row reads *"Queued"*, not
*"Sent"*, because "sent" invites the reading "received and confirmed". A
received row reads *"Received — not yet verified"* until the server says otherwise.
A published reward reads *"awaiting approval"*, not *"paid"*, because approval
and payment are separate acts by separate people. See
[docs/VERIFICATION.md](docs/VERIFICATION.md) for every threshold and why it
sits where it does, and [docs/REWARDS.md](docs/REWARDS.md) for the close and
publication rules.

## Features

- **Reporting, not enrollment.** FieldPulse does not perform enrollment. Agents
  report the enrollment activity they did in the field; the application
  captures the evidence for that report and lets the server verify it.
- **Weekly standing and rewards.** Closing a period freezes the verified weekly
  ranking and writes reward entitlements from that frozen ranking, ranked by
  verified count only. Publishing an entitlement tells the agent what they are
  owed — it does not pay them.
- **Offline capture.** Photo, count and metadata persist to IndexedDB and sync
  later. A failed upload is a state, not an error the agent has to solve.
- **Idempotent submission.** Client-generated UUIDs mean a retry after a timeout
  is a replay (`200`), not a second row (`409` only for a genuine collision
  across agents).
- **Asynchronous verification.** Submissions return `202` in milliseconds;
  expensive work happens in `workers/process_queue.php` with retries, a claim
  lease, and a visible failure state.
- **Perceptual duplicate detection.** A 64-bit DCT-based pHash plus a
  band-indexed distance search catches re-photographed evidence, not just
  re-uploaded bytes.
- **Geofencing that escalates instead of accusing.** An agent with no site
  assigned, or a fix too coarse to decide the fence, goes to human review — never
  to auto-verified, because that would make removing a site assignment a way to
  disable geofencing entirely.
- **Operator review queue.** Supervisors and admins see the image, the evidence
  checks, the nearest duplicate and its distance, and approve or reject with a
  required note. Agents cannot reach it; the server enforces the role
  independently of what the UI shows.
- **Device-bound sessions.** A non-extractable ECDSA P-256 key in the browser
  signs state-changing requests. Device registration needs an operator-issued
  one-time pairing code.
- **Installable PWA.** Manifest, icons, and an offline service worker — a
  capability of a responsive web application, not the product's identity.
- **Leaderboard** over verified counts only.
- **Privacy by omission.** The leaderboard returns no email, no device data, and
  no identifiers beyond an agent code.

## Architecture

```
  browser (responsive web app)       server (cPanel shared hosting)
  ───────────────                     ──────────────────────────────────
  React + TypeScript                  PHP 8.2, no framework, no Composer
  IndexedDB queue                     MySQL 8.0.3+ or MariaDB 10.11+
  ECDSA key in IndexedDB              private_storage/ outside document root
  service worker                      public_html/.htaccess denies dotfiles
       │                                     │
       │  POST /api/v1/submit.php           │
       │  bearer + ECDSA signature          ▼
       │  ────────────────────────►  Kernel: rate limit, auth, signature
       │                                     │
       │  202 { status: QUEUED }     writes file to quarantine/
       │  ◄────────────────────────       ledger row, enqueues job
       │                                     │
       │  GET /api/v1/submission.php?uuid=  workers/process_queue.php
       │  ◄────────────────────────────     13 checks → disposition
       │                                     │
                                       processed/<disposition>/, aggregate
```

The document root holds only `index.html`, `assets/`, and `api/`. All
application code, configuration, logs and stored images live in
`private_storage/`, one level above it, so a misconfigured host that serves the
whole account still cannot read them. `public_html/.htaccess` denies dotfiles,
undeclared suffixes and the `api/.htaccess` itself.

## The offline and verification flow

The full state machine, and the part most worth reading before changing anything
about the client:

| Local state | Meaning | What the agent sees |
|---|---|---|
| `PENDING` | Written locally, never sent | *Waiting to upload* |
| `SYNCING` | Upload in flight | *Uploading now* |
| `RETRY_WAIT` | Network lost or the server asked for a backoff; a retry is scheduled | *Upload failed, will retry* |
| `SENT` | Server returned `2xx` | *Uploaded* |
| `FAILED_AUTH` | The session expired mid-sync | *Sign in again to upload* |
| `FAILED_PERMANENT` | The server refused the submission outright | *Upload refused* |

Every one of these is a **receipt**. None of them is a verification. `SENT` in
particular is not a synonym for "confirmed", and the queue screen says so in
those words both before and after sync.

The server's verdict arrives only from
`GET /api/v1/submission.php?uuid=…` and is shown in a separate column, with its
own wording — `QUEUED` reads *"Queued for verification"*, `REQUIRES_REVIEW`
reads *"With a supervisor — not counted yet"*, `REJECTED` reads *"Rejected — not
counted"* — so a queued row cannot be misread as a confirmed one, and a verified
count is visibly distinguished from one merely received.

When a submission is `SENT` and the device is online, the queue fetches the
server's state on a timer; offline it says so rather than leaving a stale
verdict on screen.

## Security model

- **Two factors on state-changing calls.** A 15-minute HS256 bearer token *and*
  an ECDSA signature over `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256HEX(body)`, with
  a replay-protected nonce and a timestamp window.
- **A weak first token by design.** Login returns a `scope=bootstrap` token that
  can reach exactly one route: device registration. Upgrading it to a
  device-bound token requires the pairing code an operator issues.
- **The refresh cookie is unreadable by design.** `HttpOnly`, `Secure`,
  `SameSite=Strict`, scoped to `/api/v1/auth`, and `Origin`-checked — the only
  two cookie-authenticated endpoints, so they are the only two that need CSRF
  defence.
- **No enumeration oracle.** Every login failure returns the same
  `401 UNAUTHENTICATED` body, and the password hash is verified even when the
  username does not exist, so response time reveals nothing either.
- **Validation details are safe in production.** `error.details.field` is
  allowlisted rather than gated behind `APP_DEBUG`, so a client can tell an agent
  which input is wrong without the debug flag leaking internals.
- **No IMEI in the auth flow.** An IMEI may be recorded on the agent row by an
  operator for inventory, but no endpoint accepts it, no login uses it, and a
  browser cannot read one from a handset.

## What this is not evidence of

A `VERIFIED` disposition means the server's checks passed. It does **not** prove
which device took the photograph, who was holding it, or that the image was not
edited before upload.

Every client-supplied value — captured time, coordinates, accuracy — is a claim.
It is cross-checked against the file's own EXIF and against server-observed
facts, and disagreement triggers review. But a determined client controls those
values, so the checks bound what an honest-but-sloppy agent gets away with; they
are not adversarial authentication.

**There is no hardware attestation, and nothing here pretends otherwise.** The
key pair is browser-generated and non-extractable, and a PWA "installation" is a
service-worker registration a user can delete and re-create. Neither is platform
attestation, so claims of "hardware-backed identity" or "proof the photo came
from this handset's camera" would be false. What the key genuinely provides is
device *binding*: a stolen password cannot mint a second bound device without the
pairing code, because the private half never leaves the browser.

Attestation would require the Android `Keystore`/`Camera2` signed-capture path or
in-app store distribution. Neither is implemented, and both change the deployment
model.

## What FieldPulse does not do

- **It does not pay anyone.** A published reward entitlement is a statement that
  the organisation owes an agent something for a frozen week. FieldPulse never
  moves money. An agent looking at `PENDING` or `APPROVED` has been *told what
  they are owed* — they have not been paid, and the UI must never say otherwise.
  Only the organisation's own confirmation marks an entitlement paid.
- **It does not perform enrollment.** FieldPulse is not an enrollment system.
  Agents report the enrollment activity they performed in the field; this
  application captures the evidence, verifies it, freezes the resulting weekly
  standing, and makes the reward defensible. Enrollment itself happens outside
  it. The word "provision" is used for creating an agent account, because
  enrolling an agent is not what that command does.
- **It is not a native mobile application.** It is a responsive web application.
  Installing it adds an icon and an offline cache; nothing is distributed through
  an app store, and the service worker can be deleted and re-created.
- **It does not choose who to reward, or how much.** The organisation supplies
  the tier schedule. FieldPulse computes the frozen weekly standing the schedule
  applies to, and stops there.
- **It does not decide rewards from a live leaderboard.** The standing used for a
  reward is the one frozen when the period closed. A leaderboard that moves
  afterwards is informational.

## Verification

The complete gate. Everything below is expected to pass with zero warnings.

```bash
# frontend
npm ci
npm run lint      # oxlint, no warnings
npm test          # vitest
npm run build     # tsc -b && vite build

# backend — deploy_test.php owns "every PHP file parses" and the .htaccess
# assertions; the rest are individual suites
php private_storage/bin/deploy_test.php --httpd=...  # syntax + .htaccess + branding
php private_storage/bin/selftest.php                # offline unit tests
php private_storage/bin/verification.php            # EXIF, pHash, timestamps, geofence
php private_storage/bin/queue.php                   # queue claiming, retries, pipeline
php private_storage/bin/contract.php                # auth, signature, idempotency
php private_storage/bin/integration.php             # end to end against a live database
php private_storage/bin/healthcheck.php             # live environment, schema, storage
php private_storage/bin/brand_assets.php --check    # icons and colours match the source

# both database engines, from empty
php private_storage/bin/db_matrix.php
```

Or run all of it — five tiers, fresh database, real browser and both engines,
from one command:

```bash
php private_storage/bin/release_gate.php --httpd=/path/to/apache/bin/httpd
```

The gate prints every tier's result and exits non-zero on any failure; a PASS
line at the end is the certification. The record of the last run is in
[docs/RELEASE.md](docs/RELEASE.md).

`db_matrix.php` applies every migration from scratch and runs the
database-facing suites per engine. A cell only reads PASS if migrations applied
*and* the healthcheck is clean *and* the suites are green, and the exit code is
non-zero if any cell fails.

Two things about running these by hand, both discovered the hard way:

- **The database suites are not idempotent against a used database.**
  `bin/queue.php` builds a fixture from a deterministic image seed stamped with
  the current time, so a second run against the same database produces a
  byte-different but perceptually identical image — and the duplicate detector,
  working correctly, rejects it. Start from a fresh database; `db_matrix.php`
  does this for you. [docs/VERIFICATION.md](docs/VERIFICATION.md) has the
  details.

  This matters for ordering, because `bin/deploy_test.php` shells out to
  `bin/queue.php` itself. So a full pass is:

  ```bash
  php private_storage/bin/db_matrix.php            # both engines, from empty
  # then, on a freshly migrated database:
  php private_storage/bin/deploy_test.php --httpd=/path/to/apache/bin/httpd
  ```

  Pass `--httpd` when an Apache binary is available. Without it, the suite still
  runs every static `.htaccess` assertion but reports a warning that the
  status-code tier was skipped — and a skipped tier is not a pass.
- **PHP needs an OpenSSL config file on Windows.** Without
  `OPENSSL_CONF` pointing at `extras/ssl/openssl.cnf`, every HTTPS-capable test
  fails on certificate loading and it looks like a code fault.

Supported engines are MySQL **8.0.3 or later** (tested on 8.0.40) and MariaDB
**10.11 or later** (tested on 11.4.13). MariaDB 10.3–10.10 is not claimed; the
`SKIP LOCKED` fallback exists, but those versions are not in the matrix.

## Deploying to cPanel

Full instructions, including `.htaccess` requirements and the credential and
pairing-code workflow, are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). In short:

1. Build the frontend: `npm run build`.
2. Copy `dist/` into `public_html/`, and `api/` to `public_html/api/`.
3. Upload `private_storage/` **outside** the document root — a sibling of
   `public_html`, not a child — and set permissions (`750` on the directory,
   `640` on `.env` and the migration files) or it will be served as plaintext.
4. `cp .env.example private_storage/.env`, set the database credentials, and
   generate the signing secret with
   `php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"` into `JWT_SECRET`.
   **Rotating it invalidates every access and refresh token**, so every agent is
   signed out.
5. Run `php private_storage/bin/migrate.php`.
6. Confirm the host honours `AllowOverride All`, or the `.htaccess` denials do
   nothing.
7. `php private_storage/bin/healthcheck.php` must be clean before you provision
   anyone.
8. Provision with `bin/provision_agent.php`, issue a pairing code with
   `bin/pair_device.php`, and hand both to the agent out of band.

## Documentation

| Document | Covers |
|---|---|
| [docs/API.md](docs/API.md) | Every endpoint, the signature scheme, the error envelope |
| [docs/VERIFICATION.md](docs/VERIFICATION.md) | All fourteen pipeline steps, every threshold, and what the pipeline cannot prove |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | cPanel install, `.htaccess`, cron, secrets, the database matrix |
| [docs/PRODUCTION_AUDIT.md](docs/PRODUCTION_AUDIT.md) | The original hardening pass, kept for what it found |
| [docs/RELEASE.md](docs/RELEASE.md) | The release gate: what it runs, and the last certification |

## Limitations

Stated plainly, because each is a real constraint rather than a bug report.

- **No native hardware attestation.** See
  [above](#what-this-is-not-evidence-of).
- **Adversarial clients are not fully addressed.** The checks bound careless and
  honest-but-sloppy input, not a determined attacker who controls their own
  client.
- **Duplicate detection is heuristic.** A pHash catches re-photographed
  evidence; an image altered enough to escape the hash band is not detected, and
  the band width is a deliberate trade against false positives on legitimately
  similar shots.
- **Geofencing escalates rather than decides.** No site, a coarse fix, or an
  agent working across a boundary all reach a human. This is intentional: the
  alternative fails open.
- **Review throughput is the bottleneck.** Verification is a queue behind one
  cron worker. High volume means longer `estimated_review_seconds`, and the
  estimate is not a deadline.
- **A verified submission is not provenance.** A reviewer can establish that a
  file satisfies the policy, not who produced it.
- **Browser storage is evictable.** A user who clears site data before syncing
  loses the queued submissions; the capture screen says so.
- **Single-role per agent.** One agent row carries one role; there is no
  per-site scoping of what a supervisor may review.
- **MariaDB below 10.11 is untested**, not supported.

## Repository layout

```
src/                     React app — pages, API client, crypto, IndexedDB queue
public/                  favicon and app icons, generated by bin/brand_assets.php
public_html/             the only deployed directory: shell, assets, api/
private_storage/
  app/                   Domain, Database, Verification, Security, Http, Support
  bin/                   migrations, tests, deployment and admin tooling
  migrations/            numbered, forward-only
docs/                    API, verification policy, deployment, audit
```

`private_storage/` must never be inside the document root. `bin/deploy_test.php`
and the deployment guide both assume it, and the whole configuration-secrecy
argument depends on it.