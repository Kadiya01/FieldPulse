import { getDeviceIdentity, signPayload, hashRequestBody, generateNonce } from '../crypto/keys';
import {
  getAccessToken,
  setAccessToken,
  announceAccessToken,
  onRemoteAccessToken,
  handleAuthFailure
} from '../auth/session';

export const API_BASE = '/api/v1';

/**
 * An error carrying the server's own `error` envelope.
 *
 * The server distinguishes UNAUTHENTICATED, RATE_LIMITED, VALIDATION_FAILED
 * and so on, and several flows here branch on that distinction — a missing
 * pairing code is a 422 naming the field, not a 401. Flattening every response
 * to a boolean throws away the only information that tells the UI what to do
 * next, so the envelope is kept intact and `field` is surfaced separately
 * because it is the one detail the registration UI acts on.
 */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly field: string | null;

  constructor(status: number, code: string, message: string, field: string | null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.field = field;
  }
}

interface ErrorEnvelope {
  error?: {
    code?: string;
    message?: string;
    details?: { field?: string };
  };
}

async function toApiError(response: Response): Promise<ApiError> {
  let envelope: ErrorEnvelope = {};

  try {
    envelope = (await response.json()) as ErrorEnvelope;
  } catch {
    // A proxy or a PHP fatal returns HTML, not the envelope. The status alone
    // is still worth reporting, so this is not rethrown.
  }

  return new ApiError(
    response.status,
    envelope.error?.code ?? 'UNKNOWN',
    envelope.error?.message ?? `Request failed (${response.status}).`,
    envelope.error?.details?.field ?? null
  );
}

export interface ApiRequestOptions extends RequestInit {
  body?: string | FormData;
  /**
   * The exact bytes the device signature must cover, when they are not the
   * literal `body`.
   *
   * For a multipart request the server signs the verbatim `payload` part
   * (see docs/API.md and Http\Request::signedBody), not the assembled
   * multipart body, because the multipart boundary is chosen by the encoder
   * and is not reproducible on the server side. The caller therefore builds the
   * payload JSON once and passes that same string here and as the part, so the
   * bytes that were signed and the bytes that are sent cannot differ.
   *
   * Omit it for a JSON request, where `body` is already the signed content.
   */
  signedBody?: string;
  /**
   * Internal: set on the single post-refresh retry so a second 401 stops
   * instead of recursing. Not for callers.
   */
  __retried?: boolean;
}

