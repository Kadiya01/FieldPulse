import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  authenticatedFetch,
  login,
  registerDevice,
  restoreSession,
  ApiError
} from './client';
import { getAccessToken } from '../auth/session';
import { getDeviceIdentity } from '../crypto/keys';

/**
 * These cover the coordination that decides whether a session survives, rather
 * than the crypto itself, which keys.test.ts covers for real.
 *
 * The important property is that a rotated refresh token is spent exactly once.
 * The server treats a second presentation of the same token as theft and
 * revokes the whole family, so a client that refreshes concurrently signs the
 * user out — and the failure is invisible in dev, where a single request
 * usually runs at a time.
 */

vi.mock('../db/db', () => ({
  db: {
    device: {
      get: vi.fn(),
      put: vi.fn()
    }
  }
}));

/**
 * Declared through vi.hoisted because vi.mock is hoisted above every other
 * statement, so a plain const here is still in its temporal dead zone when the
 * factory runs. The spies are read back through the mocked module, so they
 * remain assertable.
 */
const sessionState = vi.hoisted(() => ({
  announceAccessToken: vi.fn(),
  onRemoteAccessToken: vi.fn(() => () => {}),
  handleAuthFailure: vi.fn()
}));

vi.mock('../auth/session', async () => {
  const actual = await vi.importActual<typeof import('../auth/session')>('../auth/session');

  return {
    ...actual,
    ...sessionState
  };
});

vi.mock('../crypto/keys', () => ({
  getDeviceIdentity: vi.fn(),
  signPayload: vi.fn().mockResolvedValue('c2lnbmF0dXJl'),
  hashRequestBody: vi.fn().mockResolvedValue('a'.repeat(64)),
  generateNonce: vi.fn().mockReturnValue('0'.repeat(32))
}));

const jsonResponse = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' }
  });

describe('Session restore', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getDeviceIdentity).mockResolvedValue({
      id: 'current',
      device_uuid: 'd0e0e0e0-0000-4000-8000-000000000001',
      private_key: {} as CryptoKey,
      public_key_jwk: { kty: 'EC', crv: 'P-256', x: 'x', y: 'y' },
      registered_at: 1
    });
  });

  it('restores a bound session from the cookie without a stored token', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(200, {
        access_token: 'restored-token',
        agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' }
      })
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await restoreSession();

    expect(result).toEqual({
      status: 'authenticated',
      agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' }
    });
    expect(getAccessToken()).toBe('restored-token');
    // The token is memory-only; nothing may have written it anywhere durable.
    expect(sessionState.announceAccessToken).toHaveBeenCalledWith('restored-token');
  });

  it('reports an anonymous visitor when the cookie is rejected', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(401, {})));

    await expect(restoreSession()).resolves.toEqual({ status: 'anonymous' });
    expect(getAccessToken()).toBeNull();
  });

  /**
   * A valid cookie with no local private key is a distinct state from no
   * session at all. Sending the user to the login screen here would be wrong:
   * their credentials are fine, the browser simply cannot prove possession of
   * the device key, and re-registration is what actually fixes it.
   */
  it('reports unregistered when the cookie is valid but the key is gone', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(200, {
          access_token: 'restored-token',
          agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' }
        })
      )
    );
    vi.mocked(getDeviceIdentity).mockResolvedValue(undefined);

    await expect(restoreSession()).resolves.toEqual({ status: 'unregistered' });
  });
});

