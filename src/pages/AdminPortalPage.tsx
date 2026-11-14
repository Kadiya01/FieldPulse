import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertCircle, CheckCircle2, LogOut, RefreshCw, ShieldCheck, UserRound, Users, XCircle } from 'lucide-react';
import BrandHeader from '../components/BrandHeader';
import {
  adminLogin,
  adminLogout,
  bootstrapFirstAdmin,
  decideAbhAdminAgent,
  decideAbhAdminDevice,
  fetchAbhAdminAgentDetails,
  fetchAbhAdminAgents,
  restoreAdminSession,
  type AdminAgentDetails,
  type AdminAgentItem,
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

function AdminHome({ admin, onSignOut }: { admin: SessionAdmin; onSignOut: () => void }) {
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
