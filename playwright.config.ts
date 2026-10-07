import { defineConfig, devices } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// package.json declares "type": "module", so this file is ESM and __dirname is
// not defined. webServer.cwd needs the repository root explicitly.
const HERE = path.dirname(fileURLToPath(import.meta.url));

/*
 * Publish the real repository root for the rest of the harness.
 *
 * This file is loaded from its actual path, so HERE is trustworthy. global-setup
 * is not: Playwright bundles it and evaluates the bundle from its own cache
 * directory, which makes both import.meta.url and a module-relative path resolve
 * somewhere else entirely. Without this, global setup provisions an agent and
 * writes its credentials into the cache while the test workers read a stale file
 * from the repository — and the suite fails on "Sign in failed" with a username
 * that was never created.
 */
process.env.FIELDPULSE_REPO_ROOT = HERE;

/**
 * The PHP binary path now lives in e2e/serve.mjs, which is also where it has to
 * be quoted correctly. The Windows install sits under "AppData\Local\Microsoft\
 * WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe", so any
 * path with a space in it is truncated at the space when it is passed through a
 * shell — the suite then fails to start with a message that has nothing to do
 * with the cause. serve.mjs spawns it through an argument array, which has no
 * such hazard, and there is deliberately no copy of it left here: two constants
 * for the same binary is two ways to disagree about which PHP is running.
 */

/**
 * Real-browser end-to-end configuration.
 *
 * These tests drive the built app in an actual browser against an actual PHP
 * server and an actual MySQL/MariaDB database. Nothing here is stubbed: the
 * camera is the browser's fake capture device, the queue is real IndexedDB, the
 * coordinator is the shipped BroadcastChannel + Web Locks + lease code, and the
 * server is the shipped API.
 *
 * That is why the camera needs explicit flags rather than a permissions mock:
 * an empty track would make every photo assertion vacuous.
 */
const CHROME_MEDIA_ARGS = [
  // Auto-accept the camera prompt and feed the synthetic capture device, so
  // getUserMedia resolves to a real MediaStream with decodable frames.
  '--use-fake-ui-for-media-stream',
  '--use-fake-device-for-media-stream',
  // Playwright's own gesture handling covers the click; this keeps a capture
  // started from a timer from being blocked as unmuted autoplay.
  '--autoplay-policy=no-user-gesture-required',
];

const BASE_URL = process.env.FIELDPULSE_E2E_URL ?? 'http://127.0.0.1:8791';

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  // The coordination and lock-renewal scenarios are timing-based by nature and
  // need a slow-upload run longer than the 30s lease. The default 30s ceiling
  // would fail them for the wrong reason.
  timeout: 180_000,
  // Assertions that genuinely need longer — session restore after a restart,
  // service worker readiness — ask for it explicitly. The default is kept short
  // because a wrong expectation is the most common failure while iterating, and
  // at 30s each it costs more than the work the assertion is protecting.
  expect: { timeout: 12_000 },
  // Slow-upload and stale-recovery scenarios mutate one shared server and one
  // database. Running them in parallel would let two tests trip the same fault
  // injection or contend for the same agent, so failures would no longer be
  // attributable.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],

  /*
   * A real deploy served by a real PHP process, booted by bin/e2e_server.php
   * through e2e/serve.mjs.
   *
   * The build belongs to the webServer, not to globalSetup, and that ordering
   * is load-bearing. Playwright starts the webServer before it runs
   * globalSetup, so a build done there finishes after e2e_server.php has already
   * staged dist/ into the document root. The browser then loads the previous
   * run's bundle while the suite reports it has just built the current one.
   *
   * serve.mjs skips the build when dist/ is already newer than every source
   * file, because vite-plugin-pwa's closeBundle costs ~25s per run, and only
   * rebuilds when it is not — the staleness guard is what keeps that saving from
   * turning back into the stale-bundle bug above.
   *
   * reuseExistingServer is off so a stale server from an interrupted run — with
   * an old build in its document root — cannot be silently adopted and make a
   * test pass against code that is no longer in dist/.
   */
  webServer: {
    command: `"${process.execPath}" e2e/serve.mjs`,
    url: BASE_URL,
    cwd: HERE,
    reuseExistingServer: false,
    timeout: 180_000,
    stdout: 'pipe',
    stderr: 'pipe',
    env: {
      // The E2E server only binds 127.0.0.1, but being explicit documents that
      // this suite has no business reaching anything off-host.
      FIELDPULSE_E2E_LOCAL_ONLY: '1'
    }
  },

  use: {
    baseURL: BASE_URL,
    // Camera permission is granted explicitly so the fake device is usable.
    permissions: ['camera'],
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // A fixed viewport keeps the capture canvas geometry stable across runs.
    viewport: { width: 1280, height: 800 },
  },

  projects: [
    {
      // Primary. Bundled Chromium, so the suite does not depend on whatever
      // browser the machine happens to have.
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        permissions: ['camera'],
        launchOptions: { args: CHROME_MEDIA_ARGS },
      },
    },
    {
      // Fallback, opt-in. Runs on installed Edge. Only the scenarios whose
      // assertions do not depend on Chromium-specific capture behaviour are
      // expected to be meaningful here; see e2e/README.md.
      name: 'edge',
      use: {
        ...devices['Desktop Edge'],
        channel: 'msedge',
        permissions: ['camera'],
        launchOptions: { args: CHROME_MEDIA_ARGS },
      },
    },
  ],
});
