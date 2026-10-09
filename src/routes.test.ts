import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

/**
 * Two lists that must agree: what the app routes to, and what the nav links to.
 *
 * These are asserted by reading both files as text rather than by rendering,
 * for one reason: a DOM environment on this machine costs ~45s of worker
 * start-up against a fixed 60s budget, and `App` cannot be imported at all
 * without dragging in Dexie, the crypto module and the camera. The rendering
 * half — that the entries appear as real links, that a click navigates, and
 * that Reviews is absent for an agent — is in `SiteNav.test.tsx`.
 *
 * What is left is the question no rendering test answers: *is there a route
 * behind this link?* A `NavLink` to a path nothing matches does not throw, does
 * not warn, and renders a link that silently does nothing. That is precisely how
 * `/queue` and `/leaderboard` were unreachable while every route existed.
 */

const here = dirname(fileURLToPath(import.meta.url));
const read = (name: string) => readFileSync(resolve(here, name), 'utf8');

const appSource = read('App.tsx');
const navSource = read('components/SiteNav.tsx');

/**
 * `to: '/queue'` in the nav's item list, `path="/queue"` in the router table.
 *
 * Both spellings are accepted because the two files express these differently:
 * the nav builds an array of objects and the router writes JSX. Reading for one
 * spelling only would find nothing in the other file, and "found nothing" is the
 * condition these assertions cannot distinguish from "nothing to check".
 */
const all = (source: string, patterns: RegExp[]): string[] => {
  const found = new Set<string>();

  for (const pattern of patterns) {
    for (const match of source.matchAll(pattern)) {
      found.add(match[1]);
    }
  }

  return [...found];
};

const navTargets = all(navSource, [/to="([^"]*)"/g, /to:\s*'([^']*)'/g]);
const routedPaths = all(appSource, [/path="([^"]*)"/g]);

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

describe('route table and navigation agree', () => {
  it('finds the routes to compare, so the assertions below mean something', () => {
    // An empty list satisfies every "for each" below vacuously. These guards are
    // what stop the drift test from passing because a refactor changed the
    // quoting style.
    expect(navTargets.length).toBeGreaterThanOrEqual(3);
    expect(routedPaths.length).toBeGreaterThanOrEqual(5);
  });

  it('routes every destination the nav links to', () => {
    const unrouted = navTargets.filter((to) => !routedPaths.includes(to));

    expect(unrouted).toEqual([]);
  });

  it('links to every authenticated destination the app routes to', () => {
    // `/login` is deliberately absent: it renders outside the layout, because a
    // session that does not exist cannot render chrome that needs one.
    const reachable = routedPaths.filter((p) => p !== '/login' && p !== '*');
    const unlinked = reachable.filter((p) => !navTargets.includes(p));

    expect(unlinked).toEqual([]);
  });

  it('has exactly one review route, and it is operator-only', () => {
    // Counted in the raw source rather than in `routedPaths`, which is a Set and
    // would collapse two identical declarations into one and report success. Two
    // review routes would mean one of them is outside the operator guard.
    const occurrences = (source: string, pattern: RegExp): number =>
      [...source.matchAll(pattern)].length;

    expect(occurrences(appSource, /path="\/reviews"/g)).toBe(1);
    expect(occurrences(navSource, /to: '\/reviews'/g)).toBe(1);

    // The guard, not the absence of a link, is what enforces the role.
    expect(appSource).toMatch(/ReviewsPage/);
    expect(read('pages/ReviewsPage.tsx')).toContain('isOperator');
  });

  it('has exactly one account route, and it is admin-only', () => {
    // Same reasoning as the review route: one declaration, one nav entry, and a
    // page that checks the role itself rather than trusting the link's absence.
    const occurrences = (source: string, pattern: RegExp): number =>
      [...source.matchAll(pattern)].length;

    expect(occurrences(appSource, /path="\/admin"/g)).toBe(1);
    expect(occurrences(navSource, /to: '\/admin'/g)).toBe(1);

    expect(appSource).toMatch(/AdminPage/);
    expect(read('pages/AdminPage.tsx')).toContain('isAdmin');
  });

  it('sends an unknown path to the capture screen rather than a blank page', () => {
    // A client-side route that falls through to nothing renders an empty
    // document, which an agent reports as "the app is broken".
    expect(appSource).toContain('path="*"');
  });
});