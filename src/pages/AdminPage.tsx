import { useEffect, useState, type FormEvent } from 'react';
import {
  fetchAdminAgents,
  fetchAdminDashboard,
  fetchAdminTargets,
  fetchAdminPeriods,
  fetchAdminAudit,
  fetchAdminAgentDetail,
  createAdminAgent,
  actOnAdminAgent,
  issuePairingCode,
  saveAdminTarget,
  clearAdminTarget,
  closeAdminPeriod,
  ApiError,
  type AdminAgent,
  type AdminAgentAction,
  type AdminAgentRole,
  type AdminAgentFilters,
  type AdminAgentList,
  type AdminAgentDetail,
  type AdminDashboard,
  type AdminTarget,
  type AdminTargetsResponse,
  type AdminPeriodRow,
  type AdminPeriodsResponse,
  type AdminAuditEntry,
  type CreateAdminAgentInput,
  type IssuePairingCodeResult
} from '../api/client';
import { useSession } from '../auth/sessionContext';
import {
  ShieldAlert,
  RefreshCw,
  Loader2,
  ChevronLeft,
  ChevronRight,
  Ban,
  Archive,
  RotateCcw,
  KeyRound,
  Smartphone,
  Copy,
  Check,
  CheckCircle2,
  XCircle,
  X,
  Plus,
  Search,
  Target,
  CalendarDays,
  Activity,
  Eye,
  Trash2
} from 'lucide-react';

const LIMIT = 50;

const EMPTY_FILTERS: AdminAgentFilters = {};

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'Any status' },
  { value: 'PENDING', label: 'Pending approval' },
  { value: 'ACTIVE', label: 'Active' },
  { value: 'INACTIVE', label: 'Inactive' },
  { value: 'REJECTED', label: 'Registration rejected' },
  { value: 'SUSPENDED', label: 'Suspended' },
  { value: 'DELETED', label: 'Retired' }
];

function currentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function formatMonth(value: string): string {
  if (!/^\d{4}-\d{2}/.test(value)) {
    return value;
  }

  const [year, month] = value.slice(0, 7).split('-').map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString(undefined, {
    month: 'long',
    year: 'numeric'
  });
}

/**
 * The account directory.
 *
 * A client that could be opened by a non-administrator is a screen that renders
 * a 403 panel. The route checks the role first and refuses to fetch at all, and
 * the server rejects with 403 on every call — this check is presentation, not a
 * security boundary, and the server's `admin` tier is the enforcement.
 *
 * The destructive controls here are deliberately restrained: the self-guard and
 * the last-admin guard are mirrored as disabled controls (using the directory's
 * own `requested_by` and `active_admins` figures), because a disabled control
 * teaches the administrator the constraint before the server has to refuse it.
 * The server still refuses regardless — this page is only ever a preview of the
 * rules, not their implementation.
 */
