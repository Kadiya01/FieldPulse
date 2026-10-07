import { useEffect, useState } from 'react';
import { authenticatedFetch } from '../api/client';
import {
  Trophy,
  AlertCircle,
  RefreshCw,
  Loader2
} from 'lucide-react';

/**
 * The weekly board, as the server ranks it.
 *
 * Every figure here is a *server* number. Nothing on this screen is derived from
 * the local queue, because a total that mixed the two would move when a handset
 * synced and would look like a correction. The board is served from
 * `agent_performance_summary`, and the "you" block is the same standing the
 * reward freeze reads — which is why the screen can say, honestly, that this
 * ranking is live while a reward is decided from the copy frozen at close.
 *
 * The response is cached in `localStorage` so the screen survives a dead
 * connection. That is safe because it is aggregate counts and masked names — no
 * token, and nothing the server would treat as a claim. It is keyed to whoever
 * last fetched it, so a shared handset can see a stale board, but it cannot spend
 * a session: no credential is written here.
 */

interface BoardEntry {
  rank: number;
  agent_id: number;
  agent_code: string;
  display_name: string;
  total_verified_count: number;
  total_submissions: number;
  total_pending: number;
  total_rejected: number;
}

interface BoardSelf {
  rank: number | null;
  agent_id: number;
  agent_code: string;
  display_name: string;
  total_verified_count: number;
  total_pending: number;
}

interface Board {
  period_start_date: string;
  scope: 'GLOBAL' | 'SITE';
  entries: BoardEntry[];
  pagination: { total: number; limit: number; offset: number };
  you: BoardSelf | null;
  available_periods: string[];
}

/*
 * Bumped from the previous key. The endpoint used to be read as a flat
 * {verified, pending, …} object and the screen drew four tiles from keys the
 * server has never returned; a cache written by that version would render as
 * undefined here, and a cache key is cheap.
 */
const CACHE_KEY = 'fieldpulse_leaderboard_v2';

/**
 * The agent's weekly standing, and the board it sits on.
 *
 * Every figure here is a *server* number. Nothing on this screen is derived from
 * the local queue, because a total that mixed the two would move when a handset
 * synced and would look like a correction.
 *
 * The numbers are cached in `localStorage` so the screen survives a dead
 * connection. That is safe because the response is a published ranking and site
 * metadata — no token, and nothing the server would treat as a claim. It stays
 * keyed to whoever last fetched it, so a shared handset can see a stale board,
 * but a shared handset cannot spend a session: no credential is written here.
 */
