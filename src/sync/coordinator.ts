import { db, type Submission, buildSubmitPayload } from '../db/db';
import { authenticatedFetch } from '../api/client';

const SYNC_LOCK_ID = 'main_sync_lock';
const LOCK_TTL = 30000; // 30 seconds

/**
 * How often the lease is pushed forward while work is in flight.
 *
 * A third of the TTL, so two consecutive missed beats (a throttled timer in a
 * background tab, a long GC) still leave the lease valid rather than letting it
 * lapse mid-upload.
 */
const LOCK_RENEW_INTERVAL = LOCK_TTL / 3;

const SYNC_CHANNEL = new BroadcastChannel('fieldpulse_sync_channel');

let isSyncRunning = false;
let myHolderId = crypto.randomUUID();

/**
 * The 202 and 200 bodies the submission contract defines.
 *
 * `status` is required, and the rest of the shape is checked too. A 2xx whose
 * body does not parse is treated as a *failure*, not a success: a proxy that
 * answers 200 with an HTML login page, or a truncated body, would otherwise
 * mark a submission SENT and prune the photo — after which the agent believes
 * evidence was delivered that the server never accepted. Losing the retry is
 * worse than the duplicate that a retry would have caused.
 */
export interface SubmitAccepted {
  status: string;
  submission_uuid: string;
  submission_id: number;
  self: string;
  idempotent_replay?: boolean;
}

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Parse and validate a 2xx submission response.
 *
 * Returns null when the body is not a well-formed acceptance. Callers must
 * treat null as "not accepted" and leave the submission retryable.
 */
export function parseSubmitAccepted(response: unknown): SubmitAccepted | null {
  if (typeof response !== 'object' || response === null) return null;
  const outer = response as { data?: unknown };
  if (typeof outer.data !== 'object' || outer.data === null) return null;
  const data = outer.data as Record<string, unknown>;

  if (typeof data.status !== 'string' || data.status === '') return null;
  if (typeof data.submission_uuid !== 'string' || !UUID_RE.test(data.submission_uuid)) return null;
  if (typeof data.submission_id !== 'number' || !Number.isInteger(data.submission_id)) return null;
  if (typeof data.self !== 'string' || data.self === '') return null;

  return data as unknown as SubmitAccepted;
}

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
    // Anything left mid-flight by a previous run belongs back in the queue.
    await recoverStrandedSubmissions();

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

/**
 * Return submissions stranded in SYNCING to the queue.
 *
 * WHY THIS IS NEEDED
 *
 * processQueue marks a record SYNCING before uploading it, and only ever
 * selects PENDING or a due RETRY_WAIT. So SYNCING is a terminal state for that
 * record: if the tab is killed mid-upload — a crash, a force-quit, the browser
 * reclaiming the process, the phone losing power — the record stays SYNCING
 * forever. The queue will never pick it up again, the photo is never sent, and
 * the user sees "Uploading now" indefinitely for a submission that is never
 * being uploaded. Nothing else in the app clears it.
 *
 * WHY RETRYING IS SAFE
 *
 * The retry is not a guess at what happened. The server deduplicates on
 * submission_uuid, so if the original upload did land, the retry comes back 200
 * ALREADY_RECEIVED and the client treats it as terminal success; if it did not
 * land, the retry is the first successful send. Either way the outcome is
 * correct and at worst one redundant request.
 *
 * WHY IT RUNS UNDER THE LOCK
 *
 * Called only after acquireLock() succeeded, so this tab is the coordinator and
 * no other tab is mid-queue. Resetting SYNCING records while another tab is
 * genuinely uploading them would be harmless in outcome — the idempotency key
 * absorbs it — but holding the lock means it cannot happen at all.
 *
 * @returns how many records were recovered
 */
