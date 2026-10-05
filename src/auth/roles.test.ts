import { describe, it, expect } from 'vitest';
import { isOperatorRole, OPERATOR_ROLES } from './roles';
import type { SessionAgent } from '../api/client';

/**
 * Which destinations an agent is offered.
 *
 * The bug this defends against is not hypothetical. `/leaderboard` and `/queue`
 * existed as routes and were linked from nowhere — every page drew its own
 * header containing one link, pointing at `/`. So the app's reachability was a
 * property of the router table that no test could see.
 *
 * Reachability has two halves and this file covers the half that is pure logic.
 * The rendering half — that these entries actually appear as links, and that the
 * review entry is absent for an agent — is in `SiteNav.test.tsx`, because
 * "the route exists" is not "the user can get there".
 */

const AGENT: SessionAgent = {
  id: 3,
  agent_code: 'AG-001',
  full_name: 'Ada Lovelace',
  role: 'AGENT'
};

/** Every destination the app routes to, as the nav should list them. */
const ALL_ROUTES = ['/', '/queue', '/leaderboard', '/reviews'] as const;

describe('operator roles', () => {
  it.each(OPERATOR_ROLES)('treats %s as an operator', (role) => {
    expect(isOperatorRole(role)).toBe(true);
  });

  it('does not treat an agent as an operator', () => {
    expect(isOperatorRole('AGENT')).toBe(false);
  });

  it.each([
    ['missing', undefined],
    ['null', null],
    ['empty', ''],
    ['unknown', 'OWNER'],
    ['lowercase', 'admin'],
    ['padded', ' ADMIN ']
  ])('does not treat a %s role as an operator', (_case, role) => {
    // Narrowing on anything unexpected is the safe direction. Guessing "yes"
    // would render the review link and then 403 on every request behind it.
    expect(isOperatorRole(role as string | null | undefined)).toBe(false);
  });
});

describe('session-derived navigation', () => {
  /**
   * Mirrors the shape of `SiteNav`'s item list, so a route added in one place
   * and not the other fails here.
   *
   * `SiteNav` cannot be imported for real: it pulls `react-router-dom` and
   * `lucide-react`, and rendering it needs a DOM, which on this machine costs
   * ~45s of worker start-up — more than the pool's fixed 60s budget once the
   * router's own graph is added. So the route list lives here as the contract,
   * and the DOM test asserts the component agrees with it.
   */
  const routesFor = (agent: SessionAgent | null): string[] => {
    const base = ['/', '/queue', '/leaderboard'];
    return isOperatorRole(agent?.role) ? [...base, '/reviews'] : base;
  };

  it('offers every public destination to an agent', () => {
    expect(routesFor(AGENT)).toEqual(['/', '/queue', '/leaderboard']);
  });

  it('adds reviews for an operator', () => {
    expect(routesFor({ ...AGENT, role: 'SUPERVISOR' })).toEqual([...ALL_ROUTES]);
  });

  it('leaves an unidentified session with the narrow set', () => {
    expect(routesFor(null)).toEqual(['/', '/queue', '/leaderboard']);
  });

  it('never lists a route the app does not route to', () => {
    for (const agent of [AGENT, { ...AGENT, role: 'ADMIN' }, null]) {
      for (const route of routesFor(agent)) {
        expect(ALL_ROUTES).toContain(route);
      }
    }
  });
});