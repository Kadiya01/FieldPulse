import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertCircle, CheckCircle2, ClipboardList, FileImage, LayoutDashboard, LogOut, RefreshCw, ShieldCheck, UserRound, Users, XCircle } from 'lucide-react';
import BrandHeader from '../components/BrandHeader';
import {
  adminLogin,
  adminLogout,
  bootstrapFirstAdmin,
  decideAbhAdminAgent,
  decideAbhAdminDevice,
  decideAbhAdminSubmission,
  fetchAbhAdminSubmissionEvidence,
  fetchAbhAdminSubmissions,
  fetchAbhAdminTargets,
  saveAbhAdminTarget,
  closeAbhReportingPeriod,
  fetchAbhAdminLeaderboard,
  fetchAbhAdminNotifications,
  markAbhAdminNotificationRead,
  markAllAbhAdminNotificationsRead,
  fetchAbhAdminAgentDetails,
  fetchAbhAdminAgents,
  fetchAbhAdminDashboard,
  restoreAdminSession,
  type AbhAdminDashboardData,
  type AdminAgentDetails,
  type AdminAgentItem,
  type AbhAdminSubmissionItem,
  type AbhAdminSubmissionStatus,
  type AbhAdminTargetData,
  type AbhLeaderboardData,
  type AbhNotificationItem,
  type SessionAdmin
} from '../api/client';

type AgentStatus = AdminAgentItem['status'] | 'ALL';

const statuses: AgentStatus[] = ['PENDING', 'ACTIVE', 'REJECTED', 'SUSPENDED', 'ALL'];

