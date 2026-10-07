import { useEffect, useState } from 'react';
import { Link, Outlet } from 'react-router-dom';
import {
  LogOut,
  WifiOff,
  SignalHigh
} from 'lucide-react';
import SiteNav from './SiteNav';
import { useSession } from '../auth/sessionContext';
import { handleAuthFailure } from '../auth/session';
import { logout } from '../api/client';

/**
 * Where the app's chrome lives, and the only place a page title is set.
 *
 * Every authenticated screen renders inside this outlet rather than shipping its
 * own `<header>`. Four hand-written headers is how a screen ends up offering
 * three of the four destinations and quietly becoming unreachable — which is
 * exactly what had happened: `/leaderboard` and `/queue` each linked only to
 * Capture, so nothing in the UI led anywhere except back to the start.
 */
export default function Layout() {
  const { isOperator, label } = useSession();
  const online = useOnlineStatus();

  return (
    <div className="min-h-screen bg-gray-100 flex flex-col">
      <a href="#main" className="skip-link">Skip to main content</a>

      <header className="bg-blue-600 text-white shadow-md">
        <div className="max-w-3xl mx-auto px-4 pt-3 pb-2 flex items-center gap-3">
          <Link to="/" className="flex items-center gap-2 font-bold text-lg rounded">
            <PulseMark />
            <span>FieldPulse</span>
          </Link>

          <div className="ml-auto flex items-center gap-2">
            {/* Status is text plus an icon, never colour alone. */}
            <span
              className={`inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full border ${
                online
                  ? 'border-blue-300 text-blue-50'
                  : 'border-amber-300 text-amber-50 bg-amber-600'
              }`}
            >
              {online ? <SignalHigh size={12} aria-hidden="true" /> : <WifiOff size={12} aria-hidden="true" />}
              {online ? 'Online' : 'Offline'}
            </span>

            {label && (
              <span className="hidden sm:inline text-xs text-blue-50 truncate max-w-[16rem]">
                {label}
              </span>
            )}

            <SignOutButton />
          </div>
        </div>

        <div className="max-w-3xl mx-auto px-2 pb-1">
          <SiteNav isOperator={isOperator} />
        </div>
      </header>

      <main id="main" className="main-focus-target flex-1 w-full max-w-3xl mx-auto p-4" tabIndex={-1}>
        <Outlet />
      </main>

      <footer className="max-w-3xl mx-auto px-4 pb-6 pt-2">
        <p className="text-xs text-gray-600">
          Upload status is not verification. A report is only verified once the server has
          checked it — see the server state on each queued submission. Only verified work
          counts toward weekly standing, and a reward is decided from the standing frozen
          when the period closed — never from this screen.
        </p>
      </footer>
    </div>
  );
}

/**
 * Sign out, then leave the shell.
 *
 * The redirect matters: after logout there is no access token in memory, so
 * staying on `/queue` would render a screen whose every request 401s until the
 * gate notices. Handing the failure to the session gate — the same path an
 * expired session takes — drops the in-memory token and lands on `/login`
 * without a page reload, so the IndexedDB key and the local queue survive and
 * the agent can sign back in and sync.
 */
function SignOutButton() {
  const [busy, setBusy] = useState(false);

  return (
    <button
      type="button"
      disabled={busy}
      onClick={async () => {
        setBusy(true);
        try {
          await logout();
        } catch {
          // The request may fail because the network is already gone, in which
          // case the cookie is unreachable anyway and will be cleared by the
          // server on its next expiry. Either way the local token has to go, so
          // this reports success and lets the gate take over.
        } finally {
          handleAuthFailure('FAILED_AUTH');
        }
      }}
      className="inline-flex items-center gap-1 text-xs px-2 py-1 rounded border border-blue-300 text-blue-50 hover:bg-blue-700 disabled:opacity-60"
    >
      <LogOut size={12} aria-hidden="true" />
      Sign out
    </button>
  );
}

/**
 * The wordmark glyph: a field pulse.
 *
 * The same shape as the favicon and the PWA icons, drawn inline so the header
 * needs no extra request and cannot render a broken image. `aria-hidden` because
 * the adjacent text already spells the name out — an icon with an accessible
 * name of "FieldPulse" immediately before another "FieldPulse" is read twice.
 */
function PulseMark({ size = 22 }: { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
      className="shrink-0"
    >
      <rect width="24" height="24" rx="6" fill="#ffffff" fillOpacity="0.18" />
      <path
        d="M4 12.5h3.2l1.6-3.6 2.6 7.2 1.8-4.4 1.1 2.2H20"
        stroke="#ffffff"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/**
 * Whether the browser believes it has a network.
 *
 * `navigator.onLine` only says there is a link, not that the server is
 * reachable — which is why this is advisory and paired with per-screen offline
 * handling rather than used to decide what to submit. It does mean the offline
 * capture path is at least visible before the agent is deep in a field with no
 * signal, instead of being discovered at the first failed upload.
 */
function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(() =>
    typeof navigator === 'undefined' ? true : navigator.onLine
  );

  useEffect(() => {
    const goOnline = () => setOnline(true);
    const goOffline = () => setOnline(false);

    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);

    return () => {
      window.removeEventListener('online', goOnline);
      window.removeEventListener('offline', goOffline);
    };
  }, []);

  return online;
}