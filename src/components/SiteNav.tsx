import { NavLink } from 'react-router-dom';
import {
  Camera,
  Database,
  Trophy,
  ClipboardCheck
} from 'lucide-react';

/**
 * The primary navigation, and the only place a destination is listed.
 *
 * Every authenticated screen renders inside `Layout` rather than shipping its own
 * `<header>`. Four hand-written headers is how a screen ends up offering three of
 * the four destinations and quietly becoming unreachable — which is exactly what
 * had happened: `/leaderboard` and `/queue` each linked only to Capture, so
 * nothing in the UI led anywhere except back to the start.
 *
 * Split out from `Layout` so that asking "which links exist, for whom" does not
 * have to load the sign-out path and the request helpers behind it.
 */
export default function SiteNav({ isOperator }: { isOperator: boolean }) {
  const items = [
    { to: '/', label: 'Capture', icon: Camera, end: true },
    { to: '/queue', label: 'Queue', icon: Database, end: false },
    { to: '/leaderboard', label: 'Leaderboard', icon: Trophy, end: false }
  ];

  if (isOperator) {
    items.push({ to: '/reviews', label: 'Reviews', icon: ClipboardCheck, end: false });
  }

  return (
    <nav aria-label="Primary" data-testid="site-nav">
      <ul className="flex gap-1 overflow-x-auto">
        {items.map(({ to, label, icon: Icon, end }) => (
          <li key={to}>
            {/*
              `NavLink` sets `aria-current="page"` on the active entry, so the
              current destination is announced rather than only coloured. Colour
              alone is invisible to a screen reader and to anyone who cannot
              distinguish the two shades.
            */}
            <NavLink
              to={to}
              end={end}
              className={({ isActive }) =>
                `inline-flex items-center gap-2 px-3 py-2 rounded-t-lg text-sm font-medium whitespace-nowrap ${
                  isActive
                    ? 'bg-gray-100 text-blue-800'
                    : 'bg-blue-700 text-blue-50 hover:bg-blue-800'
                }`
              }
            >
              <Icon size={16} aria-hidden="true" />
              {label}
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}