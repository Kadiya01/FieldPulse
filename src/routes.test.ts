import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

/**
 * The ABH app has distinct administrator and agent entry points. The route
 * contract is read as source so the test does not import camera, Dexie or crypto
 * modules just to verify that an ABH token cannot reach legacy pages.
 */

const here = dirname(fileURLToPath(import.meta.url));
const read = (name: string) => readFileSync(resolve(here, name), 'utf8');

const appSource = read('App.tsx');

describe('offline and verification state wording', () => {
  /*
   * The README publishes the local state table and the disposition wording as
   * user-facing text. Both are defined as maps in the queue screen, so a
   * renumbering of a state there silently invalidates the documentation — and
   * the documentation is what someone reads to decide whether a receipt has been
   * promoted to a verification somewhere it should not have been.
   */

  const queueSource = read('pages/QueuePage.tsx');
  const dbSource = read('db/db.ts');

  const statesInSchema = (() => {
    const match = dbSource.match(/status:\s*((?:'[^']+'\s*\|\s*)*'[^']+')\s*;/);
    expect(match).not.toBeNull();
    return [...match![1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
  })();

  const labelsInQueue = [...queueSource.matchAll(/^\s{2}([A-Z_]+): \{$/gm)].map((m) => m[1]);

  it('gives every schema state a label on the queue screen', () => {
    expect(statesInSchema.length).toBeGreaterThanOrEqual(6);

    for (const state of statesInSchema) {
      expect(labelsInQueue).toContain(state);
    }
  });

  it('labels none that the schema does not define', () => {
    for (const state of labelsInQueue) {
      expect(statesInSchema).toContain(state);
    }
  });

  it('never labels a receipt as verified', () => {
    // The single most important string property in the product. A local state
    // that reads "verified" would let an un-checked count be reported as
    // confirmed, and the whole upload/verification split would collapse into the
    // thing it exists to prevent.
    const localBlock = queueSource.slice(
      queueSource.indexOf('const LOCAL_STATE'),
      queueSource.indexOf('const DISPOSITION_WORDS')
    );

    for (const state of statesInSchema) {
      const entry = localBlock.slice(localSourceIndex(localBlock, state));
      const label = entry.match(/label: '([^']+)'/)?.[1] ?? '';

      expect(label.toLowerCase()).not.toContain('verified');
    }
  });

  it('keeps upload wording and verification wording in separate maps', () => {
    // Two maps, not one. Merging them is how "SENT" ends up sharing a label with
    // "VERIFIED" and the distinction the README documents stops existing in code.
    expect(queueSource).toContain('const LOCAL_STATE');
    expect(queueSource).toContain('const DISPOSITION_WORDS');
    expect(labelsInQueue).not.toContain('VERIFIED');
  });

  it('tells the agent in words that upload is not verification', () => {
    // The sentence lives in the layout footer so it is on every authenticated
    // screen, and the queue screen says it again in its column headers.
    const layoutSource = read('components/Layout.tsx');

    expect(layoutSource).toMatch(/Upload status is not verification/i);
    expect(queueSource).toMatch(/Local sync state is what this handset has done/i);
    expect(queueSource).toMatch(/Server verification state/i);
  });
});

function localSourceIndex(block: string, state: string): number {
  const at = block.indexOf(`\n  ${state}: {`);
  return at === -1 ? 0 : at;
}

describe('ABH app routing and legacy isolation', () => {
  it('keeps agent and administrator authentication on separate routes', () => {
    expect(appSource).toContain("location.pathname === '/admin'");
    expect(appSource).toContain('<AdminPortalPage />');
    expect(appSource).toMatch(/path="\\/login"/);
    expect(appSource).toMatch(/path="\\/register"/);
    expect(appSource).not.toMatch(/path="\\/admin\\/login"/);
    expect(read('pages/LoginPage.tsx')).not.toContain('admin?: boolean');
  });

  it('renders the dedicated ABH agent portal after successful agent authentication', () => {
    expect(appSource).toContain("import AbhAgentPortalPage from './pages/AbhAgentPortalPage'");
    expect(appSource).toContain('<AbhAgentPortalPage agent={agent} onSignOut={endSession} />');
    expect(read('pages/AbhAgentPortalPage.tsx')).toContain('submitAbhEnrollment');
    expect(read('pages/AbhAgentPortalPage.tsx')).toContain('fetchAbhAgentSubmissions');
  });

  it('does not expose legacy authenticated FieldPulse pages to ABH sessions', () => {
    expect(appSource).not.toContain("import CapturePage");
    expect(appSource).not.toContain("import QueuePage");
    expect(appSource).not.toContain("import DashboardPage");
    expect(appSource).not.toContain("import RewardsPage");
    expect(appSource).not.toContain("import ReviewsPage");
    expect(appSource).not.toContain("import { triggerSync }");
    expect(appSource).toContain('path="*"');
  });

  it('keeps public self-registration available to new agents', () => {
    expect(appSource.split('path="/register"').length - 1).toBe(1);
    expect(read('pages/RegisterPage.tsx')).toContain('registerAbhAgent');
    expect(read('pages/LoginPage.tsx')).toContain('to="/register"');
  });
});