export async function authenticatedFetch(path: string, options: ApiRequestOptions = {}): Promise<Response> {
  const device = await getDeviceIdentity();
  if (!device) {
    // If the Web Crypto private key is cleared or missing from IndexedDB, transition to UNREGISTERED state
    handleAuthFailure('UNREGISTERED');
    throw new Error('Device not registered or private key missing');
  }

  const accessToken = getAccessToken();
  if (!accessToken) {
    throw new Error('No access token available');
  }

  const method = options.method || 'GET';
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = generateNonce();

  // The signed content, which is NOT the same thing as the request body
  // whenever the body is multipart. `signedBody` wins when supplied.
  let signedContent = options.signedBody;
  if (signedContent === undefined) {
    if (typeof options.body === 'string') {
      signedContent = options.body;
    } else if (options.body instanceof FormData) {
      // No payload part was named, so there is nothing the server would sign.
      // Hashing the empty string here would produce a request that is
      // well-formed and guaranteed to be rejected at signature verification,
      // which is far harder to diagnose than a loud failure now.
      throw new Error(
        'multipart request requires signedBody: the server signs the verbatim "payload" part'
      );
    } else {
      signedContent = '';
    }
  }

  const bodyHash = await hashRequestBody(signedContent);

  // The signed path must be the path exactly as the router resolves it, not
  // the bare route. The server signs against Request::path(), which is the
  // full incoming path — /api/v1/submit.php for a request the browser sent to
  // that route — with the query string stripped, duplicate slashes collapsed
  // and a trailing slash removed. Signing the bare route (submit.php) produced
  // a canonical string whose digest matched nothing the server ever computes,
  // so every authenticated request failed with an indistinguishable signature
  // mismatch. Mirroring the server's normalization keeps the byte string the
  // two sides build identical by construction.
  let signedPath = `${API_BASE}${path.split('?')[0]}`;
  if (!signedPath.startsWith('/')) signedPath = `/${signedPath}`;
  signedPath = signedPath.replace(/\/+/g, '/');
  if (signedPath.length > 1 && signedPath.endsWith('/')) {
    signedPath = signedPath.slice(0, -1);
  }

  // The canonical signing payload MUST include:
  // HTTP_METHOD
  // REQUEST_PATH
  // REQUEST_TIMESTAMP
  // REQUEST_NONCE
  // SHA256(REQUEST_BODY)
  const canonicalPayload = [
    method.toUpperCase(),
    signedPath,
    timestamp,
    nonce,
    bodyHash
  ].join('\n');

  const signature = await signPayload(canonicalPayload, device.private_key);

    // Diagnostic: lets a failing run be compared against the server's own
    // digest of what it verified against, so a mismatch can be attributed to
    // the canonical string rather than guessed at.
    if (typeof console !== 'undefined') {
      const dbg = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonicalPayload)));
      console.log('[SIG] sha256=' + Array.from(dbg).map((b) => b.toString(16).padStart(2, '0')).join(''));
    }

  const headers = new Headers(options.headers || {});
  headers.set('Authorization', `Bearer ${accessToken}`);
  headers.set('X-Device-UUID', device.device_uuid);
  headers.set('X-Request-Timestamp', timestamp);
  headers.set('X-Request-Nonce', nonce);
  headers.set('X-Request-Signature', signature);

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
  });

  if (response.status === 401) {
    // Refresh and retry exactly once. An unbounded retry here is not a slow
    // loop, it is an outage: a token that is rejected on the retry as well
    // would recurse until the tab dies, and each pass would present a refresh
    // token that has already been rotated, which the server treats as theft
    // and answers by revoking the whole token family.
    if (options.__retried) {
      handleAuthFailure('FAILED_AUTH');
      throw new Error('Authentication failed');
    }

    const refreshed = await attemptTokenRefresh();
    if (refreshed) {
      return authenticatedFetch(path, { ...options, __retried: true });
    }

    handleAuthFailure('FAILED_AUTH');
    throw new Error('Authentication failed');
  }

  return response;
}

let refreshInFlight: Promise<boolean> | null = null;

async function attemptTokenRefresh(): Promise<boolean> {
  /*
   * One refresh at a time, for the whole origin.
   *
   * The refresh token is single-use and the server detects reuse by family, so
   * two concurrent refreshes mean the second one presents a token that has
   * already been rotated and the entire session is revoked as a precaution
   * against theft. That is not a slow path to a wrong answer, it is a
   * guaranteed sign-out, and it is easy to hit by accident: a page that loads
   * the leaderboard and the queue at once sends two requests, both with the
   * same expired access token, and both get a 401.
   *
   * The module-level promise serialises one tab. The cookie is shared by all of
   * them, so the lock has to be cross-tab as well, and navigator.locks provides
   * that: a tab that queued for the lock re-checks whether it still needs to
   * refresh, and adopts the token a sibling just announced rather than spending
   * a cookie that no longer exists.
   */
  if (refreshInFlight) {
    return refreshInFlight;
  }

  refreshInFlight = refreshUnderLock()
    .catch(() => false)
    .finally(() => {
      refreshInFlight = null;
    });

  return refreshInFlight;
}