export default function AdminPage() {
  const { isAdmin, label } = useSession();
  const [agents, setAgents] = useState<AdminAgent[]>([]);
  const [meta, setMeta] = useState<AdminAgentList['meta'] | null>(null);
  const [offset, setOffset] = useState(0);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');
  const [notice, setNotice] = useState('');

  const [rowError, setRowError] = useState<{ id: number; message: string } | null>(null);
  const [rowBusy, setRowBusy] = useState<number | null>(null);
  const [passwordResetId, setPasswordResetId] = useState<number | null>(null);
  const [resetPassword, setResetPassword] = useState('');
  const [issuedCode, setIssuedCode] = useState<{ id: number; result: IssuePairingCodeResult } | null>(null);

  const [filters, setFilters] = useState<AdminAgentFilters>(EMPTY_FILTERS);
  const [searchDraft, setSearchDraft] = useState('');
  const [statusDraft, setStatusDraft] = useState('');
  const [roleDraft, setRoleDraft] = useState('');
  const [locationDraft, setLocationDraft] = useState('');
  const [selfRegisteredDraft, setSelfRegisteredDraft] = useState('');

  const [dashboard, setDashboard] = useState<AdminDashboard | null>(null);
  const [dashboardError, setDashboardError] = useState('');

  const [targetPeriod, setTargetPeriod] = useState(currentMonth());
  const [targets, setTargets] = useState<AdminTarget[]>([]);
  const [targetMeta, setTargetMeta] = useState<AdminTargetsResponse['meta'] | null>(null);
  const [targetScope, setTargetScope] = useState<'ORGANIZATION' | 'LOCATION' | 'AGENT'>('ORGANIZATION');
  const [targetKey, setTargetKey] = useState('');
  const [targetValue, setTargetValue] = useState('0');
  const [targetBusy, setTargetBusy] = useState(false);
  const [targetError, setTargetError] = useState('');

  const [periods, setPeriods] = useState<AdminPeriodRow[]>([]);
  const [periodMeta, setPeriodMeta] = useState<AdminPeriodsResponse['meta'] | null>(null);
  const [periodError, setPeriodError] = useState('');
  const [periodBusy, setPeriodBusy] = useState<string | null>(null);

  const [auditEntries, setAuditEntries] = useState<AdminAuditEntry[]>([]);
  const [auditAction, setAuditAction] = useState('');
  const [auditError, setAuditError] = useState('');
  const [auditBusy, setAuditBusy] = useState(false);

  const [detail, setDetail] = useState<AdminAgentDetail | null>(null);
  const [detailBusy, setDetailBusy] = useState<number | null>(null);
  const [detailError, setDetailError] = useState('');

  const [createOpen, setCreateOpen] = useState(false);
  const [createBusy, setCreateBusy] = useState(false);
  const [createError, setCreateError] = useState('');
  const [createForm, setCreateForm] = useState<CreateAdminAgentInput>({
    agent_code: '',
    full_name: '',
    username: '',
    password: '',
    role: 'AGENT',
    status: 'ACTIVE'
  });

  const total = meta?.pagination.total ?? 0;
  const maxOffset = Math.max(0, Math.floor((total - 1) / LIMIT) * LIMIT);
  const locationOptions = meta?.locations ?? targetMeta?.locations ?? [];

  /** Load the visible page. A press or an effect: state is set after the await. */
  const fetchDir = async (at: number, activeFilters: AdminAgentFilters = filters) => {
    setLoading(true);
    setErrorMsg('');

    try {
      const dir = await fetchAdminAgents(LIMIT, at, activeFilters);
      setAgents(dir.agents);
      setMeta(dir.meta);
    } catch (err) {
      setAgents([]);

      if (err instanceof ApiError && err.status === 403) {
        // Should be unreachable: the route guards on the same role. Kept
        // because a role revoked server-side since this tab loaded lands here,
        // and "you may no longer administer" is a different thing from
        // "something broke".
        setErrorMsg('Your account no longer has administrator access.');
      } else {
        setErrorMsg(
          err instanceof Error ? err.message : 'Could not load the account directory.'
        );
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!isAdmin) {
      return;
    }

    let live = true;

    (async () => {
      setLoading(true);
      setErrorMsg('');

      try {
        const dir = await fetchAdminAgents(LIMIT, offset, filters);

        if (live) {
          setAgents(dir.agents);
          setMeta(dir.meta);
        }
      } catch (err) {
        if (live) {
          setAgents([]);

          if (err instanceof ApiError && err.status === 403) {
            setErrorMsg('Your account no longer has administrator access.');
          } else {
            setErrorMsg(
              err instanceof Error ? err.message : 'Could not load the account directory.'
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
  }, [isAdmin, offset, filters]);

  useEffect(() => {
    if (!isAdmin) {
      return;
    }

    let live = true;

    (async () => {
      try {
        const [nextDashboard, nextPeriods, nextAudit] = await Promise.all([
          fetchAdminDashboard(),
          fetchAdminPeriods('MONTHLY', 12),
          fetchAdminAudit({ limit: 25 })
        ]);

        if (live) {
          setDashboard(nextDashboard);
          setDashboardError('');
          setPeriods(nextPeriods.periods);
          setPeriodMeta(nextPeriods.meta);
          setPeriodError('');
          setAuditEntries(nextAudit.entries);
          setAuditError('');
        }
      } catch (err) {
        if (live) {
          setDashboardError(err instanceof Error ? err.message : 'Could not load management statistics.');
        }
      }
    })();

    return () => {
      live = false;
    };
  }, [isAdmin]);

  useEffect(() => {
    if (!isAdmin) {
      return;
    }

    let live = true;

    (async () => {
      try {
        const next = await fetchAdminTargets(targetPeriod);

        if (live) {
          setTargets(next.targets);
          setTargetMeta(next.meta);
          setTargetError('');
        }
      } catch (err) {
        if (live) {
          setTargetError(err instanceof Error ? err.message : 'Could not load monthly targets.');
        }
      }
    })();

    return () => {
      live = false;
    };
  }, [isAdmin, targetPeriod]);

  const refreshManagement = async () => {
    try {
      const [nextDashboard, nextTargets, nextPeriods, nextAudit] = await Promise.all([
        fetchAdminDashboard(),
        fetchAdminTargets(targetPeriod),
        fetchAdminPeriods('MONTHLY', 12),
        fetchAdminAudit({ limit: 25, action: auditAction.trim() || undefined })
      ]);

      setDashboard(nextDashboard);
      setDashboardError('');
      setTargets(nextTargets.targets);
      setTargetMeta(nextTargets.meta);
      setTargetError('');
      setPeriods(nextPeriods.periods);
      setPeriodMeta(nextPeriods.meta);
      setPeriodError('');
      setAuditEntries(nextAudit.entries);
      setAuditError('');
    } catch (err) {
      setDashboardError(err instanceof Error ? err.message : 'Could not refresh management statistics.');
    }
  };

  /** One state-changing action, then a refresh of the visible page. */
  const runAction = async (
    agent: AdminAgent,
    patch: AdminAgentAction,
    confirmText?: string
  ) => {
    if (confirmText && !window.confirm(confirmText)) {
      return;
    }

    setRowBusy(agent.id);
    setRowError(null);

    try {
      await actOnAdminAgent(agent.id, patch);
      setNotice(`${agent.agent_code} updated.`);
      void fetchDir(offset);
    } catch (err) {
      setRowError({ id: agent.id, message: err instanceof Error ? err.message : 'The change was not applied.' });
    } finally {
      setRowBusy(null);
    }
  };

  const resetPasswordFor = async (agent: AdminAgent) => {
    const problem = validatePassword(resetPassword);
    if (problem) {
      setRowError({ id: agent.id, message: problem });
      return;
    }

    await runAction(agent, { action: 'SET_PASSWORD', password: resetPassword });
    setResetPassword('');
    setPasswordResetId(null);
  };

  /** Mint a pairing code and hold it in a dedicated panel; issuing alone never
      changes the row, so the directory is not refetched and the code stays up. */
  const issueCode = async (agent: AdminAgent) => {
    setRowBusy(agent.id);
    setRowError(null);
    setIssuedCode(null);

    try {
      const result = await issuePairingCode(agent.id);
      setIssuedCode({ id: agent.id, result });
    } catch (err) {
      setRowError({ id: agent.id, message: err instanceof Error ? err.message : 'The registration code could not be issued.' });
    } finally {
      setRowBusy(null);
    }
  };

  const dismissCode = () => setIssuedCode(null);

  const applyFilters = (event: FormEvent) => {
    event.preventDefault();

    const next: AdminAgentFilters = {};

    if (searchDraft.trim()) {
      next.q = searchDraft.trim();
    }
    if (statusDraft) {
      next.status = statusDraft as AdminAgentFilters['status'];
    }
    if (roleDraft) {
      next.role = roleDraft as AdminAgentFilters['role'];
    }
    if (locationDraft) {
      next.location = locationDraft;
    }
    if (selfRegisteredDraft) {
      next.self_registered = selfRegisteredDraft === 'true';
    }

    setOffset(0);
    setFilters(next);
  };

  const clearFilters = () => {
    setSearchDraft('');
    setStatusDraft('');
    setRoleDraft('');
    setLocationDraft('');
    setSelfRegisteredDraft('');
    setOffset(0);
    setFilters({});
  };

  const openCreate = () => {
    setCreateError('');
    setCreateOpen(true);
  };

  const updateCreateField = (field: keyof CreateAdminAgentInput, value: string) => {
    setCreateForm(current => ({
      ...current,
      [field]: value === '' ? undefined : value
    }));
  };

  const submitCreate = async (event: FormEvent) => {
    event.preventDefault();
    setCreateError('');

    if (!createForm.agent_code.trim() || !createForm.full_name.trim()) {
      setCreateError('Agent code and full name are required.');
      return;
    }
    if (!createForm.username.trim()) {
      setCreateError('Username is required.');
      return;
    }

    const passwordProblem = validatePassword(createForm.password ?? '');
    if (passwordProblem) {
      setCreateError(passwordProblem);
      return;
    }

    setCreateBusy(true);

    try {
      const created = await createAdminAgent({
        ...createForm,
        agent_code: createForm.agent_code.trim(),
        full_name: createForm.full_name.trim(),
        username: createForm.username.trim(),
        password: createForm.password ?? '',
        monthly_target: Number(createForm.monthly_target ?? 0)
      });

      setNotice(`${created.agent_code} created.`);
      setCreateOpen(false);
      setCreateForm({
        agent_code: '',
        full_name: '',
        username: '',
        password: '',
        role: 'AGENT',
        status: 'ACTIVE'
      });
      setOffset(0);
      await fetchDir(0);
      await refreshManagement();
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : 'The account could not be created.');
    } finally {
      setCreateBusy(false);
    }
  };

  const saveTarget = async (event: FormEvent) => {
    event.preventDefault();
    setTargetError('');

    const value = Number(targetValue);

    if (!Number.isInteger(value) || value < 0 || value > 1000000) {
      setTargetError('Target must be a whole number between 0 and 1,000,000.');
      return;
    }

    if (targetScope !== 'ORGANIZATION' && !targetKey) {
      setTargetError(targetScope === 'AGENT' ? 'Choose an agent.' : 'Choose a location.');
      return;
    }

    setTargetBusy(true);

    try {
      await saveAdminTarget({
        scope_type: targetScope,
        scope_key: targetScope === 'ORGANIZATION' ? undefined : targetKey,
        agent_id: targetScope === 'AGENT' ? Number(targetKey) : undefined,
        location: targetScope === 'LOCATION' ? targetKey : undefined,
        period: targetPeriod,
        target: value
      });

      setNotice(`Target saved for ${formatMonth(targetPeriod)}.`);
      await refreshManagement();
    } catch (err) {
      setTargetError(err instanceof Error ? err.message : 'The target could not be saved.');
    } finally {
      setTargetBusy(false);
    }
  };

  const removeTarget = async (target: AdminTarget) => {
    if (!window.confirm(`Clear the ${target.scope_label} target for ${formatMonth(target.period_start_date)}?`)) {
      return;
    }

    setTargetBusy(true);
    setTargetError('');

    try {
      await clearAdminTarget({
        scope_type: target.scope_type,
        scope_key: target.scope_type === 'ORGANIZATION' ? undefined : target.scope_key,
        agent_id: target.scope_type === 'AGENT' ? Number(target.scope_key) : undefined,
        location: target.scope_type === 'LOCATION' ? target.scope_key : undefined,
        period: target.period_start_date
      });
      setNotice(`Target cleared for ${formatMonth(target.period_start_date)}.`);
      await refreshManagement();
    } catch (err) {
      setTargetError(err instanceof Error ? err.message : 'The target could not be cleared.');
    } finally {
      setTargetBusy(false);
    }
  };

  const closePeriod = async (period: AdminPeriodRow) => {
    if (!window.confirm(`Close ${formatMonth(period.period_start_date)} now? Closing freezes the standing and publishes rewards.`)) {
      return;
    }

    setPeriodBusy(period.period_start_date);
    setPeriodError('');

    try {
      const result = await closeAdminPeriod(period.period_start_date, period.period_type);

      if (result.closed) {
        setNotice(`${formatMonth(period.period_start_date)} closed and published.`);
      } else {
        setNotice(`${formatMonth(period.period_start_date)} was not closed: ${result.reason.replace(/_/g, ' ')}.`);
      }

      await refreshManagement();
    } catch (err) {
      setPeriodError(err instanceof Error ? err.message : 'The period could not be closed.');
    } finally {
      setPeriodBusy(null);
    }
  };

  const applyAuditFilter = async (event: FormEvent) => {
    event.preventDefault();
    setAuditBusy(true);
    setAuditError('');

    try {
      const next = await fetchAdminAudit({
        limit: 25,
        action: auditAction.trim() || undefined
      });
      setAuditEntries(next.entries);
    } catch (err) {
      setAuditError(err instanceof Error ? err.message : 'Could not load the audit log.');
    } finally {
      setAuditBusy(false);
    }
  };

  const viewDetails = async (agent: AdminAgent) => {
    setDetailBusy(agent.id);
    setDetailError('');

    try {
      setDetail(await fetchAdminAgentDetail(agent.id));
    } catch (err) {
      setDetailError(err instanceof Error ? err.message : 'Could not load the account details.');
    } finally {
      setDetailBusy(null);
    }
  };

  if (!isAdmin) {
    return (
      <section className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center gap-2 text-amber-700 mb-2">
          <ShieldAlert aria-hidden="true" />
          <h1 className="text-lg font-bold">Account management unavailable</h1>
        </div>
        <p className="text-sm text-gray-700">
          Managing accounts is limited to administrators. Your account does not have that role,
          so this page will not load the directory.
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
          <h1 className="text-xl font-bold text-gray-900">Account management</h1>
          <p className="text-sm text-gray-700">
            {loading
              ? 'Loading…'
              : `${total} account${total === 1 ? '' : 's'}${meta ? ` · ${meta.active_admins} active administrator${meta.active_admins === 1 ? '' : 's'}` : ''}`}
            {label && <span className="text-gray-600"> · signed in as {label}</span>}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={openCreate}
            className="inline-flex items-center gap-1 text-sm font-medium text-white bg-blue-700 px-3 py-2 rounded hover:bg-blue-800"
          >
            <Plus size={14} aria-hidden="true" />
            Create account
          </button>
          <button
            type="button"
            onClick={() => {
              void fetchDir(offset);
              void refreshManagement();
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

      {/* Both banners are live regions: an action on a row below the fold is not
          useful feedback. */}
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

      <aside className="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
        Agent registrations appear here as pending. Approve an agent before they can sign in; use
        the review queue separately to approve or reject submitted records.
      </aside>

      <AdminDashboardSection dashboard={dashboard} error={dashboardError} />

      <form
        onSubmit={applyFilters}
        className="mb-4 bg-white rounded-lg shadow p-4"
        aria-label="Filter accounts"
      >
        <div className="flex items-center gap-2 mb-3">
          <Search size={16} aria-hidden="true" className="text-blue-800" />
          <h2 className="font-semibold text-gray-900">Find an account</h2>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <label className="text-sm text-gray-700">
            Search
            <input
              type="search"
              value={searchDraft}
              onChange={event => setSearchDraft(event.target.value)}
              placeholder="Code, name, email or NIN"
              className="mt-1 w-full border border-gray-400 rounded-md p-2"
            />
          </label>
          <label className="text-sm text-gray-700">
            Status
            <select
              value={statusDraft}
              onChange={event => setStatusDraft(event.target.value)}
              className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
            >
              {STATUS_OPTIONS.map(option => (
                <option key={option.value} value={option.value}>{option.label}</option>
              ))}
            </select>
          </label>
          <label className="text-sm text-gray-700">
            Role
            <select
              value={roleDraft}
              onChange={event => setRoleDraft(event.target.value)}
              className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
            >
              <option value="">Any role</option>
              <option value="AGENT">Agent</option>
              <option value="SUPERVISOR">Supervisor</option>
              <option value="ADMIN">Administrator</option>
            </select>
          </label>
          <label className="text-sm text-gray-700">
            Location
            <select
              value={locationDraft}
              onChange={event => setLocationDraft(event.target.value)}
              className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
            >
              <option value="">Any location</option>
              {locationOptions.map(location => (
                <option key={location} value={location}>{location}</option>
              ))}
            </select>
          </label>
          <label className="text-sm text-gray-700">
            Registration
            <select
              value={selfRegisteredDraft}
              onChange={event => setSelfRegisteredDraft(event.target.value)}
              className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
            >
              <option value="">Any source</option>
              <option value="true">Self-registered</option>
              <option value="false">Created by admin</option>
            </select>
          </label>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <button
            type="submit"
            className="inline-flex items-center gap-1 text-sm font-medium bg-blue-700 text-white px-3 py-2 rounded-md hover:bg-blue-800"
          >
            <Search size={14} aria-hidden="true" />
            Apply filters
          </button>
          <button
            type="button"
            onClick={clearFilters}
            className="text-sm text-gray-700 bg-white px-3 py-2 rounded border border-gray-300 hover:bg-gray-50"
          >
            Clear
          </button>
          {Object.keys(filters).length > 0 && (
            <span className="text-sm text-gray-600">
              {total} matching account{total === 1 ? '' : 's'}
            </span>
          )}
        </div>
      </form>

      {createOpen && (
        <form onSubmit={submitCreate} className="mb-4 bg-white rounded-lg shadow p-4" aria-label="Create an account">
          <div className="flex items-center justify-between gap-3 mb-3">
            <h2 className="font-semibold text-gray-900">Create an account</h2>
            <button
              type="button"
              onClick={() => setCreateOpen(false)}
              className="text-gray-500 hover:text-gray-800 p-1"
              aria-label="Close create form"
            >
              <X size={16} aria-hidden="true" />
            </button>
          </div>

          {createError && (
            <p role="alert" className="text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2 mb-3">
              {createError}
            </p>
          )}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <CreateField label="Agent code" value={createForm.agent_code} onChange={value => updateCreateField('agent_code', value)} required />
            <CreateField label="Full name" value={createForm.full_name} onChange={value => updateCreateField('full_name', value)} required />
            <CreateField label="Username" value={createForm.username} onChange={value => updateCreateField('username', value)} required />
            <CreateField label="Password" type="password" value={createForm.password} onChange={value => updateCreateField('password', value)} required />
            <label className="text-sm text-gray-700">
              Role
              <select
                value={createForm.role ?? 'AGENT'}
                onChange={event => updateCreateField('role', event.target.value)}
                className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
              >
                <option value="AGENT">Agent</option>
                <option value="SUPERVISOR">Supervisor</option>
                <option value="ADMIN">Administrator</option>
              </select>
            </label>
            <label className="text-sm text-gray-700">
              Status
              <select
                value={createForm.status ?? 'ACTIVE'}
                onChange={event => updateCreateField('status', event.target.value)}
                className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
              >
                <option value="ACTIVE">Active</option>
                <option value="PENDING">Pending approval</option>
                <option value="SUSPENDED">Suspended</option>
              </select>
            </label>
            <CreateField label="NIN (optional)" value={createForm.nin} onChange={value => updateCreateField('nin', value)} />
            <CreateField label="Phone (optional)" value={createForm.phone} onChange={value => updateCreateField('phone', value)} />
            <CreateField label="Email (optional)" type="email" value={createForm.email} onChange={value => updateCreateField('email', value)} />
            <CreateField label="Location (optional)" value={createForm.location} onChange={value => updateCreateField('location', value)} />
            <CreateField label="Monthly target" type="number" value={createForm.monthly_target === undefined ? '' : String(createForm.monthly_target)} onChange={value => updateCreateField('monthly_target', value)} />
            <CreateField label="IMEI (optional)" value={createForm.imei} onChange={value => updateCreateField('imei', value)} />
          </div>

          <button
            type="submit"
            disabled={createBusy}
            className="mt-3 inline-flex items-center gap-1 text-sm font-medium bg-blue-700 text-white px-4 py-2 rounded-md hover:bg-blue-800 disabled:opacity-60"
          >
            {createBusy ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Plus size={14} aria-hidden="true" />}
            Create account
          </button>
        </form>
      )}

      {detailError && (
        <p role="alert" className="mb-4 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">
          {detailError}
        </p>
      )}

      {detail && (
        <AgentDetailSection
          detail={detail}
          onClose={() => setDetail(null)}
          onEditProfile={async (email, location, phone) => {
            await runAction(detail, {
              action: 'SET_PROFILE',
              email: email.trim() || null,
              location: location.trim() || null,
              phone: phone.trim() || null
            });
            await viewDetails(detail);
          }}
        />
      )}

      <AdminTargetsSection
        period={targetPeriod}
        onPeriodChange={setTargetPeriod}
        targets={targets}
        meta={targetMeta}
        scope={targetScope}
        onScopeChange={value => {
          setTargetScope(value);
          setTargetKey('');
        }}
        scopeKey={targetKey}
        onScopeKeyChange={setTargetKey}
        value={targetValue}
        onValueChange={setTargetValue}
        busy={targetBusy}
        error={targetError}
        onSubmit={saveTarget}
        onClear={target => void removeTarget(target)}
      />

      <AdminPeriodsSection
        periods={periods}
        meta={periodMeta}
        error={periodError}
        busy={periodBusy}
        onClose={period => void closePeriod(period)}
      />

      <AdminAuditSection
        entries={auditEntries}
        action={auditAction}
        onActionChange={setAuditAction}
        error={auditError}
        busy={auditBusy}
        onSubmit={applyAuditFilter}
      />

      {agents.length === 0 && !loading && (
        <p className="text-gray-700 bg-white rounded-lg shadow p-6 text-center">
          No accounts to show.
        </p>
      )}

      <ul className="space-y-4">
        {agents.map(agent => (
          <li key={agent.id}>
            <AgentCard
              agent={agent}
              isSelf={agent.agent_code === meta?.requested_by}
              lastAdmin={agent.role === 'ADMIN' && (meta?.active_admins ?? 1) <= 1}
              busy={rowBusy === agent.id}
              error={rowError?.id === agent.id ? rowError.message : ''}
              resetting={passwordResetId === agent.id}
              resetPassword={resetPassword}
              onResetPasswordChange={setResetPassword}
              onToggleReset={() => {
                setPasswordResetId(passwordResetId === agent.id ? null : agent.id);
                setResetPassword('');
              }}
              onResetPassword={() => void resetPasswordFor(agent)}
              onRoleChange={(role) => void runAction(agent, { action: 'SET_ROLE', role })}
              onSuspend={(reason) =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'SUSPENDED', reason },
                  `Suspend ${agent.agent_code}? Their devices are revoked and they can no longer sign in until reinstated.`
                )}
              onReinstate={(reason) => void runAction(agent, { action: 'SET_STATUS', status: 'ACTIVE', reason })}
              onApprove={(reason) =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'ACTIVE', reason },
                  `Approve ${agent.full_name}'s agent registration? They will be able to sign in with their IMEI.`
                )}
              onReject={(reason) =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'REJECTED', reason },
                  `Reject ${agent.full_name}'s registration? They will not be able to sign in.`
                )}
              onReviewAgain={() =>
                void runAction(agent, { action: 'SET_STATUS', status: 'PENDING' })}
              onRetire={(reason) =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'DELETED', reason },
                  `Retire ${agent.agent_code} permanently? Their login and devices are revoked, and a retired account can never be restored.`
                )}
              onRevoke={() =>
                void runAction(
                  agent,
                  { action: 'REVOKE_CREDENTIAL' },
                  `Revoke the login for ${agent.agent_code}? They can no longer sign in until an administrator issues a new credential.`
                )}
              onIssueCode={() => void issueCode(agent)}
              onDismissCode={dismissCode}
              issuedCode={issuedCode?.id === agent.id ? issuedCode.result : null}
              onViewDetails={() => void viewDetails(agent)}
              detailsBusy={detailBusy === agent.id}
              onTargetChange={(target) =>
                void runAction(agent, { action: 'SET_MONTHLY_TARGET', monthly_target: target })}
            />
          </li>
        ))}
      </ul>

      {meta && total > LIMIT && (
        <nav className="flex items-center justify-between mt-4" aria-label="Account directory pages">
          <button
            type="button"
            disabled={offset === 0}
            onClick={() => setOffset(offset - LIMIT)}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
          >
            <ChevronLeft size={14} aria-hidden="true" />
            Previous
          </button>
          <span className="text-sm text-gray-700">
            Page {offset / LIMIT + 1} of {Math.floor((total - 1) / LIMIT) + 1}
          </span>
          <button
            type="button"
            disabled={offset >= maxOffset}
            onClick={() => setOffset(offset + LIMIT)}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
          >
            Next
            <ChevronRight size={14} aria-hidden="true" />
          </button>
        </nav>
      )}
    </section>
  );
}

