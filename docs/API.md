# FieldPulse API

Base path: `/api/v1`. All responses are JSON. All timestamps are UTC ISO-8601.
All errors share one envelope, so a client only needs one error parser.

---

## Conventions

### Success

```json
{ "data": { "...": "..." } }
```

### Error

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Human-readable summary.",
    "field": "count_claimed",
    "request_id": "3f9a1c2e-..."
  }
}
```

`error.code` is stable and safe to branch on. `error.message` is not — it may be
reworded. `error.field` appears only for validation failures. `request_id` is
also written to the application log, so a user reporting a failure can quote it
and you can find the exact request.

| Code | Status | Meaning |
|---|---|---|
| `VALIDATION_FAILED` | 422 | A field is missing or malformed |
| `UNAUTHENTICATED` | 401 | Missing, expired, or invalid token |
| `FORBIDDEN` | 403 | Authenticated, but not permitted |
| `RATE_LIMITED` | 429 | Too many attempts; see `Retry-After` |
| `IDEMPOTENCY_CONFLICT` | 409 | `submission_uuid` belongs to another agent |
| `FILE_HASH_MISMATCH` | 422 | Uploaded bytes ≠ signed `file_sha256` |
| `FILE_TOO_LARGE` | 413 | Over `MAX_UPLOAD_BYTES` |
| `UNSUPPORTED_MEDIA` | 415 | Not `multipart/form-data` |
| `CLOCK_SKEW` | 401 | Signature timestamp outside ±`CLOCK_SKEW_SECONDS` |
| `NONCE_REPLAY` | 401 | Signature nonce already used |
| `DEVICE_MISMATCH` | 403 | Token and signature are for different devices |
| `CLOCK_TOO_OLD` | 401 | `captured_at` predates the allowed window |
| `SERVICE_UNAVAILABLE` | 503 | Storage or database unreachable |

### Authentication

Two independent factors on nearly every call:

1. **Access token** — `Authorization: Bearer <jwt>`, HS256, 15-minute lifetime.
2. **Device signature** — required on state-changing agent calls. Headers:

```
X-Device-Id: <device uuid>
X-Timestamp: <unix seconds>
X-Nonce: <random base64url, never reused>
X-Signature: <base64url signature>
```

The signed string is exactly:

```
METHOD \n PATH \n TIMESTAMP \n NONCE \n SHA256HEX(raw request body)
```

`PATH` is the request path only, with no query string and no scheme/host.

For `submit.php` the signed bytes are the **verbatim contents of the `payload`
part**, not the assembled multipart body. The multipart boundary is chosen by
the encoder and is not reproducible on the server side, so hashing the whole
body would mean hashing bytes the verifier can never reconstruct. The server
reads the `payload` part back out of the form and signs exactly those bytes.

**This is the single most common integration mistake.** The PWA must serialise
the `payload` JSON **once** and reuse that same string for both the hash and the
`payload` part. Serialising twice — even with identical key order — risks a
different byte sequence, and the result is a signature over bytes the server
never received, which presents as a `SIGNATURE_INVALID` on a request that looks
correct. `file_sha256` is a separate digest, computed over the file bytes, and is
*not* what the device signature covers.

### CSRF

`POST /auth/refresh` and `POST /auth/logout` are the only endpoints
cookie-authenticated, so only these enforce an `Origin` check. Everything else
uses a bearer header, which a browser will not attach cross-origin.

---

## Endpoints

| Method | Path | Auth |
|---|---|---|
| POST | `/auth/login` | public, username + password |
| POST | `/auth/refresh` | refresh cookie + Origin |
| POST | `/auth/logout` | refresh cookie + Origin |
| POST | `/device/register` | bootstrap token + pairing code |
| POST | `/api/v1/submit.php` | **signed** |
| GET | `/api/v1/submission.php?uuid=` | bearer, own submissions only |
| GET | `/api/v1/leaderboard.php` | bearer |
| GET | `/api/v1/reviews.php` | operator |
| POST | `/api/v1/reviews/decide.php` | operator |

---

### POST /auth/login

Exchanges a username and password for a **bootstrap** session.

```json
{ "username": "ada", "password": "…" }
```

```json
{
  "access_token": "eyJ…",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_expires_at": "2026-10-28T09:41:05Z",
  "agent": { "id": 3, "agent_code": "AG-001", "full_name": "Ada Lovelace" },
  "device_bound": false,
  "next_step": "device.register"
}
```

This response is **flat, not wrapped in `data`**, and so are `/auth/refresh`,
`/auth/logout` and `/device/register`.

A `Set-Cookie: fp_refresh=…; HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth`
accompanies it. The PWA cannot read it, which is the point.

The access token is a *bootstrap* token: `scope=bootstrap`, `device_uuid=null`.
It is deliberately weak and will only reach `/device/register`. Following it with
`/device/register` replaces it with a device-bound token. `device_bound` is
returned explicitly so a client never has to infer it from a scope claim.

**Every failure is the same `401 UNAUTHENTICATED` with the same body**, and
`Credentials::verify()` runs even when the username does not exist so the
response time does not reveal it either. No such user, wrong password, inactive
agent and no-credential-configured are indistinguishable at the edge: any of
them being distinguishable is a way to learn things about the account.

`429 RATE_LIMITED` here is expected during a credential-stuffing attempt. The
limit is keyed on the username **and** the source IP; per-IP is what stops a
single host walking the whole user table.

IMEI is not accepted, read, or required anywhere in this flow. It is not an
authentication factor and is not used as one.

---

### POST /auth/refresh

Body is empty. Reads the cookie, rotates the token, and issues a new one. **A
refresh token is single-use.** Presenting a token that was already rotated means
the token was stolen and replayed, so the entire family is revoked and every
device for that agent must log in again. That is deliberately harsh: it is the
only way reuse is detectable.

---

### POST /device/register

Binds a browser-generated ECDSA P-256 public key to the agent, upgrading the
bootstrap session. Authenticated by the **bootstrap** access token; the Kernel
rejects any other token on this route.

```json
{
  "device_uuid": "9f1c1f6e-…",
  "public_key_jwk": { "kty": "EC", "crv": "P-256", "x": "…", "y": "…" },
  "pairing_code": "1234567890"
}
```

The private key is generated non-extractable and never leaves the browser; only
the JWK above is sent. The field is `public_key_jwk`, a JWK object — not a PEM
string.

`pairing_code` is required when the agent's policy demands it (`ALWAYS`, or
`FIRST_DEVICE_ONLY` before the first device exists). If it is missing, this
returns `422 VALIDATION_FAILED` with `error.details.field = "pairing_code"`,
which is how the client knows to prompt: the policy is server-side, so the UI
asks when it is asked rather than guessing. Codes are issued out of band by
`bin/pair_device.php`, are single-use, and are consumed by a conditional
`UPDATE`, so two concurrent redemptions cannot both succeed.

A `device_uuid` that already exists and belongs to **this** agent re-binds
idempotently; the same key must be presented, or it is refused. One belonging to
another agent returns the same generic `401` as every other failure, so this
route is not a device-enumeration oracle.

On success the bootstrap refresh family is **revoked** (`BOOTSTRAP_CONSUMED`)
and a device-bound token and cookie are returned:

```json
{
  "device_uuid": "9f1c1f6e-…",
  "status": "ACTIVE",
  "device_bound": true,
  "access_token": "eyJ…",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_expires_at": "2026-10-28T09:41:05Z",
  "agent": { "id": 3, "agent_code": "AG-001", "full_name": "Ada Lovelace" }
}
```

Retiring the bootstrap family is the point of the binding step. Until a device
is bound, login is pure credentials, so an unrevoked bootstrap session could mint
tokens and enrol another device indefinitely; revoking it is scoped to
`device_id IS NULL` so the bound token minted in the same request is untouched.

---

### POST /api/v1/submit.php

`multipart/form-data` with two parts:

| Part | Type | Notes |
|---|---|---|
| `payload` | JSON string | The signed metadata. These exact bytes are what the device signature covers. |
| `file` | binary | JPEG or PNG, ≤ `MAX_UPLOAD_BYTES` |

The upload is validated on the server before anything is stored, and every check
uses the **bytes**, never the client's claims:

| Check | Rejects |
|---|---|
| `UPLOAD_ERR_OK` | truncated or failed multipart bodies |
| `is_uploaded_file()` | a `payload`-style field passed as `file` |
| byte size vs `MAX_UPLOAD_BYTES` | oversized uploads (`413`) |
| `finfo` against `storage.allowed_mimes` | anything that is not genuinely JPEG or PNG |
| actual GD decode | a file with a valid header but no image data |
| width/height vs `MAX_IMAGE_DIMENSION` | oversized dimensions |
| width × height vs `MAX_IMAGE_PIXELS` | decompression-bomb pixel counts |
| `sha256(file bytes)` vs `file_sha256` | a file swapped after signing |

Two consequences worth stating plainly. A valid JPEG header is not sufficient:
libjpeg tolerates a truncated scan and returns an image, so the decode check is
a real gate rather than a formality. And the extension comes from the
server-detected MIME, so a Windows PE binary renamed `photo.jpg` with a matching
client MIME is rejected on content, not on its name.

```json
{
  "submission_uuid": "9f1c…",
  "count_claimed": 12,
  "captured_at": "2026-09-28T09:41:05Z",
  "latitude": 6.5244,
  "longitude": 3.3792,
  "accuracy_m": 12.5,
  "file_sha256": "e3b0c442…",
  "notes": "optional, ≤500 chars"
}
```

`submission_uuid` is generated on the device and is the idempotency key.
`file_sha256` is **required**: it is what ties the device signature to the file
bytes, and omitting it would allow the image to be swapped after signing.
`latitude` and `longitude` must be supplied together or not at all.
`captured_at` is ISO-8601.

**There is no `agent_id`, `device_uuid`, or IMEI in this payload, by design.**
Ownership is resolved server-side from the bearer token and the bound device
row, so the request has no say in whose submission it becomes. The field list is
enforced as an allowlist: an unknown key — including an `agent_id` — is rejected
with `422`, not silently dropped, so a client that tries to assert its own owner
fails loudly instead of appearing to succeed.

#### Responses

| Status | Meaning |
|---|---|
| `202` | Accepted and queued for verification. First time this UUID is seen. |
| `200` | Idempotent replay of your own submission; `idempotent_replay: true` |
| `409` | That UUID belongs to another agent |

**`202` — first acceptance**

```json
{
  "data": {
    "status": "QUEUED",
    "submission_uuid": "9f1c…",
    "submission_id": 41,
    "self": "/api/v1/submission.php?uuid=9f1c…",
    "count_claimed": 12,
    "received_at": "2026-09-28T09:41:07Z",
    "estimated_review_seconds": 900
  }
}
```

`submission_id` is a JSON **number**, not a string. It is the server's row id and
is stable for the life of the submission; poll `self` for the verdict.

**`200` — idempotent replay**

Same UUID, same `submission_id` as the original, plus:

```json
{
  "data": {
    "status": "ALREADY_RECEIVED",
    "submission_uuid": "9f1c…",
    "submission_id": 41,
    "idempotent_replay": true,
    "submission_status": "QUEUED",
    "self": "/api/v1/submission.php?uuid=9f1c…"
  }
}
```

`202` and `200` are **both terminal for the client**: in either case the server
holds the file and the local copy may be pruned. A retry is free, because the
UUID is the idempotency key.

A `2xx` whose body does not match the shape above is **not** an acceptance.
Treat it as a failure and retry — a proxy returning `200` with an HTML error
page would otherwise mark a submission as delivered that the server never
received, and prune the only copy of the evidence.

`202` is correct, not provisional hand-waving: the file is stored and the work
is scheduled. The verdict arrives asynchronously — see VERIFICATION.md. A `202`
followed by a `REJECTED` verdict is the system working, not a contradiction.

**`409` — idempotency conflict**

```json
{
  "error": {
    "code": "IDEMPOTENCY_CONFLICT",
    "message": "That submission identifier belongs to another agent."
  }
}
```

The response deliberately contains no `data` envelope: it must not confirm that
the other agent's submission exists, and must not reveal anything about it.
This is terminal for the client.

---

### GET /api/v1/submission.php?uuid=…

Bearer-authenticated, and scoped to the calling agent's own submissions.

```json
{
  "data": {
    "submission_uuid": "9f1c…",
    "status": "REQUIRES_REVIEW",
    "count_claimed": 12,
    "received_at": "2026-09-28T09:41:07Z",
    "captured_at": "2026-09-28T09:41:05Z",
    "verified_at": null,
    "counted": false,
    "pending": false,
    "disposition": "REQUIRES_REVIEW",
    "reason": "SITE_UNASSIGNED",
    "reasons": [{ "code": "SITE_UNASSIGNED", "detail": "…" }],
    "awaiting_review": true,
    "reviewed_at": null,
    "verification_version": "v1.0.0"
  }
}
```

`pending: true` means the job has not finished; the disposition fields are
absent rather than guessed, because reporting `VERIFIED` for a submission with
no verdict yet would be a lie the PWA would show the agent as a confirmed count.

A UUID belonging to another agent returns `404 UNKNOWN_SUBMISSION`, identical to
a UUID that does not exist. A `403` would confirm the UUID is real, turning this
into an oracle for guessing other agents' submission identifiers.

`awaiting_review` distinguishes "flagged by the automated pass" from "a
supervisor has ruled on it" — the same `REQUIRES_REVIEW` disposition covers both,
and an agent whose submission has been sitting unreviewed for a week deserves to
be able to tell that apart from one already actioned.

---

### GET /leaderboard

Query: `period` (`YYYY-MM-DD`, any day in the week — normalised to its Monday),
`site` (site name or id, optional), `limit` (1–100, default 25), `offset`.

```json
{ "data": { "period_start": "2026-09-28", "site": null, "entries": [ … ], "self": { "rank": 12, "total_verified_count": 31 } } }
```

`self` is present because "is my rank different from what the list shows?" is
the only question a field agent actually asks. Entries expose
`agent_code`, `name`, `site_name`, `total_verified_count`. No email, no IMEI, no
device data — this endpoint is readable by every agent, so it exposes nothing
that is not already public within the field programme.

---

### GET /reviews  ·  POST /reviews/decide

Operator-only (`SUPERVISOR` or `ADMIN`). An `AGENT` role receives `403`.

`GET /reviews` returns the queue with the evidence needed to decide: the image,
EXIF, geofence result, nearest duplicate and its distance, and the full list of
reasons the automated pass recorded.

`POST /reviews/decide`:

```json
{ "submission_id": 41, "decision": "APPROVE", "note": "timestamp discrepancy explained" }
```

Every decision is appended to an immutable `review_decisions` row recording who
acted, when, the note, and the `VERIFICATION_VERSION` in force. Nothing
overwrites a prior decision, so the audit trail cannot be edited away by the same
supervisor who made it.

---

## Client integration order

1. `POST /auth/login` with username and password. Keep the returned access token
   in memory only — never in `localStorage` or `sessionStorage` — and let the
   `HttpOnly` refresh cookie be stored by the browser.
2. Generate a non-extractable ECDSA P-256 key pair in the browser
   (`crypto.subtle.generateKey(..., false, ['sign','verify'])`), persist the
   `CryptoKey` handle and its public JWK in IndexedDB, and never send the
   private half anywhere.
3. `POST /device/register` with the bootstrap token, `device_uuid`,
   `public_key_jwk`, and a pairing code if the server asks for one. This returns
   the device-bound token that replaces the bootstrap one.
4. On a page load with no token in memory, call `/auth/refresh` once to restore
   it. If that succeeds but IndexedDB has no private key, the state is
   `UNREGISTERED` — the cookie is fine, the browser cannot prove the device —
   and the user re-registers rather than logging in again.
5. On any `401`, call `/auth/refresh` and retry **once**. Serialise refreshes
   with `navigator.locks`: the refresh token is single-use, so two tabs (or two
   concurrent requests) refreshing independently present a rotated token and the
   server revokes the family. Broadcast the new access token to sibling tabs
   rather than storing it.
5. For `submit`, serialise the `payload` JSON **once**, sign that string, then
   send it as the `payload` part alongside `file`. Never sign the assembled
   multipart body — the boundary is not reproducible server-side.
6. On `202` or `200` with a well-formed body, the submission is delivered:
   prune the local photo. On any other outcome, keep it and retry.
7. Poll the `self` link until `pending` is false, or refresh proactively at ~80%
   of `expires_in` rather than waiting for the 401 — that saves a round trip on
   flaky mobile networks.
