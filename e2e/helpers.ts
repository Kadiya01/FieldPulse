import { expect, type BrowserContext, type Page, type TestInfo } from '@playwright/test';
import { chromium, devices } from '@playwright/test';
import { readCredentials, readOperatorCredentials, REPO_ROOT, type Credentials } from './fixtures';
import path from 'node:path';
import { rmSync } from 'node:fs';

/**
 * Launch a real browser with a persistent profile.
 *
 * A persistent context is required, not preferred. IndexedDB only survives a
 * restart if the profile directory does, and "the queue survives the app being
 * closed" is one of the things under test — with an ephemeral context the data
 * is thrown away by the harness and the test would pass without ever proving
 * durability.
 *
 * @param fresh Wipe the profile before launching. True for a test's first
 *   launch, so a run cannot inherit a previous run's device key, submissions or
 *   session. False when relaunching the same profile within a test to prove the
 *   data survived — see the note below.
 */
export async function launchApp(
  testInfo: TestInfo,
  profileName: string,
  fresh = true
): Promise<BrowserContext> {
  const profile = path.join(REPO_ROOT, '.e2e_profiles', profileName);

  /*
   * The profile may only be wiped on a test's first launch.
   *
   * Wiping unconditionally is what made this helper look harmless and be fatal:
   * a restart test closes the context and opens the same profile again to prove
   * data survived, and deleting the directory on the way back in throws away
   * exactly what it set out to test. The restart then failed for the misleading
   * reason that the session was gone, which points at the cookie jar rather than
   * at the harness.
   */
  if (fresh) {
    rmSync(profile, { recursive: true, force: true });
  }

  /*
   * Edge is not a separate engine: it is Chromium under a different channel, so
   * @playwright/test exports no `edge`. Selecting the channel is the whole
   * difference, and it means the fake-device flags below apply identically.
   */
  const channel = testInfo.project.name === 'edge' ? 'msedge' : undefined;

  const context = await chromium.launchPersistentContext(profile, {
    ...devices['Desktop Chrome'],
    baseURL: testInfo.project.use.baseURL as string,
    viewport: { width: 1280, height: 800 },
    permissions: ['camera', 'geolocation'],
    /*
     * A fixed position, so the captured submission has real coordinates rather
     * than nulls. A null geofence would send every submission to manual review
     * and make the upload assertions depend on review state instead of on the
     * upload path.
     */
    geolocation: { latitude: 6.5244, longitude: 3.3792, accuracy: 12 },
    launchOptions: {
      channel,
      args: [
        '--use-fake-ui-for-media-stream',
        '--use-fake-device-for-media-stream',
        '--autoplay-policy=no-user-gesture-required'
      ]
    }
  });

  // Forward the browser's own diagnostics to the test output. Playwright does
  // not surface page console messages on its own, and the signature debugging
  // log lives in the page.
  const wire = (p: Page) => {
    p.on('console', (msg) => {
      const text = msg.text();
      if (text.includes('[SIG]') || text.includes('[REG]')) {
        console.log(`  [page] ${text}`);
      }
    });
  };
  for (const p of context.pages()) wire(p);
  context.on('page', wire);

  return context;
}

/** The first page of a persistent context, which starts with one already open. */
export async function firstPage(context: BrowserContext): Promise<Page> {
  return context.pages()[0] ?? (await context.newPage());
}

/**
 * Sign in through the real login form and land on the capture screen.
 *
 * Typed into the actual inputs and submitted with the actual button, so the
 * whole authentication path runs: POST /auth/login.php, device key generation,
 * POST /device/register.php, and the pairing exchange when the deployment
 * policy demands one.
 *
 * Not shortcut by seeding localStorage or calling the API directly — a test that
 * skipped this would never exercise the gate that decides whether the app is
 * usable at all.
 */
export async function signIn(page: Page, creds: Credentials = readCredentials()): Promise<void> {
  await page.goto('/login');

  await expect(page.getByRole('heading', { name: 'FieldPulse sign in' })).toBeVisible();

  await page.locator('#username').fill(creds.username);
  await page.locator('#password').fill(creds.password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  /*
   * The deployment's pairing policy decides whether this stops at a pairing
   * screen. Branching on what the app actually rendered, rather than assuming
   * one policy, is what lets the same suite run against a deployment configured
   * either way.
   */
  const pairHeading = page.getByRole('heading', { name: 'Pair this device' });

  await Promise.race([
    expect(page).toHaveURL(/\/$/, { timeout: 30_000 }),
    expect(pairHeading).toBeVisible({ timeout: 30_000 })
  ]);

  if (await pairHeading.isVisible().catch(() => false)) {
    await page.locator('#pairing').fill(creds.pairingCode);
    await page.getByRole('button', { name: 'Pair device' }).click();
  }

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole('heading', { name: 'Capture' })).toBeVisible();
}

