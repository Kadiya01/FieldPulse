import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { CheckCircle2, Clock3, FileImage, LogOut, RefreshCw, Send, XCircle } from 'lucide-react';
import BrandHeader from '../components/BrandHeader';
import {
  fetchAbhAgentSubmissions,
  submitAbhEnrollment,
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
  const [items, setItems] = useState<AbhAgentSubmissionItem[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const refreshHistory = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchAbhAgentSubmissions(month, 50, 0);
      setItems(result.items);
      setTotal(result.total);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Submission history could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [month]);

  useEffect(() => {
    void refreshHistory();
  }, [refreshHistory]);

  const summary = useMemo(() => {
    const approved = items.filter(item => item.status === 'APPROVED');
    return {
      approvedQuantity: approved.reduce((sum, item) => sum + item.claimed_quantity, 0),
      pending: items.filter(item => item.status === 'PENDING_REVIEW').length,
      rejected: items.filter(item => item.status === 'REJECTED').length
    };
  }, [items]);

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
      const result = await submitAbhEnrollment({
        idempotency_key: idempotencyKey,
        reporting_month: month,
        activity_date: activityDate,
        claimed_quantity: count,
        agent_note: note,
        evidence
      });
      setNotice(
        result.idempotent_replay
          ? `The earlier submission ${result.reference_no} was already received.`
          : `Submission received. Reference: ${result.reference_no}. It is now awaiting review.`
      );
      setQuantity('1');
      setNote('');
      setEvidence(null);
      if (fileRef.current) fileRef.current.value = '';
      resetRequestKey();
      await refreshHistory();
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

        <section className="grid gap-3 sm:grid-cols-3" aria-label="Monthly submission summary">
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Approved enrollments</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.approvedQuantity}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pending submissions</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.pending}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Rejected submissions</p>
            <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{summary.rejected}</p>
          </div>
        </section>

        <section className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
          <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="text-lg font-bold text-slate-900">Submit monthly enrollment</h2>
              <p className="mt-1 text-xs text-slate-500">Your account and approved browser device are linked to this report automatically.</p>
            </div>

            <div>
              <label htmlFor="abh-reporting-month" className="block text-sm font-semibold text-slate-700">Reporting month</label>
              <input id="abh-reporting-month" type="month" required max={currentMonth()} value={month}
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
              <Send size={17} aria-hidden="true" /> {busy ? 'Submitting evidence…' : 'Submit for review'}
            </button>
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
