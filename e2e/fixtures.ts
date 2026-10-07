import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * Shared plumbing for the browser suite.
 *
 * Everything here talks to real things. The fixture provisions a real agent
 * through the real administrative CLIs, arms faults through the state file the
 * test router reads, and reads submission rows back out of the browser's own
 * IndexedDB rather than out of a variable the test set up. There is no in-memory
 * double of any of it, so a test cannot pass by agreeing with itself.
 */

// ESM: package.json declares "type": "module", so __dirname does not exist.
//
// The repository root is taken from FIELDPULSE_REPO_ROOT when set, because
// Playwright evaluates global-setup from a cache directory where any
// module-relative or import.meta.url-derived path is wrong. playwright.config.ts
// sets it from its own real path. The fallback keeps this module usable when
// imported directly by a worker, which does load it from its actual location.
const HERE = path.dirname(fileURLToPath(import.meta.url));

export const REPO_ROOT = process.env.FIELDPULSE_REPO_ROOT
  ? path.resolve(process.env.FIELDPULSE_REPO_ROOT)
  : path.resolve(HERE, '..');

const PHP = process.env.FIELDPULSE_PHP ?? 'php';

const CREDS_PATH = path.join(REPO_ROOT, '.e2e_creds.json');
const FAULTS_PATH = path.join(REPO_ROOT, '.e2e_faults.json');

export type CredentialRole = 'AGENT' | 'SUPERVISOR' | 'ADMIN';

export type Credentials = {
  agentCode: string;
  username: string;
  password: string;
  pairingCode: string;
  role: CredentialRole;
};

/**
 * The suite needs two identities, not one.
 *
 * Rewards are decided by an operator and seen by the agent who earned them, and
 * those are different people by construction — the server refuses a decision
 * from anyone but a SUPERVISOR or ADMIN. A single fixture would have to log in
 * as both through one profile, which would prove much less: the whole point of
 * the operator spec is that a separate session, with a separate role, is the one
 * that moves the entitlement.
 */
type CredentialStore = { agent?: Credentials; operator?: Credentials };

/** Locally unique per run, so a crashed run cannot poison the next one. */
function runId(): string {
  return Math.random().toString(36).slice(2, 10);
}

/**
 * Run an administrative CLI and return its output.
 *
 * Exits non-zero on failure rather than returning it, so a provisioning
 * problem stops the run at setup instead of surfacing later as a login
 * timeout with no explanation.
 */
function php(args: string[], input?: string): string {
  try {
    return execFileSync(PHP, args, {
      cwd: path.join(REPO_ROOT, 'private_storage'),
      encoding: 'utf8',
      input,
      stdio: ['pipe', 'pipe', 'pipe'],
      env: {
        ...process.env,
        // The bundled OpenSSL config on Windows; harmless elsewhere.
        OPENSSL_CONF: process.env.OPENSSL_CONF ?? '',
      }
    });
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error);
    throw new Error(`php ${args.join(' ')} failed: ${detail}`);
  }
}

/**
 * Create an agent, give it a password, and issue a pairing code.
 *
 * Written to .e2e_creds.json for the specs to read. The password goes in over
 * stdin rather than argv because this process list is readable by anything
 * running as the same user.
 */
export function provisionAgent(): Credentials {
  const creds = provision('AGENT');
  writeCreds('agent', creds);

  return creds;
}

/**
 * Create the SUPERVISOR who decides rewards.
 *
 * Provisioned exactly like an agent, because the deployment has one identity
 * concept: an operator is an agent row whose role is SUPERVISOR. Everything
 * else — the password, the device pairing, the session — is the same path an
 * agent walks, which is what lets the operator spec sign in through the real
 * login form rather than seeding a role into storage.
 */
export function provisionOperator(): Credentials {
  const creds = provision('SUPERVISOR');
  writeCreds('operator', creds);

  return creds;
}

/**
 * Provision one identity and hand back its credentials without writing them to
 * the shared file.
 *
 * The reward specs each provision their own agent and operator rather than
 * sharing global setup's. A pairing code is redeemable exactly once and every
 * fresh browser profile needs its own device, so a single shared identity would
 * only work for the first launch that used it.
 */
export function provisionCredentials(role: CredentialRole): Credentials {
  return provision(role);
}

