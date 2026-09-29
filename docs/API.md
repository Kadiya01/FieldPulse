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

`PATH` is the request path only, with no query string and no scheme/host. For
`submit.php` the body is the multipart body, byte for byte — which is why the
JSON metadata travels in a `payload` part: re-serialising it would change the
bytes and break the signature.

**This is the single most common integration mistake.** The PWA must capture the
multipart body as a `Blob`/`ArrayBuffer`, hash those exact bytes, and send the
same bytes. Building the form twice — once to hash, once to send — will
reorder the parts and produce a `FILE_HASH_MISMATCH` that looks like a bug in the
server.

### CSRF

`POST /auth/refresh` and `POST /auth/logout` are the only endpoints
cookie-authenticated, so only these enforce an `Origin` check. Everything else
uses a bearer header, which a browser will not attach cross-origin.

---

## Endpoints

| Method | Path | Auth |
|---|---|---|
| POST | `/auth/challenge` | public |
| POST | `/auth/login` | public |
| POST | `/auth/refresh` | refresh cookie + Origin |
| POST | `/auth/logout` | refresh cookie + Origin |
| POST | `/device/register` | public, pairing code required |
| POST | `/submit` | **signed** |
| GET | `/submission.php?uuid=` | bearer, own submissions only |
| GET | `/leaderboard` | bearer |
| GET | `/reviews` | operator |
| POST | `/reviews/decide` | operator |

---

### POST /auth/challenge

Issues the one-time value a device must sign to log in.

```json
{ "imei": "490154203237518", "device_id": "…optional for first binding…" }
```

```json
{ "data": { "challenge_id": "…", "challenge": "base64url", "expires_at": "…", "expires_in": 300 } }
```

`429 RATE_LIMITED` here is normal and expected during a credential-stuffing
attempt; the limit is per IMEI **and** per source IP.

---

### POST /auth/login

```json
{ "imei": "490154203237518", "challenge_id": "…", "signature": "base64url ES256" }
```

```json
{
  "data": {
    "access_token": "eyJ…",
    "token_type": "Bearer",
    "expires_in": 900,
    "refresh_expires_in": 2592000,
    "device_id": "…",
    "agent": { "agent_code": "AG-001", "name": "…", "role": "AGENT" }
  }
}
```

A `Set-Cookie: fp_refresh=…; HttpOnly; Secure; SameSite=Strict; Path=/api/v1/auth`
accompanies this. The client must store it; the PWA cannot read it, which is the
point.

An unknown IMEI returns `401` with a **generic** message. Telling a caller
whether an IMEI is registered turns the login endpoint into an enumeration oracle
for the entire agent roster, and since agent codes are guessable, that leaks the
operator's book.

---

### POST /auth/refresh

Body is empty. Reads the cookie, rotates the token, and issues a new one. **A
refresh token is single-use.** Presenting a token that was already rotated means
the token was stolen and replayed, so the entire family is revoked and every
device for that agent must log in again. That is deliberately harsh: it is the
only way reuse is detectable.

---

### POST /device/register

Binds a new device key to an agent. Requires **either** a one-time pairing code
issued by an operator (`bin/pair_device.php`) **or** a valid access token for a
device already bound to the same agent.

```json
{ "imei": "490154203237518", "pairing_code": "1234567890", "device_id": "…", "public_key": "…PEM…" }
```

Without a pairing code and without an existing valid session, this returns
`403`. A known IMEI is not a credential — anyone who learns a 15-digit IMEI must
not be able to bind a device to that agent, and IMEIs are guessable in bulk.

---

### POST /submit

`multipart/form-data` with two parts:

| Part | Type | Notes |
|---|---|---|
| `payload` | JSON string | The signed metadata |
| `file` | binary | JPEG or PNG, ≤ `MAX_UPLOAD_BYTES` |

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

Responses:

| Status | Meaning |
|---|---|
| `202` | Accepted and queued for verification |
| `200` | Idempotent replay of your own submission; `idempotent_replay: true` |
| `409` | That UUID belongs to another agent |

`202` is correct, not provisional hand-waving: the file is stored and the work
is scheduled. The verdict arrives asynchronously — see VERIFICATION.md. A `202`
followed by a `REJECTED` verdict is the system working, not a contradiction.

The response includes `self`, the polling target for the verdict.

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

1. `GET /auth/challenge` with the IMEI.
2. Sign the challenge with the device key. `Security\CanonicalPayload::build()`
   is the reference implementation; the challenge itself is signed with the same
   detached-signature encoding, not the header scheme.
3. `POST /auth/login`, store the refresh cookie, keep the access token in memory.
4. On any `401`, call `/auth/refresh` and retry **once**.
5. For `submit`, build the multipart body once, hash it, sign, send.
6. Poll the `self` link until `pending` is false, or refresh proactively at ~80%
   of `expires_in` rather than waiting for the 401 — that saves a round trip on
   flaky mobile networks.
