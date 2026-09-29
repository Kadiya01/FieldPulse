import Dexie, { type Table } from 'dexie';

export interface Submission {
  submission_uuid: string;
  agent_id: string;
  device_uuid: string;
  count_claimed: number;
  client_latitude: number | null;
  client_longitude: number | null;
  client_captured_at: number;
  created_at: number;
  updated_at: number;
  status: 'PENDING' | 'SYNCING' | 'RETRY_WAIT' | 'SENT' | 'FAILED_AUTH' | 'FAILED_PERMANENT';
  retry_count: number;
  next_retry_at: number;
  last_attempt_at: number | null;
  last_error_code: number | null;
  last_error_message: string | null;
  server_submission_id: string | null;
  photo_blob?: Blob;
  photo_mime_type: string;
  photo_size: number;
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
  registered_at: number;
}

export class FieldPulseDB extends Dexie {
  submissions!: Table<Submission, string>;
  sync_lock!: Table<SyncLock, string>;
  device!: Table<DeviceRegistration, string>;

  constructor() {
    super('FieldPulseDB');
    this.version(1).stores({
      submissions: 'submission_uuid, status, created_at, next_retry_at, agent_id, device_uuid',
      sync_lock: 'id',
      device: 'id'
    });
  }
}

export const db = new FieldPulseDB();
