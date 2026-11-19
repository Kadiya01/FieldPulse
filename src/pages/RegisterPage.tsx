import React, { useState } from 'react';
import { Loader2, ShieldCheck } from 'lucide-react';
import { registerAbhAgent } from '../api/client';
import { generateAndStoreDeviceIdentity, getDeviceIdentity, signPayload } from '../crypto/keys';
import BrandHeader from '../components/BrandHeader';

const DEVICE_TYPES = [
  'EACRUGGED',
  'EASTAEON',
  'HENA',
  'MANTRA',
  'OUKITEL',
  'SAMSUNG',
  'WHEATEK',
] as const;

export default function RegisterPage() {
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [email, setEmail] = useState('');
  const [location, setLocation] = useState('');
  const [imei, setImei] = useState('');
  const [deviceType, setDeviceType] = useState<string>('');
  const [agentCode, setAgentCode] = useState('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const [registered, setRegistered] = useState(false);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrorMsg('');

    if (password !== confirmPassword) {
      setErrorMsg('The two passwords do not match.');
      return;
    }

    if (new TextEncoder().encode(password).length > 72) {
      setErrorMsg('Choose a password no longer than 72 bytes.');
      return;
    }

    setBusy(true);
    try {
      // The non-extractable private key stays in this browser. Registration
      // sends only the public JWK and a proof that this browser owns its key.
      let device = await getDeviceIdentity();
      if (!device) {
        await generateAndStoreDeviceIdentity();
        device = await getDeviceIdentity();
      }
      if (!device) {
        throw new Error('This browser could not store its security key. Use a supported browser over HTTPS and try again.');
      }

      const cleanImei = imei.replace(/[\s-]/g, '');
      const keyProof = await signPayload(
        `ABH_REGISTRATION\n${device.device_uuid}\n${cleanImei}`,
        device.private_key
      );

      const result = await registerAbhAgent({
        full_name: fullName.trim(),
        phone: phone.trim(),
        password,
        email: email.trim() || undefined,
        location: location.trim() || undefined,
        imei: cleanImei,
        device_type: deviceType,
        device_uuid: device.device_uuid,
        public_key_jwk: device.public_key_jwk,
        key_proof: keyProof,
      });

      setAgentCode(result.agent.agent_code);
      setRegistered(true);
    } catch (error) {
      setErrorMsg(error instanceof Error ? error.message : 'Registration could not be completed.');
    } finally {
      setBusy(false);
    }
  };

  if (registered) {
    return (
      <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <section className="w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-2xl sm:p-10">
          <BrandHeader className="mb-4 flex justify-center" />
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
            <ShieldCheck size={30} aria-hidden="true" />
          </div>
          <h1 className="mt-5 text-2xl font-bold text-slate-900">Application submitted</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">
            Your ABH agent application is waiting for administrator approval. You cannot submit
            enrollment reports until your account has been approved.
          </p>
          <div className="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Your agent reference</p>
            <p className="mt-1 text-xl font-bold tracking-wide text-slate-900">{agentCode}</p>
            <p className="mt-2 text-xs text-slate-500">Keep this reference for your records.</p>
          </div>
          <p className="mt-5 text-sm text-slate-600">
            The administrator will review your account and registered device. Keep this browser available; its security key is required when signing in.
          </p>
        </section>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      <section className="w-full max-w-xl rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
        <BrandHeader className="mb-4 flex justify-center" />
        <h1 className="mt-2 text-center text-3xl font-bold text-slate-900">ABH agent registration</h1>
        <p className="mt-3 text-center text-sm leading-6 text-slate-600">
          Create your agent account. An administrator must approve your application before you can use the Agent Website.
        </p>

        {errorMsg && (
          <div role="alert" className="mt-6 rounded-xl bg-red-50 p-3 text-sm text-red-800">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="mt-7 grid gap-5 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label htmlFor="full-name" className="block text-sm font-semibold text-slate-800">Full name</label>
            <input
              id="full-name"
              type="text"
              autoComplete="name"
              minLength={2}
              maxLength={160}
              required
              value={fullName}
              onChange={event => setFullName(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div className="sm:col-span-2">
            <label htmlFor="phone" className="block text-sm font-semibold text-slate-800">Nigerian mobile phone number</label>
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
              aria-describedby="phone-note"
            />
            <p id="phone-note" className="mt-2 text-xs text-slate-500">
              Use an 11-digit Nigerian mobile number or include country code +234.
            </p>
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-semibold text-slate-800">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="new-password"
              minLength={8}
              maxLength={72}
              required
              value={password}
              onChange={event => setPassword(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
              aria-describedby="password-note"
            />
            <p id="password-note" className="mt-2 text-xs text-slate-500">
              At least 8 characters, including a letter and a number.
            </p>
          </div>

          <div>
            <label htmlFor="confirm-password" className="block text-sm font-semibold text-slate-800">Confirm password</label>
            <input
              id="confirm-password"
              type="password"
              autoComplete="new-password"
              minLength={8}
              maxLength={72}
              required
              value={confirmPassword}
              onChange={event => setConfirmPassword(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div>
            <label htmlFor="email" className="block text-sm font-semibold text-slate-800">Email address (optional)</label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={event => setEmail(event.target.value)}
              placeholder="agent@example.com"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div>
            <label htmlFor="location" className="block text-sm font-semibold text-slate-800">Operating location / LGA (optional)</label>
            <input
              id="location"
              type="text"
              maxLength={191}
              value={location}
              onChange={event => setLocation(event.target.value)}
              placeholder="e.g. Kano, Fagge LGA"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div>
            <label htmlFor="imei" className="block text-sm font-semibold text-slate-800">Registered device IMEI</label>
            <input
              id="imei"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              minLength={14}
              maxLength={16}
              pattern="[0-9]{14,16}"
              required
              value={imei}
              onChange={event => setImei(event.target.value.replace(/\D/g, '').slice(0, 16))}
              placeholder="14–16 digits"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>

          <div>
            <label htmlFor="device-type" className="block text-sm font-semibold text-slate-800">Device type</label>
            <select
              id="device-type"
              required
              value={deviceType}
              onChange={event => setDeviceType(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            >
              <option value="" disabled>Select your device type</option>
              {DEVICE_TYPES.map(type => <option key={type} value={type}>{type}</option>)}
            </select>
          </div>

          <button
            type="submit"
            disabled={busy || !deviceType}
            className="sm:col-span-2 flex w-full items-center justify-center gap-2 rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {busy
              ? <><Loader2 className="animate-spin" size={19} aria-hidden="true" /> Submitting application…</>
              : <><ShieldCheck size={19} aria-hidden="true" /> Submit for approval</>}
          </button>
        </form>
      </section>
    </main>
  );
}