/**
 * One directory row.
 *
 * The two irreversible-feeling actions — retire and revoke — confirm before
 * firing, and the genuinely terminal one asks in words what it ends. Everything
 * is still enforced by the server's guards; these controls only forecast them.
 */
function AgentCard({
  agent,
  isSelf,
  lastAdmin,
  busy,
  error,
  resetting,
  resetPassword,
  issuedCode,
  onResetPasswordChange,
  onToggleReset,
  onResetPassword,
  onRoleChange,
  onSuspend,
  onReinstate,
  onApprove,
  onReject,
  onReviewAgain,
  onRetire,
  onRevoke,
  onIssueCode,
  onDismissCode,
  onViewDetails,
  detailsBusy,
  onTargetChange
}: {
  agent: AdminAgent;
  isSelf: boolean;
  lastAdmin: boolean;
  busy: boolean;
  error: string;
  resetting: boolean;
  resetPassword: string;
  issuedCode: IssuePairingCodeResult | null;
  onResetPasswordChange: (value: string) => void;
  onToggleReset: () => void;
  onResetPassword: () => void;
  onRoleChange: (role: AdminAgentRole) => void;
  onSuspend: (reason?: string) => void;
  onReinstate: (reason?: string) => void;
  onApprove: (reason?: string) => void;
  onReject: (reason?: string) => void;
  onReviewAgain: () => void;
  onRetire: (reason?: string) => void;
  onRevoke: () => void;
  onIssueCode: () => void;
  onDismissCode: () => void;
  onViewDetails: () => void;
  detailsBusy: boolean;
  onTargetChange: (target: number) => void;
}) {
  const retired = agent.status === 'DELETED';
  const pendingRegistration = agent.self_registered && agent.status === 'PENDING';
  const rejectedRegistration = agent.self_registered && agent.status === 'REJECTED';
  const [copied, setCopied] = useState(false);
  const [editingTarget, setEditingTarget] = useState(false);
  const [targetInput, setTargetInput] = useState(String(agent.monthly_target ?? 0));
  const [statusReason, setStatusReason] = useState('');

  const copyCode = async (code: string) => {
    if (typeof navigator === 'undefined' || typeof navigator.clipboard?.writeText !== 'function') {
      return;
    }

    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard can be unavailable (permissions, insecure context). The code
      // is visible on screen and typeable — nothing breaks.
    }
  };

  // The self-guard and the last-admin guard, previewed as disabled controls.
  // The server enforces both regardless; a control that does nothing teaches
  // the administrator the constraint before a 409 has to explain it.
  const roleSelect = (
    <select
      id={`role-${agent.id}`}
      value={agent.role}
      onChange={e => onRoleChange(e.target.value as AdminAgentRole)}
      disabled={busy || retired || isSelf}
      title={lastAdmin
        ? 'The last active administrator cannot be re-roled.'
        : isSelf ? 'You cannot re-role your own account.' : undefined}
      className="text-sm border border-gray-400 rounded-md p-1.5 bg-white disabled:opacity-50"
    >
      <option value="AGENT">Agent</option>
      <option value="SUPERVISOR">Supervisor</option>
      <option value="ADMIN">Administrator</option>
    </select>
  );

  return (
    <article className="bg-white rounded-lg shadow p-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
        <div>
          <h2 className="font-semibold text-gray-900">
            {agent.agent_code} · {agent.full_name || 'Unnamed agent'}
          </h2>
          <p className="text-sm text-gray-700">
            {agent.username ? `username ${agent.username}` : 'no username'}
            {agent.nin && <> · NIN {agent.nin}</>}
            {agent.phone && <> · phone {agent.phone}</>}
            {agent.email && <> · email {agent.email}</>}
            {agent.location && <> · location {agent.location}</>}
            <> · monthly target {agent.monthly_target ?? 0}</>
            {' · '}{agent.active_device_count} active device{agent.active_device_count === 1 ? '' : 's'}
            {isSelf && <span className="text-gray-600"> · this is you</span>}
          </p>
          {agent.status_reason && (
            <p className="text-sm text-amber-900 mt-1">Status reason: {agent.status_reason}</p>
          )}
        </div>
        <span className={`text-xs font-medium px-2 py-1 rounded-full border ${STATUS_TONE[agent.status] ?? 'text-gray-800 border-gray-300'}`}>
          {STATUS_WORDS[agent.status] ?? agent.status}
        </span>
      </header>

      <div role="alert" aria-live="assertive">
        {error && (
          <p className="text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2 mb-2">
            {error}
          </p>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <div className="flex items-center gap-2">
          <label htmlFor={`role-${agent.id}`} className="text-gray-600">Role</label>
          {roleSelect}
        </div>

        <div className="flex flex-wrap gap-2">
          {pendingRegistration && (
            <>
              <button
                type="button"
                disabled={busy}
                onClick={() => onApprove(statusReason.trim() || undefined)}
                className="inline-flex items-center gap-1 text-sm font-medium text-green-800 bg-white px-3 py-1.5 rounded border border-green-300 hover:bg-green-50 disabled:opacity-50"
              >
                <CheckCircle2 size={14} aria-hidden="true" />
                Approve registration
              </button>
              <button
                type="button"
                disabled={busy}
                onClick={() => onReject(statusReason.trim() || undefined)}
                className="inline-flex items-center gap-1 text-sm font-medium text-red-800 bg-white px-3 py-1.5 rounded border border-red-300 hover:bg-red-50 disabled:opacity-50"
              >
                <XCircle size={14} aria-hidden="true" />
                Reject
              </button>
            </>
          )}
          {rejectedRegistration && (
            <button
              type="button"
              disabled={busy}
              onClick={onReviewAgain}
              className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
            >
              <RotateCcw size={14} aria-hidden="true" />
              Reopen registration
            </button>
          )}
          {!retired && agent.status === 'ACTIVE' && (
            <button
              type="button"
              disabled={busy || isSelf || lastAdmin}
              onClick={() => onSuspend(statusReason.trim() || undefined)}
              title={lastAdmin ? 'The last active administrator cannot be suspended.' : undefined}
              className="inline-flex items-center gap-1 text-sm font-medium text-amber-800 bg-white px-3 py-1.5 rounded border border-amber-300 hover:bg-amber-50 disabled:opacity-50"
            >
              <Ban size={14} aria-hidden="true" />
              Suspend
            </button>
          )}

          {!retired && agent.status === 'SUSPENDED' && (
            <button
              type="button"
              disabled={busy}
              onClick={() => onReinstate(statusReason.trim() || undefined)}
              className="inline-flex items-center gap-1 text-sm font-medium text-green-800 bg-white px-3 py-1.5 rounded border border-green-300 hover:bg-green-50 disabled:opacity-50"
            >
              <RotateCcw size={14} aria-hidden="true" />
              Reinstate
            </button>
          )}

          {!retired && (
            <button
              type="button"
              disabled={busy || isSelf || lastAdmin}
              onClick={() => onRetire(statusReason.trim() || undefined)}
              title={lastAdmin ? 'The last active administrator cannot be retired.' : undefined}
              className="inline-flex items-center gap-1 text-sm font-medium text-red-800 bg-white px-3 py-1.5 rounded border border-red-300 hover:bg-red-50 disabled:opacity-50"
            >
              <Archive size={14} aria-hidden="true" />
              Retire
            </button>
          )}

          {!retired && (
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setTargetInput(String(agent.monthly_target ?? 0));
                setEditingTarget(!editingTarget);
              }}
              className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
            >
              Set target
            </button>
          )}

          <button
            type="button"
            disabled={detailsBusy}
            onClick={onViewDetails}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
          >
            {detailsBusy
              ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
              : <Eye size={14} aria-hidden="true" />}
            View details
          </button>

          <button
            type="button"
            disabled={busy}
            onClick={onToggleReset}
            className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
          >
            <KeyRound size={14} aria-hidden="true" />
            Reset password
          </button>

          {!retired && agent.status === 'ACTIVE' && (
            <button
              type="button"
              disabled={busy}
              onClick={onIssueCode}
              title="Mint a one-time code this agent uses on the /register page to bind their first device."
              className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
            >
              <Smartphone size={14} aria-hidden="true" />
              Registration code
            </button>
          )}

          <button
            type="button"
            disabled={busy || isSelf || !agent.has_credential}
            onClick={onRevoke}
            title={isSelf ? 'You cannot revoke your own credential.' : undefined}
            className="inline-flex items-center gap-1 text-sm font-medium text-gray-800 bg-white px-3 py-1.5 rounded border border-gray-300 hover:bg-gray-50 disabled:opacity-50"
          >
            <Ban size={14} aria-hidden="true" />
            Revoke login
          </button>

          {busy && <Loader2 size={16} className="animate-spin text-blue-700" aria-hidden="true" />}
        </div>
      </div>

      {!retired && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <label htmlFor={`reason-${agent.id}`} className="text-xs font-semibold text-gray-700">
            Reason for status change (optional)
          </label>
          <input
            id={`reason-${agent.id}`}
            type="text"
            value={statusReason}
            onChange={event => setStatusReason(event.target.value)}
            maxLength={500}
            placeholder="Shown to the agent and stored in the audit log"
            className="flex-1 min-w-56 text-sm border border-gray-400 rounded-md p-2"
          />
        </div>
      )}

      {editingTarget && !retired && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <label htmlFor={`target-${agent.id}`} className="text-xs font-semibold text-gray-700">
            Monthly Target Quota:
          </label>
          <input
            id={`target-${agent.id}`}
            type="number"
            min="0"
            max="1000000"
            value={targetInput}
            onChange={e => setTargetInput(e.target.value)}
            className="w-28 text-sm border border-gray-400 rounded-md p-1.5"
          />
          <button
            type="button"
            disabled={busy || isNaN(parseInt(targetInput, 10)) || parseInt(targetInput, 10) < 0}
            onClick={() => {
              onTargetChange(parseInt(targetInput, 10));
              setEditingTarget(false);
            }}
            className="inline-flex items-center gap-1 text-sm font-medium bg-blue-700 text-white px-3 py-1.5 rounded-md hover:bg-blue-800 disabled:opacity-60"
          >
            Save target
          </button>
          <button
            type="button"
            onClick={() => setEditingTarget(false)}
            className="text-xs text-gray-600 hover:underline px-2"
          >
            Cancel
          </button>
        </div>
      )}

      {resetting && !retired && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <label htmlFor={`reset-${agent.id}`} className="sr-only">
            New password for {agent.agent_code}
          </label>
          <input
            id={`reset-${agent.id}`}
            type="password"
            value={resetPassword}
            onChange={e => onResetPasswordChange(e.target.value)}
            placeholder="8–128 characters, one letter and one digit"
            autoComplete="new-password"
            className="flex-1 min-w-56 text-sm border border-gray-400 rounded-md p-2"
          />
          <button
            type="button"
            disabled={busy}
            onClick={onResetPassword}
            className="inline-flex items-center gap-1 text-sm font-medium bg-blue-700 text-white px-3 py-2 rounded-md hover:bg-blue-800 disabled:opacity-60"
          >
            {busy
              ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
              : <KeyRound size={14} aria-hidden="true" />}
            Set password
          </button>
        </div>
      )}

      {issuedCode && !retired && (
        <div
          role="status"
          aria-live="polite"
          className="mt-3 border border-blue-300 bg-blue-50 rounded p-3"
        >
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-sm font-semibold text-gray-900">
                One-time registration code for {agent.agent_code}
              </p>
              <p className="text-xs text-gray-700 mt-0.5">
                Shown once · single use · expires in about {formatExpiry(issuedCode.ttl_seconds)}. Send it and the
                account’s username and password to the agent; they enter it on the
                /register page.
              </p>
            </div>
            <button
              type="button"
              onClick={onDismissCode}
              className="text-gray-500 hover:text-gray-800 p-1"
              aria-label="Dismiss the registration code"
            >
              <X size={16} aria-hidden="true" />
            </button>
          </div>

          <div className="mt-2 flex flex-wrap items-center gap-3">
            <code className="font-mono text-2xl tracking-widest text-gray-900 bg-white border border-gray-300 rounded px-3 py-1">
              {issuedCode.pairing_code}
            </code>
            <button
              type="button"
              onClick={() => void copyCode(issuedCode.pairing_code)}
              className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50"
            >
              {copied
                ? <Check size={14} aria-hidden="true" />
                : <Copy size={14} aria-hidden="true" />}
              {copied ? 'Copied' : 'Copy'}
            </button>
          </div>
        </div>
      )}

      {retired && (
        <p className="text-sm bg-gray-100 text-gray-800 rounded px-3 py-2 mt-3">
          Retired account. It cannot be changed or reinstated — its history stays for
          the record, its login and devices are gone.
        </p>
      )}
    </article>
  );
}

