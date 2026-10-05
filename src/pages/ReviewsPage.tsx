import { useEffect, useState } from 'react';
import {
  fetchReviewQueue,
  decideReview,
  ApiError,
  type ReviewQueueItem
} from '../api/client';
import { useSession } from '../auth/sessionContext';
import { ShieldAlert, RefreshCw, MapPin, Clock, Check, X, Loader2 } from 'lucide-react';

/**
 * The operator review queue.
 *
 * A client that could be opened by an agent is a screen that renders a 403. The
 * route checks the role first and refuses to fetch at all, and the server checks
 * it again on every call — this check is presentation, not a security boundary,
 * and the comment says so where the next reader will see it.
 */
export default function ReviewsPage() {
  const { isOperator, label } = useSession();
  const [items, setItems] = useState<ReviewQueueItem[]>([]);
  const [reasons, setReasons] = useState<{ code: string; count: number }[]>([]);
  const [reasonFilter, setReasonFilter] = useState('');
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');
  const [notice, setNotice] = useState('');

  /*
   * Load the queue, for the mount and for every filter change.
   *
   * An effect is the right tool: this is a synchronisation with a remote system,
   * not derived state. It is written as an inline async body because the Refresh
   * button shares `applyQueue`, and calling a setState-bearing helper straight
   * from the effect is the synchronous-update pattern `react/set-state-in-effect`
   * warns about. Everything that touches state here does so after an await, and
   * `live` drops the results of a screen that has already gone.
   */
  useEffect(() => {
    if (!isOperator) {
      return;
    }

    let live = true;

    (async () => {
      try {
        const queue = await fetchReviewQueue(reasonFilter || undefined);

        if (live) {
          setItems(queue.items);
          setReasons(queue.meta.reasons);
          setTotal(queue.meta.pagination.total);
          setErrorMsg('');
          setNotice('');
        }
      } catch (err) {
        if (live) {
          setItems([]);

          if (err instanceof ApiError && err.status === 403) {
            // Should be unreachable: the route guards on the same role. Kept
            // because a role revoked server-side since this tab loaded lands
            // here, and "you may not see this" is a different thing from
            // "something broke".
            setErrorMsg('Your account no longer has review access.');
          } else {
            setErrorMsg(
              err instanceof Error
                ? err.message
                : 'Could not load the review queue. Check your connection.'
            );
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
  }, [isOperator, reasonFilter]);

  /** Manual refresh and post-decision reload: a press, so state up front is fine. */
  const reload = async () => {
    setLoading(true);
    setErrorMsg('');

    try {
      const queue = await fetchReviewQueue(reasonFilter || undefined);
      setItems(queue.items);
      setReasons(queue.meta.reasons);
      setTotal(queue.meta.pagination.total);
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : 'Could not load the review queue.'
      );
    } finally {
      setLoading(false);
    }
  };

  if (!isOperator) {
    return (
      <section className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center gap-2 text-amber-700 mb-2">
          <ShieldAlert aria-hidden="true" />
          <h1 className="text-lg font-bold">Review queue unavailable</h1>
        </div>
        <p className="text-sm text-gray-700">
          Reviewing is limited to supervisors and administrators. Your account does not have
          that role, so this page will not load any submissions.
        </p>
        <p className="text-sm text-gray-700 mt-2">
          If you believe this is wrong, ask an administrator to check your role.
        </p>
      </section>
    );
  }

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h1 className="text-xl font-bold text-gray-900">Review queue</h1>
          <p className="text-sm text-gray-700">
            {loading ? 'Loading…' : `${total} submission${total === 1 ? '' : 's'} awaiting a decision`}
            {label && <span className="text-gray-600"> · signed in as {label}</span>}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div>
            <label htmlFor="reason" className="sr-only">Filter by reason</label>
            <select
              id="reason"
              value={reasonFilter}
              onChange={e => setReasonFilter(e.target.value)}
              className="text-sm border border-gray-400 rounded-md p-2 bg-white"
            >
              <option value="">All reasons</option>
              {reasons.map(r => (
                <option key={r.code} value={r.code}>
                  {r.code} ({r.count})
                </option>
              ))}
            </select>
          </div>
          <button
            type="button"
            onClick={() => {
              setLoading(true);
              void reload();
            }}
            disabled={loading}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-60"
          >
            {loading
              ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
              : <RefreshCw size={14} aria-hidden="true" />}
            Refresh
          </button>
        </div>
      </div>

      {/* Both banners are live regions: a decision that lands below the fold is
          not useful feedback. */}
      <div aria-live="polite">
        {errorMsg && (
          <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded mb-4 text-sm">
            {errorMsg}
          </p>
        )}
        {notice && (
          <p className="bg-green-100 border border-green-400 text-green-900 px-4 py-3 rounded mb-4 text-sm">
            {notice}
          </p>
        )}
      </div>

      {items.length === 0 && !loading && (
        <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center">
          Nothing is waiting for review{reasonFilter ? ` with reason ${reasonFilter}` : ''}.
        </p>
      )}

      <ul className="space-y-4">
        {items.map(item => (
          <li key={item.submission_id}>
            <ReviewCard item={item} onDecided={reload} />
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * One queued submission, with its evidence and a decision form.
 *
 * The checks are rendered as a list of labelled pass/fail words rather than a
 * grid of coloured cells: eight statuses in four colours is unreadable for
 * anyone who cannot separate them, and colour-blind reviewers are exactly the
 * people whose judgement this page exists to collect.
 */
function ReviewCard({ item, onDecided }: { item: ReviewQueueItem; onDecided: () => Promise<void> }) {
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState<'APPROVE' | 'REJECT' | null>(null);
  const [errorMsg, setErrorMsg] = useState('');

  const decide = async (decision: 'APPROVE' | 'REJECT') => {
    // The server requires 5–1000 characters. Checking here as well means the
    // reviewer is told before the round trip, and is told why, rather than
    // receiving a 422 whose `field` is the note they cannot see.
    if (note.trim().length < 5) {
      setErrorMsg('A note of at least 5 characters is required with every decision.');
      return;
    }

    setBusy(decision);
    setErrorMsg('');

    try {
      await decideReview(item.submission_id, decision, note.trim());
      setNote('');
      await onDecided();
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'The decision could not be recorded.');
    } finally {
      setBusy(null);
    }
  };

  const position = item.position.latitude !== null && item.position.longitude !== null
    ? `${item.position.latitude.toFixed(5)}, ${item.position.longitude.toFixed(5)}`
    : 'Not supplied';

  return (
    <article className="bg-white rounded-lg shadow p-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
        <div>
          <h2 className="font-semibold text-gray-900">
            {item.agent.agent_code} · {item.agent.full_name || 'Unnamed agent'}
          </h2>
          <p className="text-sm text-gray-700">
            {item.count_claimed} claimed ·{' '}
            <span className="inline-flex items-center gap-1">
              <Clock size={12} aria-hidden="true" />
              {new Date(item.received_at.replace(' ', 'T') + 'Z').toLocaleString()}
            </span>
          </p>
        </div>
        <p className="text-xs font-mono text-gray-600 break-all">{item.submission_uuid}</p>
      </header>

      <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm mb-3">
        <div className="flex gap-2">
          <dt className="text-gray-600">Reason</dt>
          <dd className="font-medium">{item.review_reason ?? 'Not recorded'}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-gray-600">Position</dt>
          <dd className="font-medium inline-flex items-center gap-1">
            <MapPin size={12} aria-hidden="true" />
            {position}
          </dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-gray-600">Image</dt>
          <dd className="font-medium">{item.image.width}×{item.image.height}</dd>
        </div>
        <div className="flex gap-2">
          <dt className="text-gray-600">Device</dt>
          <dd className="font-mono text-xs break-all">{item.device.device_uuid ?? '—'}</dd>
        </div>
      </dl>

      <div className="mb-3">
        <h3 className="text-sm font-semibold text-gray-800 mb-1">Automated checks</h3>
        <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
          {Object.entries(item.checks).map(([name, status]) => (
            <li key={name} className="flex justify-between gap-2 border-b border-gray-200 py-1">
              <span className="text-gray-700">{CHECK_LABELS[name] ?? name}</span>
              {/* The word carries the meaning; colour and weight only reinforce
                  it, so the table is readable in greyscale and to anyone who
                  cannot separate the hues. */}
              <span className={`font-medium ${STATUS_TONE[status] ?? 'text-gray-800'}`}>
                {STATUS_WORDS[status] ?? status}
              </span>
            </li>
          ))}
        </ul>
      </div>

      {item.already_reviewed && (
        <p className="text-sm bg-gray-100 text-gray-800 rounded px-3 py-2 mb-3">
          Already decided{item.reviewed_at ? ` on ${item.reviewed_at.replace(' ', 'T') + 'Z'}` : ''}
          {item.review_note ? ` — “${item.review_note}”` : ''}.
        </p>
      )}

      <div role="alert" aria-live="assertive">
        {errorMsg && (
          <p className="text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2 mb-2">
            {errorMsg}
          </p>
        )}
      </div>

      <label htmlFor={`note-${item.submission_id}`} className="block text-sm font-medium text-gray-800 mb-1">
        Decision note <span className="font-normal text-gray-600">(required)</span>
      </label>
      <textarea
        id={`note-${item.submission_id}`}
        value={note}
        onChange={e => setNote(e.target.value)}
        rows={2}
        className="w-full border border-gray-400 rounded-md p-2 text-sm mb-2"
        placeholder="Why this evidence does or does not support the claimed count"
      />

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => void decide('APPROVE')}
          disabled={busy !== null}
          className="inline-flex items-center gap-1 bg-green-700 text-white px-4 py-2 rounded-md font-medium hover:bg-green-800 disabled:opacity-60"
        >
          {busy === 'APPROVE'
            ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            : <Check size={16} aria-hidden="true" />}
          Approve
        </button>
        <button
          type="button"
          onClick={() => void decide('REJECT')}
          disabled={busy !== null}
          className="inline-flex items-center gap-1 bg-red-700 text-white px-4 py-2 rounded-md font-medium hover:bg-red-800 disabled:opacity-60"
        >
          {busy === 'REJECT'
            ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            : <X size={16} aria-hidden="true" />}
          Reject
        </button>
      </div>
    </article>
  );
}

/**
 * Human names for the check keys the server returns.
 *
 * The keys are stable identifiers in the database; showing them raw is how a
 * supervisor learns to skim `perceptual_duplicate` without reading it.
 */
const CHECK_LABELS: Record<string, string> = {
  auth: 'Signature',
  device: 'Device',
  client_gps: 'Client GPS',
  exif: 'EXIF',
  timestamp: 'Timestamp',
  geofence: 'Geofence',
  exact_duplicate: 'Exact duplicate',
  perceptual_duplicate: 'Near-duplicate'
};

/**
 * Every status the verification pipeline can write, spelled out.
 *
 * Transcribed from the constants that produce them — `ExifExtractor::ABSENT`,
 * `TimestampVerifier::DISCREPANCY`, `Geofence::GPS_UNRELIABLE`,
 * `DuplicateDetector::POSSIBLE`, `DeviceStatus::REVOKED` — because an unmapped
 * value is the one a reviewer needs to read. Anything not listed here is shown
 * verbatim rather than being guessed into a pass or a fail.
 */
const STATUS_WORDS: Record<string, string> = {
  // signature: the Kernel already rejected anything that was not signed, so
  // every queued row reads VERIFIED. It is shown rather than hidden because a
  // reviewer comparing this against their own expectation is how a pipeline
  // regression gets noticed.
  VERIFIED: 'Verified',

  // device
  PENDING: 'Pending',
  ACTIVE: 'Active',
  REVOKED: 'Revoked',
  DISABLED: 'Disabled',
  UNKNOWN: 'Unknown',

  // client GPS
  PRESENT: 'Supplied',
  ABSENT: 'Not supplied',

  // EXIF
  PRESENT_VALID: 'Readable, consistent',
  PRESENT_INVALID: 'Present but inconsistent',
  UNREADABLE: 'Unreadable',
  INCONSISTENT: 'Cross-tag contradiction',

  // timestamp
  VALID: 'Consistent',
  MISSING: 'Missing',
  UNPARSEABLE: 'Unparseable',
  FUTURE: 'In the future',
  TOO_OLD: 'Too old',
  DISCREPANCY: 'EXIF and client disagree',

  // geofence
  WITHIN_GEOFENCE: 'Inside the site boundary',
  OUTSIDE_GEOFENCE: 'Outside the site boundary',
  NO_GPS: 'No position',
  INVALID_GPS: 'Implausible position',
  GPS_UNRELIABLE: 'Accuracy too coarse',
  SITE_UNASSIGNED: 'Agent has no site',

  // duplicates
  NONE: 'None',
  DUPLICATE: 'Exact duplicate',
  EXACT: 'Exact duplicate',
  PERCEPTUAL: 'Visually similar',
  POSSIBLE: 'Possibly similar',
  NOT_ASSESSED: 'Not assessed',
  NOT_EVALUATED: 'Not evaluated'
};

/**
 * Statuses that should stop being read as neutral.
 *
 * Applied as a word plus a weight, never as a colour on its own: the words above
 * already carry the meaning, and this only makes the ones worth reading twice
 * stand out. An unknown status is not styled as a pass.
 */
const STATUS_TONE: Record<string, string> = {
  VALID: 'text-green-800',
  VERIFIED: 'text-green-800',
  ACTIVE: 'text-green-800',
  WITHIN_GEOFENCE: 'text-green-800',
  PRESENT_VALID: 'text-green-800',
  CONSISTENT: 'text-green-800',

  PRESENT: 'text-gray-800',
  NONE: 'text-gray-800',

  ABSENT: 'text-amber-800',
  MISSING: 'text-amber-800',
  NO_GPS: 'text-amber-800',
  SITE_UNASSIGNED: 'text-amber-800',
  POSSIBLE: 'text-amber-800',
  NOT_ASSESSED: 'text-amber-800',
  NOT_EVALUATED: 'text-amber-800',
  PENDING: 'text-amber-800',

  OUTSIDE_GEOFENCE: 'text-red-800',
  INVALID_GPS: 'text-red-800',
  GPS_UNRELIABLE: 'text-red-800',
  DISCREPANCY: 'text-red-800',
  FUTURE: 'text-red-800',
  TOO_OLD: 'text-red-800',
  PRESENT_INVALID: 'text-red-800',
  INCONSISTENT: 'text-red-800',
  UNPARSEABLE: 'text-red-800',
  DUPLICATE: 'text-red-800',
  EXACT: 'text-red-800',
  PERCEPTUAL: 'text-red-800',
  REVOKED: 'text-red-800',
  DISABLED: 'text-red-800'
};