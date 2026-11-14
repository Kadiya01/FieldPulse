import { useCallback, useEffect, useRef, useState } from 'react';
import { CheckCircle2, Clock3, FileImage, LogOut, RefreshCw, Send, XCircle } from 'lucide-react';
import BrandHeader from '../components/BrandHeader';
import {
  fetchAbhAgentLeaderboard,
  fetchAbhAgentSubmissions,
  submitAbhEnrollment,
  submitAbhCorrection,
  type AbhAgentMonthlySummary,
  type AbhLeaderboardData,
  type AbhAgentSubmissionItem,
  type SessionAgent
} from '../api/client';

function todayIso(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function currentMonth(): string {
  return todayIso().slice(0, 7);
}

function monthEnd(month: string): string {
  const [year, value] = month.split('-').map(Number);
  const last = new Date(year, value, 0);
  return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`;
}

function statusLabel(status: string): string {
  switch (status) {
    case 'PENDING_REVIEW': return 'Pending review';
    case 'APPROVED': return 'Approved';
    case 'REJECTED': return 'Rejected';
    case 'CORRECTION_REQUESTED': return 'Correction requested';
    default: return status.replaceAll('_', ' ').toLowerCase();
  }
}

function statusClass(status: string): string {
  if (status === 'APPROVED') return 'bg-emerald-50 text-emerald-800';
  if (status === 'REJECTED') return 'bg-red-50 text-red-800';
  if (status === 'CORRECTION_REQUESTED') return 'bg-amber-50 text-amber-800';
  return 'bg-blue-50 text-blue-800';
}

export default function AbhAgentPortalPage({
  agent,
  onSignOut
}: {
  agent: SessionAgent;
  onSignOut: () => void;
}) {
  const [month, setMonth] = useState(currentMonth);
  const [activityDate, setActivityDate] = useState(todayIso);
  const [quantity, setQuantity] = useState('1');
  const [note, setNote] = useState('');
  const [evidence, setEvidence] = useState<File | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());
  const [correctionSubmissionId, setCorrectionSubmissionId] = useState<number | null>(null);
  const [items, setItems] = useState<AbhAgentSubmissionItem[]>([]);
  const [leaderboard, setLeaderboard] = useState<AbhLeaderboardData | null>(null);
  const [leaderboardLoading, setLeaderboardLoading] = useState(true);
  const [total, setTotal] = useState(0);
  const [summary, setSummary] = useState<AbhAgentMonthlySummary>({
    period_status: null,
    submitted_count: 0,
    approved_quantity: 0,
    pending_count: 0,
    rejected_count: 0,
    target_quantity: null,
    progress_percent: null,
    leaderboard_rank: null
  });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const refreshHistory = useCallback(async (showLoading = true) => {
    if (showLoading) setLoading(true);
    try {
      const result = await fetchAbhAgentSubmissions(month, 50, 0);
      setError('');
      setItems(result.items);
      setTotal(result.total);
      setSummary(result.summary ?? {
        period_status: null,
        submitted_count: 0,
        approved_quantity: 0,
        pending_count: 0,
        rejected_count: 0,
        target_quantity: null,
        progress_percent: null,
        leaderboard_rank: null
      });
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Submission history could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [month]);

  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      if (active) void refreshHistory(false);
    }, 0);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [refreshHistory]);

  const refreshLeaderboard = useCallback(async () => {
    setLeaderboardLoading(true);
    try {
      const result = await fetchAbhAgentLeaderboard(month, 20, 0);
      setLeaderboard(result);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Monthly leaderboard could not be loaded.');
    } finally {
      setLeaderboardLoading(false);
    }
  }, [month]);

  useEffect(() => {
    let active = true;
    const timer = window.setTimeout(() => {
      if (active) void refreshLeaderboard();
    }, 0);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [refreshLeaderboard]);

  const maximumDate = month === currentMonth() ? todayIso() : monthEnd(month);
  const minimumDate = `${month}-01`;

  const resetRequestKey = () => setIdempotencyKey(crypto.randomUUID());

  const changeMonth = (nextMonth: string) => {
    if (!nextMonth) return;
    setMonth(nextMonth);
    const firstDay = `${nextMonth}-01`;
    const nextDate = nextMonth === currentMonth() ? todayIso() : firstDay;
    setActivityDate(nextDate);
    resetRequestKey();
  };

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError('');
    setNotice('');

    const count = Number(quantity);
    if (!Number.isInteger(count) || count < 1 || count > 500) {
      setError('Enter a whole-number enrollment quantity between 1 and 500.');
      return;
    }
    if (!evidence) {
      setError('Attach a JPEG or PNG evidence image before submitting.');
      return;
    }
    if (evidence.size <= 0 || evidence.size > 5 * 1024 * 1024) {
      setError('Evidence must be a non-empty image no larger than 5 MB.');
      return;
    }
    if (!['image/jpeg', 'image/png'].includes(evidence.type)) {
      setError('Only JPEG and PNG evidence images are accepted.');
      return;
    }

    setBusy(true);
    try {
      const result = correctionSubmissionId === null
        ? await submitAbhEnrollment({
            idempotency_key: idempotencyKey,
            reporting_month: month,
            activity_date: activityDate,
            claimed_quantity: count,
            agent_note: note,
            evidence
          })
        : await submitAbhCorrection({
            submission_id: correctionSubmissionId,
            idempotency_key: idempotencyKey,
            reporting_month: month,
            activity_date: activityDate,
            claimed_quantity: count,
            agent_note: note,
            evidence
          });
      setNotice(
        result.idempotent_replay
          ? `The earlier request for ${result.reference_no} was already received.`
          : correctionSubmissionId === null
            ? `Submission received. Reference: ${result.reference_no}. It is now awaiting review.`
            : `Correction received for ${result.reference_no}. Revision ${result.revision_no} is now awaiting review.`
      );
      setCorrectionSubmissionId(null);
      setQuantity('1');
      setNote('');
      setEvidence(null);
      if (fileRef.current) fileRef.current.value = '';
      resetRequestKey();
      await Promise.all([refreshHistory(), refreshLeaderboard()]);
    } catch (caught) {
      // Keep the same idempotency key on failure. A network timeout may occur
      // after the server has committed, and retrying must not create a duplicate.
      setError(caught instanceof Error ? caught.message : 'The submission could not be sent.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-100">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4">
          <div className="flex items-center gap-3">
            <BrandHeader />
            <div className="border-l border-slate-200 pl-3">
              <p className="font-bold text-slate-900">ABH Monthly Enrollment</p>
              <p className="text-xs text-slate-500">Agent portal</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <div className="text-right">
              <p className="text-sm font-semibold text-slate-800">{agent.full_name}</p>
              <p className="text-xs text-slate-500">{agent.agent_code}</p>
            </div>
            <button type="button" onClick={onSignOut}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
              <LogOut size={16} aria-hidden="true" /> Sign out
            </button>
          </div>
        </div>
      </header>

      <div className="mx-auto max-w-6xl space-y-6 px-4 py-6">
        <section>
          <h1 className="text-2xl font-bold text-slate-900">Monthly activity</h1>
          <p className="mt-1 text-sm text-slate-600">
            Submit your enrollment quantity and supporting evidence. An upload is not approved work;
            only an administrator-approved submission counts toward your monthly performance.
          </p>
        </section>

        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" aria-label="Monthly submission summary">
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Approved enrollments</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.approved_quantity}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending submissions</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.pending_count}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Rejected submissions</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.rejected_count}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Monthly target</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.target_quantity ?? 'Not set'}</p>
            <p className="mt-1 text-xs text-slate-500">
              {summary.progress_percent === null ? 'Target progress unavailable' : `${summary.progress_percent.toFixed(2)}% achieved`}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Leaderboard position</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.leaderboard_rank === null ? '—' : `#${summary.leaderboard_rank}`}</p>
            <p className="mt-1 text-xs text-slate-500">
              {summary.period_status === 'CLOSED' ? 'Final monthly position' : 'Live monthly position'}
            </p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Submissions this month</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.submitted_count}</p>
            <p className="mt-1 text-xs text-slate-500">{total} record(s) shown in history</p>
          </div>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-lg font-bold text-slate-900">Monthly leaderboard</h2>
              <p className="mt-1 text-xs text-slate-500">
                {leaderboard?.period_status === 'CLOSED' ? 'Final frozen ranking' : 'Live ranking based on approved enrollments only'}
              </p>
            </div>
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{month}</span>
          </div>

          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <div className="rounded-xl bg-slate-50 p-4">
              <p className="text-xs text-slate-500">Your position</p>
              <p className="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {leaderboard?.viewer.rank_position ? `#${leaderboard.viewer.rank_position}` : 'Not ranked'}
              </p>
            </div>
            <div className="rounded-xl bg-slate-50 p-4">
              <p className="text-xs text-slate-500">Your approved enrollments</p>
              <p className="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {leaderboard?.viewer.approved_quantity.toLocaleString() ?? '—'}
              </p>
            </div>
            <div className="rounded-xl bg-slate-50 p-4">
              <p className="text-xs text-slate-500">Your target progress</p>
              <p className="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {leaderboard?.viewer.progress_percent === null || leaderboard?.viewer.progress_percent === undefined
                  ? '—'
                  : `${leaderboard.viewer.progress_percent}%`}
              </p>
            </div>
          </div>

          {leaderboardLoading ? (
            <p role="status" className="mt-4 py-5 text-center text-sm text-slate-500">Loading leaderboard…</p>
          ) : leaderboard?.items.length ? (
            <div className="mt-4 overflow-x-auto">
              <table className="w-full min-w-[540px] text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr><th className="py-3 pr-4">Rank</th><th className="py-3 pr-4">Agent</th><th className="py-3 pr-4 text-right">Approved</th><th className="py-3 text-right">Target progress</th></tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {leaderboard.items.map(item => (
                    <tr key={item.agent_id} className={item.is_viewer ? 'bg-blue-50' : ''}>
                      <td className="py-3 pr-4 font-semibold text-slate-900">#{item.rank_position}</td>
                      <td className="py-3 pr-4">
                        <p className="font-semibold text-slate-900">{item.display_name}{item.is_viewer ? ' (You)' : ''}</p>
                        <p className="text-xs text-slate-500">{item.agent_code}</p>
                      </td>
                      <td className="py-3 pr-4 text-right font-semibold tabular-nums text-slate-900">{item.approved_quantity.toLocaleString()}</td>
                      <td className="py-3 text-right tabular-nums text-slate-700">
                        {item.progress_percent === null ? '—' : `${item.progress_percent}%`}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="mt-4 rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No approved enrollments have been recorded for this month yet.</p>
          )}
          {leaderboard && leaderboard.total > leaderboard.items.length && (
            <p className="mt-3 text-xs text-slate-500">Showing the first {leaderboard.items.length} of {leaderboard.total} ranked agents. Your individual position is shown above.</p>
          )}
        </section>

        <section className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
          <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="text-lg font-bold text-slate-900">{correctionSubmissionId === null ? 'Submit monthly enrollment' : `Correct submission #${correctionSubmissionId}`}</h2>
              <p className="mt-1 text-xs text-slate-500">{correctionSubmissionId === null
                ? 'Your account and approved browser device are linked to this report automatically.'
                : 'This creates a new immutable revision. Your earlier report and evidence remain in the review history.'}</p>
            </div>

            <div>
              <label htmlFor="abh-reporting-month" className="block text-sm font-semibold text-slate-700">Reporting month</label>
              <input id="abh-reporting-month" type="month" required max={currentMonth()} value={month}
                disabled={correctionSubmissionId !== null}
                onChange={event => changeMonth(event.target.value)}
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900" />
            </div>

            <div>
              <label htmlFor="abh-activity-date" className="block text-sm font-semibold text-slate-700">Activity date</label>
              <input id="abh-activity-date" type="date" required min={minimumDate} max={maximumDate}
                value={activityDate} onChange={event => { setActivityDate(event.target.value); resetRequestKey(); }}
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900" />
            </div>

            <div>
              <label htmlFor="abh-quantity" className="block text-sm font-semibold text-slate-700">Number of enrollments</label>
              <input id="abh-quantity" type="number" required min={1} max={500} step={1} value={quantity}
                onChange={event => { setQuantity(event.target.value); resetRequestKey(); }}
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900" />
            </div>

            <div>
              <label htmlFor="abh-note" className="block text-sm font-semibold text-slate-700">Note (optional)</label>
              <textarea id="abh-note" rows={3} maxLength={1000} value={note}
                onChange={event => { setNote(event.target.value); resetRequestKey(); }}
                placeholder="Add a short note about this activity."
                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900" />
            </div>

            <div>
              <label htmlFor="abh-evidence" className="block text-sm font-semibold text-slate-700">Evidence image</label>
              <div className="mt-1 rounded-xl border border-dashed border-slate-300 p-4">
                <input ref={fileRef} id="abh-evidence" type="file" required accept="image/jpeg,image/png"
                  onChange={event => { setEvidence(event.target.files?.[0] ?? null); resetRequestKey(); }}
                  className="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:font-semibold file:text-white" />
                <p className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                  <FileImage size={15} aria-hidden="true" /> JPEG or PNG, maximum 5 MB
                </p>
                {evidence && <p className="mt-2 truncate text-xs font-medium text-slate-700">{evidence.name}</p>}
              </div>
            </div>

            {error && (
              <div role="alert" className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <XCircle size={17} className="mt-0.5 shrink-0" aria-hidden="true" /> {error}
              </div>
            )}
            {notice && (
              <div role="status" className="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                <CheckCircle2 size={17} className="mt-0.5 shrink-0" aria-hidden="true" /> {notice}
              </div>
            )}

            <button type="submit" disabled={busy || !evidence}
              className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60">
              <Send size={17} aria-hidden="true" /> {busy ? 'Submitting evidence…' : correctionSubmissionId === null ? 'Submit for review' : 'Submit corrected revision'}
            </button>
            {correctionSubmissionId !== null && (
              <button type="button" disabled={busy} onClick={() => {
                setCorrectionSubmissionId(null);
                setQuantity('1');
                setNote('');
                setEvidence(null);
                if (fileRef.current) fileRef.current.value = '';
                resetRequestKey();
              }} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700">
                Cancel correction
              </button>
            )}
            <p className="text-xs leading-5 text-slate-500">
              Your submission will remain pending until an authorised reviewer checks the evidence.
              Do not submit another copy while the same request is processing.
            </p>
          </form>

          <section className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-bold text-slate-900">Submission history</h2>
                <p className="text-xs text-slate-500">{total} record(s) for {month}</p>
              </div>
              <button type="button" onClick={() => void refreshHistory()} disabled={loading}
                className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">
                <RefreshCw size={14} aria-hidden="true" /> Refresh
              </button>
            </div>

            {loading ? (
              <p role="status" className="py-8 text-center text-sm text-slate-500">Loading your submissions…</p>
            ) : items.length === 0 ? (
              <div className="rounded-xl bg-slate-50 px-4 py-8 text-center">
                <Clock3 className="mx-auto text-slate-400" size={24} aria-hidden="true" />
                <p className="mt-2 text-sm font-medium text-slate-700">No submissions for this month yet.</p>
                <p className="mt-1 text-xs text-slate-500">Your accepted reports will appear here.</p>
              </div>
            ) : (
              <div className="space-y-3">
                {items.map(item => (
                  <article key={item.id} className="rounded-xl border border-slate-200 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold text-slate-900">{item.reference_no}</p>
                        <p className="mt-1 text-xs text-slate-500">{item.activity_date} · Revision {item.revision_no}</p>
                      </div>
                      <span className={`rounded-full px-3 py-1 text-xs font-semibold ${statusClass(item.status)}`}>
                        {statusLabel(item.status)}
                      </span>
                    </div>
                    <div className="mt-3 flex items-center justify-between text-sm">
                      <span className="text-slate-600">Claimed enrollments</span>
                      <strong className="tabular-nums text-slate-900">{item.claimed_quantity}</strong>
                    </div>
                    <p className="mt-2 text-xs text-slate-500">Submitted {new Date(item.submitted_at.replace(' ', 'T').slice(0, 19) + 'Z').toLocaleString()}</p>
                    {item.agent_note && (
                      <p className="mt-2 text-xs text-slate-600"><strong>Agent note:</strong> {item.agent_note}</p>
                    )}
                    {item.status === 'CORRECTION_REQUESTED' && (
                      <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3">
                        <p className="text-xs font-semibold text-amber-900">Reviewer requested a correction</p>
                        {item.review_note && (
                          <p className="mt-1 whitespace-pre-wrap text-sm text-amber-900">{item.review_note}</p>
                        )}
                        <button type="button" onClick={() => {
                          setCorrectionSubmissionId(item.id);
                          setMonth(item.period_key);
                          setActivityDate(item.activity_date);
                          setQuantity(String(item.claimed_quantity));
                          setNote(item.agent_note ?? '');
                          setEvidence(null);
                          if (fileRef.current) fileRef.current.value = '';
                          resetRequestKey();
                          setError('');
                          setNotice('');
                        }} className="mt-3 rounded-lg bg-amber-800 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-900">
                          Correct and resubmit
                        </button>
                      </div>
                    )}
                  </article>
                ))}
              </div>
            )}
          </section>
        </section>
      </div>
    </main>
  );
}
