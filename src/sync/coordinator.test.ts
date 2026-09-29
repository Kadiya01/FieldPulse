import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { parseSubmitAccepted, buildSubmitForm, uploadSubmission } from './coordinator';
import type { Submission } from '../db/db';

/**
 * The client half of the submission contract.
 *
 * The server half is asserted over a real socket by private_storage/bin/
 * contract.php. What is asserted here is the part that suite structurally
 * cannot see: what this device *does* with the answer.
 *
 * The one behaviour worth defending hardest is the malformed-2xx case. A 202
 * whose body does not parse means the server may never have stored the file,
 * and treating it as an acceptance deletes the only copy of the evidence. Every
 * other assertion in this file is a convenience next to that one.
 */

/**
 * The database is mocked rather than backed by fake-indexeddb.
 *
 * Every assertion here is about the decision the client makes from a response,
 * not about Dexie's behaviour, and standing up a fake IndexedDB would add a
 * dependency and a second thing that can break for reasons unrelated to the
 * contract. buildSubmitPayload is imported from the real module below so the
 * payload allowlist is still asserted against production code.
 */
vi.mock('../db/db', async () => {
  const actual = await vi.importActual<typeof import('../db/db')>('../db/db');

  return {
    ...actual,
    db: {
      submissions: {
        update: vi.fn()
      },
      // authenticatedFetch reads the device identity before it will sign
      // anything, so the table has to answer. The key itself is not used:
      // the crypto primitives are mocked below, and the signature scheme is
      // asserted for real in keys.test.ts. What matters here is that the
      // request is assembled and dispatched.
      device: {
        get: vi.fn().mockResolvedValue({
          id: 'current',
          device_uuid: 'dev-uuid',
          private_key: {} as unknown as CryptoKey,
          registered_at: 0
        })
      }
    }
  };
});

const { buildSubmitPayload } = await import('../db/db');
const { db } = await import('../db/db');

/**
 * The session is stubbed, not exercised. An access token in memory is a
 * precondition for the request going out at all, and auth/session is covered
 * by its own phase; these tests are about the response handling that follows.
 */
/**
 * announceAccessToken and onRemoteAccessToken exist so sibling tabs can share
 * one rotated refresh token instead of each spending the cookie and tripping
 * the server's reuse detection. The coordinator never calls them directly, but
 * client.ts calls onRemoteAccessToken at module load, so a partial mock has to
 * supply it or importing the client throws before any test runs.
 */
vi.mock('../auth/session', () => ({
  getAccessToken: () => 'test-access-token',
  setAccessToken: vi.fn(),
  announceAccessToken: vi.fn(),
  onRemoteAccessToken: vi.fn(() => () => {}),
  handleAuthFailure: vi.fn()
}));

/**
 * The crypto primitives are mocked so the request can be dispatched. The
 * signature scheme is a real concern with a real test of its own
 * (keys.test.ts); here it would only be an obstacle between the test and the
 * response handling it is actually about.
 */
vi.mock('../crypto/keys', () => ({
  getDeviceIdentity: vi.fn().mockResolvedValue({
    id: 'current',
    device_uuid: 'dev-uuid',
    private_key: {},
    registered_at: 0
  }),
  signPayload: vi.fn().mockResolvedValue('c2lnbmF0dXJl'),
  hashRequestBody: vi.fn().mockResolvedValue('a'.repeat(64)),
  generateNonce: () => 'dGVzdC1ub25jZS0xMjM0NTY3ODkw'
}));

/** A 202 exactly as SubmitController emits it. */
const QUEUED_202 = {
  data: {
    status: 'QUEUED',
    submission_uuid: '9f1c0a2b-3d4e-4f50-8a6b-7c8d9e0f1a2b',
    submission_id: 41,
    self: '/api/v1/submission.php?uuid=9f1c0a2b-3d4e-4f50-8a6b-7c8d9e0f1a2b',
    count_claimed: 12,
    received_at: '2026-09-28T09:41:07Z',
    estimated_review_seconds: 900
  }
};

/** A 200 exactly as SubmitController emits it. */
const REPLAY_200 = {
  data: {
    status: 'ALREADY_RECEIVED',
    submission_uuid: '9f1c0a2b-3d4e-4f50-8a6b-7c8d9e0f1a2b',
    submission_id: 41,
    idempotent_replay: true,
    submission_status: 'QUEUED',
    self: '/api/v1/submission.php?uuid=9f1c0a2b-3d4e-4f50-8a6b-7c8d9e0f1a2b'
  }
};

function makeSubmission(overrides: Partial<Submission> = {}): Submission {
  return {
    submission_uuid: '9f1c0a2b-3d4e-4f50-8a6b-7c8d9e0f1a2b',
    count_claimed: 12,
    latitude: 6.5244,
    longitude: 3.3792,
    accuracy_m: 12.5,
    captured_at: '2026-09-28T09:41:05Z',
    created_at: 1_757_000_000_000,
    updated_at: 1_757_000_000_000,
    status: 'SYNCING',
    retry_count: 0,
    next_retry_at: 0,
    last_attempt_at: null,
    last_error_code: null,
    last_error_message: null,
    server_submission_id: null,
    photo_blob: new Blob([new Uint8Array([0xff, 0xd8, 0xff, 0xe0])], { type: 'image/jpeg' }),
    photo_mime_type: 'image/jpeg',
    photo_size: 4,
    file_sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    client_exif: '{}',
    ...overrides
  };
}

