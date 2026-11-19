import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  fetchDashboard,
  markNotificationRead,
  type DashboardData
} from '../api/client';
import {
  AlertCircle,
  Bell,
  CheckCircle2,
  Loader2,
  RefreshCw,
  Target,
  Trophy
} from 'lucide-react';

/**
 * The agent's monthly standing.
 *
 * Every number on this page is a server figure, read from the persisted
 * summary. Nothing is derived from the local queue: a total that mixed local
 * and server counts would move when the handset synced and would look like the
 * server had corrected itself.
 *
 * An unset target is shown as "not configured", never as 0%, because those are
 * different claims. 0% means the agent enrolled nobody against a target that
 * exists; "not configured" means there is nothing to measure against yet.
 */
export default function DashboardPage() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');
  const [notice, setNotice] = useState('');

  const load = async () => {
    setLoading(true);
    setErrorMsg('');

    try {
      setData(await fetchDashboard());
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Could not load your dashboard.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const fresh = await fetchDashboard();
        if (live) {
          setData(fresh);
          setErrorMsg('');
        }
      } catch (err) {
        if (live) {
          setErrorMsg(err instanceof Error ? err.message : 'Could not load your dashboard.');
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

  const markAllRead = async () => {
    setNotice('');

    try {
      const { unread_count: unread } = await markNotificationRead();
      setData(prev => prev
        ? {
            ...prev,
            notifications: {
              unread_count: unread,
              items: prev.notifications.items.map(item => ({
                ...item,
                read_at: item.read_at ?? new Date().toISOString()
              }))
            }
          }
        : prev);
      setNotice('Notifications marked as read.');
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Could not update notifications.');
    }
  };

  if (loading && !data) {
    return (
      <p role="status" className="inline-flex items-center gap-2 text-gray-700">
        <Loader2 size={16} className="animate-spin" aria-hidden="true" />
        Loading your dashboard…
      </p>
    );
  }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Dashboard</h1>
          {data && (
            <p className="text-sm text-gray-700">
              {data.agent.agent_code} · {data.agent.full_name} · {formatMonth(data.period.period_start_date)}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => void load()}
          disabled={loading}
          className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-60"
        >
          {loading
            ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
            : <RefreshCw size={14} aria-hidden="true" />}
          Refresh
        </button>
      </div>

      <div aria-live="polite">
        {errorMsg && (
          <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded text-sm mb-3">
            {errorMsg}
          </p>
        )}
        {notice && (
          <p className="bg-green-100 border border-green-400 text-green-900 px-4 py-3 rounded text-sm mb-3">
            {notice}
          </p>
        )}
      </div>

      {data && (
        <>
          <div className="grid gap-3 sm:grid-cols-2">
            <article className="bg-white rounded-lg shadow p-4">
              <div className="flex items-center gap-2 text-blue-800 mb-2">
                <CheckCircle2 size={18} aria-hidden="true" />
                <h2 className="font-semibold">Approved enrollments</h2>
              </div>
              <p className="text-3xl font-bold text-gray-900">{data.totals.approved_enrollments}</p>
              <p className="text-xs text-gray-700 mt-1">
                {data.totals.pending} pending · {data.totals.rejected} rejected ·{' '}
                {data.totals.total_submissions} total submitted
              </p>
            </article>

            <article className="bg-white rounded-lg shadow p-4">
              <div className="flex items-center gap-2 text-blue-800 mb-2">
                <Target size={18} aria-hidden="true" />
                <h2 className="font-semibold">Monthly target</h2>
              </div>
              {data.target.configured ? (
                <>
                  <p className="text-3xl font-bold text-gray-900">
                    {formatPercent(data.target.achievement_percent)}
                  </p>
                  <p className="text-xs text-gray-700 mt-1">
                    {data.totals.approved_enrollments} of {data.target.monthly_target} ·{' '}
                    {data.target.remaining} remaining
                  </p>
                  <div
                    className="mt-2 h-2 rounded bg-gray-200 overflow-hidden"
                    role="img"
                    aria-label={`${formatPercent(data.target.achievement_percent)} of monthly target achieved`}
                  >
                    <div
                      className="h-full bg-blue-700"
                      style={{ width: `${Math.min(100, Math.max(0, data.target.achievement_percent ?? 0))}%` }}
                    />
                  </div>
                </>
              ) : (
                <p className="text-sm text-gray-700">
                  No monthly target has been set for your account yet.
                </p>
              )}
            </article>
          </div>

          <article className="bg-white rounded-lg shadow p-4">
            <div className="flex items-center gap-2 text-blue-800 mb-2">
              <Trophy size={18} aria-hidden="true" />
              <h2 className="font-semibold">Monthly standing</h2>
            </div>
            {data.leaderboard.rank === null ? (
              <p className="text-sm text-gray-700">
                You are not ranked yet. A verified submission puts you on the board.
              </p>
            ) : (
              <p className="text-sm text-gray-800">
                Rank <strong>{data.leaderboard.rank}</strong> of {data.leaderboard.total_agents} agents
                this month. <Link className="text-blue-800 underline" to="/leaderboard">See the full board</Link>.
              </p>
            )}
          </article>

          <article className="bg-white rounded-lg shadow p-4">
            <div className="flex items-center justify-between gap-2 mb-2">
              <div className="flex items-center gap-2 text-blue-800">
                <Bell size={18} aria-hidden="true" />
                <h2 className="font-semibold">Notifications</h2>
                {data.notifications.unread_count > 0 && (
                  <span className="text-xs bg-amber-100 text-amber-900 border border-amber-300 rounded-full px-2 py-0.5">
                    {data.notifications.unread_count} unread
                  </span>
                )}
              </div>
              {data.notifications.unread_count > 0 && (
                <button
                  type="button"
                  onClick={() => void markAllRead()}
                  className="text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50"
                >
                  Mark all read
                </button>
              )}
            </div>

            {data.notifications.items.length === 0 ? (
              <p className="text-sm text-gray-700">No notifications yet.</p>
            ) : (
              <ul className="divide-y divide-gray-200">
                {data.notifications.items.map(item => (
                  <li key={item.id} className="py-2">
                    <p className="text-sm font-medium text-gray-900">
                      {item.title}
                      {!item.read_at && <span className="ml-2 text-xs text-amber-800">Unread</span>}
                    </p>
                    <p className="text-sm text-gray-700">{item.message}</p>
                    <p className="text-xs text-gray-500">{formatTimestamp(item.created_at)}</p>
                  </li>
                ))}
              </ul>
            )}
          </article>

          <article className="bg-white rounded-lg shadow p-4">
            <div className="flex items-center justify-between gap-2 mb-2">
              <h2 className="font-semibold text-gray-900">Recent submissions</h2>
              <Link className="text-sm text-blue-800 underline" to="/queue">Open queue</Link>
            </div>
            {data.recent_submissions.length === 0 ? (
              <p className="text-sm text-gray-700">
                No submissions yet. <Link className="text-blue-800 underline" to="/">Capture your first one</Link>.
              </p>
            ) : (
              <ul className="divide-y divide-gray-200">
                {data.recent_submissions.map(item => (
                  <li key={item.submission_uuid} className="py-2 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-gray-900">
                        {item.count_claimed} enrollment{item.count_claimed === 1 ? '' : 's'}
                      </p>
                      <p className="text-xs text-gray-600 font-mono">{item.submission_uuid}</p>
                    </div>
                    <div className="text-right">
                      <p className="text-xs font-medium text-gray-800">{statusWord(item.status)}</p>
                      <p className="text-xs text-gray-500">
                        {item.reporting_month ? formatMonth(item.reporting_month) : formatTimestamp(item.received_at)}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </article>
        </>
      )}

      {!data && !loading && errorMsg && (
        <div className="flex items-start gap-2 bg-white rounded-lg shadow p-4 text-sm text-gray-700">
          <AlertCircle size={18} className="text-red-700 shrink-0" aria-hidden="true" />
          Your dashboard could not be loaded. Try Refresh when the connection is back.
        </div>
      )}
    </section>
  );
}

function formatMonth(value: string): string {
  const date = new Date(`${value.slice(0, 10)}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

function formatPercent(value: number | null): string {
  if (value === null) {
    return '—';
  }
  return `${Number.isInteger(value) ? value : value.toFixed(1)}%`;
}

function formatTimestamp(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

const STATUS_WORDS: Record<string, string> = {
  VERIFIED: 'Verified',
  REQUIRES_REVIEW: 'Awaiting review',
  REJECTED: 'Rejected',
  PENDING: 'Pending upload',
  SYNCING: 'Uploading',
  RETRY_WAIT: 'Waiting to retry',
  SENT: 'Received',
  FAILED_AUTH: 'Sign-in needed',
  FAILED_PERMANENT: 'Not accepted'
};

function statusWord(status: string): string {
  return STATUS_WORDS[status] ?? status;
}