function AdminSignIn({ onAuthenticated }: { onAuthenticated: (admin: SessionAdmin) => void }) {
  const [mode, setMode] = useState<'login' | 'setup'>('login');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fullName, setFullName] = useState('');
  const [bootstrapKey, setBootstrapKey] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    try {
      if (mode === 'setup') {
        await bootstrapFirstAdmin({
          bootstrap_key: bootstrapKey,
          full_name: fullName.trim(),
          email: email.trim(),
          password
        });
        setNotice('The first administrator was created. Signing in now…');
      }
      const result = await adminLogin(email.trim(), password);
      onAuthenticated(result.admin);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Administrator sign in failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      <section className="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <BrandHeader className="mb-5 flex justify-center" />
        <div className="mb-5 flex items-center justify-center gap-2 text-blue-800">
          <ShieldCheck size={22} aria-hidden="true" />
          <span className="text-sm font-semibold uppercase tracking-wide">ABH secure administration</span>
        </div>
        <h1 className="text-center text-2xl font-bold text-slate-900">
          {mode === 'login' ? 'Administrator sign in' : 'Set up first administrator'}
        </h1>
        <p className="mt-2 text-center text-sm leading-6 text-slate-600">
          {mode === 'login'
            ? 'Use your ABH administrator email and password. Agent accounts cannot access this portal.'
            : 'This one-time setup requires the secret configured in the private .env file.'}
        </p>

        {error && <div role="alert" className="mt-5 rounded-xl bg-red-50 p-3 text-sm text-red-800">{error}</div>}
        {notice && <div role="status" className="mt-5 rounded-xl bg-blue-50 p-3 text-sm text-blue-800">{notice}</div>}

        <form onSubmit={submit} className="mt-6 space-y-4">
          {mode === 'setup' && (
            <>
              <div>
                <label htmlFor="admin-full-name" className="block text-sm font-semibold text-slate-800">Administrator full name</label>
                <input id="admin-full-name" required minLength={2} maxLength={160} value={fullName}
                  onChange={event => setFullName(event.target.value)}
                  className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" />
              </div>
              <div>
                <label htmlFor="bootstrap-key" className="block text-sm font-semibold text-slate-800">One-time setup key</label>
                <input id="bootstrap-key" type="password" required minLength={32} value={bootstrapKey}
                  autoComplete="off" onChange={event => setBootstrapKey(event.target.value)}
                  className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" />
              </div>
            </>
          )}
          <div>
            <label htmlFor="admin-email" className="block text-sm font-semibold text-slate-800">Administrator email</label>
            <input id="admin-email" type="email" autoComplete="username" required value={email}
              onChange={event => setEmail(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" />
          </div>
          <div>
            <label htmlFor="admin-password" className="block text-sm font-semibold text-slate-800">Password</label>
            <input id="admin-password" type="password" autoComplete="current-password" required minLength={8}
              value={password} onChange={event => setPassword(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100" />
          </div>
          <button type="submit" disabled={busy || !email.trim() || !password || (mode === 'setup' && (!bootstrapKey || !fullName.trim()))}
            className="w-full rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60">
            {busy ? 'Please wait…' : mode === 'login' ? 'Sign in securely' : 'Create first administrator'}
          </button>
        </form>

        <div className="mt-6 border-t border-slate-200 pt-5 text-center">
          {mode === 'login' ? (
            <button type="button" onClick={() => { setMode('setup'); setError(''); }}
              className="text-sm font-semibold text-blue-700 hover:underline">
              First-time setup
            </button>
          ) : (
            <div className="space-y-2">
              <p className="text-xs leading-5 text-slate-500">
                After successful setup, remove ABH_ADMIN_BOOTSTRAP_KEY from the private .env file.
                The database also prevents another first administrator from being created.
              </p>
              <button type="button" onClick={() => { setMode('login'); setError(''); setNotice(''); }}
                className="text-sm font-semibold text-blue-700 hover:underline">
                Return to administrator sign in
              </button>
            </div>
          )}
        </div>
      </section>
    </main>
  );
}

function AdminSubmissionQueue({ admin }: { admin: SessionAdmin }) {
  const [status, setStatus] = useState<AbhAdminSubmissionStatus>('PENDING_REVIEW');
  const [month, setMonth] = useState('');
  const [items, setItems] = useState<AbhAdminSubmissionItem[]>([]);
  const [total, setTotal] = useState(0);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [evidenceUrl, setEvidenceUrl] = useState<string | null>(null);
  const [evidenceLoading, setEvidenceLoading] = useState(false);
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(() => crypto.randomUUID());

  const canRead = admin.permissions.includes('submissions.read');
  const canReview = admin.permissions.includes('submissions.review');
  const selected = items.find(item => item.id === selectedId) ?? null;

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchAbhAdminSubmissions(status, month || undefined, 100, 0);
      setItems(result.items);
      setTotal(result.total);
      if (selectedId !== null && !result.items.some(item => item.id === selectedId)) {
        setSelectedId(null);
        setEvidenceUrl(null);
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The submission queue could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [status, month, selectedId]);

  useEffect(() => {
    if (!canRead) return;
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [canRead, load]);

  useEffect(() => {
    if (selectedId === null) return;
    let active = true;
    let objectUrl: string | null = null;
    const timer = window.setTimeout(() => {
      setEvidenceLoading(true);
      void fetchAbhAdminSubmissionEvidence(selectedId)
        .then(blob => {
          if (!active) return;
          objectUrl = URL.createObjectURL(blob);
          setEvidenceUrl(objectUrl);
        })
        .catch(caught => {
          if (active) setError(caught instanceof Error ? caught.message : 'Evidence could not be loaded.');
        })
        .finally(() => {
          if (active) setEvidenceLoading(false);
        });
    }, 0);

    return () => {
      active = false;
      window.clearTimeout(timer);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [selectedId]);

  const selectItem = (id: number) => {
    setSelectedId(id);
    setEvidenceUrl(null);
    setReason('');
    setError('');
    setNotice('');
  };

  const decide = async (decision: 'APPROVE' | 'REJECT' | 'REQUEST_CORRECTION') => {
    if (!selected) return;
    const note = reason.trim();
    if (decision !== 'APPROVE' && note.length < 2) {
      setError('Add a clear reason before rejecting or requesting a correction.');
      return;
    }

    setSaving(true);
    setError('');
    setNotice('');
    try {
      const result = await decideAbhAdminSubmission(
        selected.id,
        decision,
        note || undefined,
        idempotencyKey
      );
      setNotice(
        result.idempotent_replay
          ? `The earlier review of ${result.reference_no} was already recorded.`
          : `${result.reference_no} is now ${result.status.replaceAll('_', ' ').toLowerCase()}.`
      );
      setIdempotencyKey(crypto.randomUUID());
      setReason('');
      await load();
      if (decision !== 'APPROVE' || result.status !== 'APPROVED') {
        setSelectedId(null);
        setEvidenceUrl(null);
      }
    } catch (caught) {
      // Keep this key on failure so a retry after a lost response is idempotent.
      setError(caught instanceof Error ? caught.message : 'The review decision could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  if (!canRead) {
    return (
      <div className="mx-auto max-w-7xl px-4 py-8">
        <p className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
          Your administrator role does not include permission to read enrollment submissions.
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-5 px-4 py-6">
      <section className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Enrollment submissions</h1>
          <p className="mt-1 text-sm text-slate-600">
            Check the agent, reporting month, claimed quantity and evidence before recording a decision.
          </p>
        </div>
        <button type="button" onClick={() => void load()} disabled={loading}
          className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50">
          <RefreshCw size={15} aria-hidden="true" /> Refresh queue
        </button>
      </section>

      <section className="flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap gap-2">
          {(['PENDING_REVIEW', 'CORRECTION_REQUESTED', 'APPROVED', 'REJECTED', 'ALL'] as AbhAdminSubmissionStatus[]).map(value => (
            <button key={value} type="button" onClick={() => setStatus(value)}
              className={status === value
                ? 'rounded-full bg-blue-700 px-3 py-2 text-xs font-semibold text-white'
                : 'rounded-full border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700'}>
              {value === 'PENDING_REVIEW' ? 'Pending review' : value === 'CORRECTION_REQUESTED' ? 'Correction requested' : value === 'ALL' ? 'All' : value}
            </button>
          ))}
        </div>
        <label className="block text-sm font-semibold text-slate-700">
          Reporting month
          <input type="month" value={month} onChange={event => setMonth(event.target.value)}
            className="mt-1 block rounded-xl border border-slate-300 px-3 py-2 font-normal text-slate-900" />
        </label>
      </section>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
        <section className="min-w-0 space-y-3">
          <p className="text-xs text-slate-500">{total} matching submission(s)</p>
          {loading && <p role="status" className="rounded-xl bg-white p-5 text-sm text-slate-600">Loading submissions…</p>}
          {!loading && items.length === 0 && (
            <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center">
              <ClipboardList size={26} className="mx-auto text-slate-400" aria-hidden="true" />
              <p className="mt-3 text-sm font-semibold text-slate-800">No submissions in this view</p>
              <p className="mt-1 text-xs text-slate-500">Try another status or reporting month.</p>
            </div>
          )}
          {items.map(item => (
            <button key={item.id} type="button" onClick={() => selectItem(item.id)}
              className={selectedId === item.id
                ? 'w-full rounded-2xl border-2 border-blue-600 bg-white p-4 text-left shadow-sm'
                : 'w-full rounded-2xl border border-slate-200 bg-white p-4 text-left hover:border-slate-400'}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-semibold text-slate-900">{item.reference_no}</p>
                  <p className="mt-1 text-xs text-slate-600">{item.full_name} · {item.agent_code}</p>
                </div>
                <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                  {item.status.replaceAll('_', ' ')}
                </span>
              </div>
              <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-600">
                <span>{item.period_key}</span>
                <span>Activity {item.activity_date}</span>
                <span><strong className="text-slate-900">{item.claimed_quantity}</strong> enrollments</span>
              </div>
            </button>
          ))}
        </section>

        <aside className="min-w-0">
          <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-bold text-slate-900">Submission evidence and decision</h2>
            {!selected ? (
              <p className="mt-4 text-sm text-slate-500">Select a submission to inspect its details and evidence.</p>
            ) : (
              <>
                <div className="mt-4 space-y-2 border-b border-slate-200 pb-4">
                  <p className="font-semibold text-slate-900">{selected.reference_no}</p>
                  <p className="text-sm text-slate-700">{selected.full_name} · {selected.agent_code}</p>
                  <p className="text-xs text-slate-500">{selected.phone} · {selected.location_text || 'No location recorded'}</p>
                  <div className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 text-sm">
                    <div><p className="text-xs text-slate-500">Reporting month</p><p className="font-semibold">{selected.period_key}</p></div>
                    <div><p className="text-xs text-slate-500">Activity date</p><p className="font-semibold">{selected.activity_date}</p></div>
                    <div><p className="text-xs text-slate-500">Claimed quantity</p><p className="font-semibold tabular-nums">{selected.claimed_quantity}</p></div>
                    <div><p className="text-xs text-slate-500">Revision / reviews</p><p className="font-semibold">{selected.revision_no} / {selected.review_count}</p></div>
                  </div>
                  {selected.agent_note && (
                    <div><p className="text-xs font-semibold text-slate-500">Agent note</p><p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{selected.agent_note}</p></div>
                  )}
                  <p className="text-xs text-slate-500">
                    Submitted {new Date(selected.submitted_at.replace(' ', 'T').slice(0, 19) + 'Z').toLocaleString()} · {selected.original_filename}
                  </p>
                </div>

                <div className="py-4">
                  <div className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800">
                    <FileImage size={16} aria-hidden="true" /> Evidence image
                  </div>
                  {evidenceLoading && <p role="status" className="text-sm text-slate-500">Loading private evidence…</p>}
                  {!evidenceLoading && evidenceUrl && (
                    <img src={evidenceUrl} alt={`Evidence for ${selected.reference_no}`}
                      className="max-h-[440px] w-full rounded-xl border border-slate-200 bg-slate-50 object-contain" />
                  )}
                  {!evidenceLoading && !evidenceUrl && (
                    <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">Evidence is not available. Do not approve this report until the evidence can be inspected.</p>
                  )}
                </div>

                {canReview && selected.status === 'PENDING_REVIEW' && (
                  <div className="space-y-3 border-t border-slate-200 pt-4">
                    <label className="block text-sm font-semibold text-slate-700">
                      Review note {selected.status === 'PENDING_REVIEW' ? '(required for rejection or correction)' : ''}
                      <textarea value={reason} onChange={event => setReason(event.target.value)} rows={3} maxLength={2000}
                        placeholder="Explain what was checked, or why correction/rejection is required."
                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm font-normal" />
                    </label>
                    <div className="flex flex-wrap gap-2">
                      <button type="button" disabled={saving || !evidenceUrl}
                        onClick={() => void decide('APPROVE')}
                        className="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
                        Approve submission
                      </button>
                      <button type="button" disabled={saving || reason.trim().length < 2}
                        onClick={() => void decide('REQUEST_CORRECTION')}
                        className="rounded-xl border border-amber-400 px-4 py-2.5 text-sm font-semibold text-amber-800 disabled:opacity-50">
                        Request correction
                      </button>
                      <button type="button" disabled={saving || reason.trim().length < 2}
                        onClick={() => void decide('REJECT')}
                        className="rounded-xl border border-red-300 px-4 py-2.5 text-sm font-semibold text-red-700 disabled:opacity-50">
                        Reject
                      </button>
                    </div>
                    <p className="text-xs leading-5 text-slate-500">
                      Approval adds this revision's quantity to the agent's approved monthly total. A second approval is blocked.
                    </p>
                  </div>
                )}
                {canReview && selected.status !== 'PENDING_REVIEW' && (
                  <p className="border-t border-slate-200 pt-4 text-sm text-slate-500">
                    This submission already has a decision and cannot be reviewed again through this queue.
                  </p>
                )}
                {!canReview && (
                  <p className="border-t border-slate-200 pt-4 text-sm text-slate-500">
                    Your role can inspect evidence but does not have permission to record a review decision.
                  </p>
                )}
              </>
            )}
          </section>
        </aside>
      </div>

      {error && (
        <div role="alert" className="fixed bottom-4 right-4 flex max-w-lg items-start gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-800 shadow-lg">
          <AlertCircle size={18} className="mt-0.5 shrink-0" /> {error}
          <button type="button" onClick={() => setError('')} aria-label="Dismiss error"><XCircle size={18} /></button>
        </div>
      )}
      {notice && <p role="status" className="mx-auto max-w-7xl px-4 text-sm font-medium text-emerald-800">{notice}</p>}
    </div>
  );
}

function currentAdminDate(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function currentAdminMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function AdminTargetManagement({ admin }: { admin: SessionAdmin }) {
  const [month, setMonth] = useState(currentAdminMonth);
  const [data, setData] = useState<AbhAdminTargetData | null>(null);
  const [organisationQuantity, setOrganisationQuantity] = useState('');
  const [selectedAgentId, setSelectedAgentId] = useState('');
  const [agentQuantity, setAgentQuantity] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [confirmClose, setConfirmClose] = useState(false);

  const isClosed = data?.period?.status === 'CLOSED';
  const canManage = admin.permissions.includes('targets.manage');
  const canClose = admin.permissions.includes('periods.close');
  const pendingCount = data?.pending_submissions_count ?? 0;
  const monthHasEnded = data?.period ? data.period.ends_on < currentAdminDate() : false;

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchAbhAdminTargets(month);
      setData(result);
      setOrganisationQuantity(result.organisation_target === null ? '' : String(result.organisation_target));
      const nextAgentId = selectedAgentId !== '' && result.agents.some(agent => agent.id === Number(selectedAgentId))
        ? selectedAgentId
        : String(result.agents[0]?.id ?? '');
      setSelectedAgentId(nextAgentId);
      const target = result.agent_targets.find(item => item.agent_id === Number(nextAgentId));
      setAgentQuantity(target ? String(target.target_quantity) : '');
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Monthly targets could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [month, selectedAgentId]);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const saveOrganisation = async () => {
    const quantity = Number(organisationQuantity);
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 1000000000) {
      setError('Enter an organisation target between 1 and 1,000,000,000.');
      return;
    }
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await saveAbhAdminTarget({ period_key: month, scope_type: 'ORGANISATION', target_quantity: quantity });
      setNotice(`Organisation target for ${month} saved.`);
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The organisation target could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  const saveAgent = async () => {
    const quantity = Number(agentQuantity);
    const agentId = Number(selectedAgentId);
    if (!Number.isInteger(agentId) || agentId < 1) {
      setError('Select an active agent.');
      return;
    }
    if (!Number.isSafeInteger(quantity) || quantity < 1 || quantity > 1000000000) {
      setError('Enter an individual target between 1 and 1,000,000,000.');
      return;
    }
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await saveAbhAdminTarget({
        period_key: month,
        scope_type: 'AGENT',
        agent_id: agentId,
        target_quantity: quantity
      });
      setNotice('Individual target saved.');
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The individual target could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  const closePeriod = async () => {
    if (!canClose || !data?.period || data.period.status !== 'OPEN') return;
    if (pendingCount > 0 || !monthHasEnded) return;

    setSaving(true);
    setError('');
    setNotice('');
    try {
      const result = await closeAbhReportingPeriod(month);
      setConfirmClose(false);
      setNotice(
        `${result.period_key} is finalised. ${result.ranked_agent_count} agent(s) were included in the frozen leaderboard.`
      );
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The reporting month could not be finalised.');
    } finally {
      setSaving(false);
    }
  };

  const filteredTargets = (data?.agent_targets ?? []).filter(item => {
    const term = search.trim().toLowerCase();
    return term === '' || [item.full_name, item.agent_code, item.phone].some(value => value.toLowerCase().includes(term));
  });

  const chooseAgent = (id: string) => {
    setSelectedAgentId(id);
    const target = data?.agent_targets.find(item => item.agent_id === Number(id));
    setAgentQuantity(target ? String(target.target_quantity) : '');
  };

  if (!canManage && !canClose) {
    return (
      <div className="mx-auto max-w-7xl px-4 py-8">
        <p className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
          Your administrator role does not include permission to manage targets or close reporting months.
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-5 px-4 py-6">
      <section className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Monthly targets and finalisation</h1>
          <p className="mt-1 text-sm text-slate-600">{canManage ? 'Set organisation and individual goals.' : 'View period readiness and close completed reporting months.'} Target changes and finalisation are audited.</p>
        </div>
        <label className="block text-sm font-semibold text-slate-700">
          Reporting month
          <input type="month" value={month} onChange={event => setMonth(event.target.value)}
            className="mt-1 block rounded-xl border border-slate-300 bg-white px-3 py-2 font-normal text-slate-900" />
        </label>
      </section>

      <section className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Period status</p>
          <p className="mt-1 font-semibold text-slate-900">
            {data?.period ? data.period.status : 'Not created'}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            {data?.period
              ? `${data.period.starts_on} to ${data.period.ends_on} · ${data.period.timezone}`
              : 'Saving the first target creates this month as an OPEN reporting period.'}
          </p>
        </div>
        {isClosed && (
          <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
            Closed periods cannot be edited
          </span>
        )}
        {!isClosed && data?.period && (
          <div className="text-right">
            <p className="text-xs font-semibold text-slate-700">{pendingCount} submission(s) awaiting review or correction</p>
            <p className="mt-1 text-xs text-slate-500">
              A month cannot be finalised until all submissions are reviewed and the calendar month has ended.
            </p>
          </div>
        )}
      </section>

      {loading && <p role="status" className="rounded-xl bg-white p-4 text-sm text-slate-600">Loading targets…</p>}

      {canManage && (
      <>
      <div className="grid gap-5 lg:grid-cols-2">
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-lg font-bold text-slate-900">Organisation target</h2>
          <p className="mt-1 text-sm text-slate-600">One approved-enrollment goal for the whole organisation.</p>
          <label className="mt-4 block text-sm font-semibold text-slate-700">
            Monthly enrollment target
            <input type="number" min={1} max={1000000000} step={1} value={organisationQuantity}
              onChange={event => setOrganisationQuantity(event.target.value)}
              disabled={isClosed || saving}
              className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900 disabled:bg-slate-100" />
          </label>
          <button type="button" onClick={() => void saveOrganisation()}
            disabled={loading || saving || isClosed || !organisationQuantity}
            className="mt-4 rounded-xl bg-blue-700 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            {saving ? 'Saving…' : 'Save organisation target'}
          </button>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-lg font-bold text-slate-900">Individual agent target</h2>
          <p className="mt-1 text-sm text-slate-600">Set or update a monthly goal for an active approved agent.</p>
          <label className="mt-4 block text-sm font-semibold text-slate-700">
            Agent
            <select value={selectedAgentId} onChange={event => chooseAgent(event.target.value)}
              disabled={isClosed || saving || !data?.agents.length}
              className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900 disabled:bg-slate-100">
              <option value="">Select an active agent</option>
              {(data?.agents ?? []).map(agent => (
                <option key={agent.id} value={agent.id}>{agent.full_name} · {agent.agent_code}</option>
              ))}
            </select>
          </label>
          <label className="mt-4 block text-sm font-semibold text-slate-700">
            Monthly enrollment target
            <input type="number" min={1} max={1000000000} step={1} value={agentQuantity}
              onChange={event => setAgentQuantity(event.target.value)}
              disabled={isClosed || saving || !selectedAgentId}
              className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-900 disabled:bg-slate-100" />
          </label>
          <button type="button" onClick={() => void saveAgent()}
            disabled={loading || saving || isClosed || !selectedAgentId || !agentQuantity}
            className="mt-4 rounded-xl bg-blue-700 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">
            {saving ? 'Saving…' : 'Save individual target'}
          </button>
        </section>
      </div>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-slate-900">Assigned agent targets</h2>
            <p className="mt-1 text-xs text-slate-500">{data?.agent_targets.length ?? 0} individual target(s) for {month}</p>
          </div>
          <input type="search" value={search} onChange={event => setSearch(event.target.value)}
            placeholder="Filter by agent, code or phone"
            className="w-full max-w-sm rounded-xl border border-slate-300 px-3 py-2 text-sm" />
        </div>
        {filteredTargets.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 p-5 text-sm text-slate-600">No individual targets have been assigned in this month.</p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="py-3 pr-4">Agent</th><th className="py-3 pr-4">Code</th><th className="py-3 pr-4">Phone</th><th className="py-3 text-right">Target</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredTargets.map(item => (
                  <tr key={item.target_id}>
                    <td className="py-3 pr-4 font-medium text-slate-900">{item.full_name}</td>
                    <td className="py-3 pr-4 text-slate-600">{item.agent_code}</td>
                    <td className="py-3 pr-4 text-slate-600">{item.phone}</td>
                    <td className="py-3 text-right font-semibold tabular-nums text-slate-900">{item.target_quantity.toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      </>
      )}

      {canClose && !isClosed && data?.period?.status === 'OPEN' && (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-lg font-bold text-slate-900">Finalise reporting month</h2>
          <p className="mt-1 text-sm text-slate-600">
            Finalisation freezes approved totals, the organisation result and every agent's monthly rank.
          </p>
          <div className="mt-3 space-y-1 text-sm">
            <p className="text-slate-700">Unreviewed submissions: <strong>{pendingCount}</strong></p>
            <p className="text-slate-700">Calendar month ended: <strong>{monthHasEnded ? 'Yes' : 'No'}</strong></p>
          </div>
          {!confirmClose ? (
            <button type="button" onClick={() => setConfirmClose(true)}
              disabled={saving || loading || pendingCount > 0 || !monthHasEnded}
              className="mt-4 rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40">
              Finalise {month}
            </button>
          ) : (
            <div className="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4">
              <p className="font-semibold text-amber-950">Confirm monthly finalisation</p>
              <p className="mt-2 text-sm leading-6 text-amber-900">
                This action is permanent. Agents will no longer be able to submit or correct reports for {month}.
                Review decisions and target changes will also be blocked. The approved results and rankings will be saved as final snapshots.
              </p>
              <div className="mt-4 flex flex-wrap gap-2">
                <button type="button" onClick={() => void closePeriod()} disabled={saving}
                  className="rounded-xl bg-amber-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
                  {saving ? 'Finalising…' : 'Yes, finalise month'}
                </button>
                <button type="button" onClick={() => setConfirmClose(false)} disabled={saving}
                  className="rounded-xl border border-amber-500 px-4 py-2.5 text-sm font-semibold text-amber-950">
                  Cancel
                </button>
              </div>
            </div>
          )}
        </section>
      )}

      {isClosed && data?.organisation_snapshot && (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-lg font-bold text-slate-900">Frozen organisation result</h2>
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            <div><p className="text-xs text-slate-500">Approved enrollments</p><p className="mt-1 text-2xl font-bold tabular-nums">{data.organisation_snapshot.approved_quantity.toLocaleString()}</p></div>
            <div><p className="text-xs text-slate-500">Organisation target</p><p className="mt-1 text-2xl font-bold tabular-nums">{data.organisation_snapshot.target_quantity?.toLocaleString() ?? 'Not set'}</p></div>
            <div><p className="text-xs text-slate-500">Target progress</p><p className="mt-1 text-2xl font-bold tabular-nums">{data.organisation_snapshot.progress_percent === null ? '—' : `${data.organisation_snapshot.progress_percent}%`}</p></div>
          </div>
          <p className="mt-3 text-xs text-slate-500">
            {data.organisation_snapshot.agent_count} agent(s) ranked · Finalised {new Date(data.organisation_snapshot.closed_at.replace(' ', 'T').slice(0, 19) + 'Z').toLocaleString()}
          </p>
        </section>
      )}

      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{notice}</p>}
    </div>
  );
}

function AdminLeaderboardView() {
  const [month, setMonth] = useState(currentAdminMonth);
  const [data, setData] = useState<AbhLeaderboardData | null>(null);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setData(await fetchAbhAdminLeaderboard(month, 100, 0));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The monthly leaderboard could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [month]);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const filtered = (data?.items ?? []).filter(item => {
    const term = search.trim().toLowerCase();
    return term === '' || item.display_name.toLowerCase().includes(term) || item.agent_code.toLowerCase().includes(term);
  });

  return (
    <div className="mx-auto max-w-7xl space-y-5 px-4 py-6">
      <section className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Monthly leaderboard</h1>
          <p className="mt-1 text-sm text-slate-600">
            {data?.period_status === 'CLOSED'
              ? 'Final ranking from immutable month-end snapshots.'
              : 'Live ranking based on administrator-approved enrollment quantities.'}
          </p>
        </div>
        <label className="block text-sm font-semibold text-slate-700">
          Reporting month
          <input type="month" value={month} onChange={event => setMonth(event.target.value)}
            className="mt-1 block rounded-xl border border-slate-300 bg-white px-3 py-2 font-normal text-slate-900" />
        </label>
      </section>

      <section className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-2xl border border-slate-200 bg-white p-5">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Reporting period</p>
          <p className="mt-2 text-xl font-bold text-slate-900">{data?.period_status ?? 'Not created'}</p>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Ranked agents</p>
          <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{data?.total ?? 0}</p>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Ranking rule</p>
          <p className="mt-2 text-sm font-semibold text-slate-900">Equal totals share the same rank</p>
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-bold text-slate-900">Approved agent performance</h2>
          <div className="flex flex-wrap items-center gap-2">
            <input type="search" value={search} onChange={event => setSearch(event.target.value)}
              placeholder="Filter agent or code"
              className="rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            <button type="button" onClick={() => void load()} disabled={loading}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50">
              <RefreshCw size={14} aria-hidden="true" /> Refresh
            </button>
          </div>
        </div>

        {loading ? (
          <p role="status" className="py-8 text-center text-sm text-slate-500">Loading monthly results…</p>
        ) : filtered.length === 0 ? (
          <p className="mt-4 rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-600">No approved enrollments are ranked for this month.</p>
        ) : (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[640px] text-left text-sm">
              <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="py-3 pr-4">Rank</th><th className="py-3 pr-4">Agent</th><th className="py-3 pr-4">Agent code</th><th className="py-3 pr-4 text-right">Approved enrollments</th><th className="py-3 text-right">Target progress</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map(item => (
                  <tr key={item.agent_id}>
                    <td className="py-3 pr-4 font-bold tabular-nums text-slate-900">#{item.rank_position}</td>
                    <td className="py-3 pr-4 font-semibold text-slate-900">{item.display_name}</td>
                    <td className="py-3 pr-4 text-slate-600">{item.agent_code}</td>
                    <td className="py-3 pr-4 text-right font-semibold tabular-nums text-slate-900">{item.approved_quantity.toLocaleString()}</td>
                    <td className="py-3 text-right tabular-nums text-slate-700">
                      {item.progress_percent === null ? '—' : `${item.progress_percent}%`}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
        {data && data.total > data.items.length && (
          <p className="mt-3 text-xs text-slate-500">Showing {data.items.length} of {data.total} ranked agents.</p>
        )}
      </section>
    </div>
  );
}

function AdminNotificationsView() {
  const [items, setItems] = useState<AbhNotificationItem[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const result = await fetchAbhAdminNotifications(50, 0);
      setItems(result.items);
      setUnreadCount(result.unread_count);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Administrator notifications could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => { void load(); }, 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const markOne = async (id: number) => {
    setBusy(true);
    setError('');
    try {
      await markAbhAdminNotificationRead(id);
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The notification could not be marked as read.');
    } finally {
      setBusy(false);
    }
  };

  const markAll = async () => {
    setBusy(true);
    setError('');
    try {
      await markAllAbhAdminNotificationsRead();
      await load();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Notifications could not be marked as read.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="mx-auto max-w-7xl space-y-5 px-4 py-6">
      <section className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Administrator notifications</h1>
          <p className="mt-1 text-sm text-slate-600">{unreadCount} unread notification(s)</p>
        </div>
        <div className="flex items-center gap-2">
          <button type="button" onClick={() => void load()} disabled={loading || busy}
            className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50">
            Refresh
          </button>
          {unreadCount > 0 && (
            <button type="button" onClick={() => void markAll()} disabled={busy}
              className="rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
              Mark all read
            </button>
          )}
        </div>
      </section>

      {loading ? (
        <p role="status" className="rounded-xl bg-white p-5 text-sm text-slate-600">Loading notifications…</p>
      ) : items.length === 0 ? (
        <p className="rounded-2xl border border-slate-200 bg-white p-8 text-sm text-slate-600">No administrator notifications yet.</p>
      ) : (
        <div className="space-y-3">
          {items.map(item => (
            <article key={item.id} className={item.read_at ? 'rounded-2xl border border-slate-200 bg-white p-5' : 'rounded-2xl border border-blue-200 bg-blue-50 p-5'}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="font-semibold text-slate-900">{item.title}</p>
                  <p className="mt-1 text-sm leading-6 text-slate-700">{item.message}</p>
                  <p className="mt-2 text-xs text-slate-500">{new Date(item.created_at.replace(' ', 'T').slice(0, 19) + 'Z').toLocaleString()}</p>
                </div>
                {!item.read_at && (
                  <button type="button" onClick={() => void markOne(item.id)} disabled={busy}
                    className="rounded-xl border border-blue-300 px-3 py-2 text-xs font-semibold text-blue-800 disabled:opacity-50">
                    Mark read
                  </button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
    </div>
  );
}

function formatDashboardNumber(value: number): string {
  return new Intl.NumberFormat('en-NG').format(value);
}

function currentDashboardMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function AdminOverview() {
  const [month, setMonth] = useState(currentDashboardMonth);
  const [refreshVersion, setRefreshVersion] = useState(0);
  const [data, setData] = useState<AbhAdminDashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let live = true;
    setLoading(true);
    setError('');
    fetchAbhAdminDashboard(month)
      .then(result => { if (live) setData(result); })
      .catch(caught => {
        if (live) setError(caught instanceof Error ? caught.message : 'Could not load dashboard summaries.');
      })
      .finally(() => { if (live) setLoading(false); });
    return () => { live = false; };
  }, [month, refreshVersion]);

  const metrics: Array<{ label: string; value: string; detail: string }> = [];
  if (data?.agents) {
    metrics.push(
      { label: 'Registered agents', value: formatDashboardNumber(data.agents.total), detail: `${formatDashboardNumber(data.agents.active)} active` },
      { label: 'Pending applications', value: formatDashboardNumber(data.agents.pending), detail: 'Awaiting administrator review' },
      { label: 'Suspended agents', value: formatDashboardNumber(data.agents.suspended), detail: `${formatDashboardNumber(data.agents.rejected)} rejected` }
    );
  }
  if (data?.submissions) {
    metrics.push(
      { label: 'Reports submitted', value: formatDashboardNumber(data.submissions.total), detail: `${formatDashboardNumber(data.submissions.claimed_quantity)} claimed enrollments` },
      { label: 'Awaiting review', value: formatDashboardNumber(data.submissions.pending_review + data.submissions.correction_requested), detail: `${formatDashboardNumber(data.submissions.pending_review)} pending · ${formatDashboardNumber(data.submissions.correction_requested)} corrections` },
      { label: 'Approved reports', value: formatDashboardNumber(data.submissions.approved), detail: `${formatDashboardNumber(data.submissions.rejected)} rejected` }
    );
  }
  if (data?.performance) {
    metrics.push({
      label: 'Approved enrollments',
      value: formatDashboardNumber(data.performance.approved_quantity),
      detail: data.performance.organisation_target === null
        ? 'Organisation target not set'
        : `Target: ${formatDashboardNumber(data.performance.organisation_target)} · ${data.performance.progress_percent ?? '0.00'}%`
    });
  }
  if (data?.rewards) {
    const programCount = Object.values(data.rewards.programs_by_status).reduce((sum, value) => sum + value, 0);
    const eligibleCount = data.rewards.qualifications_by_status.ELIGIBLE ?? 0;
    const pendingAwards = data.rewards.awards_by_status.PENDING_APPROVAL ?? 0;
    metrics.push(
      { label: 'Reward programmes', value: formatDashboardNumber(programCount), detail: 'For the selected reporting month' },
      { label: 'Eligible agents', value: formatDashboardNumber(eligibleCount), detail: 'Calculated by the reward rules' },
      { label: 'Awards awaiting approval', value: formatDashboardNumber(pendingAwards), detail: `${formatDashboardNumber(data.rewards.paid_events)} paid · ${formatDashboardNumber(data.rewards.delivered_events)} delivered` }
    );
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Administration overview</h1>
          <p className="mt-1 text-sm text-slate-600">Live account, enrollment, performance and reward summaries from the ABH database.</p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs font-semibold text-slate-600">
            Reporting month
            <input type="month" value={month} onChange={event => setMonth(event.target.value)}
              className="mt-1 block rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800" />
          </label>
          <button type="button" onClick={() => setRefreshVersion(value => value + 1)} disabled={loading}
            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-60">
            <RefreshCw size={16} aria-hidden="true" /> Refresh
          </button>
        </div>
      </div>

      {data?.period ? (
        <p className="text-sm text-slate-600">
          Period {data.period.period_key} · {data.period.status === 'CLOSED' ? 'Finalised' : 'Open'}
        </p>
      ) : data && (
        <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
          No reporting period has been created for {month}. Monthly totals are shown as zero until the period is opened.
        </p>
      )}

      {loading && <p role="status" className="text-sm text-slate-500">Loading live summaries…</p>}
      {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}

      {data && (
        <>
          <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {metrics.map(metric => (
              <article key={metric.label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p className="text-sm font-medium text-slate-500">{metric.label}</p>
                <p className="mt-2 text-3xl font-bold tabular-nums text-slate-900">{metric.value}</p>
                <p className="mt-2 text-xs text-slate-500">{metric.detail}</p>
              </article>
            ))}
          </section>

          {data.performance && (
            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="mb-4">
                <h2 className="text-lg font-bold text-slate-900">Top agents this month</h2>
                <p className="text-sm text-slate-500">Ranked by approved enrollments, not unreviewed submissions.</p>
              </div>
              {data.performance.top_agents.length === 0 ? (
                <p className="text-sm text-slate-500">No approved agent totals are recorded for this month yet.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[560px] text-left text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                      <tr><th className="py-2 pr-3">Rank</th><th className="py-2 pr-3">Agent</th><th className="py-2 pr-3">Approved enrollments</th><th className="py-2">Agent target</th></tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {data.performance.top_agents.map(agent => (
                        <tr key={agent.agent_id}>
                          <td className="py-3 pr-3 font-semibold text-slate-700">#{agent.rank_position}</td>
                          <td className="py-3 pr-3"><span className="font-semibold text-slate-900">{agent.full_name}</span><span className="block text-xs text-slate-500">{agent.agent_code}</span></td>
                          <td className="py-3 pr-3 tabular-nums font-semibold text-slate-900">{formatDashboardNumber(agent.approved_quantity)}</td>
                          <td className="py-3 tabular-nums text-slate-600">{agent.target_quantity === null ? 'Not set' : `${formatDashboardNumber(agent.target_quantity)} (${agent.progress_percent ?? '0.00'}%)`}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>
          )}
        </>
      )}
    </div>
  );
}

function AdminHome({ admin, onSignOut }: { admin: SessionAdmin; onSignOut: () => void }) {
  const canViewOverview = [
    'agents.read', 'submissions.read', 'targets.manage', 'periods.close', 'rewards.manage', 'rewards.approve'
  ].some(permission => admin.permissions.includes(permission));
  const [activeSection, setActiveSection] = useState<'overview' | 'agents' | 'submissions' | 'targets' | 'leaderboard' | 'notifications'>(() =>
    canViewOverview
      ? 'overview'
      : 'notifications'
  );
  const [status, setStatus] = useState<AgentStatus>('PENDING');
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [items, setItems] = useState<AdminAgentItem[]>([]);
  const [total, setTotal] = useState(0);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [details, setDetails] = useState<AdminAgentDetails | null>(null);
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const canReadAgents = admin.permissions.includes('agents.read');
  const canReadDevices = admin.permissions.includes('devices.read');

  const loadAgents = useCallback(async () => {
    setLoading(true);
    setError('');
    setError('');
    try {
      const result = await fetchAbhAdminAgents(status, search);
      setItems(result.items);
      setTotal(result.total);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load agent applications.');
    } finally {
      setLoading(false);
    }
  }, [status, search]);

  const loadDetails = useCallback(async (id: number) => {
    setSelectedId(id);
    setDetails(null);
    setReason('');
    try {
      const result = await fetchAbhAdminAgentDetails(id);
      setDetails(result);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load agent details.');
    }
  }, []);

  useEffect(() => {
    if (!canReadAgents) return;
    const timer = window.setTimeout(() => { void loadAgents(); }, 0);
    return () => window.clearTimeout(timer);
  }, [canReadAgents, loadAgents]);

  const agentAction = async (decision: 'APPROVE' | 'REJECT' | 'SUSPEND' | 'REACTIVATE') => {
    if (!selectedId) return;
    if (['REJECT', 'SUSPEND'].includes(decision) && reason.trim().length < 2) {
      setError('Enter a reason before rejecting or suspending an agent.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await decideAbhAdminAgent(selectedId, decision, reason.trim() || undefined);
      await loadAgents();
      await loadDetails(selectedId);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The agent status could not be changed.');
    } finally {
      setSaving(false);
    }
  };

  const deviceAction = async (deviceId: number, decision: 'APPROVE' | 'REVOKE') => {
    if (decision === 'REVOKE' && reason.trim().length < 2) {
      setError('Enter a reason before revoking a device.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await decideAbhAdminDevice(deviceId, decision, reason.trim() || undefined);
      if (selectedId) await loadDetails(selectedId);
      await loadAgents();
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'The device status could not be changed.');
    } finally {
      setSaving(false);
    }
  };

  const permission = (key: string) => admin.permissions.includes(key);
  const roleLabel = admin.roles.map(role => role.replaceAll('_', ' ')).join(', ');

  return (
    <main className="min-h-screen bg-slate-100">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4">
          <div className="flex items-center gap-3">
            <BrandHeader />
            <div className="hidden border-l border-slate-200 pl-3 sm:block">
              <p className="font-bold text-slate-900">ABH Admin Portal</p>
              <p className="text-xs text-slate-500">{roleLabel}</p>
            </div>
          </div>
          <div className="flex items-center gap-3">
            <div className="hidden text-right sm:block">
              <p className="text-sm font-semibold text-slate-800">{admin.full_name}</p>
              <p className="text-xs text-slate-500">{admin.email}</p>
            </div>
            <button type="button" onClick={onSignOut}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
              <LogOut size={16} aria-hidden="true" /> Sign out
            </button>
          </div>
        </div>
      </header>

      <nav aria-label="Administrator sections" className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl gap-2 px-4 py-3">
          {canViewOverview && (
            <button type="button" onClick={() => setActiveSection('overview')}
              className={activeSection === 'overview' ? 'inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'inline-flex items-center gap-2 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
              <LayoutDashboard size={16} aria-hidden="true" /> Overview
            </button>
          )}
          {canReadAgents && (
            <button type="button" onClick={() => setActiveSection('agents')}
              className={activeSection === 'agents' ? 'rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
              Agent applications
            </button>
          )}
          <button type="button" onClick={() => setActiveSection('notifications')}
            className={activeSection === 'notifications' ? 'rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
            Notifications
          </button>
          {admin.permissions.includes('submissions.read') && (
            <button type="button" onClick={() => setActiveSection('submissions')}
              className={activeSection === 'submissions' ? 'rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
              Enrollment review
            </button>
          )}
          {admin.permissions.includes('submissions.read') && (
            <button type="button" onClick={() => setActiveSection('leaderboard')}
              className={activeSection === 'leaderboard' ? 'rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
              Leaderboard
            </button>
          )}
          {(admin.permissions.includes('targets.manage') || admin.permissions.includes('periods.close')) && (
            <button type="button" onClick={() => setActiveSection('targets')}
              className={activeSection === 'targets' ? 'rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white' : 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700'}>
              Monthly targets
            </button>
          )}
        </div>
      </nav>

      {activeSection === 'overview' ? (
        <AdminOverview />
      ) : activeSection === 'notifications' ? (
        <AdminNotificationsView />
      ) : activeSection === 'submissions' ? (
        <AdminSubmissionQueue admin={admin} />
      ) : activeSection === 'leaderboard' ? (
        <AdminLeaderboardView />
      ) : activeSection === 'targets' ? (
        <AdminTargetManagement admin={admin} />
      ) : (
      <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 lg:grid-cols-[minmax(0,1fr)_minmax(350px,0.9fr)]">
        <section className="min-w-0">
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-2xl font-bold text-slate-900">Agent applications</h1>
              <p className="mt-1 text-sm text-slate-600">Review account status and registered device authorisation separately.</p>
            </div>
            <button type="button" onClick={() => void loadAgents()} disabled={loading}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
              <RefreshCw size={16} aria-hidden="true" /> Refresh
            </button>
          </div>

          {!canReadAgents ? (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
              Your administrator role does not include access to agent applications.
            </div>
          ) : (
            <>
              <div className="mb-4 flex flex-wrap gap-2">
                {statuses.map(item => (
                  <button key={item} type="button" onClick={() => setStatus(item)}
                    className={status === item
                      ? 'rounded-full bg-blue-700 px-3 py-2 text-xs font-semibold text-white'
                      : 'rounded-full border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700'}>
                    {item === 'ALL' ? 'All agents' : item}
                  </button>
                ))}
              </div>

              <form onSubmit={event => { event.preventDefault(); setSearch(searchInput.trim()); }}
                className="mb-4 flex gap-2">
                <input value={searchInput} onChange={event => setSearchInput(event.target.value)}
                  placeholder="Search name, phone, code or location"
                  className="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm outline-none focus:border-blue-600" />
                <button type="submit" className="rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white">Search</button>
              </form>

              <p className="mb-3 text-xs text-slate-500">{total} matching agent(s)</p>
              <div className="space-y-3">
                {loading && <p role="status" className="rounded-xl bg-white p-5 text-sm text-slate-600">Loading applications…</p>}
                {!loading && items.length === 0 && <p className="rounded-xl bg-white p-5 text-sm text-slate-600">No applications match this filter.</p>}
                {items.map(item => (
                  <button key={item.id} type="button" onClick={() => void loadDetails(item.id)}
                    className={selectedId === item.id
                      ? 'w-full rounded-2xl border-2 border-blue-600 bg-white p-4 text-left shadow-sm'
                      : 'w-full rounded-2xl border border-slate-200 bg-white p-4 text-left hover:border-slate-400'}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="flex min-w-0 items-start gap-3">
                        <span className="rounded-xl bg-slate-100 p-2 text-slate-600"><UserRound size={20} /></span>
                        <div className="min-w-0">
                          <p className="truncate font-semibold text-slate-900">{item.full_name}</p>
                          <p className="text-xs text-slate-500">{item.agent_code} · {item.phone}</p>
                          <p className="mt-1 text-xs text-slate-500">{item.location_text || 'Location not supplied'}</p>
                        </div>
                      </div>
                      <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{item.status}</span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-4 text-xs text-slate-600">
                      <span><Users size={14} className="mr-1 inline" /> {item.device_count} device(s)</span>
                      <span>{item.pending_device_count} pending device(s)</span>
                      <span>{item.active_device_count} authorised</span>
                    </div>
                  </button>
                ))}
              </div>
            </>
          )}
        </section>

        <aside className="min-w-0">
          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-bold text-slate-900">Application details</h2>
            {!details ? (
              <p className="mt-4 text-sm text-slate-500">Select an agent to inspect their application and registered devices.</p>
            ) : (
              <>
                <div className="mt-4 border-b border-slate-200 pb-4">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="font-bold text-slate-900">{details.agent.full_name}</h3>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold">{details.agent.status}</span>
                  </div>
                  <p className="mt-1 text-xs text-slate-500">{details.agent.agent_code} · {details.agent.phone}</p>
                  <p className="mt-1 text-xs text-slate-500">{details.agent.email || 'No email'} · {details.agent.location_text || 'No location'}</p>
                  {details.agent.status_reason && <p className="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">Status reason: {details.agent.status_reason}</p>}
                  {permission('agents.approve') && details.agent.status === 'PENDING' && (
                    <button type="button" disabled={saving} onClick={() => void agentAction('APPROVE')}
                      className="mt-4 inline-flex items-center gap-2 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">
                      <CheckCircle2 size={16} /> Approve account
                    </button>
                  )}
                  {(permission('agents.reject') && details.agent.status === 'PENDING')
                    || (permission('agents.suspend') && details.agent.status === 'ACTIVE') ? (
                      <div className="mt-4 space-y-2">
                        <label className="block text-xs font-semibold text-slate-600">Reason for rejection, suspension or device revocation</label>
                        <textarea value={reason} onChange={event => setReason(event.target.value)} rows={2}
                          className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                        <div className="flex gap-2">
                          {permission('agents.reject') && details.agent.status === 'PENDING' && (
                            <button type="button" disabled={saving || reason.trim().length < 2} onClick={() => void agentAction('REJECT')}
                              className="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 disabled:opacity-50">Reject</button>
                          )}
                          {permission('agents.suspend') && details.agent.status === 'ACTIVE' && (
                            <button type="button" disabled={saving || reason.trim().length < 2} onClick={() => void agentAction('SUSPEND')}
                              className="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 disabled:opacity-50">Suspend</button>
                          )}
                        </div>
                      </div>
                    ) : null}
                  {permission('agents.suspend') && details.agent.status === 'SUSPENDED' && (
                    <button type="button" disabled={saving} onClick={() => void agentAction('REACTIVATE')}
                      className="mt-4 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-60">Reactivate account</button>
                  )}
                </div>

                <div className="pt-4">
                  <h3 className="font-semibold text-slate-900">Registered devices</h3>
                  {!canReadDevices ? (
                    <p className="mt-2 text-xs text-slate-500">Your role cannot inspect device records.</p>
                  ) : details.devices.length === 0 ? (
                    <p className="mt-2 text-xs text-slate-500">No device registrations found.</p>
                  ) : (
                    <div className="mt-3 space-y-3">
                      {details.devices.map(device => (
                        <div key={device.id} className="rounded-xl border border-slate-200 p-3">
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-sm font-semibold text-slate-800">{device.device_type || 'Registered device'}</p>
                            <span className="text-xs font-semibold text-slate-500">{device.status}</span>
                          </div>
                          <p className="mt-1 font-mono text-xs text-slate-600">IMEI: {device.imei_value}</p>
                          <p className="mt-1 text-xs text-slate-500">Registered {new Date(device.registered_at).toLocaleString()}</p>
                          {permission('devices.approve') && device.status === 'PENDING' && details.agent.status === 'ACTIVE' && (
                            <button type="button" disabled={saving} onClick={() => void deviceAction(device.id, 'APPROVE')}
                              className="mt-3 rounded-lg bg-blue-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60">Authorise device</button>
                          )}
                          {permission('devices.revoke') && device.status === 'ACTIVE' && (
                            <button type="button" disabled={saving || reason.trim().length < 2}
                              onClick={() => void deviceAction(device.id, 'REVOKE')}
                              className="mt-3 rounded-lg border border-red-300 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-50">Revoke device</button>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </>
            )}
          </div>
        </aside>
      </div>
      )}
      {error && (
        <div role="alert" className="fixed bottom-4 right-4 flex max-w-lg items-start gap-2 rounded-xl bg-red-50 p-4 text-sm text-red-800 shadow-lg">
          <AlertCircle size={18} className="mt-0.5 shrink-0" /> {error}
          <button type="button" onClick={() => setError('')} aria-label="Dismiss error"><XCircle size={18} /></button>
        </div>
      )}
    </main>
  );
}

export default function AdminPortalPage() {
  const [admin, setAdmin] = useState<SessionAdmin | null>(null);
  const [checking, setChecking] = useState(true);
  const navigate = useNavigate();

  useEffect(() => {
    let live = true;
    const onAdminFailure = () => setAdmin(null);
    window.addEventListener('admin_auth_failure', onAdminFailure);
    restoreAdminSession()
      .then(value => { if (live) setAdmin(value); })
      .finally(() => { if (live) setChecking(false); });
    return () => {
      live = false;
      window.removeEventListener('admin_auth_failure', onAdminFailure);
    };
  }, []);

  if (checking) {
    return <main className="min-h-screen bg-slate-950 flex items-center justify-center text-white">Restoring administrator session…</main>;
  }

  const signOut = async () => {
    try { await adminLogout(); } finally {
      setAdmin(null);
      navigate('/admin/login', { replace: true });
    }
  };

  return admin
    ? <AdminHome admin={admin} onSignOut={() => void signOut()} />
    : <AdminSignIn onAuthenticated={value => { setAdmin(value); navigate('/admin', { replace: true }); }} />;
}
