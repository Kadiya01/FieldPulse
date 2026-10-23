import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const navSource = readFileSync(resolve(here, 'SiteNav.tsx'), 'utf8');

describe('site navigation contract', () => {
  it('includes the public destinations and uses the same route names the app exposes', () => {
    expect(navSource).toContain("to: '/'");
    expect(navSource).toContain("to: '/queue'");
    expect(navSource).toContain("to: '/leaderboard'");
    expect(navSource).toContain("to: '/rewards'");
    expect(navSource).toContain("to: '/reviews'");
    expect(navSource).toContain("to: '/admin'");
  });

  it('keeps reviews and admin behind role gates in the component source', () => {
    expect(navSource).toContain('if (isOperator)');
    expect(navSource).toContain('if (isAdmin)');
    expect(navSource).toContain("items.push({ to: '/reviews'");
    expect(navSource).toContain("items.push({ to: '/admin'");
  });

  it('exposes a named navigation landmark and uses NavLink for each entry', () => {
    expect(navSource).toContain('<nav aria-label="Primary"');
    expect(navSource).toContain('<NavLink');
    expect(navSource).toContain('aria-hidden="true"');
    expect(navSource).toContain('className={({ isActive }) =>');
  });
});