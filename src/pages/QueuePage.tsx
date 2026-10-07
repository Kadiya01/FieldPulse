import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Camera,
  CheckCircle2,
  Clock,
  AlertCircle,
  RefreshCw,
  ShieldCheck,
  Loader2
} from 'lucide-react';
import { useSubmissions } from '../hooks/useSubmissions';
import { triggerSync } from '../sync/coordinator';
import { fetchServerVerification, type ServerVerification } from '../api/client';
import type { Submission } from '../db/db';

/**
 * The two halves of "what happened to my photo", kept apart.
 *
 * This screen used to show one status column and call it status. That merged two
 * different questions with two different clocks:
 *
 *   - *Did this handset manage to upload it?* Local, known the moment Dexie
 *     writes the row, and the only thing the offline capture path can act on.
 *   - *Did the server accept it as evidence?* Server-side, asynchronous,
 *     possibly minutes later, possibly never, and only ever the server's answer.
 *
 * Merged, `SENT` sat under a heading that an agent had every reason to read as
 * "counted". It means the upload succeeded. The verification state below it is
 * the only thing that may say a count was accepted, and it is the only one the
 * leaderboard is built from.
 */
export default function QueuePage() {
  const submissions = useSubmissions();
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

  return (
    <section>
      <div className="flex flex-wrap items-center justify-between gap-3 mb-1">
        <h1 className="text-xl font-bold text-gray-900">Queue</h1>
        <div className="flex items-center gap-2">
          <Link
            to="/"
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50"
          >
            <Camera size={14} aria-hidden="true" />
            Capture
          </Link>
          <button
            type="button"
            onClick={() => triggerSync()}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50"
          >
            <RefreshCw size={14} aria-hidden="true" />
            Sync now
          </button>
        </div>
      </div>

      <p className="text-sm text-gray-700 mb-4">
        Local sync state is what this handset has done. Server verification state is what the
        server has decided, and only the latter can make a report verified — which is the
        only thing that moves your weekly standing.
      </p>

      {!online && (
        <p className="bg-amber-100 border border-amber-400 text-amber-900 px-4 py-3 rounded mb-4 text-sm">
          Offline. Captures are still saved on this device and will upload when a connection
          returns; verdicts cannot be checked until then.
        </p>
      )}

      {submissions.length === 0 ? (
        <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center">
          No submissions yet. <Link to="/" className="text-blue-800 underline">Take one</Link>.
        </p>
      ) : (
        <ul className="space-y-3">
          {submissions.map(sub => (
            <li key={sub.submission_uuid}>
              <SubmissionCard submission={sub} online={online} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function SubmissionCard({ submission, online }: { submission: Submission; online: boolean }) {
  const [server, setServer] = useState<ServerVerification | null>(null);
  const [looked, setLooked] = useState(false);
  const [checking, setChecking] = useState(false);
  const [lookupError, setLookupError] = useState('');

  /*
   * Look the verdict up as soon as the server confirms it has the file, and
   * whenever the connection comes back.
   *
   * The upload and the verification are separate events, so the second can land
   * minutes after the first — a queue that only checked on mount would show "no
   * verdict" until the next reload. Nothing is set synchronously here: the first
   * statement awaits, so this is a synchronisation with a remote system rather
   * than a cascading render, and the flags stay where the user left them. The
   * manual button below is the one that shows progress, because that one has a
   * user waiting on it.
   */
  useEffect(() => {
    if (submission.status !== 'SENT' || !online || looked) {
      return;
    }

    let live = true;

    (async () => {
      try {
        const result = await fetchServerVerification(submission.submission_uuid);
        if (live) {
          setServer(result);
        }
      } catch (err) {
        if (live) {
          setLookupError(
            err instanceof Error
              ? err.message
              : 'Could not reach the server to check this submission.'
          );
        }
      } finally {
        if (live) {
          setLooked(true);
        }
      }
    })();

    return () => {
      live = false;
    };
  }, [submission.status, submission.submission_uuid, online, looked]);

  /** The manual re-check, where a spinner is honest because someone pressed a button. */
  const checkNow = async () => {
    setChecking(true);
    setLookupError('');

    try {
      setServer(await fetchServerVerification(submission.submission_uuid));
    } catch (err) {
      setLookupError(
        err instanceof Error ? err.message : 'Could not reach the server to check this submission.'
      );
    } finally {
      setChecking(false);
      setLooked(true);
    }
  };

  const local = LOCAL_STATE[submission.status] ?? {
    label: submission.status,
    tone: 'bg-gray-100 text-gray-800',
    Icon: Clock
  };

  return (
    <article className="bg-white rounded shadow p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <local.Icon className={`shrink-0 mt-0.5 ${local.iconTone}`} aria-hidden="true" />
          <div>
            <p className="font-medium">{submission.count_claimed} claimed</p>
            <p className="text-xs text-gray-600">
              {new Date(submission.created_at).toLocaleString()}
            </p>
            <p className="text-xs text-gray-600 mt-0.5 break-all font-mono">
              {submission.submission_uuid}
            </p>
          </div>
        </div>

        <div className="text-right shrink-0">
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-600">
            Local sync
          </p>
          <span className={`inline-block text-xs font-semibold px-2 py-1 rounded-full ${local.tone}`}>
            {local.label}
          </span>
        </div>
      </div>

      {submission.status === 'SENT' && (
        <p className="text-xs text-gray-700 mt-2">
          Upload accepted. The photo has been cleared from this device; the copy the server
          holds is the one under review.
        </p>
      )}

      <ServerState
        submission={submission}
        server={server}
        looked={looked}
        checking={checking}
        error={lookupError}
        onCheck={() => void checkNow()}
      />
    </article>
  );
}

/**
 * The server's answer, or a truthful statement that there is not one yet.
 *
 * Three states are kept apart on purpose: not asked yet, asked and there is no
 * verdict, and asked and there is a verdict. Collapsing the first two into
 * "Pending" would be indistinguishable from a job the server is still working
 * on, and an agent cannot tell "being checked" from "never arrived" — which are
 * very different things to do about.
 */
function ServerState({
  submission,
  server,
  looked,
  checking,
  error,
  onCheck
}: {
  submission: Submission;
  server: ServerVerification | null;
  looked: boolean;
  checking: boolean;
  error: string;
  onCheck: () => void;
}) {
  if (submission.status !== 'SENT') {
    return (
      <div className="mt-3 border-t border-gray-200 pt-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-600">
          Server verification
        </p>
        <p className="text-sm text-gray-700 mt-0.5">
          Not asked. The server has not confirmed receipt of this submission yet, so there is
          no verdict to show.
        </p>
      </div>
    );
  }

  return (
    <div className="mt-3 border-t border-gray-200 pt-2">
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-gray-600">
          Server verification
        </p>
        <button
          type="button"
          onClick={onCheck}
          disabled={checking}
          className="inline-flex items-center gap-1 text-xs text-blue-800 underline disabled:opacity-60"
        >
          {checking && <Loader2 size={12} className="animate-spin" aria-hidden="true" />}
          {checking ? 'Checking' : 'Check verdict'}
        </button>
      </div>

      {/* aria-live so a verdict arriving does not require the agent to notice a
          change they were not told to wait for. */}
      <div aria-live="polite" className="mt-1">
        {error && (
          <p className="text-sm text-red-800">
            {error} The upload itself is unaffected; only this check failed.
          </p>
        )}

        {!error && checking && (
          <p className="text-sm text-gray-700 flex items-center gap-1">
            <Loader2 size={14} className="animate-spin" aria-hidden="true" />
            Asking the server…
          </p>
        )}

        {!error && !checking && server && (
          <Verdict server={server} />
        )}

        {!error && !checking && !server && looked && (
          <p className="text-sm text-gray-700">
            The server has no record of this identifier. If the upload reported success this is
            worth reporting: it means the acknowledgement and the stored file disagree.
          </p>
        )}

        {!error && !checking && !server && !looked && (
          <p className="text-sm text-gray-700">Not checked yet.</p>
        )}
      </div>
    </div>
  );
}

function Verdict({ server }: { server: ServerVerification }) {
  if (server.pending) {
    return (
      <p className="text-sm text-gray-700 flex items-center gap-1">
        <Clock size={14} aria-hidden="true" />
        Queued for verification. No verdict yet — this is the ordinary state for a few minutes
        after upload.
      </p>
    );
  }

  const disposition = server.disposition ?? server.status;
  const tone = DISPOSITION_TONE[disposition] ?? 'text-gray-800';

  return (
    <div>
      <p className={`text-sm font-semibold flex items-center gap-1 ${tone}`}>
        {disposition === 'VERIFIED' && <ShieldCheck size={14} aria-hidden="true" />}
        {DISPOSITION_WORDS[disposition] ?? disposition}
      </p>
      {server.reason && (
        <p className="text-xs text-gray-700 mt-0.5">
          Reason: <span className="font-medium">{server.reason}</span>
          {server.reasons && server.reasons.length > 1 && (
            <span className="block text-gray-600">
              Also flagged: {server.reasons.map(r => r.code).join(', ')}
            </span>
          )}
        </p>
      )}
      {server.awaiting_review && (
        <p className="text-xs text-gray-700 mt-0.5">
          With a supervisor{server.reviewed_at ? '' : ', not yet decided'}.
        </p>
      )}
      {!server.counted && (
        <p className="text-xs text-gray-700 mt-0.5">
          This count is not included in any total.
        </p>
      )}
    </div>
  );
}

/**
 * Local upload state.
 *
 * The tone is a class on the badge, but the label is always present, so the row
 * reads the same with no colour at all. `iconTone` exists for the glyph only and
 * is deliberately the same colour family as the badge.
 */
const LOCAL_STATE: Record<Submission['status'], {
  label: string;
  tone: string;
  iconTone: string;
  Icon: typeof Clock;
}> = {
  PENDING: {
    label: 'Waiting to upload',
    tone: 'bg-gray-100 text-gray-800',
    iconTone: 'text-gray-600',
    Icon: Clock
  },
  SYNCING: {
    label: 'Uploading now',
    tone: 'bg-blue-100 text-blue-800',
    iconTone: 'text-blue-700',
    Icon: RefreshCw
  },
  RETRY_WAIT: {
    label: 'Upload failed, will retry',
    tone: 'bg-amber-100 text-amber-900',
    iconTone: 'text-amber-700',
    Icon: Clock
  },
  SENT: {
    label: 'Uploaded',
    tone: 'bg-green-100 text-green-800',
    iconTone: 'text-green-700',
    Icon: CheckCircle2
  },
  FAILED_AUTH: {
    label: 'Sign in again to upload',
    tone: 'bg-red-100 text-red-800',
    iconTone: 'text-red-700',
    Icon: AlertCircle
  },
  FAILED_PERMANENT: {
    label: 'Upload refused',
    tone: 'bg-red-100 text-red-800',
    iconTone: 'text-red-700',
    Icon: AlertCircle
  }
};

/**
 * Disposition wording, and which of them are worth drawing attention to.
 *
 * Transcribed from `SubmissionRepository`'s status constants. `counted` comes
 * from the server and is rendered explicitly underneath, because the difference
 * between "verified" and "included in the total" is the difference the
 * leaderboard actually reports.
 */
const DISPOSITION_WORDS: Record<string, string> = {
  QUEUED: 'Queued for verification',
  PROCESSING: 'Being verified now',
  VERIFIED: 'Verified — counted',
  REQUIRES_REVIEW: 'With a supervisor — not counted yet',
  REJECTED: 'Rejected — not counted'
};

const DISPOSITION_TONE: Record<string, string> = {
  VERIFIED: 'text-green-800',
  REJECTED: 'text-red-800',
  REQUIRES_REVIEW: 'text-amber-800',
  QUEUED: 'text-gray-800',
  PROCESSING: 'text-gray-800'
};