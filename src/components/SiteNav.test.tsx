// @vitest-environment happy-dom

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route, Outlet } from 'react-router-dom';
import SiteNav from './SiteNav';
import { isOperatorRole, isAdminRole } from '../auth/roles';

/**
 * The navigation as it is drawn, not as the router would match it.
 *
 * A destination no one can click to is unreachable in the sense that matters,
 * and a route table can look complete while every page links only to `/`. So
 * these assert on links, and on `aria-current` — the thing that tells a screen
 * reader where it is, which colour alone does not.
 *
 * Scoped to `SiteNav` deliberately. `SiteNav` depends on `react-router-dom` and
 * `lucide-react` but not on the request helpers, Dexie or the crypto module, and
 * keeping it that way is what lets this file render inside the pool's 60s worker
 * start-up budget on a machine where a DOM environment alone costs ~45s. See the
 * route-list contract in `auth/roles.test.ts` for the pure half of this.
 */

/**
 * `SiteNav` renders the nav alone; `Layout` renders it beside an `<Outlet>`.
 * Wrapping it the same way here keeps the test honest about what clicking a link
 * does — the route changes and the placeholder screen is what comes up — instead
 * of asserting on an `href` that no router ever had to resolve.
 */
function Chrome({ isOperator, isAdmin }: { isOperator: boolean; isAdmin: boolean }) {
  return (
    <>
      <SiteNav isOperator={isOperator} isAdmin={isAdmin} />
      <Outlet />
    </>
  );
}

function renderNav(flags: { isOperator: boolean; isAdmin: boolean }, initialPath = '/') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route element={<Chrome isOperator={flags.isOperator} isAdmin={flags.isAdmin} />}>
          <Route path="/" element={<p>Capture screen</p>} />
          <Route path="/queue" element={<p>Queue screen</p>} />
          <Route path="/leaderboard" element={<p>Leaderboard screen</p>} />
          <Route path="/rewards" element={<p>Rewards screen</p>} />
          <Route path="/reviews" element={<p>Reviews screen</p>} />
          <Route path="/admin" element={<p>Admin screen</p>} />
        </Route>
      </Routes>
    </MemoryRouter>
  );
}

const neither = { isOperator: false, isAdmin: false } as const;
const admin = { isOperator: true, isAdmin: true } as const;

describe('primary navigation', () => {
  it('names itself, so a screen reader can skip past it', () => {
    renderNav(neither);

    // Without a name this is the first `navigation` landmark on the page and gets
    // announced as such; with one it is distinguishable from the region landmarks.
    expect(screen.getByRole('navigation', { name: 'Primary' })).toBeInTheDocument();
  });

  it.each([
    ['Queue', /queue/i, 'Queue screen'],
    ['Leaderboard', /leaderboard/i, 'Leaderboard screen'],
    ['Rewards', /rewards/i, 'Rewards screen'],
    ['Reviews', /reviews/i, 'Reviews screen'],
    ['Admin', /admin/i, 'Admin screen']
  ])('navigates to %s', async (_name, linkName, expected) => {
    renderNav(admin);

    await userEvent.click(screen.getByRole('link', { name: linkName }));

    expect(await screen.findByText(expected)).toBeInTheDocument();
  });

  it('navigates back to Capture from another screen', async () => {
    renderNav(neither, '/queue');

    await userEvent.click(screen.getByRole('link', { name: /capture/i }));

    expect(await screen.findByText('Capture screen')).toBeInTheDocument();
  });

  it('marks the current destination for assistive technology', () => {
    renderNav(neither, '/leaderboard');

    const current = screen.getByRole('link', { current: 'page' });
    expect(current).toHaveTextContent(/leaderboard/i);
  });

  it('does not mark a destination merely because it shares a prefix', () => {
    // `/` is a prefix of every path. Without `end`, Capture would report itself
    // active on every screen and the tab bar would lie about where the user is.
    renderNav(neither, '/queue');

    expect(screen.getByRole('link', { name: /capture/i })).not.toHaveAttribute('aria-current');
    expect(screen.getByRole('link', { current: 'page' })).toHaveTextContent(/queue/i);
  });
});

describe('review queue link', () => {
  // Driven through `isOperatorRole` rather than a literal `true`, so these fail
  // if the role names and the nav's condition ever drift apart.
  it('is offered to a supervisor', () => {
    renderNav({ isOperator: isOperatorRole('SUPERVISOR'), isAdmin: isAdminRole('SUPERVISOR') });

    expect(screen.getByRole('link', { name: /reviews/i })).toBeInTheDocument();
  });

  it('is offered to an administrator', () => {
    renderNav({ isOperator: isOperatorRole('ADMIN'), isAdmin: isAdminRole('ADMIN') });

    expect(screen.getByRole('link', { name: /reviews/i })).toBeInTheDocument();
  });

  it('is not offered to an agent', () => {
    // Offering it to someone who will receive 403 teaches them the button is
    // broken. The check is presentation; `Kernel::assertOperator` is enforcement.
    renderNav({ isOperator: isOperatorRole('AGENT'), isAdmin: isAdminRole('AGENT') });

    expect(screen.queryByRole('link', { name: /reviews/i })).not.toBeInTheDocument();
  });

  it('is not offered when the session identity is unknown', () => {
    renderNav({ isOperator: isOperatorRole(undefined), isAdmin: isAdminRole(undefined) });

    expect(screen.queryByRole('link', { name: /reviews/i })).not.toBeInTheDocument();
  });
});

describe('account directory link', () => {
  it('is offered to an administrator', () => {
    renderNav({ isOperator: isOperatorRole('ADMIN'), isAdmin: isAdminRole('ADMIN') });

    expect(screen.getByRole('link', { name: /admin/i })).toBeInTheDocument();
  });

  it('is not offered to a supervisor, who is an operator but not an admin', () => {
    // The narrower gate is the point: a supervisor can review submissions but
    // cannot manage accounts, and a link that 403s is worse than no link.
    renderNav({ isOperator: isOperatorRole('SUPERVISOR'), isAdmin: isAdminRole('SUPERVISOR') });

    expect(screen.queryByRole('link', { name: /admin/i })).not.toBeInTheDocument();
  });

  it('is not offered to an agent', () => {
    renderNav({ isOperator: isOperatorRole('AGENT'), isAdmin: isAdminRole('AGENT') });

    expect(screen.queryByRole('link', { name: /admin/i })).not.toBeInTheDocument();
  });

  it('is not offered when the session identity is unknown', () => {
    renderNav({ isOperator: isOperatorRole(undefined), isAdmin: isAdminRole(undefined) });

    expect(screen.queryByRole('link', { name: /admin/i })).not.toBeInTheDocument();
  });
});

describe('decoration', () => {
  it('hides every icon from assistive technology', () => {
    renderNav(neither);

    // Each entry is icon plus word. An unlabelled icon is announced as an empty
    // image, so the accessible name must come from the text alone.
    const link = screen.getByRole('link', { name: /capture/i });
    expect(link.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
    expect(link).toHaveAccessibleName('Capture');
  });
});