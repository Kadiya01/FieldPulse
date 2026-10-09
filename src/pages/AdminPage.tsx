import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import {
  fetchAdminAgents,
  createAdminAgent,
  actOnAdminAgent,
  issuePairingCode,
  ApiError,
  type AdminAgent,
  type AdminAgentAction,
  type AdminAgentRole,
  type AdminAgentList,
  type IssuePairingCodeResult
} from '../api/client';
import { useSession } from '../auth/sessionContext';
import {
  ShieldAlert,
  RefreshCw,
  Loader2,
  ChevronLeft,
  ChevronRight,
  UserPlus,
  Ban,
  Archive,
  RotateCcw,
  KeyRound,
  Smartphone,
  Copy,
  Check,
  X
} from 'lucide-react';

const LIMIT = 50;

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

  const emptyForm = {
    agent_code: '',
    full_name: '',
    username: '',
    password: '',
    role: 'AGENT' as AdminAgentRole,
    imei: ''
  };
  const [form, setForm] = useState(emptyForm);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState('');

  const [rowError, setRowError] = useState<{ id: number; message: string } | null>(null);
  const [rowBusy, setRowBusy] = useState<number | null>(null);
  const [passwordResetId, setPasswordResetId] = useState<number | null>(null);
  const [resetPassword, setResetPassword] = useState('');
  const [issuedCode, setIssuedCode] = useState<{ id: number; result: IssuePairingCodeResult } | null>(null);

  const total = meta?.pagination.total ?? 0;
  const maxOffset = Math.max(0, Math.floor((total - 1) / LIMIT) * LIMIT);

  /** Load the visible page. A press or an effect: state is set after the await. */
  const fetchDir = async (at: number) => {
    setLoading(true);
    setErrorMsg('');

    try {
      const dir = await fetchAdminAgents(LIMIT, at);
      setAgents(dir.agents);
      setMeta(dir.meta);
      setNotice('');
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
        const dir = await fetchAdminAgents(LIMIT, offset);

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
  }, [isAdmin, offset]);

  /** Create an account, then land on the page where the new row can be seen. */
  const createAgent = async (event: FormEvent) => {
    event.preventDefault();

    const problem = validateCreate(form);
    if (problem) {
      setCreateError(problem);
      return;
    }

    setCreating(true);
    setCreateError('');

    try {
      const created = await createAdminAgent({
        agent_code: form.agent_code.trim(),
        full_name: form.full_name.trim(),
        username: form.username.trim(),
        password: form.password,
        role: form.role,
        imei: form.imei.trim() || undefined
      });

      setForm(emptyForm);
      setNotice(`Account ${created.agent_code} created and active. Pass on their username and password.`);

      // The newest row sorts onto the first page; move there so it is visible
      // without hunting. When already there, refetch directly because `offset`
      // has not changed and the effect will not re-run.
      if (offset !== 0) {
        setOffset(0);
      } else {
        void fetchDir(0);
      }
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : 'The account could not be created.');
    } finally {
      setCreating(false);
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

        <button
          type="button"
          onClick={() => void fetchDir(offset)}
          disabled={loading}
          className="inline-flex items-center gap-1 text-sm font-medium text-blue-800 bg-white px-3 py-2 rounded border border-blue-300 hover:bg-blue-50 disabled:opacity-60"
        >
          {loading
            ? <Loader2 size={14} className="animate-spin" aria-hidden="true" />
            : <RefreshCw size={14} aria-hidden="true" />}
          Refresh
        </button>
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

      <CreateAccountForm
        form={form}
        setForm={setForm}
        creating={creating}
        error={createError}
        onSubmit={createAgent}
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
              onSuspend={() =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'SUSPENDED' },
                  `Suspend ${agent.agent_code}? Their devices are revoked and they can no longer sign in until reinstated.`
                )}
              onReinstate={() => void runAction(agent, { action: 'SET_STATUS', status: 'ACTIVE' })}
              onRetire={() =>
                void runAction(
                  agent,
                  { action: 'SET_STATUS', status: 'DELETED' },
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
 * The create form, folded up by default.
 *
 * A `<details>` element is the native disclosure: keyboard-focusable, announced
 * as a disclosure, and openable without JavaScript. The submit path mirrors the
 * server's validation so a mistake is told to the administrator before a round
 * trip — the server still validates again, and is the authority on collisions.
 */
function CreateAccountForm({
  form,
  setForm,
  creating,
  error,
  onSubmit
}: {
  form: typeof emptyFormShape;
  setForm: (next: typeof emptyFormShape) => void;
  creating: boolean;
  error: string;
  onSubmit: (event: FormEvent) => void;
}) {
  return (
    <details className="bg-white rounded-lg shadow mb-4 open:pb-4">
      <summary className="flex items-center gap-2 px-4 py-3 font-medium text-gray-900 cursor-pointer list-none">
        <UserPlus size={16} aria-hidden="true" />
        Create an account
      </summary>

      <form onSubmit={onSubmit} className="px-4 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
        <Field label="Agent code" htmlFor="new-agent_code" hint="e.g. AG-012">
          <input
            id="new-agent_code"
            value={form.agent_code}
            onChange={e => setForm({ ...form, agent_code: e.target.value })}
            className={inputClass}
            pattern="[A-Za-z0-9._-]{2,64}"
            required
          />
        </Field>

        <Field label="Full name" htmlFor="new-full_name">
          <input
            id="new-full_name"
            value={form.full_name}
            onChange={e => setForm({ ...form, full_name: e.target.value })}
            className={inputClass}
            required
          />
        </Field>

        <Field label="Username" htmlFor="new-username" hint="3–64 characters, with at least one letter">
          <input
            id="new-username"
            value={form.username}
            onChange={e => setForm({ ...form, username: e.target.value })}
            className={inputClass}
            autoComplete="off"
            required
          />
        </Field>

        <Field label="Password" htmlFor="new-password" hint="8–128 characters, at least one letter and one digit">
          <input
            id="new-password"
            type="password"
            value={form.password}
            onChange={e => setForm({ ...form, password: e.target.value })}
            className={inputClass}
            autoComplete="new-password"
            required
          />
        </Field>

        <Field label="Role" htmlFor="new-role">
          <select
            id="new-role"
            value={form.role}
            onChange={e => setForm({ ...form, role: e.target.value as AdminAgentRole })}
            className={inputClass}
          >
            <option value="AGENT">Agent</option>
            <option value="SUPERVISOR">Supervisor</option>
            <option value="ADMIN">Administrator</option>
          </select>
        </Field>

        <Field label="IMEI (optional)" htmlFor="new-imei" hint="Administrative reference only; never a login">
          <input
            id="new-imei"
            value={form.imei}
            onChange={e => setForm({ ...form, imei: e.target.value })}
            className={inputClass}
            inputMode="numeric"
            placeholder="14–16 digits"
          />
        </Field>

        <div className="sm:col-span-2 flex items-center gap-3">
          {error && (
            <p role="alert" className="text-sm text-red-800 bg-red-100 border border-red-300 rounded px-3 py-2 flex-1">
              {error}
            </p>
          )}
          <button
            type="submit"
            disabled={creating}
            className="inline-flex items-center gap-1 bg-blue-700 text-white px-4 py-2 rounded-md font-medium hover:bg-blue-800 disabled:opacity-60 ml-auto"
          >
            {creating
              ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
              : <UserPlus size={16} aria-hidden="true" />}
            Create
          </button>
        </div>
      </form>
    </details>
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
  onRetire,
  onRevoke,
  onIssueCode,
  onDismissCode
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
  onSuspend: () => void;
  onReinstate: () => void;
  onRetire: () => void;
  onRevoke: () => void;
  onIssueCode: () => void;
  onDismissCode: () => void;
}) {
  const retired = agent.status === 'DELETED';
  const [copied, setCopied] = useState(false);

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
            {' · '}{agent.active_device_count} active device{agent.active_device_count === 1 ? '' : 's'}
            {isSelf && <span className="text-gray-600"> · this is you</span>}
          </p>
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
          {!retired && agent.status === 'ACTIVE' && (
            <button
              type="button"
              disabled={busy || isSelf || lastAdmin}
              onClick={onSuspend}
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
              onClick={onReinstate}
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
              onClick={onRetire}
              title={lastAdmin ? 'The last active administrator cannot be retired.' : undefined}
              className="inline-flex items-center gap-1 text-sm font-medium text-red-800 bg-white px-3 py-1.5 rounded border border-red-300 hover:bg-red-50 disabled:opacity-50"
            >
              <Archive size={14} aria-hidden="true" />
              Retire
            </button>
          )}

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

function Field({
  label,
  htmlFor,
  hint,
  children
}: {
  label: string;
  htmlFor: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label htmlFor={htmlFor} className="block text-sm font-medium text-gray-800 mb-1">
        {label}
      </label>
      {children}
      {hint && <p className="text-xs text-gray-600 mt-1">{hint}</p>}
    </div>
  );
}

const inputClass = 'w-full border border-gray-400 rounded-md p-2 text-sm bg-white';

const emptyFormShape = {
  agent_code: '',
  full_name: '',
  username: '',
  password: '',
  role: 'AGENT' as AdminAgentRole,
  imei: ''
};

/**
 * Client-side mirror of Validator's create rules, so the administrator is told
 * why before the round trip. The server re-validates and is the authority on
 * duplicates; this only short-circuits the obvious mistakes.
 */
function validateCreate(form: typeof emptyFormShape): string | null {
  if (!/^[A-Za-z0-9._-]{2,64}$/.test(form.agent_code.trim())) {
    return 'Agent code must be 2 to 64 characters using letters, digits, dot, underscore or hyphen.';
  }

  if (form.full_name.trim().length < 1) {
    return 'Full name is required.';
  }

  const username = form.username.trim();
  if (!/^[A-Za-z0-9._-]{3,64}$/.test(username)) {
    return 'Username must be 3 to 64 characters using letters, digits, dot, underscore or hyphen.';
  }
  if (!/[A-Za-z]/.test(username)) {
    return 'Username must contain at least one letter.';
  }

  const passwordProblem = validatePassword(form.password);
  if (passwordProblem) {
    return passwordProblem;
  }

  const imei = form.imei.trim().replace(/[\s-]/g, '');
  if (imei !== '' && !/^\d{14,16}$/.test(imei)) {
    return 'IMEI must be 14 to 16 digits.';
  }

  return null;
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

const STATUS_WORDS: Record<string, string> = {
  ACTIVE: 'Active',
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
  ACTIVE: 'text-green-800 border-green-300 bg-green-50',
  SUSPENDED: 'text-amber-800 border-amber-300 bg-amber-50',
  DELETED: 'text-gray-800 border-gray-300 bg-gray-100'
};