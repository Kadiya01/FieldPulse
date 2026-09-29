import Dexie, { type Table } from 'dexie';

/**
 * A submission as it exists on the handset, before the server has seen it.
 *
 * There is deliberately no `agent_id` here. Ownership is derived server-side
 * from the bearer token and the bound device, so a locally-stored agent
 * identifier would be a claim the server must ignore and the client must not be
 * able to forge. Storing one only invites a later change that starts sending it,
 * which is how a client ends up believing it can attribute a submission to an
 * agent when it cannot.
 *
 * The field names below are the ones that go on the wire, deliberately. The
 * local record and the `payload` part of the request are the same object, so
 * there is no mapping step in which a field can be renamed on one side only.
 */
export interface Submission {
  submission_uuid: string;
  count_claimed: number;
  latitude: number | null;
  longitude: number | null;
  /** GPS horizontal accuracy in metres; null when no position was obtained. */
  accuracy_m: number | null;
  /** ISO-8601 UTC, the same shape the server's `capturedAt()` validator accepts. */
  captured_at: string;
  created_at: number;
  updated_at: number;
  status: 'PENDING' | 'SYNCING' | 'RETRY_WAIT' | 'SENT' | 'FAILED_AUTH' | 'FAILED_PERMANENT';
  retry_count: number;
  next_retry_at: number;
  last_attempt_at: number | null;
  last_error_code: number | null;
  last_error_message: string | null;
  /** The server's `submission_id`, set once a submission is accepted. */
  server_submission_id: string | null;
  photo_blob?: Blob;
  photo_mime_type: string;
  photo_size: number;
  /**
   * SHA-256 of the exact `photo_blob` bytes, hex lowercase. Computed once at
   * capture time and sent as `file_sha256`. It is what binds the device
   * signature to the uploaded file, so it has to describe the blob that is
   * actually stored — recomputing it at upload time risks hashing a
   * re-encoded copy and producing an unrecoverable FILE_HASH_MISMATCH.
   */
  file_sha256: string;
  client_exif: string;
}

export interface SyncLock {
  id: string;
  holder: string;
  expires_at: number;
}

export interface DeviceRegistration {
  id: string;
  device_uuid: string;
  private_key: CryptoKey;
  /**
   * The public half, exported once at generation time.
   *
   * Kept alongside the private key so registration can send the exact JWK the
   * server validated, instead of re-deriving it from a CryptoKey at the moment
   * of registration. Re-exporting would work, but it introduces a step where a
   * key can be reconstructed that is not the one that was generated, and the
   * whole point of storing the public half is that the server never sees the
   * private one and the client never has to re-derive anything.
   *
   * This is public key material, so it is not a secret and is safe in IndexedDB.
   */
  public_key_jwk: JsonWebKey;
  registered_at: number;
}

export class FieldPulseDB extends Dexie {
  submissions!: Table<Submission, string>;
  sync_lock!: Table<SyncLock, string>;
  device!: Table<DeviceRegistration, string>;

  constructor() {
    super('FieldPulseDB');
    // v1 indexed `agent_id`, which no longer exists. Leaving the index in place
    // would keep a ghost of the ownership model in the schema; the migration
    // drops it so an upgraded install cannot be queried by a field the server
    // would reject.
    this.version(1).stores({
      submissions: 'submission_uuid, status, created_at, next_retry_at, agent_id, device_uuid',
      sync_lock: 'id',
      device: 'id'
    });
    this.version(2).stores({
      submissions: 'submission_uuid, status, created_at, next_retry_at',
      sync_lock: 'id',
      device: 'id'
    });
  }
}

export const db = new FieldPulseDB();

/**
 * The JSON object sent as the `payload` multipart part, and the exact bytes the
 * device signature covers.
 *
 * Only the fields the contract defines. `latitude`/`longitude` are omitted
 * together when absent, because the server rejects a half-present pair, and
 * `notes` is omitted rather than sent as an empty string.
 */
export function buildSubmitPayload(sub: Submission): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    submission_uuid: sub.submission_uuid,
    count_claimed: sub.count_claimed,
    captured_at: sub.captured_at,
    file_sha256: sub.file_sha256
  };

  if (sub.latitude !== null && sub.longitude !== null) {
    payload.latitude = sub.latitude;
    payload.longitude = sub.longitude;
  }

  if (sub.accuracy_m !== null) {
    payload.accuracy_m = sub.accuracy_m;
  }

  return payload;
}
