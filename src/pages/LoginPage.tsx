import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { LogIn, ShieldCheck } from 'lucide-react';
import { login, type SessionAgent } from '../api/client';
import { loginErrorMessage } from '../auth/loginErrors';
import BrandHeader from '../components/BrandHeader';

/**
 * ABH agent sign-in.
 *
 * The password proves account knowledge; the non-extractable key created at
 * registration proves this is the registered browser. Account and device must
 * both have administrator approval before the API grants a session.
 */
export default function LoginPage({
  onAuthenticated,
}: {
  onAuthenticated: (agent: SessionAgent) => void;
}) {
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      const result = await login(phone.trim(), password);
      onAuthenticated(result.agent);
      navigate('/', { replace: true });
    } catch (error) {
      setErrorMsg(loginErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      <section className="w-full max-w-md rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <BrandHeader className="mb-4 flex justify-center" />
        <h1 className="mt-2 text-center text-3xl font-bold text-slate-900">Agent sign in</h1>
        <p className="mt-3 text-center text-sm leading-6 text-slate-600">
          Sign in with the phone number and password used during ABH registration.
        </p>

        {errorMsg && (
          <div role="alert" className="mt-6 rounded-xl bg-red-50 p-3 text-sm text-red-800">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="mt-7 space-y-5">
          <div>
            <label htmlFor="phone" className="block text-sm font-semibold text-slate-800">Phone number</label>
            <input
              id="phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              required
              value={phone}
              onChange={event => setPhone(event.target.value)}
              placeholder="08012345678 or +2348012345678"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-semibold text-slate-800">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={event => setPassword(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <button
            type="submit"
            disabled={busy || phone.trim() === '' || password === ''}
            className="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {busy
              ? 'Signing in…'
              : <><LogIn size={19} aria-hidden="true" /> Sign in</>}
          </button>
        </form>

        <div className="mt-6 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <ShieldCheck size={20} className="mt-0.5 shrink-0 text-blue-700" aria-hidden="true" />
          <p className="text-xs leading-5 text-slate-600">
            Sign in on the same browser used during registration. Its private security key stays
            on that device. The administrator must approve both your account and device first.
          </p>
        </div>

        <p className="mt-6 text-center text-sm text-slate-600">
          New to ABH?{' '}
          <Link to="/register" className="font-semibold text-blue-700 hover:underline">
            Register as an agent
          </Link>
        </p>
      </section>
    </main>
  );
}
