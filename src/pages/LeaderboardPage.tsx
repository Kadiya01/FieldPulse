import { useEffect, useState } from 'react';
import { authenticatedFetch } from '../api/client';
import {
  Trophy,
  AlertCircle,
  RefreshCw,
  CheckCircle2,
  Clock,
  XCircle,
  Loader2
} from 'lucide-react';

interface LeaderboardData {
  verified: number;
  submitted: number;
  pending: number;
  rejected: number;
  updated_at: number;
}

const CACHE_KEY = 'fieldpulse_leaderboard';

/**
 * The agent's own totals, as the server counts them.
 *
 * Every figure here is a *server* number. Nothing on this screen is derived from
 * the local queue, because a total that mixed the two would move when a handset
 * synced and would look like a correction. The four tiles are the same four
 * dispositions the queue page reports per submission, aggregated.
 *
 * The numbers are cached in `localStorage` so the screen survives a dead
 * connection. That is safe because the response is aggregate counts and site
 * metadata — no token, and nothing the server would treat as a claim. It stays
 * keyed to whoever last fetched it, so a shared handset can see a stale board,
 * but a shared handset cannot spend a session: no credential is written here.
 */
export default function LeaderboardPage() {
  const [data, setData] = useState<LeaderboardData | null>(() => readCache());
  // True from the start: the first thing this screen does is fetch.
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');

  /*
   * Fetch on mount, which is a synchronisation with a remote system rather than
   * derived state — that is what an effect is for.
   *
   * Written as an inline async body rather than a `useCallback` invoked from the
   * effect because the callback shared with the Refresh button would be entered
   * synchronously, and a setState on that path is the cascading render
   * `react/set-state-in-effect` exists to prevent. `live` guards the unmount,
   * which the callback version had to do with the same flag anyway.
   */
  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const fresh = await fetchBoard();

        if (live) {
          setData(fresh);
          setErrorMsg('');
        }
      } catch {
        if (live) {
          const cached = readCache();

          if (cached) {
            setData(cached);
            setErrorMsg('Showing the last figures this device received. They may be out of date.');
          } else {
            setErrorMsg('You are offline and this device has no saved figures yet.');
          }
        }
      } finally {
        if (live) {
          setLoading(false);
        }
      }
    })();

    return () => {
      live = false;
    };
  }, []);

  /** The manual refresh: a button press, so setting state up front is correct here. */
  const refresh = async () => {
    setLoading(true);

    try {
      setData(await fetchBoard());
      setErrorMsg('');
    } catch {
      // The cached board is still the best answer available, and authenticatedFetch
      // has already raised auth_failure if the session is the problem, so the gate
      // is moving this screen to the login form on its own.
      if (readCache()) {
        setErrorMsg('Showing the last figures this device received. They may be out of date.');
      } else {
        setErrorMsg('You are offline and this device has no saved figures yet.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-1">
        <h1 className="text-xl font-bold text-gray-900 flex items-center gap-2">
          <Trophy size={20} aria-hidden="true" /> Leaderboard
        </h1>
        <button
          type="button"
          onClick={() => {
            setLoading(true);
            void refresh();
          }}
          disabled={loading}
          className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-60"
        >
          {loading
            ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
            : <RefreshCw size={14} aria-hidden="true" />}
          {loading ? 'Refreshing' : 'Refresh'}
        </button>
      </div>

      <p className="text-sm text-gray-700 mb-4">
        Counts the server has verified. A capture still waiting on upload or verification does
        not appear here.
      </p>

      <div aria-live="polite">
        {errorMsg && (
          <p className="bg-amber-100 border border-amber-400 text-amber-900 px-4 py-3 rounded mb-4 flex items-start gap-2 text-sm">
            <AlertCircle className="shrink-0 mt-0.5" size={16} aria-hidden="true" />
            <span>{errorMsg}</span>
          </p>
        )}
      </div>

      {data ? (
        <>
          <dl className="grid grid-cols-2 gap-4">
            <Tile
              label="Verified"
              value={data.verified}
              hint="Counted in totals"
              icon={<CheckCircle2 size={16} aria-hidden="true" />}
              className="border-green-700 text-green-800"
            />
            <Tile
              label="Submitted"
              value={data.submitted}
              hint="Received by the server"
              icon={<Clock size={16} aria-hidden="true" />}
              className="border-blue-700 text-blue-800"
            />
            <Tile
              label="Pending review"
              value={data.pending}
              hint="Not counted yet"
              icon={<Clock size={16} aria-hidden="true" />}
              className="border-amber-600 text-amber-800"
            />
            <Tile
              label="Rejected"
              value={data.rejected}
              hint="Not counted"
              icon={<XCircle size={16} aria-hidden="true" />}
              className="border-red-700 text-red-800"
            />
          </dl>

          <p className="text-center text-xs text-gray-600 mt-4">
            {loading
              ? 'Updating…'
              : `Server figures as at ${new Date(data.updated_at * 1000).toLocaleString()}`}
          </p>
        </>
      ) : (
        loading && (
          <p role="status" className="text-center text-gray-700 flex items-center justify-center gap-2 py-8">
            <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            Loading your figures…
          </p>
        )
      )}
    </section>
  );
}

/**
 * One figure.
 *
 * The number is the content; the border, the icon and the hint line repeat it in
 * other forms. Two tiles are never told apart by hue alone — each carries a
 * distinct icon and a plain-language hint, so the grid survives greyscale,
 * colour blindness and a phone in direct sun.
 */
function Tile({
  label,
  value,
  hint,
  icon,
  className
}: {
  label: string;
  value: number;
  hint: string;
  icon: React.ReactNode;
  className: string;
}) {
  return (
    <div className={`bg-white p-4 rounded-lg shadow border-t-4 ${className}`}>
      <dt className="text-sm text-gray-700 font-medium flex items-center gap-1">
        {icon}
        {label}
      </dt>
      <dd className="text-3xl font-bold text-gray-900">{value}</dd>
      <dd className="text-xs text-gray-600 mt-1">{hint}</dd>
    </div>
  );
}

function readCache(): LeaderboardData | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    return raw ? (JSON.parse(raw) as LeaderboardData) : null;
  } catch {
    // A corrupt or unreadable cache is not worth surfacing: the only thing it
    // can cost is a stale board, and a parse failure is not the agent's problem.
    return null;
  }
}

/**
 * One round trip, plus the write to the offline cache.
 *
 * Kept at module scope and free of any setState so that both callers — the mount
 * effect and the Refresh button — can share it without either one dragging a
 * render into the other's stack. It throws rather than returning a result object
 * so the two failure messages stay where they are worded for their own context.
 */
async function fetchBoard(): Promise<LeaderboardData> {
  const res = await authenticatedFetch('/leaderboard.php');

  if (!res.ok) {
    throw new Error('Failed to fetch leaderboard');
  }

  const json = (await res.json()) as LeaderboardData;
  localStorage.setItem(CACHE_KEY, JSON.stringify(json));

  return json;
}