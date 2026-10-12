import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LogIn, Smartphone, ShieldAlert } from 'lucide-react';
import { generateAndStoreDeviceIdentity, getDeviceIdentity } from '../crypto/keys';
import { ApiError, loginAgent, registerDevice, type SessionAgent } from '../api/client';
import { loginErrorMessage } from '../auth/loginErrors';

type Stage = 'imei' | 'pairing';

export default function AgentLoginPage({
  onAuthenticated
}: {
  onAuthenticated: (agent: SessionAgent) => void;
}) {
  const [stage, setStage] = useState<Stage>('imei');
  const [imei, setImei] = useState('');
  const [pairingCode, setPairingCode] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  const bindDevice = async (code?: string): Promise<SessionAgent | null> => {
    try {
      const existing = await getDeviceIdentity();
      if (!existing) {
        await generateAndStoreDeviceIdentity();
      }
    } catch {
      setErrorMsg(
        'This browser could not create a device key. Open FieldPulse over HTTPS and allow site storage.'
      );
      return null;
    }

    try {
      const result = await registerDevice(code);
      setErrorMsg('');
      return result.agent;
    } catch (err) {
      if (err instanceof ApiError && err.field === 'pairing_code') {
        setStage('pairing');
        setPairingCode('');
        setErrorMsg('This account needs a one-time device pairing code. Ask an administrator for one.');
        return null;
      }

      setErrorMsg(err instanceof Error ? err.message : 'Could not register this device.');
      return null;
    }
  };

  const handleLogin = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      await loginAgent(imei.trim());
      const agent = await bindDevice();
      if (agent) {
        onAuthenticated(agent);
        navigate('/', { replace: true });
      }
    } catch (err) {
      setErrorMsg(loginErrorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const handlePairing = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      const agent = await bindDevice(pairingCode.trim());
      if (agent) {
        onAuthenticated(agent);
        navigate('/', { replace: true });
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      <section className="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <div className="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-100 text-blue-700">
          <Smartphone size={28} aria-hidden="true" />
        </div>
        <p className="text-center text-sm font-semibold uppercase tracking-[0.2em] text-blue-700">
          FieldPulse
        </p>
        <h1 className="mt-2 text-center text-3xl font-bold text-slate-900">
          {stage === 'imei' ? 'Agent sign in' : 'Pair this device'}
        </h1>
        <p className="mt-3 text-center text-sm leading-6 text-slate-600">
          {stage === 'imei'
            ? 'Use the IMEI you entered during registration.'
            : 'Enter the one-time code provided by your administrator.'}
        </p>

        {errorMsg && (
          <div role="alert" className="mt-6 rounded-xl bg-red-50 p-3 text-sm text-red-800">
            {errorMsg}
          </div>
        )}

        {stage === 'imei' ? (
          <form onSubmit={handleLogin} className="mt-7 space-y-5">
            <div>
              <label htmlFor="agent-imei" className="block text-sm font-semibold text-slate-800">
                Device IMEI
              </label>
              <input
                id="agent-imei"
                type="text"
                inputMode="numeric"
                autoComplete="off"
                maxLength={16}
                required
                value={imei}
                onChange={event => setImei(event.target.value.replace(/[^\d\s-]/g, ''))}
                className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none transition focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
                placeholder="Enter your IMEI"
              />
              <p className="mt-2 text-xs text-slate-500">
                IMEI is an identifier, not a secret. Your browser also binds its device key.
              </p>
            </div>
            <button
              type="submit"
              disabled={busy || imei.trim() === ''}
              className="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {busy ? 'Signing in…' : <><LogIn size={19} aria-hidden="true" /> Sign in</>}
            </button>
          </form>
        ) : (
          <form onSubmit={handlePairing} className="mt-7 space-y-5">
            <label htmlFor="pairing-code" className="block text-sm font-semibold text-slate-800">
              One-time pairing code
            </label>
            <input
              id="pairing-code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              required
              value={pairingCode}
              onChange={event => setPairingCode(event.target.value)}
              className="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
            <button
              type="submit"
              disabled={busy || pairingCode.trim() === ''}
              className="w-full rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800 disabled:opacity-60"
            >
              {busy ? 'Pairing…' : 'Pair device'}
            </button>
          </form>
        )}

        <div className="mt-7 border-t border-slate-100 pt-5 text-center text-sm text-slate-600">
          New to FieldPulse?{' '}
          <Link to="/register" className="font-semibold text-blue-700 hover:underline">
            Register as an agent
          </Link>
        </div>
        <div className="mt-3 flex items-center justify-center gap-1 text-xs text-slate-500">
          <ShieldAlert size={13} aria-hidden="true" />
          <Link to="/admin/login" className="hover:text-blue-700 hover:underline">
            Administrator sign in
          </Link>
        </div>
      </section>
    </main>
  );
}
