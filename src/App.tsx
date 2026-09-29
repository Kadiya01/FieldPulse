import { useEffect, useState } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import CapturePage from './pages/CapturePage';
import QueuePage from './pages/QueuePage';
import LoginPage from './pages/LoginPage';
import LeaderboardPage from './pages/LeaderboardPage';
import { restoreSession, type RestoreResult } from './api/client';

type Gate = 'checking' | 'ready' | 'login' | 'unregistered';

/**
 * The session gate every route sits behind.
 *
 * Kept out of `main.tsx` so that file only mounts the tree, and so that
 * Fast Refresh has a module whose exports are components — an HMR boundary
 * around a router that is re-evaluating session state is a confusing thing to
 * watch during development.
 */
export default function App() {
  const [gate, setGate] = useState<Gate>('checking');

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
        <p className="text-gray-500 text-sm">Restoring session...</p>
      </div>
    );
  }

  if (gate === 'unregistered') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm text-center">
          <h1 className="text-xl font-bold text-gray-800 mb-2">Device key missing</h1>
          <p className="text-sm text-gray-600 mb-6">
            This browser&apos;s device key is no longer available, so it cannot prove
            which device it is. Sign in again to generate a new one.
          </p>
          <button
            type="button"
            onClick={() => { window.location.href = '/login'; }}
            className="w-full bg-blue-600 text-white p-3 rounded-md font-medium hover:bg-blue-700"
          >
            Sign in again
          </button>
        </div>
      </div>
    );
  }

  return (
    <Routes>
      <Route path="/" element={gate === 'ready' ? <CapturePage /> : <Navigate to="/login" replace />} />
      <Route path="/queue" element={gate === 'ready' ? <QueuePage /> : <Navigate to="/login" replace />} />
      <Route path="/leaderboard" element={gate === 'ready' ? <LeaderboardPage /> : <Navigate to="/login" replace />} />
      <Route path="/login" element={gate === 'ready' ? <Navigate to="/" replace /> : <LoginPage />} />
      {/* Without a catch-all an unknown URL renders an empty shell, since
          Routes has nothing to match. Send it back to the capture screen
          rather than leaving the user on a blank page. */}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