describe('Login and registration', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getDeviceIdentity).mockResolvedValue({
      id: 'current',
      device_uuid: 'd0e0e0e0-0000-4000-8000-000000000001',
      private_key: {} as CryptoKey,
      public_key_jwk: { kty: 'EC', crv: 'P-256', x: 'x', y: 'y' },
      registered_at: 1
    });
  });

  it('sends the field names the server validates', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(200, {
        access_token: 'bootstrap-token',
        agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' },
        device_bound: false,
        next_step: 'device.register'
      }))
      .mockResolvedValueOnce(jsonResponse(200, {
        device_uuid: 'd0e0e0e0-0000-4000-8000-000000000001',
        status: 'ACTIVE',
        device_bound: true,
        access_token: 'bound-token',
        agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' }
      }));
    vi.stubGlobal('fetch', fetchMock);

    await login('ada', 'hunter2');
    await registerDevice();

    const registerCall = fetchMock.mock.calls[1];
    expect(registerCall[0]).toBe('/api/v1/device/register.php');

    const sent = JSON.parse((registerCall[1] as RequestInit).body as string);

    // `public_key_jwk` is the name DeviceController reads. Sending
    // `public_jwk` instead is a 422 naming a field that does not exist, which
    // reads like a server bug rather than a client typo.
    expect(sent.public_key_jwk).toEqual({ kty: 'EC', crv: 'P-256', x: 'x', y: 'y' });
    expect(sent.device_uuid).toBe('d0e0e0e0-0000-4000-8000-000000000001');
    // No code was offered, so none is sent: an empty pairing_code would consume
    // nothing but could be read as an attempt.
    expect(sent).not.toHaveProperty('pairing_code');
  });

  /**
   * The pairing code prompt is driven by the server's 422, not by a client-side
   * guess at the policy, so this pins the specific thing the UI branches on:
   * the field name in the error details.
   */
  it('surfaces the pairing_code field so the UI can ask for a code', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          error: {
            code: 'VALIDATION_FAILED',
            message: 'A pairing code is required to register a new device.',
            details: { field: 'pairing_code' }
          }
        })
      )
    );

    const error = await registerDevice().catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).field).toBe('pairing_code');
    expect((error as ApiError).status).toBe(422);
  });

  it('replaces the bootstrap token with the bound one', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(200, {
        device_uuid: 'd0e0e0e0-0000-4000-8000-000000000001',
        status: 'ACTIVE',
        device_bound: true,
        access_token: 'bound-token',
        agent: { id: 1, agent_code: 'AG-001', full_name: 'Ada' }
      }))
    );

    await registerDevice();

    // The server revokes the bootstrap family once a device binds, so holding
    // the bootstrap token afterwards would send an already-revoked session on
    // every subsequent request.
    expect(getAccessToken()).toBe('bound-token');
  });
});

describe('Refresh coordination', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(getDeviceIdentity).mockResolvedValue({
      id: 'current',
      device_uuid: 'd0e0e0e0-0000-4000-8000-000000000001',
      private_key: {} as CryptoKey,
      public_key_jwk: { kty: 'EC', crv: 'P-256', x: 'x', y: 'y' },
      registered_at: 1
    });
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  /**
   * Two requests failing with the same expired token is the ordinary case, not
   * an exotic one: a page that loads the leaderboard and the queue fires both
   * at once. If each ran its own refresh, the second would present a rotated
   * token and the server would revoke the family. One refresh, one retry each.
   */
  it('refreshes once for concurrent 401s and retries both requests', async () => {
    let refreshCalls = 0;
    const firstCall = new Set<string>();

    const fetchMock = vi.fn(async (url: string) => {
      if (url.includes('refresh.php')) {
        refreshCalls++;
        // Held open briefly so the second request really is in flight when the
        // first one starts its refresh. Without the delay both would fail
        // before either began, and the test would pass for the wrong reason.
        await new Promise(resolve => setTimeout(resolve, 10));
        return jsonResponse(200, { access_token: 'fresh-token' });
      }

      // Both requests fail once, as they would sharing one expired token.
      if (!firstCall.has(url)) {
        firstCall.add(url);
        return jsonResponse(401, {});
      }

      return jsonResponse(200, { ok: true });
    });
    vi.stubGlobal('fetch', fetchMock);

    const [leaderboard, queue] = await Promise.all([
      authenticatedFetch('/leaderboard.php', {}),
      authenticatedFetch('/queue.php', {})
    ]);

    // One refresh, and both requests recovered on the retry.
    expect(refreshCalls).toBe(1);
    expect(leaderboard.status).toBe(200);
    expect(queue.status).toBe(200);
  });

  it('gives up rather than recursing when the retried request also 401s', async () => {
    let refreshCalls = 0;
    const fetchMock = vi.fn(async (url: string) => {
      if (url.includes('refresh.php')) {
        refreshCalls++;
        return jsonResponse(200, { access_token: 'fresh-token' });
      }
      return jsonResponse(401, {});
    });
    vi.stubGlobal('fetch', fetchMock);

    await expect(authenticatedFetch('/submit.php', {})).rejects.toThrow('Authentication failed');

    // Exactly one refresh and one retry. An unbounded retry is not a slow
    // failure, it is an outage that also revokes the family on every pass.
    expect(refreshCalls).toBe(1);
    expect(sessionState.handleAuthFailure).toHaveBeenCalledWith('FAILED_AUTH');
  });

  it('refuses to sign when the private key is missing', async () => {
    vi.mocked(getDeviceIdentity).mockResolvedValue(undefined);
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    await expect(authenticatedFetch('/submit.php', {})).rejects.toThrow();

    // Signing is impossible without a key, and attempting the request anyway
    // would send an unauthenticated write to the server.
    expect(fetchMock).not.toHaveBeenCalled();
    expect(sessionState.handleAuthFailure).toHaveBeenCalledWith('UNREGISTERED');
  });
});
