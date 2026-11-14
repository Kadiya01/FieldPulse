import { useEffect, useState } from 'react';
import { Routes, Route, Navigate, useLocation } from 'react-router-dom';
import CapturePage from './pages/CapturePage';
import QueuePage from './pages/QueuePage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import LeaderboardPage from './pages/LeaderboardPage';
import ReviewsPage from './pages/ReviewsPage';
import RewardsPage from './pages/RewardsPage';
import AdminPortalPage from './pages/AdminPortalPage';
import DashboardPage from './pages/DashboardPage';
import ProfilePage from './pages/ProfilePage';
import Layout from './components/Layout';
import BrandHeader from './components/BrandHeader';
import { restoreSession, logout, type RestoreResult, type SessionAgent } from './api/client';
import { triggerSync } from './sync/coordinator';
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
  const location = useLocation();
  const isAdminPath = location.pathname === '/admin' || location.pathname.startsWith('/admin/');
  const [gate, setGate] = useState<Gate>('checking');
  const [agent, setAgent] = useState<SessionAgent | null>(null);
  const session = useSessionValue(agent);

  useEffect(() => {
    if (isAdminPath) {
      return;
    }
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

        if (result.status === 'offline') {
          // The session could not be checked because the server could not be
          // reached, which says nothing about whether the agent is signed in.
          // The app opens with no identity and no token — nothing is granted,
          // and the first request once the network returns still has to
          // authenticate — but the captures already queued on this handset stay
          // visible, which is the whole point of a queue that is supposed to
          // survive going offline.
          setGate('ready');

          // A no-op while the network is down, and the thing that starts the
          // queue moving again once it is not.
          void triggerSync();

          return;
        }

        setGate(
          result.status === 'authenticated' ? 'ready'
            : result.status === 'unregistered' ? 'unregistered'
            : 'login'
        );

        // A capture taken before the last reload is still on this handset
        // waiting for the connection it could not get. Opening the app is the
        // signal that it is worth trying again, and waiting for the agent to
        // notice a queue that is supposed to empty itself is not a design.
        if (result.status === 'authenticated') {
          void triggerSync();
        }
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
  }, [isAdminPath]);

  if (isAdminPath) {
    return <AdminPortalPage />;
  }

  if (gate === 'checking') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center">
        <div className="flex flex-col items-center gap-2">
          <BrandHeader className="flex justify-center" />
          <p role="status" className="text-gray-700 text-sm">Restoring session…</p>
        </div>
      </div>
    );
  }

  if (gate === 'unregistered') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm text-center">
          <BrandHeader className="mb-4 flex justify-center" />
          <h1 className="text-xl font-bold text-gray-900 mb-2">Device key missing</h1>
          <p className="text-sm text-gray-700 mb-6">
            The private security key created during registration is no longer available in this browser.
            For security, the session has been ended. An administrator must revoke the lost device
            and authorize a replacement before you can sign in again.
          </p>
          <p className="text-sm text-gray-700 mb-6">
            Use the original browser profile if the key is still available there. Do not register a duplicate
            account; contact your administrator for device replacement.
          </p>
          <button
            type="button"
            onClick={() => { window.location.href = '/login'; }}
            className="w-full bg-blue-700 text-white p-3 rounded-md font-medium hover:bg-blue-800"
          >
            Return to sign in
          </button>
        </div>
      </div>
    );
  }

  // ABH authentication is now independent, but the legacy FieldPulse capture,
  // queue, reward and admin pages still call legacy endpoints. Keep those screens
  // unreachable until their ABH-specific APIs are implemented; an ABH token must
  // never be sent to a legacy account or enrollment endpoint.
  if (gate === 'ready') {
    const endSession = async () => {
      try {
        await logout();
      } catch {
        // The local token is cleared by logout() even when the server is offline.
      } finally {
        setAgent(null);
        setGate('login');
        window.history.replaceState(null, '', '/login');
      }
    };

    return (
      <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <section className="w-full max-w-lg rounded-3xl bg-white p-8 text-center shadow-2xl sm:p-10">
          <BrandHeader className="mb-5 flex justify-center" />
          {agent ? (
            <>
              <h1 className="text-2xl font-bold text-slate-900">ABH account signed in</h1>
              <p className="mt-3 text-sm text-slate-600">
                Welcome, {agent.full_name}. Your account and approved browser device have been authenticated.
              </p>
              <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Agent reference</p>
                <p className="mt-1 text-xl font-bold text-slate-900">{agent.agent_code}</p>
              </div>
              <p className="mt-5 text-sm leading-6 text-slate-600">
                The ABH enrollment dashboard and monthly submission pages are the next development stage.
                Legacy FieldPulse capture and reporting screens are disabled for ABH accounts.
              </p>
            </>
          ) : (
            <>
              <h1 className="text-2xl font-bold text-slate-900">Connection unavailable</h1>
              <p className="mt-3 text-sm leading-6 text-slate-600">
                The system could not verify an ABH session. No enrollment access has been granted.
                Reconnect and sign in again.
              </p>
            </>
          )}
          <button
            type="button"
            onClick={endSession}
            className="mt-7 w-full rounded-xl bg-slate-900 px-4 py-3 font-semibold text-white hover:bg-slate-800"
          >
            {agent ? 'Sign out' : 'Return to sign in'}
          </button>
        </section>
      </main>
    );
  }

  const isReady = (): boolean => (gate as Gate) === 'ready';
  const requireReady = (element: React.ReactNode) =>
    isReady() ? element : <Navigate to="/login" replace />;

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
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/profile" element={<ProfilePage />} />
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
          {/*
            The account directory is mounted for every authenticated session and
            the page checks `isAdmin` itself, exactly like Reviews. An
            administrator demoted server-side since this tab loaded must land on
            a "no longer admin" panel, not on stale controls.
          */}
        </Route>


        <Route
          path="/login"
          element={
            isReady() ? (
              <Navigate to="/" replace />
            ) : (
              <LoginPage
                onAuthenticated={(authenticated) => {
                  setAgent(authenticated);
                  setGate('ready');
                }}
              />
            )
          }
        />

        <Route
          path="/register"
          element={
            isReady() ? (
              <Navigate to="/" replace />
            ) : (
              /*
               * Public self-registration remains pending until an admin
               * approves the new agent in account management.
               */
              <RegisterPage />
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