async function refreshUnderLock(): Promise<boolean> {
  const locks = navigator.locks;
  const work = async (): Promise<boolean> => {
    // A sibling may have refreshed while this tab was queued behind the lock. If
    // so, the access token changed under us and the 401 that sent us here has
    // already been answered by someone else.
    if (remoteTokenSeen) {
      remoteTokenSeen = false;
      return getAccessToken() !== null;
    }

    const response = await fetch(`${API_BASE}/auth/refresh.php`, {
      method: 'POST',
      // Cookies are automatically sent if withCredentials is true (or SameOrigin)
    });

    if (!response.ok) {
      return false;
    }

    const data = await response.json();
    // The rotated access token has to be stored before the caller retries.
    // RefreshController returns it under access_token; discarding it here made
    // the retry re-send the token that had just been rejected, so the 401 branch
    // recursed instead of recovering.
    setAccessToken(data.access_token);
    // Tell the other tabs. One may be holding a request about to 401, and this
    // is cheaper than letting it spend the cookie again.
    announceAccessToken(data.access_token);
    return true;
  };

  if (typeof locks?.request === 'function') {
    return locks.request('fieldpulse:refresh', work);
  }

  // No Web Locks: still single-flight within this tab, just not across tabs.
  return work();
}

// A token announced by a sibling tab means our next refresh can be skipped.
let remoteTokenSeen = false;
onRemoteAccessToken(() => {
  remoteTokenSeen = true;
});

export interface SessionAgent {
  id: number;
  agent_code: string;
  full_name: string;
  /**
   * `AGENT`, `SUPERVISOR` or `ADMIN`.
   *
   * Present for presentation only: it decides whether the operator-only review
   * link is shown. It grants nothing. The server checks the role against the
   * database row on every request, so a client that edited this string would see
   * the link and then receive 403 from every review call. See `auth/roles.ts`.
   */
  role?: string;
}

export interface LoginResult {
  access_token: string;
  expires_in: number;
  agent: SessionAgent;
  device_bound: boolean;
  next_step: string;
}

/**
 * Exchange a username and password for a bootstrap session.
 *
 * The result is a *bootstrap* session, not a usable one: it is bound to no
 * device, so the caller must follow it with registerDevice() before making any
 * signed request. `device_bound` is returned by the server precisely so this
 * client does not have to infer it.
 */