/** Sign in as the provisioned SUPERVISOR rather than the agent. */
export async function signInOperator(page: Page, creds: Credentials = readOperatorCredentials()): Promise<void> {
  await signIn(page, creds);
}

/**
 * Open the Rewards screen from the primary navigation.
 *
 * Clicking the nav entry rather than `goto('/rewards')` is deliberate: it proves
 * the destination is reachable for this role, which is the part of "the agent
 * can see their rewards" that a direct URL would skip.
 */
export async function openRewards(page: Page): Promise<void> {
  await page.getByRole('link', { name: 'Rewards' }).click();
  await expect(page.getByRole('heading', { name: 'Rewards', exact: true })).toBeVisible();
}

/**
 * Take a photo through the real capture flow and save it to the device.
 *
 * The shutter is pressed, not synthesised: the page draws the current video
 * frame to a canvas and encodes a JPEG, so what reaches IndexedDB is a genuine
 * photograph of the browser's fake capture device rather than a fixture blob.
 * A non-empty photo is asserted before saving, because a capture that silently
 * produced zero bytes would make every later assertion pass for the wrong
 * reason.
 */
export async function captureAndSave(page: Page, countClaimed: number): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Capture' })).toBeVisible();

  const shutter = page.getByRole('button', { name: 'Take photo' });

  // The shutter is disabled until getUserMedia has produced a stream, so this
  // wait is really a wait for the camera to be live.
  await expect(shutter).toBeEnabled({ timeout: 30_000 });
  await shutter.click();

  // The confirmation view replaces the live preview once a frame is encoded.
  const preview = page.getByAltText('Photo just captured, awaiting confirmation');
  await expect(preview).toBeVisible({ timeout: 30_000 });

  const photo = await preview.evaluate((img: HTMLImageElement) => ({
    width: img.naturalWidth,
    height: img.naturalHeight
  }));

  expect(photo.width, 'captured frame has real width').toBeGreaterThan(0);
  expect(photo.height, 'captured frame has real height').toBeGreaterThan(0);

  await page.locator('#count').fill(String(countClaimed));
  await page.getByRole('button', { name: 'Save offline' }).click();

  /*
   * Wait for the confirm view to be replaced by the live camera again. That
   * reset happens only after the write to IndexedDB has completed, so the
   * shutter becoming enabled is the app's own signal that the photo is stored —
   * which is what the caller then verifies independently by reading the row.
   */
  await expect(preview).toBeHidden({ timeout: 30_000 });
  await expect(page.getByRole('button', { name: 'Take photo' })).toBeEnabled({ timeout: 30_000 });
}

/**
 * Capture the device-bound access token from the registration response.
 *
 * The token is held in memory by the app and never written anywhere a test can
 * read it, which is the point. Capturing it off the wire lets a test make the
 * same authenticated call the app would, so an authorization assertion tests
 * the server's rule rather than the UI's. Attach this before signIn, which is
 * what drives POST /device/register.php; await the returned promise later.
 */
export function captureDeviceToken(page: Page): Promise<string> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(
      () => reject(new Error('device registration response never carried an access token')),
      30_000
    );

    page.on('response', async (response) => {
      if (!response.url().includes('/device/register.php') || !response.ok()) {
        return;
      }

      try {
        const body = (await response.json()) as { access_token?: string };

        if (body.access_token) {
          clearTimeout(timer);
          resolve(body.access_token);
        }
      } catch {
        // Not the response we are waiting for; keep listening.
      }
    });
  });
}

/** The uuid of the most recently created local submission. */
export async function latestUuid(page: Page): Promise<string> {
  const uuid = await page.evaluate(async () => {
    const db = await new Promise<IDBDatabase>((resolve, reject) => {
      const req = indexedDB.open('FieldPulseDB');
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });

    return new Promise<string | undefined>((resolve, reject) => {
      const req = db
        .transaction('submissions', 'readonly')
        .objectStore('submissions')
        .getAll();

      req.onsuccess = () => {
        const rows = (req.result ?? []) as Array<{ created_at: number; submission_uuid: string }>;
        const newest = rows.sort((a, b) => b.created_at - a.created_at)[0];
        resolve(newest?.submission_uuid);
      };
      req.onerror = () => reject(req.error);
    });
  });

  if (!uuid) {
    throw new Error('no local submission found');
  }

  return uuid;
}
