import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LogIn, KeyRound, ShieldAlert } from 'lucide-react';
import { generateAndStoreDeviceIdentity, getDeviceIdentity } from '../crypto/keys';
import { login, registerDevice, ApiError, type SessionAgent } from '../api/client';

type Stage = 'credentials' | 'pairing';

/**
 * Sign in, then bind this browser's key.
 *
 * `onAuthenticated` hands the agent to the session gate. It is a prop rather than
 * a second restore because the answer is already in hand here: making the gate
 * re-ask the server for something this call just received would cost a round
 * trip and a visible pause on the one screen where a pause reads as failure.
 */
export default function LoginPage({
  onAuthenticated
}: {
  onAuthenticated: (agent: SessionAgent) => void;
}) {
  const [stage, setStage] = useState<Stage>('credentials');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [pairingCode, setPairingCode] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  /**
   * Register the local key pair against the agent.
   *
   * Pairing is the reason this is separate from the credential step: the
   * server may or may not require a code depending on the agent's policy, and
   * it only says which by returning a 422 that names `pairing_code`. Guessing
   * the policy client-side would mean shipping a field the server has to
   * correct, so the flow asks only when it is actually asked.
   *
   * A pairing code is one-time and out-of-band, so a wrong guess here cannot be
   * retried indefinitely: the server counts attempts. A fresh code is required
   * after three, which is why the code field is cleared on every attempt.
   */
  const completeRegistration = async (code?: string): Promise<SessionAgent | null> => {
    // Getting or minting the key pair is its own failure domain: if crypto or
    // storage is unavailable, that is a browser problem, not a credential
    // problem, and must never be reported as a rejected password. Reuse the
    // stored key if the browser already has one — regenerating on every login
    // would leave an orphan key per attempt and present a different device_uuid
    // to a policy that may only permit the first device.
    try {
      const existing = await getDeviceIdentity();
      if (!existing) {
        await generateAndStoreDeviceIdentity();
      }
    } catch {
      setErrorMsg(
        'This browser could not create a device key. Open this page over HTTPS, ' +
        'allow storage for this site, or try another browser.'
      );
      return null;
    }

    try {
      const result = await registerDevice(code);
      setErrorMsg('');
      return result.agent;
    } catch (err) {
      if (err instanceof ApiError && err.field === 'pairing_code') {
        // The policy wants a code. Show the field and keep the session.
        setStage('pairing');
        setPairingCode('');
        setErrorMsg(
          'This device must be paired before it can be used. Enter the code issued by an administrator.'
        );
        return null;
      }

      setErrorMsg(err instanceof Error ? err.message : 'Device registration failed.');
      return null;
    }
  };

  const handleCredentials = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      await login(username, password);
      const agent = await completeRegistration();

      if (agent) {
        onAuthenticated(agent);
        navigate('/', { replace: true });
      }
    } catch (err) {
      // Only the credentials call reaches here: completeRegistration handles its
      // own device and pairing failures and reports them itself. Every
      // credential failure is deliberately identical server-side, so there is
      // nothing more specific to say than the status allows.
      setErrorMsg(
        err instanceof ApiError && err.status === 429
          ? 'Too many attempts. Wait a few minutes and try again.'
          : 'Sign in failed. Check your username and password.'
      );
    } finally {
      setBusy(false);
    }
  };

  const handlePairing = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      const agent = await completeRegistration(pairingCode.trim());

      if (agent) {
        onAuthenticated(agent);
        navigate('/', { replace: true });
      }
    } catch (err) {
      setErrorMsg(
        err instanceof Error ? err.message : 'Pairing failed. Ask for a new code and try again.'
      );
    } finally {
      setBusy(false);
    }
  };

  if (stage === 'pairing') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
          <div className="flex justify-center mb-6 text-amber-700">
            <ShieldAlert size={48} aria-hidden="true" />
          </div>
          <h1 className="text-2xl font-bold text-center text-gray-900 mb-2">Pair this device</h1>
          <p className="text-sm text-gray-700 text-center mb-6">
            Signed in as <span className="font-medium">{username}</span>.
          </p>

          {/* An instruction, not an error, so it is announced politely: an alert
              role here would interrupt a screen reader mid-form for something the
              agent is being asked to do, not something that went wrong. */}
          <div role="status" className="bg-amber-100 text-amber-900 p-3 rounded mb-4 text-sm">
            {errorMsg}
          </div>

          <form onSubmit={handlePairing} className="space-y-4">
            <div>
              <label htmlFor="pairing" className="block text-sm font-medium text-gray-800">
                Pairing code
              </label>
              <input
                id="pairing"
                type="text"
                inputMode="numeric"
                autoComplete="one-time-code"
                required
                value={pairingCode}
                onChange={e => setPairingCode(e.target.value)}
                aria-describedby="pairing-help"
                className="mt-1 w-full border-gray-400 rounded-md shadow-sm p-2 border"
              />
              <p id="pairing-help" className="mt-1 text-xs text-gray-700">
                Issued out of band by an administrator. Single-use and expiring.
              </p>
            </div>
            <button
              type="submit"
              disabled={busy || pairingCode.trim() === ''}
              className="w-full bg-amber-700 text-white p-3 rounded-md font-medium hover:bg-amber-800 flex justify-center items-center gap-2 disabled:opacity-60"
            >
              {busy ? 'Pairing…' : <><KeyRound size={20} aria-hidden="true" /> Pair device</>}
            </button>
          </form>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
      <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
        <div className="flex justify-center mb-6 text-blue-700">
          <KeyRound size={48} aria-hidden="true" />
        </div>
        <h1 className="text-2xl font-bold text-center text-gray-900 mb-6">FieldPulse sign in</h1>

        {errorMsg && (
          <div role="alert" className="bg-red-100 text-red-800 p-3 rounded mb-4 text-sm">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleCredentials} className="space-y-4">
          <div>
            <label htmlFor="username" className="block text-sm font-medium text-gray-800">
              Agent username
            </label>
            <input
              id="username"
              type="text"
              autoComplete="username"
              required
              value={username}
              onChange={e => setUsername(e.target.value)}
              className="mt-1 w-full border-gray-400 rounded-md shadow-sm p-2 border"
            />
          </div>
          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-800">
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={e => setPassword(e.target.value)}
              className="mt-1 w-full border-gray-400 rounded-md shadow-sm p-2 border"
            />
          </div>
          <button
            type="submit"
            disabled={busy}
            className="w-full bg-blue-700 text-white p-3 rounded-md font-medium hover:bg-blue-800 flex justify-center items-center gap-2 disabled:opacity-60"
          >
            {busy ? 'Signing in…' : <><LogIn size={20} aria-hidden="true" /> Sign in</>}
          </button>
        </form>
        <p className="mt-4 text-xs text-gray-700 text-center">
          This device gets its own cryptographic key on first sign-in. The key never leaves it,
          and it is not sent to the server in any form.
        </p>

        <p className="mt-4 text-sm text-gray-700 text-center">
          First time on this device?{' '}
          <Link to="/register" className="text-blue-700 font-medium hover:underline">
            Register it with a code
          </Link>
        </p>
      </div>
    </div>
  );
}