describe('parseSubmitAccepted', () => {
  it('accepts a well-formed 202 QUEUED body', () => {
    const parsed = parseSubmitAccepted(QUEUED_202);

    expect(parsed).not.toBeNull();
    expect(parsed!.status).toBe('QUEUED');
    expect(parsed!.submission_id).toBe(41);
  });

  it('accepts a well-formed 200 ALREADY_RECEIVED body', () => {
    const parsed = parseSubmitAccepted(REPLAY_200);

    expect(parsed).not.toBeNull();
    expect(parsed!.status).toBe('ALREADY_RECEIVED');
    expect(parsed!.idempotent_replay).toBe(true);
  });

  it.each([
    ['an HTML error page served with 200', { data: { status: 'OK' } }],
    ['a body with no data envelope', { error: { code: 'X' } }],
    ['a data envelope that is null', { data: null }],
    ['a non-integer submission_id', { data: { ...QUEUED_202.data, submission_id: '41' } }],
    ['a missing submission_id', { data: { ...QUEUED_202.data, submission_id: undefined } }],
    ['a missing status', { data: { ...QUEUED_202.data, status: undefined } }],
    ['a UUID that is not a UUID', { data: { ...QUEUED_202.data, submission_uuid: 'not-a-uuid' } }],
    ['a missing self link', { data: { ...QUEUED_202.data, self: undefined } }],
    ['null', null],
    ['a bare string', 'accepted']
  ])('rejects %s', (_label, body) => {
    expect(parseSubmitAccepted(body)).toBeNull();
  });
});

describe('buildSubmitPayload', () => {
  it('never includes an ownership claim', () => {
    const payload = buildSubmitPayload(makeSubmission());

    expect(payload).not.toHaveProperty('agent_id');
    expect(payload).not.toHaveProperty('device_uuid');
    expect(payload).not.toHaveProperty('imei');
  });

  it('omits latitude and longitude together rather than half a pair', () => {
    const payload = buildSubmitPayload(makeSubmission({ latitude: null, longitude: null }));

    expect(payload).not.toHaveProperty('latitude');
    expect(payload).not.toHaveProperty('longitude');
  });

  it('carries only fields the contract defines', () => {
    const payload = buildSubmitPayload(makeSubmission());

    expect(Object.keys(payload).sort()).toEqual([
      'accuracy_m',
      'captured_at',
      'count_claimed',
      'file_sha256',
      'latitude',
      'longitude',
      'submission_uuid'
    ]);
  });

  it('does not carry local bookkeeping fields onto the wire', () => {
    const payload = buildSubmitPayload(makeSubmission());

    for (const localOnly of ['status', 'retry_count', 'created_at', 'photo_blob', 'client_exif']) {
      expect(payload).not.toHaveProperty(localOnly);
    }
  });
});

describe('buildSubmitForm', () => {
  it('sends the identical string it was given, so the signature covers what is transmitted', () => {
    const json = JSON.stringify(buildSubmitPayload(makeSubmission()));
    const form = buildSubmitForm(makeSubmission(), json);

    // Read as a string, not a File: a payload part carrying a filename lands in
    // $_FILES on the server and becomes invisible to signedBody().
    expect(form.get('payload')).toBe(json);
    expect(typeof form.get('payload')).toBe('string');
  });

  it('attaches the photo as the file part', () => {
    const form = buildSubmitForm(makeSubmission(), '{}');
    const file = form.get('file') as File;

    expect(file).toBeInstanceOf(Blob);
    expect(file.size).toBe(4);
  });
});

describe('uploadSubmission response handling', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.mocked(db.submissions.update).mockReset().mockResolvedValue(undefined);
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  const respondWith = (status: number, body: unknown) =>
    fetchMock.mockResolvedValue(
      new Response(typeof body === 'string' ? body : JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' }
      })
    );

  const lastUpdate = () =>
    vi.mocked(db.submissions.update).mock.calls.at(-1)![1] as Record<string, unknown>;

  it('marks a 202 SENT and prunes the photo', async () => {
    respondWith(202, QUEUED_202);
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).toBe('SENT');
    expect(lastUpdate().server_submission_id).toBe('41');
    expect(lastUpdate().photo_blob).toBeUndefined();
  });

  it('marks a 200 ALREADY_RECEIVED SENT as well', async () => {
    respondWith(200, REPLAY_200);
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).toBe('SENT');
    expect(lastUpdate().server_submission_id).toBe('41');
  });

  it('does NOT mark a 202 with a malformed body SENT', async () => {
    // A proxy returning 200 with an HTML login page, or a truncated body.
    respondWith(202, { data: { status: 'QUEUED' } });
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).not.toBe('SENT');
    expect(lastUpdate().status).toBe('RETRY_WAIT');
  });

  it('does NOT mark an unparseable 200 SENT', async () => {
    respondWith(200, '<html>login</html>');
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).not.toBe('SENT');
  });

  it('marks a 409 FAILED_PERMANENT without retrying', async () => {
    respondWith(409, { error: { code: 'IDEMPOTENCY_CONFLICT', message: 'no' } });
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).toBe('FAILED_PERMANENT');
    expect(lastUpdate().last_error_code).toBe(409);
  });

  it('marks a 422 FAILED_PERMANENT, since an unchanged body cannot become valid', async () => {
    respondWith(422, { error: { code: 'VALIDATION_ERROR' } });
    await uploadSubmission(makeSubmission());

    expect(lastUpdate().status).toBe('FAILED_PERMANENT');
  });

  it('retries a 500 rather than declaring success', async () => {
    respondWith(500, { error: { code: 'INTERNAL' } });
    await expect(uploadSubmission(makeSubmission())).rejects.toThrow();

    expect(lastUpdate().status).toBe('RETRY_WAIT');
  });

  it('fails permanently when there is no photo to upload', async () => {
    await uploadSubmission(makeSubmission({ photo_blob: undefined }));

    expect(lastUpdate().status).toBe('FAILED_PERMANENT');
  });
});
