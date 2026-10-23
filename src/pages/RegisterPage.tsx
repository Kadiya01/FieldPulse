import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Loader2, ShieldCheck } from 'lucide-react';
import { registerAgent } from '../api/client';
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
  const [nin, setNin] = useState('');
  const [phone, setPhone] = useState('');
  const [imei, setImei] = useState('');
  const [deviceType, setDeviceType] = useState<string>('');
  const [errorMsg, setErrorMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const [registered, setRegistered] = useState(false);
  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setErrorMsg('');

    try {
      const normalizedImei = imei.replace(/[\s-]/g, '');
      await registerAgent({
        full_name: fullName.trim(),
        nin: nin.trim(),
        phone: phone.trim(),
        imei: normalizedImei,
        device_type: deviceType,
      });

      setRegistered(true);
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'Registration could not be completed.');
    } finally {
      setBusy(false);
    }
  };

  if (registered) {
    return (
      <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
        <section className="w-full max-w-md rounded-3xl bg-white p-8 text-center shadow-2xl sm:p-10">
          <BrandHeader className="mb-4 flex justify-center" />
          <h1 className="mt-5 text-2xl font-bold text-slate-900">Registration submitted</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">
            Your registration is waiting for administrator approval. You can sign in with your IMEI
            after it is approved.
          </p>
          {errorMsg && (
            <p role="status" className="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
              {errorMsg}
            </p>
          )}
          <Link
            to="/login"
            className="mt-6 inline-flex w-full justify-center rounded-xl bg-blue-700 px-4 py-3 font-semibold text-white hover:bg-blue-800"
          >
            Continue to agent sign in
          </Link>
        </section>
      </main>
    );
  }

  return (
    <main className="min-h-screen bg-slate-950 flex items-center justify-center p-4">
      <section className="w-full max-w-xl rounded-3xl bg-white p-8 shadow-2xl sm:p-10">
      <BrandHeader className="mb-4 flex justify-center" />
        <h1 className="mt-2 text-center text-3xl font-bold text-slate-900">Agent registration</h1>
        <p className="mt-3 text-center text-sm leading-6 text-slate-600">
          Submit your details for administrator approval. You can sign in after your registration is approved.
        </p>

        {errorMsg && (
          <div role="alert" className="mt-6 rounded-xl bg-red-50 p-3 text-sm text-red-800">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleSubmit} className="mt-7 grid gap-5 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label htmlFor="full-name" className="block text-sm font-semibold text-slate-800">
              Full name
            </label>
            <input
              id="full-name"
              type="text"
              autoComplete="name"
              maxLength={191}
              required
              value={fullName}
              onChange={event => setFullName(event.target.value)}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="phone" className="block text-sm font-semibold text-slate-800">
              Nigerian mobile phone number
            </label>
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
            <label htmlFor="nin" className="block text-sm font-semibold text-slate-800">
              National Identification Number (NIN)
            </label>
            <input
              id="nin"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              maxLength={11}
              pattern="[0-9]{11}"
              required
              value={nin}
              onChange={event => setNin(event.target.value.replace(/\D/g, '').slice(0, 11))}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
              aria-describedby="nin-note"
            />
            <p id="nin-note" className="mt-2 text-xs text-slate-500">
              Stored for authorized administrator access.
            </p>
          </div>
          <div>
            <label htmlFor="imei" className="block text-sm font-semibold text-slate-800">
              Device IMEI
            </label>
            <input
              id="imei"
              type="text"
              inputMode="numeric"
              autoComplete="off"
              maxLength={16}
              required
              value={imei}
              onChange={event => setImei(event.target.value.replace(/[^\d\s-]/g, ''))}
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 outline-none focus:border-blue-600 focus:ring-4 focus:ring-blue-100"
            />
          </div>
          <div className="sm:col-span-2">
            <label htmlFor="device-type" className="block text-sm font-semibold text-slate-800">
              Device type
            </label>
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
              ? <><Loader2 className="animate-spin" size={19} aria-hidden="true" /> Creating account…</>
              : <><ShieldCheck size={19} aria-hidden="true" /> Submit for approval</>}
          </button>
        </form>

        <p className="mt-6 text-center text-sm text-slate-600">
          Already registered?{' '}
          <Link to="/login" className="font-semibold text-blue-700 hover:underline">Agent sign in</Link>
        </p>
        <p className="mt-2 text-center text-sm text-slate-600">
          Administrator?{' '}
          <Link to="/admin/login" className="font-semibold text-blue-700 hover:underline">
            Admin sign in
          </Link>
        </p>
      </section>
    </main>
  );
}