function validatePassword(password: string): string | null {
  if (password.length < 8 || password.length > 128) {
    return 'Password must be 8 to 128 characters.';
  }
  if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
    return 'Password must contain at least one letter and one digit.';
  }
  return null;
}

function AdminDashboardSection({ dashboard, error }: { dashboard: AdminDashboard | null; error: string }) {
  if (error) {
    return (
      <p role="alert" className="mb-4 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">
        {error}
      </p>
    );
  }

  if (!dashboard) {
    return (
      <div className="mb-4 bg-white rounded-lg shadow p-4 text-sm text-gray-600">
        Loading management statistics…
      </div>
    );
  }

  const cards = [
    { label: 'Total agents', value: dashboard.agents.total, note: `${dashboard.agents.self_registered} self-registered` },
    { label: 'Active agents', value: dashboard.agents.active, note: `${dashboard.agents.pending} pending approval` },
    { label: 'Approved enrollments', value: dashboard.enrollments.approved, note: `${dashboard.enrollments.pending} pending, ${dashboard.enrollments.rejected} rejected` },
    { label: 'Monthly target', value: dashboard.target.monthly_target, note: dashboard.target.configured ? `${dashboard.target.achievement_percent ?? 0}% achieved` : 'Not configured' },
    { label: 'Devices', value: dashboard.devices.active, note: `${dashboard.devices.total} total, ${dashboard.devices.revoked} revoked` },
    { label: 'Reporting agents', value: dashboard.enrollments.agents_reporting, note: `${dashboard.enrollments.submissions} submissions` }
  ];

  return (
    <section className="mb-4" data-testid="admin-dashboard" aria-label="Management overview">
      <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <h2 className="font-semibold text-gray-900">Management overview</h2>
        <span className="text-sm text-gray-600">
          {formatMonth(dashboard.period.period_start_date)} · {dashboard.period.due ? 'closing due' : 'open'}
          {dashboard.period.frozen ? ' · closed' : ''}
          {dashboard.period.published ? ' · published' : ''}
        </span>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        {cards.map(card => (
          <div key={card.label} className="bg-white rounded-lg shadow p-4">
            <p className="text-xs uppercase tracking-wide text-gray-600">{card.label}</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">{card.value}</p>
            <p className="text-xs text-gray-600 mt-1">{card.note}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-3 lg:grid-cols-2 mt-3">
        <div className="bg-white rounded-lg shadow p-4">
          <h3 className="font-semibold text-gray-900 mb-2">By location</h3>
          {dashboard.locations.length === 0 ? (
            <p className="text-sm text-gray-600">No active agents yet.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {dashboard.locations.map(location => (
                <li key={location.location} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 pb-1">
                  <span className="font-medium text-gray-900">{location.location}</span>
                  <span className="text-gray-700">
                    {location.agent_count} agent{location.agent_count === 1 ? '' : 's'} · {location.approved} approved
                    {' · '}{location.configured ? `${location.target} target` : 'no target'}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="bg-white rounded-lg shadow p-4">
          <h3 className="font-semibold text-gray-900 mb-2">Top agents this month</h3>
          {dashboard.top_agents.length === 0 ? (
            <p className="text-sm text-gray-600">No agents to rank yet.</p>
          ) : (
            <ol className="space-y-2 text-sm list-decimal list-inside">
              {dashboard.top_agents.map(agent => (
                <li key={agent.agent_id} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-gray-100 pb-1">
                  <span className="font-medium text-gray-900">{agent.full_name}</span>
                  <span className="text-gray-700">
                    {agent.agent_code} · {agent.approved} approved
                    {agent.configured ? ` of ${agent.target}` : ''}
                  </span>
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>
    </section>
  );
}

function CreateField({
  label,
  value,
  onChange,
  type = 'text',
  required = false
}: {
  label: string;
  value?: string | number;
  onChange: (value: string) => void;
  type?: string;
  required?: boolean;
}) {
  return (
    <label className="text-sm text-gray-700">
      {label}
      <input
        type={type}
        value={value ?? ''}
        onChange={event => onChange(event.target.value)}
        required={required}
        min={type === 'number' ? 0 : undefined}
        max={type === 'number' ? 1000000 : undefined}
        className="mt-1 w-full border border-gray-400 rounded-md p-2"
      />
    </label>
  );
}

function AgentDetailSection({
  detail,
  onClose,
  onEditProfile
}: {
  detail: AdminAgentDetail;
  onClose: () => void;
  onEditProfile: (email: string, location: string, phone: string) => Promise<void>;
}) {
  const [email, setEmail] = useState(detail.email ?? '');
  const [location, setLocation] = useState(detail.location ?? '');
  const [phone, setPhone] = useState(detail.phone ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    setEmail(detail.email ?? '');
    setLocation(detail.location ?? '');
    setPhone(detail.phone ?? '');
    setError('');
  }, [detail.id, detail.email, detail.location, detail.phone]);

  const saveProfile = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError('');

    try {
      await onEditProfile(email, location, phone);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The profile could not be updated.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="mb-4 bg-white rounded-lg shadow p-4" data-testid="admin-agent-detail" aria-label="Agent details">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="font-semibold text-gray-900">
            {detail.agent_code} · {detail.full_name}
          </h2>
          <p className="text-sm text-gray-700">
            {detail.status} · {detail.role} · monthly target {detail.target.target || 'not configured'}
            {' · '}{detail.summary.approved} approved this month
          </p>
          {detail.status_reason && (
            <p className="text-sm text-amber-900 mt-1">Status reason: {detail.status_reason}</p>
          )}
        </div>
        <button type="button" onClick={onClose} className="text-gray-500 hover:text-gray-800 p-1" aria-label="Close agent details">
          <X size={16} aria-hidden="true" />
        </button>
      </div>

      {error && <p role="alert" className="mt-2 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">{error}</p>}

      <div className="grid gap-4 lg:grid-cols-2 mt-3">
        <div>
          <h3 className="font-semibold text-gray-900 mb-2">Devices</h3>
          {detail.devices.length === 0 ? (
            <p className="text-sm text-gray-600">No devices registered.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {detail.devices.map(device => (
                <li key={device.id} className="border border-gray-200 rounded p-2">
                  <p className="font-medium text-gray-900">{device.status}</p>
                  <p className="text-gray-700 break-all">{device.device_uuid}</p>
                  <p className="text-gray-600">
                    {device.imei ? `IMEI ${device.imei} · ` : ''}
                    last seen {device.last_seen_at ?? 'never'}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <h3 className="font-semibold text-gray-900 mb-2">Target history</h3>
          {detail.targets.length === 0 ? (
            <p className="text-sm text-gray-600">No period-specific targets. The account default or a location target applies.</p>
          ) : (
            <ul className="space-y-1 text-sm">
              {detail.targets.map(target => (
                <li key={target.id} className="flex justify-between gap-2 border-b border-gray-100 pb-1">
                  <span>{formatMonth(target.period_start_date)}</span>
                  <span className="font-medium">{target.target}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      <form onSubmit={saveProfile} className="mt-4 border-t border-gray-200 pt-3">
        <h3 className="font-semibold text-gray-900 mb-2">Profile</h3>
        <div className="grid gap-3 sm:grid-cols-3">
          <CreateField label="Email" type="email" value={email} onChange={setEmail} />
          <CreateField label="Location" value={location} onChange={setLocation} />
          <CreateField label="Phone" value={phone} onChange={setPhone} />
        </div>
        <button
          type="submit"
          disabled={busy}
          className="mt-2 inline-flex items-center gap-1 text-sm font-medium bg-blue-700 text-white px-3 py-2 rounded-md hover:bg-blue-800 disabled:opacity-60"
        >
          {busy && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
          Save profile
        </button>
      </form>

      <div className="mt-4 border-t border-gray-200 pt-3">
        <h3 className="font-semibold text-gray-900 mb-2">Recent audit</h3>
        <AuditList entries={detail.recent_audit} />
      </div>
    </section>
  );
}

function AdminTargetsSection({
  period,
  onPeriodChange,
  targets,
  meta,
  scope,
  onScopeChange,
  scopeKey,
  onScopeKeyChange,
  value,
  onValueChange,
  busy,
  error,
  onSubmit,
  onClear
}: {
  period: string;
  onPeriodChange: (value: string) => void;
  targets: AdminTarget[];
  meta: AdminTargetsResponse['meta'] | null;
  scope: 'ORGANIZATION' | 'LOCATION' | 'AGENT';
  onScopeChange: (value: 'ORGANIZATION' | 'LOCATION' | 'AGENT') => void;
  scopeKey: string;
  onScopeKeyChange: (value: string) => void;
  value: string;
  onValueChange: (value: string) => void;
  busy: boolean;
  error: string;
  onSubmit: (event: FormEvent) => void;
  onClear: (target: AdminTarget) => void;
}) {
  return (
    <section className="mb-4 bg-white rounded-lg shadow p-4" data-testid="admin-targets" aria-label="Monthly targets">
      <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <div className="flex items-center gap-2">
          <Target size={16} aria-hidden="true" className="text-blue-800" />
          <h2 className="font-semibold text-gray-900">Monthly targets</h2>
        </div>
        <span className="text-sm text-gray-600">
          Organization target: {meta?.organization_target ?? 0}
        </span>
      </div>

      {error && <p role="alert" className="mb-2 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">{error}</p>}

      <form onSubmit={onSubmit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
        <label className="text-sm text-gray-700">
          Period
          <input
            type="month"
            value={period}
            onChange={event => onPeriodChange(event.target.value)}
            className="mt-1 w-full border border-gray-400 rounded-md p-2"
          />
        </label>
        <label className="text-sm text-gray-700">
          Scope
          <select
            value={scope}
            onChange={event => onScopeChange(event.target.value as 'ORGANIZATION' | 'LOCATION' | 'AGENT')}
            className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
          >
            <option value="ORGANIZATION">Organization</option>
            <option value="LOCATION">Location</option>
            <option value="AGENT">Agent</option>
          </select>
        </label>
        {scope === 'ORGANIZATION' ? (
          <div className="text-sm text-gray-600 pb-2">Applies to the whole organization.</div>
        ) : (
          <label className="text-sm text-gray-700">
            {scope === 'AGENT' ? 'Agent' : 'Location'}
            <select
              value={scopeKey}
              onChange={event => onScopeKeyChange(event.target.value)}
              className="mt-1 w-full border border-gray-400 rounded-md p-2 bg-white"
            >
              <option value="">Choose…</option>
              {scope === 'AGENT'
                ? (meta?.agents ?? []).map(agent => (
                    <option key={agent.id} value={String(agent.id)}>
                      {agent.agent_code} · {agent.full_name}
                    </option>
                  ))
                : (meta?.locations ?? []).map(location => (
                    <option key={location} value={location}>{location}</option>
                  ))}
            </select>
          </label>
        )}
        <label className="text-sm text-gray-700">
          Target
          <input
            type="number"
            min="0"
            max="1000000"
            value={value}
            onChange={event => onValueChange(event.target.value)}
            className="mt-1 w-full border border-gray-400 rounded-md p-2"
          />
        </label>
        <button
          type="submit"
          disabled={busy}
          className="inline-flex items-center justify-center gap-1 text-sm font-medium bg-blue-700 text-white px-3 py-2 rounded-md hover:bg-blue-800 disabled:opacity-60"
        >
          {busy && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
          Save target
        </button>
      </form>

      <div className="mt-3">
        {targets.length === 0 ? (
          <p className="text-sm text-gray-600">No targets set for {formatMonth(period)}.</p>
        ) : (
          <ul className="divide-y divide-gray-100 text-sm">
            {targets.map(target => (
              <li key={target.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span>
                  <span className="font-medium text-gray-900">{target.scope_type}</span>
                  {' · '}{target.scope_label} · {target.target}
                </span>
                <button
                  type="button"
                  onClick={() => onClear(target)}
                  disabled={busy}
                  className="inline-flex items-center gap-1 text-xs font-medium text-red-800 bg-white px-2 py-1 rounded border border-red-300 hover:bg-red-50 disabled:opacity-50"
                >
                  <Trash2 size={12} aria-hidden="true" />
                  Clear
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}

function AdminPeriodsSection({
  periods,
  meta,
  error,
  busy,
  onClose
}: {
  periods: AdminPeriodRow[];
  meta: AdminPeriodsResponse['meta'] | null;
  error: string;
  busy: string | null;
  onClose: (period: AdminPeriodRow) => void;
}) {
  return (
    <section className="mb-4 bg-white rounded-lg shadow p-4" data-testid="admin-periods" aria-label="Period history">
      <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
        <div className="flex items-center gap-2">
          <CalendarDays size={16} aria-hidden="true" className="text-blue-800" />
          <h2 className="font-semibold text-gray-900">Period history</h2>
        </div>
        <span className="text-sm text-gray-600">
          Close grace: {meta?.grace_hours ?? 0} hours · {meta?.business_timezone ?? ''}
        </span>
      </div>

      {error && <p role="alert" className="mb-2 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">{error}</p>}

      <ul className="divide-y divide-gray-100 text-sm">
        {periods.map(period => (
          <li key={`${period.period_type}-${period.period_start_date}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
            <div>
              <p className="font-medium text-gray-900">{formatMonth(period.period_start_date)}</p>
              <p className="text-gray-600">
                {period.totals.approved} approved · {period.totals.pending} pending · {period.rewards.total} rewards
                {' · '}{period.frozen ? 'closed' : period.due ? 'due to close' : 'open'}
              </p>
            </div>
            <button
              type="button"
              onClick={() => onClose(period)}
              disabled={busy === period.period_start_date || period.frozen || !period.due}
              className="text-sm font-medium text-blue-800 bg-white px-3 py-1.5 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-50"
            >
              {busy === period.period_start_date
                ? 'Closing…'
                : period.frozen ? 'Closed' : period.due ? 'Close period' : 'Not due'}
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}

function AdminAuditSection({
  entries,
  action,
  onActionChange,
  error,
  busy,
  onSubmit
}: {
  entries: AdminAuditEntry[];
  action: string;
  onActionChange: (value: string) => void;
  error: string;
  busy: boolean;
  onSubmit: (event: FormEvent) => void;
}) {
  return (
    <section className="mb-4 bg-white rounded-lg shadow p-4" data-testid="admin-audit" aria-label="Audit log">
      <div className="flex items-center gap-2 mb-2">
        <Activity size={16} aria-hidden="true" className="text-blue-800" />
        <h2 className="font-semibold text-gray-900">Audit log</h2>
      </div>

      <form onSubmit={onSubmit} className="flex flex-wrap items-end gap-2 mb-3">
        <label className="text-sm text-gray-700">
          Action
          <input
            type="text"
            value={action}
            onChange={event => onActionChange(event.target.value)}
            placeholder="admin.target.set"
            className="mt-1 block border border-gray-400 rounded-md p-2"
          />
        </label>
        <button
          type="submit"
          disabled={busy}
          className="text-sm font-medium bg-blue-700 text-white px-3 py-2 rounded-md hover:bg-blue-800 disabled:opacity-60"
        >
          {busy ? 'Loading…' : 'Filter audit'}
        </button>
      </form>

      {error && <p role="alert" className="mb-2 text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2">{error}</p>}

      <AuditList entries={entries} />
    </section>
  );
}

function AuditList({ entries }: { entries: AdminAuditEntry[] }) {
  if (entries.length === 0) {
    return <p className="text-sm text-gray-600">No audit entries.</p>;
  }

  return (
    <ul className="space-y-2 text-sm">
      {entries.map(entry => (
        <li key={entry.id} className="border-b border-gray-100 pb-1">
          <p className="text-gray-900">
            <span className="font-medium">{entry.action}</span>
            {' · '}{entry.created_at}
            {entry.actor_agent_code ? ` · by ${entry.actor_agent_code}` : ''}
          </p>
          {(entry.entity_type || entry.entity_id) && (
            <p className="text-gray-600">
              {entry.entity_type ?? 'entity'}{entry.entity_id ? ` #${entry.entity_id}` : ''}
            </p>
          )}
          {entry.metadata && Object.keys(entry.metadata).length > 0 && (
            <p className="text-gray-600 break-words">{JSON.stringify(entry.metadata)}</p>
          )}
        </li>
      ))}
    </ul>
  );
}

const STATUS_WORDS: Record<string, string> = {
  PENDING: 'Pending approval',
  ACTIVE: 'Active',
  INACTIVE: 'Inactive',
  REJECTED: 'Registration rejected',
  SUSPENDED: 'Suspended',
  DELETED: 'Retired'
};

/** A short, human phrase for a remaining countdown in seconds. */
function formatExpiry(seconds: number): string {
  if (seconds < 60) {
    return 'under a minute';
  }

  const minutes = Math.round(seconds / 60);
  return `${minutes} minute${minutes === 1 ? '' : 's'}`;
}

const STATUS_TONE: Record<string, string> = {
  PENDING: 'text-amber-800 border-amber-300 bg-amber-50',
  ACTIVE: 'text-green-800 border-green-300 bg-green-50',
  INACTIVE: 'text-gray-800 border-gray-300 bg-gray-100',
  REJECTED: 'text-red-800 border-red-300 bg-red-50',
  SUSPENDED: 'text-amber-800 border-amber-300 bg-amber-50',
  DELETED: 'text-gray-800 border-gray-300 bg-gray-100'
};