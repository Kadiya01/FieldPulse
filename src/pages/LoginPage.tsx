import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LogIn, KeyRound, ShieldAlert } from 'lucide-react';
import { generateAndStoreDeviceIdentity, getDeviceIdentity } from '../crypto/keys';
import { login, registerDevice, ApiError } from '../api/client';
import type { SessionAgent } from '../api/client';

type Stage = 'credentials' | 'pairing' | 'done';

export default function LoginPage() {
  const [stage, setStage] = useState<Stage>('credentials');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [pairingCode, setPairingCode] = useState('');
  const [agent, setAgent] = useState<SessionAgent | null>(null);
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
  const completeRegistration = async (code?: string) => {
    // Reuse the stored key if the browser already has one. Re-generating on
    // every login would leave an orphan key per attempt and, worse, would
    // present a different device_uuid to a policy that may only permit the
    // first device.
    const existing = await getDeviceIdentity();
    if (!existing) {
      await generateAndStoreDeviceIdentity();
    }

    try {
      const result = await registerDevice(code);
      setAgent(result.agent);
      setStage('done');
      setErrorMsg('');
      navigate('/');
    } catch (err) {
      if (err instanceof ApiError && err.field === 'pairing_code') {
        // The policy wants a code. Show the field and keep the session.
        setStage('pairing');
        setPairingCode('');
        setErrorMsg(
          'This device must be paired before it can be used. Enter the code issued by an administrator.'
        );
        return;
      }

      setErrorMsg(err instanceof Error ? err.message : 'Device registration failed.');
    }
  };

  const handleCredentials = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      await login(username, password);
      await completeRegistration();
    } catch (err) {
      // Every credential failure is deliberately identical server-side, so
      // there is nothing more specific to say than the status allows.
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
      await completeRegistration(pairingCode.trim());
    } finally {
      setBusy(false);
    }
  };

  if (stage === 'pairing') {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
          <div className="flex justify-center mb-6 text-amber-600">
            <ShieldAlert size={48} />
          </div>
          <h1 className="text-2xl font-bold text-center text-gray-800 mb-2">Pair this device</h1>
          <p className="text-sm text-gray-600 text-center mb-6">
            Signed in as <span className="font-medium">{username}</span>.
          </p>

          {errorMsg && (
            <div className="bg-amber-100 text-amber-900 p-3 rounded mb-4 text-sm">{errorMsg}</div>
          )}

          <form onSubmit={handlePairing} className="space-y-4">
            <div>
              <label htmlFor="pairing" className="block text-sm font-medium text-gray-700">
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
                className="mt-1 w-full border-gray-300 rounded-md shadow-sm p-2 border focus:ring-amber-500 focus:border-amber-500"
              />
            </div>
            <button
              type="submit"
              disabled={busy || pairingCode.trim() === ''}
              className="w-full bg-amber-600 text-white p-3 rounded-md font-medium hover:bg-amber-700 flex justify-center items-center gap-2 disabled:opacity-50"
            >
              {busy ? 'Pairing...' : <><KeyRound size={20} /> Pair device</>}
            </button>
          </form>

          <p className="mt-4 text-xs text-gray-500 text-center">
            Codes are single-use and expire. Ask an administrator for a new one if this one fails.
          </p>
        </div>
      </div>
    );
  }

  if (stage === 'done' && agent) {
    return (
      <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm text-center">
          <div className="flex justify-center mb-6 text-green-600">
            <KeyRound size={48} />
          </div>
          <h1 className="text-2xl font-bold text-gray-800">Device registered</h1>
          <p className="mt-2 text-sm text-gray-600">
            {agent.full_name} ({agent.agent_code})
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
      <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
        <div className="flex justify-center mb-6 text-blue-600">
          <KeyRound size={48} />
        </div>
        <h1 className="text-2xl font-bold text-center text-gray-800 mb-6">FieldPulse Login</h1>

        {errorMsg && (
          <div role="alert" className="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleCredentials} className="space-y-4">
          <div>
            <label htmlFor="username" className="block text-sm font-medium text-gray-700">
              Agent Username
            </label>
            <input
              id="username"
              type="text"
              autoComplete="username"
              required
              value={username}
              onChange={e => setUsername(e.target.value)}
              className="mt-1 w-full border-gray-300 rounded-md shadow-sm p-2 border focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700">
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={e => setPassword(e.target.value)}
              className="mt-1 w-full border-gray-300 rounded-md shadow-sm p-2 border focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <button
            type="submit"
            disabled={busy}
            className="w-full bg-blue-600 text-white p-3 rounded-md font-medium hover:bg-blue-700 flex justify-center items-center gap-2 disabled:opacity-50"
          >
            {busy ? 'Signing in...' : <><LogIn size={20} /> Sign in</>}
          </button>
        </form>
        <p className="mt-4 text-xs text-gray-500 text-center">
          This device gets its own cryptographic key on first sign-in. The key never leaves it.
        </p>
      </div>
    </div>
  );
}
