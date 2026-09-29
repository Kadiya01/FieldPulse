import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { authenticatedFetch } from '../api/client';
import { Trophy, Home, AlertCircle, RefreshCw } from 'lucide-react';

interface LeaderboardData {
  verified: number;
  submitted: number;
  pending: number;
  rejected: number;
  updated_at: number;
}

export default function LeaderboardPage() {
  const [data, setData] = useState<LeaderboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');

  const fetchLeaderboard = async () => {
    setLoading(true);
    setErrorMsg('');
    try {
      const res = await authenticatedFetch('/leaderboard.php');
      if (!res.ok) throw new Error('Failed to fetch leaderboard');
      const json = await res.json();
      setData(json);
      // Cached for offline viewing. Safe in localStorage because this response
      // is aggregate counts and site metadata — no token, and nothing the
      // server would treat as a claim. It stays keyed to whoever last fetched
      // it, so a shared handset can see a stale board, but a shared handset
      // cannot spend a session: no credential is written here.
      localStorage.setItem('fieldpulse_leaderboard', JSON.stringify(json));
    } catch {
      /*
       * Fall back to the cache. This deliberately swallows the reason: a 401,
       * a 500 and a dropped connection all resolve to the same stale board, and
       * telling the agent "showing cached data" is true in every case. The auth
       * failure is not hidden, though — authenticatedFetch has already raised
       * auth_failure and the gate has moved the user to the login screen.
       */
      const cached = localStorage.getItem('fieldpulse_leaderboard');
      if (cached) {
        setData(JSON.parse(cached));
        setErrorMsg('Showing offline cached leaderboard.');
      } else {
        setErrorMsg('You are offline and no cached data is available.');
      }
    } finally {
      setLoading(false);
    }
  };

  // Fetches on mount, which is a synchronisation with a remote system rather
  // than derived state, so this is what an effect is for.
  useEffect(() => {
    void fetchLeaderboard();
  }, []);

  return (
    <div className="min-h-screen bg-gray-100 flex flex-col">
      <header className="bg-blue-600 text-white p-4 flex justify-between items-center shadow-md">
        <h1 className="text-xl font-bold flex items-center gap-2">
          <Trophy size={20} /> Leaderboard
        </h1>
        <Link to="/" className="flex items-center gap-2 bg-blue-700 px-3 py-1 rounded">
          <Home size={18} /> Home
        </Link>
      </header>

      <main className="flex-1 p-4 max-w-lg mx-auto w-full">
        <div className="flex justify-between items-center mb-6">
          <h2 className="font-semibold text-gray-700">Your Performance</h2>
          <button 
            onClick={fetchLeaderboard}
            disabled={loading}
            className="text-blue-600 text-sm font-medium flex items-center gap-1 bg-blue-50 px-2 py-1 rounded border border-blue-200 disabled:opacity-50"
          >
            <RefreshCw size={14} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
        </div>

        {errorMsg && (
          <div className="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4 flex items-start gap-2 text-sm">
            <AlertCircle className="shrink-0 mt-0.5" size={16} />
            <span>{errorMsg}</span>
          </div>
        )}

        {data ? (
          <div className="grid grid-cols-2 gap-4">
            <div className="bg-white p-4 rounded-lg shadow border-t-4 border-green-500">
              <p className="text-sm text-gray-500 font-medium">Verified</p>
              <p className="text-3xl font-bold text-gray-800">{data.verified}</p>
            </div>
            <div className="bg-white p-4 rounded-lg shadow border-t-4 border-blue-500">
              <p className="text-sm text-gray-500 font-medium">Submitted</p>
              <p className="text-3xl font-bold text-gray-800">{data.submitted}</p>
            </div>
            <div className="bg-white p-4 rounded-lg shadow border-t-4 border-orange-500">
              <p className="text-sm text-gray-500 font-medium">Pending Review</p>
              <p className="text-3xl font-bold text-gray-800">{data.pending}</p>
            </div>
            <div className="bg-white p-4 rounded-lg shadow border-t-4 border-red-500">
              <p className="text-sm text-gray-500 font-medium">Rejected</p>
              <p className="text-3xl font-bold text-gray-800">{data.rejected}</p>
            </div>
            <div className="col-span-2 text-center text-xs text-gray-400 mt-2">
              Last updated: {new Date(data.updated_at * 1000).toLocaleString()}
            </div>
          </div>
        ) : (
          !loading && <div className="text-center text-gray-500">No data available.</div>
        )}
      </main>
    </div>
  );
}