function provision(role: CredentialRole): Credentials {
  const agentCode = 'e2e_' + runId();
  const username = 'e2e_' + runId();
  const password = 'E2e-' + runId() + '-pw';
  const bin = ['bin/provision_agent.php'];

  php([...bin, `--code=${agentCode}`, `--name=E2E ${role}`, `--role=${role}`]);
  php(['bin/set_credentials.php', `--code=${agentCode}`, `--username=${username}`, '--password-stdin'], password);

  const pairing = php(['bin/pair_device.php', `--code=${agentCode}`, '--label=e2e']);

  // The code is printed once and never stored in the clear, so it has to be
  // taken from the output rather than queried back.
  const match = pairing.match(/^\s{4}(\d{6,})\s*$/m);

  if (!match) {
    throw new Error('could not read the pairing code from pair_device.php output:\n' + pairing);
  }

  return { agentCode, username, password, pairingCode: match[1], role };
}

function writeCreds(slot: 'agent' | 'operator', creds: Credentials): void {
  let store: CredentialStore = {};

  if (existsSync(CREDS_PATH)) {
    try {
      store = JSON.parse(readFileSync(CREDS_PATH, 'utf8')) as CredentialStore;
    } catch {
      store = {};
    }
  }

  store[slot] = creds;
  writeFileSync(CREDS_PATH, JSON.stringify(store, null, 2));
}

function readCreds(slot: 'agent' | 'operator'): Credentials {
  if (!existsSync(CREDS_PATH)) {
    throw new Error('No .e2e_creds.json - global setup did not run.');
  }

  const store = JSON.parse(readFileSync(CREDS_PATH, 'utf8')) as CredentialStore;
  const creds = store[slot];

  if (!creds) {
    throw new Error(`No ${slot} credentials in .e2e_creds.json - global setup did not provision one.`);
  }

  return creds;
}

export function readCredentials(): Credentials {
  return readCreds('agent');
}

export function readOperatorCredentials(): Credentials {
  return readCreds('operator');
}

/* -------------------------------------------------------------------------- */
/* Reward state for the browser suite                                         */
/* -------------------------------------------------------------------------- */

export type SeededReward = {
  id: number;
  rank: number;
  status: string;
  tier_label: string | null;
  reward_amount: string | null;
  currency: string | null;
  total_verified_count: number;
};

export type SeedResult = {
  action: 'seed';
  period: string;
  agent: string;
  agent_id: number;
  closed: boolean;
  reason: string;
  frozen: number;
  published: number;
  reward: SeededReward | null;
};

/**
 * Arrange a closed reward week for an agent.
 *
 * Delegates to bin/e2e_state.php, which writes the standing and closes the
 * period through the real RewardService. The returned `period` is the week that
 * was arranged and `reward.id` is the entitlement, so a spec asserts against
 * the exact row the engine published rather than recomputing either.
 */
export function seedReward(agentCode: string, options: {
  weeksAgo?: number;
  period?: string;
  verified?: number;
  amount?: number | string;
  currency?: string;
} = {}): SeedResult {
  const args = ['bin/e2e_state.php', 'seed', `--agent=${agentCode}`];

  if (options.period) {
    args.push(`--period=${options.period}`);
  } else {
    args.push(`--weeks-ago=${options.weeksAgo ?? 2}`);
  }

  if (options.verified !== undefined) {
    args.push(`--verified=${options.verified}`);
  }

  // An explicit omission leaves the tier amount NULL; that is a state the UI
  // has to render as "not set" rather than as zero, so it must be reachable.
  if (options.amount !== undefined) {
    args.push(`--amount=${options.amount}`);
  }

  if (options.currency !== undefined) {
    args.push(`--currency=${options.currency}`);
  }

  return parseLastJson(php(args)) as SeedResult;
}

/**
 * Rewrite the live summary after the period froze.
 *
 * The mutation the cutoff rule exists to neutralise: a late verification, or
 * bin/reaggregate.php, rewriting a historical week. The published entitlement
 * must not move when this runs.
 */
export function bumpLiveSummary(agentCode: string, period: string, verified: number): void {
  php(['bin/e2e_state.php', 'bump', `--agent=${agentCode}`, `--period=${period}`, `--verified=${verified}`]);
}

/**
 * The last line of a CLI's stdout is its machine-readable result. bootstrapping
 * and logging noise precede it, and none of it is JSON.
 */
function parseLastJson(output: string): unknown {
  const lines = output.trim().split(/\r?\n/).filter((line) => line.trim() !== '');

  for (let i = lines.length - 1; i >= 0; i -= 1) {
    const line = lines[i].trim();

    if (line.startsWith('{') && line.endsWith('}')) {
      return JSON.parse(line);
    }
  }

  throw new Error('no JSON result in CLI output:\n' + output);
}