export async function login(username: string, password: string): Promise<LoginResult> {
  const response = await fetch(`${API_BASE}/auth/login.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const data = (await response.json()) as LoginResult;

  // Held in memory for the registration call that follows. Never written to
  // storage, so a reload in the middle of login resumes from the cookie rather
  // than from a token left lying around in localStorage.
  setAccessToken(data.access_token);
  announceAccessToken(data.access_token);

  return data;
}

export interface RegisterResult {
  device_uuid: string;
  status: string;
  device_bound: boolean;
  access_token: string;
  agent: SessionAgent;
}

/**
 * Bind the browser's key pair to the agent, upgrading the bootstrap session.
 *
 * Requires the bootstrap access token already in memory, because the server
 * only accepts a bootstrap token on this route. A 422 naming `pairing_code`
 * means the agent's policy requires an out-of-band code and the UI must ask for
 * one; the caller distinguishes it by `error.field` rather than by status,
 * since a bad JWK also returns 422.
 */
export async function registerDevice(pairingCode?: string): Promise<RegisterResult> {
  const device = await getDeviceIdentity();

  if (!device) {
    throw new Error('No device identity; generate one before registering.');
  }

  const body: Record<string, unknown> = {
    device_uuid: device.device_uuid,
    public_key_jwk: device.public_key_jwk
  };

  if (pairingCode) {
    body.pairing_code = pairingCode;
  }

  const response = await fetch(`${API_BASE}/device/register.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${getAccessToken() ?? ''}`
    },
    body: JSON.stringify(body)
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const data = (await response.json()) as RegisterResult;

  // The bound token replaces the bootstrap one, and the server has just revoked
  // the bootstrap family, so the value swapped in here is the only session left.
  setAccessToken(data.access_token);
  announceAccessToken(data.access_token);

  return data;
}

/**
 * End the session on the server, so the refresh cookie stops being usable.
 *
 * Not a signed request and not bearer-only: the credential is the cookie, and
 * the server revokes the whole token family so the token is dead even if it was
 * copied. Deliberately not wrapped in `authenticatedFetch` — that helper signs
 * with the device key and retries on 401, and a logout must never be retried
 * into a second family revocation or made to depend on a device that may
 * already be unusable.
 *
 * The caller drops the in-memory access token regardless of the outcome; a
 * logout that failed because the network is down must still leave nothing usable
 * on this device.
 */
export async function logout(): Promise<void> {
  try {
    await fetch(`${API_BASE}/auth/logout.php`, { method: 'POST' });
  } finally {
    setAccessToken(null);
  }
}

export type RestoreResult =
  | { status: 'authenticated'; agent: SessionAgent | null }
  | { status: 'anonymous' }
  | { status: 'offline' }
  | { status: 'unregistered' };

/**
 * Rebuild the in-memory session from the refresh cookie on page load.
 *
 * The access token is not persisted, so every reload starts with nothing and
 * has to ask the server who it is. Four outcomes, and the distinction matters:
 * a live session is usable, no session means the login screen, a registered
 * server session with no local private key means the browser cleared it and the
 * device must be re-registered — which is a different screen, because the
 * user's credentials are still perfectly good — and a network failure means the
 * question could not be asked at all.
 *
 * That last one used to be reported as "anonymous", which threw away the single
 * most important thing the app can offer with no network: the captures already
 * queued on this handset. Sending the agent to the login screen because the
 * server was unreachable means they cannot see, or even confirm, the evidence
 * they just took, and the queue they are told will upload itself is exactly what
 * they lose. A session that could not be checked is not a session that has
 * ended, so it is reported on its own and the app lets the agent through with no
 * identity. Nothing is granted: the access token stays empty, so the first
 * request after the network returns still gets a 401, still refreshes, and
 * still carries a signature.
 */
export async function restoreSession(): Promise<RestoreResult> {
  let response: Response;

  try {
    response = await fetch(`${API_BASE}/auth/refresh.php`, { method: 'POST' });
  } catch {
    // fetch only rejects when the request never reached the server. An answer,
    // any answer, is handled below.
    setAccessToken(null);

    return { status: 'offline' };
  }

  if (!response.ok) {
    setAccessToken(null);
    return { status: 'anonymous' };
  }

  const data = (await response.json()) as { access_token: string; agent?: SessionAgent };
  setAccessToken(data.access_token);
  announceAccessToken(data.access_token);

  if (!await getDeviceIdentity()) {
    // The cookie is valid but this browser has no key, so any signed request
    // would fail signature verification. Reported so the app can re-register
    // instead of looping the user back to a login they have already passed.
    return { status: 'unregistered' };
  }

  // The agent is normally present. A server that predates it — an unrefreshed
  // deploy, or a proxy serving a stale response — still authenticates this
  // session, so this is a working session with an unknown identity rather than a
  // failure. Every consumer treats a missing agent as "not an operator", which
  // hides the review link rather than showing a screen the server would refuse.
  return { status: 'authenticated', agent: data.agent ?? null };
}

/**
 * The server's own view of one submission, as reported by
 * `GET /api/v1/submission.php?uuid=`.
 *
 * This is deliberately a separate type from `Submission` in `src/db/db.ts`.
 * The local record says what this handset still has to do; this says what the
 * server decided about the evidence it already holds. They are different
 * questions with different clocks, and collapsing them into one status is how a
 * client ends up reporting "received by server" as if it were "verified" — the
 * exact claim the agent must never be able to make on the server's behalf.
 */
export interface ServerVerification {
  submission_uuid: string;
  status: string;
  count_claimed: number;
  received_at: string;
  captured_at: string | null;
  verified_at: string | null;
  counted: boolean;
  pending: boolean;
  disposition?: string;
  reason?: string | null;
  reasons?: { code: string; detail?: string }[];
  awaiting_review?: boolean;
  reviewed_at?: string | null;
  verification_version?: string | null;
}

/**
 * Fetch the server's verdict for one submission.
 *
 * `bearer`-only and scoped to the calling agent's own submissions, so no device
 * signature is needed — which also means it can be called for a submission whose
 * photo has long since been pruned from the handset. Returns null for a
 * submission the server has never heard of, which is the normal case for one
 * that is still queued locally.
 */
export async function fetchServerVerification(submissionUuid: string): Promise<ServerVerification | null> {
  const response = await authenticatedFetch(`/submission.php?uuid=${encodeURIComponent(submissionUuid)}`);

  if (response.status === 404) {
    // The server has never received this UUID. Not an error: it is the expected
    // answer for anything still sitting in the local queue.
    return null;
  }

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: ServerVerification };
  return envelope.data;
}

/**
 * The operator review queue.
 *
 * Operator-only on the server (`SUPERVISOR`/`ADMIN`); an `AGENT` receives 403.
 * The path is `reviews/index.php` rather than `reviews.php` — the shim files
 * under public_html are one per route, and there is no `reviews.php`.
 */
export interface ReviewQueueItem {
  submission_id: number;
  submission_uuid: string;
  agent: { agent_code: string; full_name: string };
  device: { device_uuid: string | null; imei: string | null };
  count_claimed: number;
  received_at: string;
  image: { width: number; height: number; path: string };
  position: { latitude: number | null; longitude: number | null };
  checks: Record<string, string>;
  review_reason: string | null;
  evidence: Record<string, unknown> | null;
  final_disposition: string | null;
  already_reviewed: boolean;
  reviewed_at: string | null;
  review_note: string | null;
}

export interface ReviewQueue {
  items: ReviewQueueItem[];
  meta: {
    pagination: { total: number; limit: number; offset: number };
    reasons: { code: string; count: number }[];
    requested_by: string;
  };
}

export async function fetchReviewQueue(
  reason?: string,
  agentCode?: string
): Promise<ReviewQueue> {
  const params = new URLSearchParams({ limit: '25' });
  if (reason) {
    params.set('reason', reason);
  }
  if (agentCode) {
    params.set('agent_code', agentCode);
  }

  const response = await authenticatedFetch(`/reviews/index.php?${params.toString()}`);

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as {
    data: ReviewQueueItem[];
    meta: ReviewQueue['meta'];
  };

  return { items: envelope.data, meta: envelope.meta };
}

/**
 * Record an approve/reject decision against a queued submission.
 *
 * `note` is mandatory server-side (5–1000 characters) and deliberately so: a
 * decision that overrules the automated pass without recording why produces an
 * audit trail that cannot answer the only question anyone will later ask of it.
 */
export async function decideReview(
  submissionId: number,
  decision: 'APPROVE' | 'REJECT',
  note: string
): Promise<{ submission_id: number; status: string; reviewed_by: string; reviewed_at: string }> {
  const response = await authenticatedFetch('/reviews/decide.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ submission_id: submissionId, decision, note })
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as {
    data: { submission_id: number; status: string; reviewed_by: string; reviewed_at: string };
  };

  return envelope.data;
}

/**
 * The account directory (§16).
 *
 * ADMIN-only on the server (`Kernel`.requireAdmin)); anyone else receives 403.
 * The list is served from the `agents` table with the handful of summary
 * columns the directory needs, and every field here is presentation — the
 * privilege checks all happen against the database row on each call.
 */
export type AdminAgentRole = 'AGENT' | 'SUPERVISOR' | 'ADMIN';
export type AdminAgentStatus = 'ACTIVE' | 'SUSPENDED' | 'DELETED';

export interface AdminAgent {
  id: number;
  agent_code: string;
  full_name: string;
  role: AdminAgentRole;
  status: AdminAgentStatus;
  /** The login username, when the account has one. Null after credential revoke/retire. */
  username: string | null;
  has_credential: boolean;
  active_device_count: number;
  created_at: string;
}

export interface AdminAgentList {
  agents: AdminAgent[];
  meta: {
    pagination: { total: number; limit: number; offset: number };
    active_admins: number;
    requested_by: string;
  };
}

/**
 * Fetch the account directory.
 *
 * The caller identifies itself in `meta.requested_by`, which the GUI uses to
 * disable the self-guarded actions the server would refuse anyway — a nuance
 * worth surfacing as disabled controls rather than as 409s.
 */
export async function fetchAdminAgents(limit = 50, offset = 0): Promise<AdminAgentList> {
  const params = new URLSearchParams({ limit: String(limit), offset: String(offset) });
  const response = await authenticatedFetch(`/admin/agents.php?${params.toString()}`);

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: AdminAgent[]; meta: AdminAgentList['meta'] };
  return { agents: envelope.data, meta: envelope.meta };
}

export interface CreateAdminAgentInput {
  agent_code: string;
  full_name: string;
  username: string;
  password: string;
  /** Optional; the server defaults a new account to AGENT. */
  role?: AdminAgentRole;
  /** An IMEI to bind at create time; optional and administrative, never a credential. */
  imei?: string;
}

/**
 * Create an account.
 *
 * A created account always lands ACTIVE with a credential, so the person can
 * sign in on the first try — a created account is a promise to a person, not a
 * draft. Duplicate code or username answers 409 IDEMPOTENCY_CONFLICT; the UI
 * shows the server's message rather than pre-checking, because the two rows can
 * collide with a concurrent create.
 */
export async function createAdminAgent(input: CreateAdminAgentInput): Promise<AdminAgent> {
  const response = await authenticatedFetch('/admin/agents.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input)
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: AdminAgent };
  return envelope.data;
}

/** The four state-changing actions the directory supports. */
export type AdminAgentAction =
  | { action: 'SET_ROLE'; role: AdminAgentRole }
  | { action: 'SET_STATUS'; status: 'ACTIVE' | 'SUSPENDED' | 'DELETED' }
  | { action: 'SET_PASSWORD'; password: string }
  | { action: 'REVOKE_CREDENTIAL' };

/**
 * Apply one state-changing action to an account.
 *
 * The server refuses four things with 409 STATE_CONFLICT that the GUI mirrors
 * as disabled controls: acting on yourself (except password reset), demoting /
 * suspending / retiring the last active administrator, and touching a retired
 * account at all. A 409 is still surfaced verbatim when one slips through,
 * because a role revoked in another tab since this one loaded is a real state.
 */
export async function actOnAdminAgent(id: number, patch: AdminAgentAction): Promise<AdminAgent> {
  const response = await authenticatedFetch('/admin/agent.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, ...patch })
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: AdminAgent };
  return envelope.data;
}

/**
 * An issued device-pairing code, riding on the account row.
 *
 * `pairing_code` is the one and only time the plaintext is served. The server
 * stores only its SHA-256 and the UI must not persist it, write it to storage,
 * or echo it back — it is copied to the clipboard and read aloud.
 */
export interface IssuePairingCodeResult extends AdminAgent {
  /** The ten-digit, single-use registration code. Show once, never store. */
  pairing_code: string;
  /** ISO 8601 timestamp after which the code stops being usable. */
  expires_at: string;
  /** Seconds the code remains valid (server-configured pairing TTL). */
  ttl_seconds: number;
}

/**
 * Mint a device-pairing code for an ACTIVE account.
 *
 * This is the admin-side half of agent onboarding: with a credential (create or
 * a password reset) and a code from here, an agent can complete a first-device
 * registration from the `/register` page without an operator ever touching SSH.
 *
 * The server refuses with 409 STATE_CONFLICT for any account that is not ACTIVE
 * (including retired accounts, which are immutable), and the GUI mirrors that
 * as a disabled control.
 */
export async function issuePairingCode(id: number, label?: string): Promise<IssuePairingCodeResult> {
  const response = await authenticatedFetch('/admin/agent.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action: 'ISSUE_PAIRING_CODE', ...(label ? { label } : {}) })
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: IssuePairingCodeResult };
  return envelope.data;
}

/**
 * Rewards (§14).
 *
 * A published entitlement, not a live figure. Every number here was copied onto
 * the row when the period closed and is served from the frozen ranking, so it
 * does not move when later submissions are verified — see `docs/REWARDS.md`.
 *
 * `status` and `is_paid` are deliberately separate fields. `PENDING` and
 * `APPROVED` are published entitlements; only `PAID` means the organisation has
 * settled the reward. Reading `APPROVED` as "paid" would tell an agent they had
 * been paid when they had not, so the UI branches on `is_paid`, never on the
 * absence of `PENDING`.
 */
export type RewardStatus = 'PENDING' | 'APPROVED' | 'PAID' | 'VOID';

export interface Reward {
  id: number;
  period_start_date: string;
  rank: number;
  total_verified_count: number;
  tier: { id: number | null; label: string | null };
  /** null means the organisation has not set a figure for this band yet. */
  amount: number | null;
  currency: string | null;
  status: RewardStatus;
  is_paid: boolean;
  published_at: string;
  approved_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
  void_reason: string | null;
  notes: string | null;
}

/** The operator view adds the recipient and the acting operator ids. */
export interface OperatorReward extends Reward {
  agent: { agent_code: string; full_name: string };
  approved_by_operator_id: number | null;
  paid_by_operator_id: number | null;
  voided_by_operator_id: number | null;
}

export interface MyRewards {
  rewards: Reward[];
  meta: { agent_code: string; grace_hours: number; payment_note: string };
}

export async function fetchMyRewards(limit = 24): Promise<MyRewards> {
  const params = new URLSearchParams({ limit: String(limit) });
  const response = await authenticatedFetch(`/rewards/self.php?${params.toString()}`);

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as {
    data: Reward[];
    meta: MyRewards['meta'];
  };

  return { rewards: envelope.data, meta: envelope.meta };
}

export interface RewardPeriod {
  rewards: OperatorReward[];
  meta: {
    period: string;
    closed: boolean;
    available_periods: string[];
    tiers: { id: number; min_rank: number; max_rank: number; tier_label: string }[];
    pagination: { total: number; limit: number; offset: number };
  };
}

export async function fetchPeriodRewards(period?: string, limit = 100): Promise<RewardPeriod> {
  const params = new URLSearchParams({ limit: String(limit) });
  if (period) {
    params.set('period', period);
  }

  const response = await authenticatedFetch(`/rewards/index.php?${params.toString()}`);

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as {
    data: OperatorReward[];
    meta: RewardPeriod['meta'];
  };

  return { rewards: envelope.data, meta: envelope.meta };
}

export async function decideReward(
  rewardId: number,
  action: 'APPROVE' | 'PAY' | 'VOID',
  reason?: string
): Promise<OperatorReward> {
  const response = await authenticatedFetch('/rewards/decide.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(reason ? { reward_id: rewardId, action, reason } : { reward_id: rewardId, action })
  });

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as { data: OperatorReward };
  return envelope.data;
}

/**
 * The weekly standing (§13).
 *
 * Served entirely from the summary table, never by aggregating submissions, so
 * the shape is a ranked list plus the caller's own row even when that row is off
 * the visible page. `you.rank` is what the reward for the week is frozen from,
 * which is why this screen says the standing is live and the reward is not.
 */
export interface LeaderboardEntry {
  rank: number;
  agent_id: number;
  agent_code: string;
  display_name: string;
  total_verified_count: number;
  total_submissions: number;
  total_pending: number;
  total_rejected: number;
}

export interface LeaderboardSelf {
  rank: number | null;
  agent_id: number;
  agent_code: string;
  display_name: string;
  total_verified_count: number;
  total_pending: number;
}

export interface Leaderboard {
  period_start_date: string;
  scope: 'GLOBAL' | 'SITE';
  site_id: number | null;
  entries: LeaderboardEntry[];
  pagination: { total: number; limit: number; offset: number };
  you: LeaderboardSelf | null;
  available_periods: string[];
}

export async function fetchLeaderboard(period?: string): Promise<Leaderboard> {
  const qs = period ? `?period=${encodeURIComponent(period)}` : '';
  const response = await authenticatedFetch(`/leaderboard.php${qs}`);

  if (!response.ok) {
    throw await toApiError(response);
  }

  const envelope = (await response.json()) as {
    data: {
      period_start_date: string;
      scope: 'GLOBAL' | 'SITE';
      site_id: number | null;
      entries: LeaderboardEntry[];
      pagination: { total: number; limit: number; offset: number };
      you: LeaderboardSelf | null;
    };
    meta: { available_periods: string[] };
  };

  return {
    ...envelope.data,
    available_periods: envelope.meta.available_periods
  };
}