export async function recoverStrandedSubmissions(): Promise<number> {
  const stranded = await db.submissions.where('status').equals('SYNCING').toArray();

  if (stranded.length === 0) {
    return 0;
  }

  await db.transaction('rw', db.submissions, async () => {
    for (const record of stranded) {
      // Back to PENDING rather than RETRY_WAIT: the attempt may never have
      // started, and PENDING is due immediately, which is the honest state.
      await db.submissions.update(record.submission_uuid, {
        status: 'PENDING',
        next_retry_at: 0,
        // Preserved, not cleared: retry_count is the user's only clue about how
        // many times this has failed, and hiding it would make a photo that
        // keeps failing look like a fresh one.
        last_error_code: null,
        last_error_message: null
      });
    }
  });

  return stranded.length;
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

/**
 * Holds the sync lease open for as long as a run is genuinely in flight.
 *
 * Returns the stop function so the caller can end it deterministically when the
 * request settles, rather than relying on the sync flag alone.
 *
 * Re-acquiring is safe to do this way: acquireLock() refuses to steal a lease
 * another tab still holds, so a beat can only ever extend our own lease or do
 * nothing. It never lets a heartbeat make a second tab steal the lock.
 */
function startLockHeartbeat(): () => void {
  const beat = setInterval(() => {
    // Once the run is winding down, stop extending the lease. attemptSync()
    // clears isSyncRunning before it calls releaseLock(); a beat still in
    // flight past that point could otherwise re-create the very lock release is
    // about to delete, and the next tab would idle out a full TTL for nothing.
    if (!isSyncRunning) return;
    void acquireLock();
  }, LOCK_RENEW_INTERVAL);

  return () => clearInterval(beat);
}

async function checkApiReachability(): Promise<boolean> {
  try {
    // A simple HEAD or GET to check if the server is reachable
    // Using a known safe endpoint or the refresh endpoint without credentials
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 5000);
    const res = await fetch('/api/v1/leaderboard.php', { method: 'HEAD', signal: controller.signal });
    clearTimeout(timeoutId);
    // 401 counts as reachable: the network path works and the server answered,
    // it just would not talk to us without a session. Treating that as offline
    // would stall the queue for a user whose only problem is an expired token.
    return res.ok || res.status === 401;
  } catch {
    // Offline, DNS failure, or the 5s timeout. Nothing distinguishes them here
    // and none of them is actionable, so the queue waits and retries.
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

/**
 * Assemble the multipart body for one submission.
 *
 * The `payload` part is serialised exactly once and that same string is both
 * appended to the form and handed to the signing layer, because the server
 * signs those bytes and then re-reads them out of the form. Re-serialising —
 * even with identical key order — risks a different byte sequence, and the
 * resulting failure (a signature over bytes the server did not receive) is
 * indistinguishable from a server bug at 3am.
 */
export function buildSubmitForm(
  sub: Submission,
  payloadJson: string
): FormData {
  const form = new FormData();

  // Two-argument append: this is a text field, and it lands in $_POST as a
  // string. Appending it as a Blob with a JSON content type would reach the
  // same $_POST slot, and would only obscure the fact that the signed bytes and
  // the transmitted bytes are the identical string passed in.
  form.append('payload', payloadJson);

  if (sub.photo_blob) {
    form.append('file', sub.photo_blob, 'capture.jpg');
  }

  return form;
}

export async function uploadSubmission(record: Submission): Promise<void> {
  if (!record.photo_blob) {
    // Nothing to upload and no way to reconstruct it. Retrying forever would
    // pin the lock owner awake, so this is terminal.
    await db.submissions.update(record.submission_uuid, {
      status: 'FAILED_PERMANENT',
      last_attempt_at: Date.now(),
      last_error_code: null,
      last_error_message: 'No photo stored for this submission'
    });
    return;
  }

  // One serialisation, used for both the form part and the signature.
  const payloadJson = JSON.stringify(buildSubmitPayload(record));
  const form = buildSubmitForm(record, payloadJson);

  let response: Response;
  try {
    // A large photo on a slow link can hold this fetch open for longer than the
    // lease is valid. Renewing once before the upload (as processQueue does)
    // does not cover the upload itself, so without a heartbeat the lease lapses
    // mid-request and a second tab can claim the lock and start a concurrent
    // sync of the same queue.
    //
    // The beat interval is shorter than the TTL, and it does not require the
    // upload to be making progress: a stalled socket still holds the lease,
    // because this tab genuinely intends to finish the request.
    const stopBeat = startLockHeartbeat();

    try {
      response = await authenticatedFetch('/submit.php', {
        method: 'POST',
        body: form,
        signedBody: payloadJson
      });
    } finally {
      stopBeat();
    }
  } catch (error: any) {
    // Network failure / Timeout, or an auth failure the client layer already
    // classified. Either way the submission is still retryable.
    await scheduleRetry(record, 0, error.message);
    throw error;
  }

  const status = response.status;
  const now = Date.now();

  if (status === 202 || status === 200) {
    let parsed: SubmitAccepted | null = null;
    try {
      parsed = parseSubmitAccepted(await response.json());
    } catch {
      // A 2xx whose body is not JSON at all. Same handling as a JSON body of
      // the wrong shape: not an acceptance.
      parsed = null;
    }

    if (parsed === null) {
      // A success status with an unreadable body. Not an acceptance: keep the
      // photo and retry, so the idempotency key makes the retry free.
      await scheduleRetry(record, status, 'Malformed acceptance response');
      return;
    }

    // 202 QUEUED (first acceptance) and 200 ALREADY_RECEIVED (idempotent
    // replay) are both terminal for the client: the server has the file. Only
    // now is it safe to drop the photo.
    await db.submissions.update(record.submission_uuid, {
      status: 'SENT',
      photo_blob: undefined, // PRUNING — the server has the bytes
      last_attempt_at: now,
      last_error_code: null,
      last_error_message: null,
      server_submission_id: String(parsed.submission_id)
    });
  } else if (status === 409) {
    // The UUID belongs to another agent. This will never succeed, and the
    // server deliberately returns nothing about whose it is.
    await db.submissions.update(record.submission_uuid, {
      status: 'FAILED_PERMANENT',
      last_attempt_at: now,
      last_error_code: status,
      last_error_message: 'IDEMPOTENCY_CONFLICT'
    });
  } else if (status === 400 || status === 413 || status === 415 || status === 422) {
    // Validation Error, Payload Too Large, Unsupported Media. Retrying an
    // unchanged body cannot change the answer.
    await db.submissions.update(record.submission_uuid, {
      status: 'FAILED_PERMANENT',
      last_attempt_at: now,
      last_error_code: status
    });
  } else if (status === 401 || status === 403) {
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