/* -------------------------------------------------------------------------- */
/* Fault injection                                                            */
/* -------------------------------------------------------------------------- */

export type SubmitFault =
  | { mode: 'none' }
  | { mode: 'delay'; ms: number }
  | { mode: 'lost_response' };

/**
 * Arm, or disarm, a submit-path fault.
 *
 * Read by the test router on every submit request, so a test can change it
 * between attempts without restarting the server. This is the only mechanism by
 * which the suite perturbs the server, and it cannot affect anything but the
 * submit endpoint.
 */
export function setSubmitFault(fault: SubmitFault): void {
  writeFileSync(FAULTS_PATH, JSON.stringify({ submit: fault }, null, 2));
}

export function clearFaults(): void {
  setSubmitFault({ mode: 'none' });
}

/* -------------------------------------------------------------------------- */
/* Reading real IndexedDB out of a real page                                  */
/* -------------------------------------------------------------------------- */

export type RowSummary = {
  submission_uuid: string;
  status: string;
  retry_count: number;
  count_claimed: number;
  hasPhoto: boolean;
  photoSize: number;
  server_submission_id: string | null;
  last_error_code: number | null;
  last_error_message: string | null;
  next_retry_at: number;
};

export type LockSummary = { id: string; holder: string; expires_at: number } | null;

/**
 * Read the submissions table straight out of the page's IndexedDB.
 *
 * Evaluated in page context against the real `FieldPulseDB`, because the
 * assertions are about what the application persisted — a row's status and
 * whether its photo survived — not about what a test variable was told.
 *
 * `photo_blob` is reported as present and its size rather than returned, so the
 * assertion can be "the photo is still on the device" without dragging
 * megabytes of binary across the bridge.
 */
export async function readSubmissions(page: import('@playwright/test').Page): Promise<RowSummary[]> {
  return page.evaluate(async () => {
    const open = (): Promise<IDBDatabase> =>
      new Promise((resolve, reject) => {
        const req = indexedDB.open('FieldPulseDB');
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
      });

    const db = await open();

    return new Promise<unknown[]>((resolve, reject) => {
      const tx = db.transaction('submissions', 'readonly');
      const req = tx.objectStore('submissions').getAll();

      req.onsuccess = () => {
        const rows = (req.result ?? []) as Array<Record<string, unknown>>;

        resolve(
          rows.map((row) => ({
            submission_uuid: row.submission_uuid as string,
            status: row.status as string,
            retry_count: row.retry_count as number,
            count_claimed: row.count_claimed as number,
            hasPhoto: row.photo_blob instanceof Blob,
            photoSize: row.photo_blob instanceof Blob ? row.photo_blob.size : 0,
            server_submission_id: (row.server_submission_id ?? null) as string | null,
            last_error_code: (row.last_error_code ?? null) as number | null,
            last_error_message: (row.last_error_message ?? null) as string | null,
            next_retry_at: row.next_retry_at as number
          }))
        );
      };
      req.onerror = () => reject(req.error);
    });
  }) as Promise<RowSummary[]>;
}

/** Read the coordinator's lease row, or null if no one holds it. */
export async function readSyncLock(page: import('@playwright/test').Page): Promise<LockSummary> {
  return page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
      const req = indexedDB.open('FieldPulseDB');
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });

    return new Promise<unknown>((resolve, reject) => {
      const req = db.transaction('sync_lock', 'readonly').objectStore('sync_lock').get('main_sync_lock');
      req.onsuccess = () => resolve(req.result ?? null);
      req.onerror = () => reject(req.error);
    });
  }) as Promise<LockSummary>;
}

/** Poll until the submission named by `uuid` reaches one of `statuses`. */
export async function waitForStatus(
  page: import('@playwright/test').Page,
  uuid: string,
  statuses: string[],
  timeoutMs = 30_000
): Promise<RowSummary> {
  const deadline = Date.now() + timeoutMs;

  for (;;) {
    const rows = await readSubmissions(page);
    const row = rows.find((r) => r.submission_uuid === uuid);

    if (row && statuses.includes(row.status)) {
      return row;
    }

    if (Date.now() > deadline) {
      throw new Error(
        `submission ${uuid} never reached ${statuses.join('|')}; last seen: ${row ? row.status : 'no row'}`
      );
    }

    await page.waitForTimeout(250);
  }
}
