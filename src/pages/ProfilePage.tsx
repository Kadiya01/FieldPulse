import { useEffect, useState } from 'react';
import {
  fetchProfile,
  updateProfile,
  changePassword,
  ApiError,
  type ProfileData
} from '../api/client';
import {
  AlertCircle,
  KeyRound,
  Loader2,
  Save,
  ShieldCheck
} from 'lucide-react';

/**
 * The agent's own contact details.
 *
 * Only email, location, phone and the agent's own password are editable. NIN,
 * IMEI, role, status and the monthly target are shown as read-only facts: those
 * are set by registration or by an administrator, and a profile form that could
 * change them would be a privilege-escalation surface and a way to move the
 * number an agent is measured against.
 *
 * Password change requires the current password even though the caller already
 * has a live session, so an unattended handset cannot be used to lock the owner
 * out of their own account.
 */
export default function ProfilePage() {
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [email, setEmail] = useState('');
  const [location, setLocation] = useState('');
  const [phone, setPhone] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [notice, setNotice] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmNewPassword, setConfirmNewPassword] = useState('');
  const [changingPassword, setChangingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [passwordNotice, setPasswordNotice] = useState('');

  useEffect(() => {
    let live = true;

    (async () => {
      try {
        const fresh = await fetchProfile();
        if (live) {
          setProfile(fresh);
          setEmail(fresh.email ?? '');
          setLocation(fresh.location ?? '');
          setPhone(fresh.phone ?? '');
          setErrorMsg('');
        }
      } catch (err) {
        if (live) {
          setErrorMsg(err instanceof Error ? err.message : 'Could not load your profile.');
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
  }, []);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setErrorMsg('');
    setNotice('');

    try {
      const updated = await updateProfile({ email, location, phone });
      setProfile(updated);
      setEmail(updated.email ?? '');
      setLocation(updated.location ?? '');
      setPhone(updated.phone ?? '');
      setNotice('Profile updated.');
    } catch (err) {
      if (err instanceof ApiError && err.field) {
        setErrorMsg(`${err.message} (${err.field})`);
      } else {
        setErrorMsg(err instanceof Error ? err.message : 'The profile was not saved.');
      }
    } finally {
      setSaving(false);
    }
  };

  const updatePassword = async (event: React.FormEvent) => {
    event.preventDefault();
    setPasswordError('');
    setPasswordNotice('');

    if (newPassword !== confirmNewPassword) {
      setPasswordError('The two new passwords do not match.');
      return;
    }

    setChangingPassword(true);

    try {
      await changePassword(currentPassword, newPassword);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmNewPassword('');
      setPasswordNotice('Password changed. Use the new password the next time you sign in.');
    } catch (err) {
      if (err instanceof ApiError && err.field) {
        setPasswordError(`${err.message} (${err.field})`);
      } else {
        setPasswordError(err instanceof Error ? err.message : 'The password was not changed.');
      }
    } finally {
      setChangingPassword(false);
    }
  };

  if (loading) {
    return (
      <p role="status" className="inline-flex items-center gap-2 text-gray-700">
        <Loader2 size={16} className="animate-spin" aria-hidden="true" />
        Loading your profile…
      </p>
    );
  }

  if (!profile) {
    return (
      <section className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center gap-2 text-red-800 mb-2">
          <AlertCircle aria-hidden="true" />
          <h1 className="text-lg font-bold">Profile unavailable</h1>
        </div>
        <p role="alert" className="text-sm text-gray-700">{errorMsg}</p>
      </section>
    );
  }

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-xl font-bold text-gray-900">Profile</h1>
        <p className="text-sm text-gray-700">
          {profile.agent_code} · {profile.full_name}
        </p>
      </div>

      <div aria-live="polite">
        {errorMsg && (
          <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded text-sm mb-3">
            {errorMsg}
          </p>
        )}
        {notice && (
          <p className="bg-green-100 border border-green-400 text-green-900 px-4 py-3 rounded text-sm mb-3">
            {notice}
          </p>
        )}
      </div>

      <form onSubmit={save} className="bg-white rounded-lg shadow p-4 space-y-4">
        <div>
          <label htmlFor="profile-email" className="block text-sm font-medium text-gray-800 mb-1">
            Email
          </label>
          <input
            id="profile-email"
            type="email"
            value={email}
            onChange={e => setEmail(e.target.value)}
            autoComplete="email"
            className="w-full border border-gray-400 rounded-md p-2"
          />
          <p className="mt-1 text-xs text-gray-600">Optional. Used only to contact you about your account.</p>
        </div>

        <div>
          <label htmlFor="profile-location" className="block text-sm font-medium text-gray-800 mb-1">
            Location
          </label>
          <input
            id="profile-location"
            type="text"
            value={location}
            onChange={e => setLocation(e.target.value)}
            autoComplete="address-level2"
            className="w-full border border-gray-400 rounded-md p-2"
          />
          <p className="mt-1 text-xs text-gray-600">The area you work in, for example a city or LGA.</p>
        </div>

        <div>
          <label htmlFor="profile-phone" className="block text-sm font-medium text-gray-800 mb-1">
            Phone
          </label>
          <input
            id="profile-phone"
            type="tel"
            value={phone}
            onChange={e => setPhone(e.target.value)}
            autoComplete="tel"
            required
            className="w-full border border-gray-400 rounded-md p-2"
          />
        </div>

        <button
          type="submit"
          disabled={saving}
          className="inline-flex items-center gap-2 bg-blue-700 text-white px-4 py-2 rounded-md font-medium hover:bg-blue-800 disabled:opacity-60"
        >
          {saving
            ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            : <Save size={16} aria-hidden="true" />}
          {saving ? 'Saving…' : 'Save changes'}
        </button>
      </form>

      <form onSubmit={updatePassword} className="bg-white rounded-lg shadow p-4 space-y-4">
        <div className="flex items-center gap-2 text-gray-800">
          <KeyRound size={18} aria-hidden="true" />
          <h2 className="font-semibold">Change password</h2>
        </div>

        {passwordError && (
          <p role="alert" className="bg-red-100 border border-red-400 text-red-800 px-4 py-3 rounded text-sm">
            {passwordError}
          </p>
        )}
        {passwordNotice && (
          <p role="status" className="bg-green-100 border border-green-400 text-green-900 px-4 py-3 rounded text-sm">
            {passwordNotice}
          </p>
        )}

        <div>
          <label htmlFor="current-password" className="block text-sm font-medium text-gray-800 mb-1">
            Current password
          </label>
          <input
            id="current-password"
            type="password"
            value={currentPassword}
            onChange={e => setCurrentPassword(e.target.value)}
            autoComplete="current-password"
            required
            className="w-full border border-gray-400 rounded-md p-2"
          />
        </div>

        <div>
          <label htmlFor="new-password" className="block text-sm font-medium text-gray-800 mb-1">
            New password
          </label>
          <input
            id="new-password"
            type="password"
            value={newPassword}
            onChange={e => setNewPassword(e.target.value)}
            autoComplete="new-password"
            minLength={8}
            maxLength={128}
            required
            className="w-full border border-gray-400 rounded-md p-2"
          />
        </div>

        <div>
          <label htmlFor="confirm-new-password" className="block text-sm font-medium text-gray-800 mb-1">
            Confirm new password
          </label>
          <input
            id="confirm-new-password"
            type="password"
            value={confirmNewPassword}
            onChange={e => setConfirmNewPassword(e.target.value)}
            autoComplete="new-password"
            minLength={8}
            maxLength={128}
            required
            className="w-full border border-gray-400 rounded-md p-2"
          />
        </div>

        <button
          type="submit"
          disabled={changingPassword}
          className="inline-flex items-center gap-2 bg-blue-700 text-white px-4 py-2 rounded-md font-medium hover:bg-blue-800 disabled:opacity-60"
        >
          {changingPassword
            ? <Loader2 size={16} className="animate-spin" aria-hidden="true" />
            : <KeyRound size={16} aria-hidden="true" />}
          {changingPassword ? 'Changing…' : 'Change password'}
        </button>
      </form>

      <section className="bg-white rounded-lg shadow p-4">
        <div className="flex items-center gap-2 text-gray-800 mb-2">
          <ShieldCheck size={18} aria-hidden="true" />
          <h2 className="font-semibold">Identity and standing</h2>
        </div>
        <dl className="grid gap-2 sm:grid-cols-2 text-sm">
          <Fact label="NIN" value={profile.nin ?? 'Not recorded'} />
          <Fact label="IMEI" value={profile.imei ?? 'Not recorded'} />
          <Fact label="Device type" value={profile.device_type ?? 'Not recorded'} />
          <Fact label="Role" value={profile.role} />
          <Fact label="Status" value={profile.status} />
          <Fact
            label="Monthly target"
            value={profile.monthly_target > 0 ? String(profile.monthly_target) : 'Not configured'}
          />
        </dl>
        <p className="mt-3 text-xs text-gray-600">
          These are set at registration or by an administrator and cannot be changed here.
        </p>
      </section>
    </section>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-gray-600">{label}</dt>
      <dd className="font-medium text-gray-900">{value}</dd>
    </div>
  );
}
