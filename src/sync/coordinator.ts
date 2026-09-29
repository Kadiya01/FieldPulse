import { db, type Submission } from '../db/db';
import { authenticatedFetch } from '../api/client';

const SYNC_LOCK_ID = 'main_sync_lock';
const LOCK_TTL = 30000; // 30 seconds
const SYNC_CHANNEL = new BroadcastChannel('fieldpulse_sync_channel');

let isSyncRunning = false;
let myHolderId = crypto.randomUUID();

export async function triggerSync() {
  SYNC_CHANNEL.postMessage('WAKE_UP');
  attemptSync();
}

SYNC_CHANNEL.onmessage = (event) => {
  if (event.data === 'WAKE_UP') {
    attemptSync();
  }
};

async function attemptSync() {
  if (!navigator.onLine) return; // Fast fail if definitely offline
  if (isSyncRunning) return;

  const acquired = await acquireLock();
  if (!acquired) return; // Another tab is the coordinator

  isSyncRunning = true;
  try {
    // Ping API to check reachability before starting queue
    if (!(await checkApiReachability())) {
      return;
    }
    await processQueue();
  } finally {
    isSyncRunning = false;
    await releaseLock();
  }
}

async function acquireLock(): Promise<boolean> {
  return await db.transaction('rw', db.sync_lock, async () => {
    const lock = await db.sync_lock.get(SYNC_LOCK_ID);
    const now = Date.now();
    
    if (!lock || lock.expires_at < now || lock.holder === myHolderId) {
      // Lock is free, expired, or already ours
      await db.sync_lock.put({
        id: SYNC_LOCK_ID,
        holder: myHolderId,
        expires_at: now + LOCK_TTL
      });
      return true;
    }
    return false;
  });
}

async function releaseLock() {
  await db.transaction('rw', db.sync_lock, async () => {
    const lock = await db.sync_lock.get(SYNC_LOCK_ID);
    if (lock && lock.holder === myHolderId) {
      await db.sync_lock.delete(SYNC_LOCK_ID);
    }
  });
}

async function checkApiReachability(): Promise<boolean> {
  try {
    // A simple HEAD or GET to check if the server is reachable
    // Using a known safe endpoint or the refresh endpoint without credentials
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000);
    const res = await fetch('/api/v1/leaderboard.php', { method: 'HEAD', signal: controller.signal });
    clearTimeout(timeoutId);
    return res.ok || res.status === 401; // 401 means it's reachable but we need auth
  } catch (error) {
    return false;
  }
}

async function processQueue() {
  while (navigator.onLine) {
    // Find oldest eligible PENDING or RETRY_WAIT record
    const now = Date.now();
    const record = await db.submissions
      .orderBy('created_at')
      .filter(sub => 
        (sub.status === 'PENDING') || 
        (sub.status === 'RETRY_WAIT' && sub.next_retry_at <= now)
      )
      .first();

    if (!record) break; // Queue empty or no eligible items

    // Mark local state SYNCING
    await db.submissions.update(record.submission_uuid, { status: 'SYNCING' });

    // Renew lock to ensure we don't lose it during a long upload
    await acquireLock();

    try {
      await uploadSubmission(record);
    } catch (error) {
      console.error('Upload failed for', record.submission_uuid, error);
      // We don't break the loop, but the upload function handles setting back to RETRY_WAIT
      // Actually we might break if it's a network error to avoid hammering
      break; 
    }
  }
}

async function uploadSubmission(record: Submission) {
  const formData = new FormData();
  formData.append('submission_uuid', record.submission_uuid);
  formData.append('agent_id', record.agent_id);
  formData.append('device_uuid', record.device_uuid);
  formData.append('count_claimed', record.count_claimed.toString());
  if (record.client_latitude) formData.append('client_latitude', record.client_latitude.toString());
  if (record.client_longitude) formData.append('client_longitude', record.client_longitude.toString());
  formData.append('client_captured_at', record.client_captured_at.toString());
  
  if (record.photo_blob) {
    formData.append('photo', record.photo_blob, 'capture.jpg');
  }

  let response: Response;
  try {
    response = await authenticatedFetch('/submit.php', {
      method: 'POST',
      body: formData
    });
  } catch (error: any) {
    // Network failure / Timeout
    await scheduleRetry(record, 500, error.message);
    throw error;
  }

  const status = response.status;
  const now = Date.now();

  if (status === 201 || status === 200) {
    // RECEIVED or ALREADY_RECEIVED
    // Delete photo_blob from IndexedDB
    await db.submissions.update(record.submission_uuid, {
      status: 'SENT',
      photo_blob: undefined, // PRUNING
      last_attempt_at: now,
      last_error_code: null
    });
  } else if (status === 400 || status === 422 || status === 409 || status === 413) {
    // Validation Error, Conflict, Payload Too Large
    await db.submissions.update(record.submission_uuid, {
      status: 'FAILED_PERMANENT',
      last_attempt_at: now,
      last_error_code: status
    });
  } else if (status === 401 || status === 403) {
    // FAILED_AUTH handled largely by authenticatedFetch but update state
    await db.submissions.update(record.submission_uuid, {
      status: 'FAILED_AUTH',
      last_attempt_at: now,
      last_error_code: status
    });
    throw new Error('Auth failed');
  } else if (status === 429) {
    const retryAfter = parseInt(response.headers.get('Retry-After') || '60', 10);
    await scheduleRetry(record, status, 'Rate Limited', retryAfter * 1000);
    throw new Error('Rate Limited');
  } else {
    // 5xx or other
    await scheduleRetry(record, status, 'Server Error');
    throw new Error('Server Error');
  }
}

async function scheduleRetry(record: Submission, code: number, message: string, fixedDelay?: number) {
  const retryCount = record.retry_count + 1;
  // Exponential backoff with jitter (Base 2s, Max 60s)
  const baseDelay = 2000;
  const maxDelay = 60000;
  
  let delay = fixedDelay;
  if (!delay) {
    const exp = Math.min(retryCount, 10);
    const backoff = baseDelay * Math.pow(2, exp - 1);
    const jitter = Math.random() * 1000;
    delay = Math.min(backoff + jitter, maxDelay);
  }

  await db.submissions.update(record.submission_uuid, {
    status: 'RETRY_WAIT',
    retry_count: retryCount,
    next_retry_at: Date.now() + delay,
    last_attempt_at: Date.now(),
    last_error_code: code,
    last_error_message: message
  });
}
