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

  // The canonical signing payload MUST include:
  // HTTP_METHOD
  // REQUEST_PATH
  // REQUEST_TIMESTAMP
  // REQUEST_NONCE
  // SHA256(REQUEST_BODY)
  const canonicalPayload = [
    method.toUpperCase(),
    path,
    timestamp,
    nonce,
    bodyHash
  ].join('\n');

  const signature = await signPayload(canonicalPayload, device.private_key);

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

export type RestoreResult =
  | { status: 'authenticated'; agent: SessionAgent }
  | { status: 'anonymous' }
  | { status: 'unregistered' };

/**
 * Rebuild the in-memory session from the refresh cookie on page load.
 *
 * The access token is not persisted, so every reload starts with nothing and
 * has to ask the server who it is. Three outcomes, and the distinction matters:
 * a live session is usable, no session means the login screen, and a registered
 * server session with no local private key means the browser cleared it and the
 * device must be re-registered — which is a different screen, because the
 * user's credentials are still perfectly good.
 */
export async function restoreSession(): Promise<RestoreResult> {
  const response = await fetch(`${API_BASE}/auth/refresh.php`, { method: 'POST' });

  if (!response.ok) {
    setAccessToken(null);
    return { status: 'anonymous' };
  }

  const data = (await response.json()) as { access_token: string; agent: SessionAgent };
  setAccessToken(data.access_token);
  announceAccessToken(data.access_token);

  if (!await getDeviceIdentity()) {
    // The cookie is valid but this browser has no key, so any signed request
    // would fail signature verification. Reported so the app can re-register
    // instead of looping the user back to a login they have already passed.
    return { status: 'unregistered' };
  }

  return { status: 'authenticated', agent: data.agent };
}
