import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * One-time preparation for the browser suite: provision a signed-in agent.
 *
 * The build and the server both belong to Playwright's `webServer` (see
 * playwright.config.ts), not here. Playwright starts the webServer *before* it
 * runs global setup, so anything this file builds or stages arrives after the
 * document root has already been served from — the browser would load the
 * previous run's bundle while this suite reported it had just built the current
 * one. Building in the webServer command makes the served bytes and the working
 * tree the same artifact by construction.
 *
 * Playwright needs to own the server process anyway: it has to be able to tear
 * it down and surface its output when a test fails.
 */

// ESM: package.json declares "type": "module".
const ROOT = process.env.FIELDPULSE_REPO_ROOT
  ?? path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export default async function globalSetup(): Promise<void> {
  /*
   * Provisioned once per run and shared by every test.
   *
   * ROOT is taken from the environment first. Playwright evaluates this file as
   * a bundle from its own cache directory, so import.meta.url points somewhere
   * that has nothing to do with the repository, and a module-relative import
   * resolves against that instead — which silently wrote the credentials into the
   * cache while the workers read a stale file from the repository. The config
   * publishes the real root for exactly this reason.
   */
  process.env.FIELDPULSE_REPO_ROOT = ROOT;

  console.log('[e2e] provisioning an agent and an operator...');

  const { provisionAgent, provisionOperator } = await import('./fixtures');
  const agent = provisionAgent();
  const operator = provisionOperator();

  console.log(`[e2e] agent ${agent.agentCode} ready as ${agent.username}`);
  console.log(`[e2e] operator ${operator.agentCode} ready as ${operator.username}`);
}
