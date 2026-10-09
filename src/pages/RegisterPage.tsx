import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Smartphone, KeyRound, ShieldAlert } from 'lucide-react';
import { generateAndStoreDeviceIdentity, getDeviceIdentity } from '../crypto/keys';
import { login, registerDevice, ApiError, type SessionAgent } from '../api/client';

/**
 * First-device registration.
 *
 * A standalone, single-screen version of the sign-in flow for the case it was
 * never built for: a brand-new agent, holding a username, a password and a
 * one-time code an administrator issued for them. The ordinary sign-in page
 * assumes the device is already bound and only asks for a code reactively; an
 * onboarding agent knows up front that they have one, and asking for everything
 * on one screen means one submission instead of a two-step dance on a phone.
 *
 * The three steps are kept visibly distinct because they fail for completely
 * different reasons, and telling them apart is the whole point of the page:
 *
 *   1. credentials  — a wrong username or password is one indistinguishable 401
 *   2. device key   — the browser could not mint or store a key pair at all
 *                     (crypto.subtle missing off HTTPS, storage blocked)
 *   3. binding      — the credentials were fine and the key was fine, but the
 *                     code was wrong, already used, or expired
 *
 * Collapsing 2 and 3 into "sign in failed" is exactly the failure this page
 * exists to end.
 */
export default function RegisterPage({
  onAuthenticated
}: {
  onAuthenticated: (agent: SessionAgent) => void;
}) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [pairingCode, setPairingCode] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      // 1. Credentials. This is the only step that can answer "wrong username
      //    or password", so it never shares a catch block with the others.
      try {
        await login(username, password);
      } catch (err) {
        setErrorMsg(
          err instanceof ApiError && err.status === 429
            ? 'Too many attempts. Wait a few minutes and try again.'
            : 'Sign in failed. Check your username and password.'
        );
        return;
      }

      // 2. Device key. Reuse an existing one: regenerating on every attempt
      //    would present a new device_uuid to a policy that may only permit the
      //    first device. A failure here is a browser/environment problem, not a
      //    credential or code problem, and is reported as such.
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
        return;
      }

      // 3. Bind the key with the code. A 422 that names `pairing_code` means the
      //    code itself was refused; anything else is shown verbatim.
      try {
        const result = await registerDevice(pairingCode.trim());
        onAuthenticated(result.agent);
        navigate('/', { replace: true });
      } catch (err) {
        if (err instanceof ApiError && err.field === 'pairing_code') {
          setErrorMsg(
            'That registration code was not accepted. It may be wrong, already used, ' +
            'or expired — ask an administrator to issue a new one.'
          );
        } else {
          setErrorMsg(
            err instanceof Error ? err.message : 'Device registration failed. Ask for a new code and try again.'
          );
        }
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
      <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
        <div className="flex justify-center mb-6 text-blue-700">
          <Smartphone size={48} aria-hidden="true" />
        </div>
        <h1 className="text-2xl font-bold text-center text-gray-900 mb-2">Register this device</h1>
        <p className="text-sm text-gray-700 text-center mb-6">
          Enter the username, password and registration code your administrator gave you.
        </p>

        {errorMsg && (
          <div role="alert" className="bg-red-100 text-red-800 p-3 rounded mb-4 text-sm">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
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
          <div>
            <label htmlFor="pairing" className="block text-sm font-medium text-gray-800">
              Registration code
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
              Issued by an administrator. Single-use and expiring.
            </p>
          </div>

          <button
            type="submit"
            disabled={busy || username.trim() === '' || password === '' || pairingCode.trim() === ''}
            className="w-full bg-blue-700 text-white p-3 rounded-md font-medium hover:bg-blue-800 flex justify-center items-center gap-2 disabled:opacity-60"
          >
            {busy ? 'Registering…' : <><KeyRound size={20} aria-hidden="true" /> Register device</>}
          </button>
        </form>

        <p className="mt-4 text-xs text-gray-700 text-center">
          This device gets its own cryptographic key on registration. The key never leaves it,
          and it is not sent to the server in any form.
        </p>

        <p className="mt-4 text-sm text-gray-700 text-center">
          Already registered?{' '}
          <Link to="/login" className="text-blue-700 font-medium hover:underline">
            Sign in
          </Link>
        </p>

        <p className="mt-2 text-xs text-gray-600 text-center flex items-center justify-center gap-1">
          <ShieldAlert size={12} aria-hidden="true" />
          No code? Ask an administrator to issue one.
        </p>
      </div>
    </div>
  );
}