export default function LeaderboardPage() {
  const [board, setBoard] = useState<Board | null>(() => readCache());
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');

  /*
   * Fetch on mount. This is a synchronisation with a remote system rather than
   * derived state — that is what an effect is for.
   *
   * An inline async body, with everything that touches state behind an await and
   * `live` guarding the unmount, so no setState is entered synchronously from
   * the effect. Changing week goes through `refreshFor` rather than state the
   * effect watches, so a fetch never triggers another fetch.
   */
  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const fresh = await fetchBoard();

        if (live) {
          setBoard(fresh);
          setErrorMsg('');
        }
      } catch {
        if (live) {
          const cached = readCache();

          if (cached) {
            setBoard(cached);
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

  /** A button press, so setting state up front is correct here. */
  const refresh = async () => {
    setLoading(true);

    try {
      const fresh = await fetchBoard(board?.period_start_date);
      setBoard(fresh);
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

  /**
   * Switching period. Kept separate from `refresh` because it must fetch the
   * week the selector names, not re-request the one currently on screen.
   */
  const refreshFor = async (nextPeriod: string) => {
    try {
      const fresh = await fetchBoard(nextPeriod);
      setBoard(fresh);
      setErrorMsg('');
    } catch {
      setErrorMsg('That week could not be loaded. Showing the last figures this device received.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-1">
        <h1 className="text-xl font-bold text-gray-900 flex items-center gap-2">
          <Trophy size={20} aria-hidden="true" /> Weekly standing
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
        Verified counts only. This board is live; the standing a reward is decided
        from is the one frozen when the period closed.
      </p>

      {errorMsg && (
        <p role="alert" className="bg-amber-100 border border-amber-400 text-amber-900 px-4 py-3 rounded mb-4 flex items-start gap-2 text-sm">
          <AlertCircle className="shrink-0 mt-0.5" size={16} aria-hidden="true" />
          <span>{errorMsg}</span>
        </p>
      )}

      {board ? (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h2 className="text-lg font-semibold text-gray-900">
              {board.scope === 'GLOBAL' ? 'Global board' : 'Site board'} · week of{' '}
              {board.period_start_date}
            </h2>
            {board.available_periods.length > 1 && (
              <div>
                <label htmlFor="period" className="sr-only">Period</label>
                <select
                  id="period"
                  value={board.period_start_date}
                  onChange={e => {
                    setLoading(true);
                    void refreshFor(e.target.value);
                  }}
                  className="text-sm border border-gray-400 rounded-md p-2 bg-white"
                >
                  {board.available_periods.map(p => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </select>
              </div>
            )}
          </div>

          {board.you && (
            <div className="bg-white rounded-lg shadow border-l-4 border-blue-700 p-4 mb-4">
              <h3 className="text-sm font-medium text-gray-800 mb-1">Your standing</h3>
              <p className="text-3xl font-bold text-gray-900">
                {board.you.rank === null ? '—' : `#${board.you.rank}`}
              </p>
              <p className="text-sm text-gray-700">
                {board.you.total_verified_count} verified
                {board.you.total_pending > 0 && ` · ${board.you.total_pending} pending`}
                <span className="text-gray-600"> · {board.you.agent_code}</span>
              </p>
            </div>
          )}

          {board.entries.length === 0 ? (
            <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center">
              No verified captures were recorded for this week.
            </p>
          ) : (
            <div className="bg-white rounded-lg shadow overflow-x-auto">
              <table className="w-full text-sm">
                <caption className="sr-only">
                  Weekly standing, ranked by verified count
                </caption>
                <thead>
                  <tr className="text-left text-gray-700 border-b border-gray-300">
                    <th scope="col" className="px-4 py-2 font-medium">Rank</th>
                    <th scope="col" className="px-4 py-2 font-medium">Agent</th>
                    <th scope="col" className="px-4 py-2 font-medium text-right">Verified</th>
                    <th scope="col" className="px-4 py-2 font-medium text-right">Pending</th>
                  </tr>
                </thead>
                <tbody>
                  {board.entries.map(entry => {
                    const isYou = board.you?.agent_id === entry.agent_id;
                    return (
                      <tr
                        key={entry.agent_id}
                        className={`border-b border-gray-100 ${isYou ? 'bg-blue-50' : ''}`}
                      >
                        <td className="px-4 py-2 font-mono">{entry.rank}</td>
                        <td className="px-4 py-2">
                          <span className="font-medium text-gray-900">{entry.agent_code}</span>
                          <span className="text-gray-600"> · {entry.display_name}</span>
                          {isYou && <span className="text-blue-800 font-medium"> · you</span>}
                        </td>
                        <td className="px-4 py-2 text-right font-semibold">{entry.total_verified_count}</td>
                        <td className="px-4 py-2 text-right text-gray-600">{entry.total_pending}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : (
        loading && (
          <p role="status" className="text-center text-gray-700 flex items-center justify-center gap-2 py-8">
            <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            Loading the board…
          </p>
        )
      )}
    </section>
  );
}

function readCache(): Board | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY);
    return raw ? (JSON.parse(raw) as Board) : null;
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
 * effect and the Refresh button — share it without either one dragging a render
 * into the other's stack.
 */
async function fetchBoard(period?: string): Promise<Board> {
  const query = period ? `?period=${encodeURIComponent(period)}` : '';
  const res = await authenticatedFetch(`/leaderboard.php${query}`);

  if (!res.ok) {
    throw new Error('Failed to fetch leaderboard');
  }

  const json = (await res.json()) as {
    data: {
      period_start_date: string;
      scope: 'GLOBAL' | 'SITE';
      entries: BoardEntry[];
      pagination: { total: number; limit: number; offset: number };
      you: BoardSelf | null;
    };
    meta: { available_periods: string[] };
  };

  const board: Board = {
    period_start_date: json.data.period_start_date,
    scope: json.data.scope,
    entries: json.data.entries,
    pagination: json.data.pagination,
    you: json.data.you,
    available_periods: json.meta.available_periods
  };

  localStorage.setItem(CACHE_KEY, JSON.stringify(board));
  return board;
}