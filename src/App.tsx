import { useEffect, useState } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import CapturePage from './pages/CapturePage';
import QueuePage from './pages/QueuePage';
import LoginPage from './pages/LoginPage';
import LeaderboardPage from './pages/LeaderboardPage';
import ReviewsPage from './pages/ReviewsPage';
import RewardsPage from './pages/RewardsPage';
import Layout from './components/Layout';
import { restoreSession, type RestoreResult, type SessionAgent } from './api/client';
import { SessionContext, useSessionValue } from './auth/sessionContext';

type Gate = 'checking' | 'ready' | 'login' | 'unregistered';

/**
 * The session gate every route sits behind.
 *
 * Kept out of `main.tsx` so that file only mounts the tree, and so that
 * Fast Refresh has a module whose exports are components — an HMR boundary
 * around a router that is re-evaluating session state is a confusing thing to
 * watch during development.
 *
 * The agent is held here as well as the gate, because it only arrives from the
 * same request that decides the gate: `restoreSession()` is already asking the
 * server who this is, and having the navigation re-ask would be a second round
 * trip for an answer already in hand.
 */
export default function App() {
  const [gate, setGate] = useState<Gate>('checking');
  const [agent, setAgent] = useState<SessionAgent | null>(null);
  const session = useSessionValue(agent);

  useEffect(() => {
    let live = true;

    // The access token is memory-only, so a reload always starts with none and
    // has to ask the server who it is using the refresh cookie. Rendering the
    // capture screen optimistically would let the user take a photo into a
    // client that has no token, and every upload would then 401 and trigger a
    // refresh that cannot succeed.
    restoreSession()
      .then((result: RestoreResult) => {
        if (!live) {
          return;
        }
        setAgent(result.status === 'authenticated' ? result.agent : null);
        setGate(
          result.status === 'authenticated' ? 'ready'
            : result.status === 'unregistered' ? 'unregistered'
            : 'login'
        );
      })
      .catch(() => {
        if (live) {
          setGate('login');
        }
      });

    // Also react to failures raised later, e.g. a refresh that the server
    // rejects. Without this the app stays on the capture screen failing every
    // request until a manual reload.
    const onFailure = (event: Event) => {
      const reason = (event as CustomEvent<{ reason: string }>).detail?.reason;
      setAgent(null);
      setGate(reason === 'UNREGISTERED' ? 'unregistered' : 'login');
    };

    window.addEventListener('auth_failure', onFailure);
    return () => {
      live = false;
      window.removeEventListener('auth_failure', onFailure);
    };
  }, []);

  if (gate === 'checking') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center">
        {/* Announced, not just drawn: this is the first thing a screen reader
            meets, and a silent pause reads as a blank page. */}
        <p role="status" className="text-gray-700 text-sm">Restoring session…</p>
      </div>
    );
  }

  if (gate === 'unregistered') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm text-center">
          <h1 className="text-xl font-bold text-gray-900 mb-2">Device key missing</h1>
          <p className="text-sm text-gray-700 mb-6">
            This browser&apos;s device key is no longer available, so it cannot prove
            which device it is. Sign in again to generate a new one.
          </p>
          <p className="text-sm text-gray-700 mb-6">
            Any captures already queued are still on this device and will upload once a
            device is bound again.
          </p>
          <button
            type="button"
            onClick={() => { window.location.href = '/login'; }}
            className="w-full bg-blue-700 text-white p-3 rounded-md font-medium hover:bg-blue-800"
          >
            Sign in again
          </button>
        </div>
      </div>
    );
  }

  const requireReady = (element: React.ReactNode) =>
    gate === 'ready' ? element : <Navigate to="/login" replace />;

  return (
    <SessionContext.Provider value={session}>
      <Routes>
        {/*
          One layout for every authenticated screen, so navigation exists in one
          place. Previously each page rendered its own header linking only to
          Capture, which left `/queue` and `/leaderboard` reachable only by typing
          the URL.
        */}
        <Route element={requireReady(<Layout />)}>
          <Route path="/" element={<CapturePage />} />
          <Route path="/queue" element={<QueuePage />} />
          <Route path="/leaderboard" element={<LeaderboardPage />} />
          {/*
            Rewards is mounted for every authenticated session. /self is
            `bearer`, so an agent sees only their own published entitlements, and
            the operator half of the page is gated by the role the page reads —
            with the server checking the same thing on every call.
          */}
          <Route path="/rewards" element={<RewardsPage />} />
          {/*
            The route is mounted for every authenticated session and the page
            checks the role itself, rather than the route being conditionally
            declared from `agent`. Both work, but only one of them still behaves
            correctly when the agent changes underneath a mounted route — and a
            role revoked server-side should not leave a stale screen.
          */}
          <Route path="/reviews" element={<ReviewsPage />} />
        </Route>

        <Route
          path="/login"
          element={
            gate === 'ready' ? (
              <Navigate to="/" replace />
            ) : (
              /*
               * On success the page hands the agent back rather than leaving the
               * gate to discover it on the next `restoreSession()`.
               *
               * It used to navigate to `/` and stop there, which could not work:
               * the gate still said `login`, so `/` redirected straight back, and
               * the agent was left looking at a "device registered" card with no
               * way forward and no way to reach the app without a manual reload.
               */
              <LoginPage
                onAuthenticated={(authenticated) => {
                  setAgent(authenticated);
                  setGate('ready');
                }}
              />
            )
          }
        />

        {/* Without a catch-all an unknown URL renders an empty shell, since
            Routes has nothing to match. Send it back to the capture screen
            rather than leaving the user on a blank page. */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </SessionContext.Provider>
  );
}