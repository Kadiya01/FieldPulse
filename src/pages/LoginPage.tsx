import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { generateAndStoreDeviceIdentity } from '../crypto/keys';
import { setAccessToken } from '../auth/session';
import { API_BASE } from '../api/client';
import { LogIn, KeyRound } from 'lucide-react';

export default function LoginPage() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [isRegistering, setIsRegistering] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const navigate = useNavigate();

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsRegistering(true);
    setErrorMsg('');

    try {
      // 1. Generate Web Crypto Identity (this stores the private key non-extractably in Dexie)
      const { device_uuid, public_jwk } = await generateAndStoreDeviceIdentity();

      // 2. Perform Login and Registration in one or two steps depending on backend contract.
      // The prompt specified:
      // POST /api/v1/auth/login.php
      // POST /api/v1/device/register.php
      
      // Let's assume login returns the short-lived access token and sets the HttpOnly cookie.
      const loginRes = await fetch(`${API_BASE}/auth/login.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
      });

      if (!loginRes.ok) {
        throw new Error('Invalid credentials');
      }

      const loginData = await loginRes.json();
      setAccessToken(loginData.access_token);

      // Now register the device identity
      const regRes = await fetch(`${API_BASE}/device/register.php`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${loginData.access_token}`
        },
        body: JSON.stringify({
          device_uuid,
          public_jwk
        })
      });

      if (!regRes.ok) {
        throw new Error('Device registration failed');
      }

      // Success
      navigate('/');
    } catch (err: any) {
      setErrorMsg(err.message || 'Login failed.');
    } finally {
      setIsRegistering(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-4">
      <div className="bg-white p-8 rounded-lg shadow-lg w-full max-w-sm">
        <div className="flex justify-center mb-6 text-blue-600">
          <KeyRound size={48} />
        </div>
        <h1 className="text-2xl font-bold text-center text-gray-800 mb-6">FieldPulse Login</h1>
        
        {errorMsg && (
          <div className="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm">
            {errorMsg}
          </div>
        )}

        <form onSubmit={handleLogin} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700">Agent Username</label>
            <input 
              type="text" 
              required
              value={username}
              onChange={e => setUsername(e.target.value)}
              className="mt-1 w-full border-gray-300 rounded-md shadow-sm p-2 border focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700">Password</label>
            <input 
              type="password" 
              required
              value={password}
              onChange={e => setPassword(e.target.value)}
              className="mt-1 w-full border-gray-300 rounded-md shadow-sm p-2 border focus:ring-blue-500 focus:border-blue-500"
            />
          </div>
          <button 
            type="submit" 
            disabled={isRegistering}
            className="w-full bg-blue-600 text-white p-3 rounded-md font-medium hover:bg-blue-700 flex justify-center items-center gap-2 disabled:opacity-50"
          >
            {isRegistering ? 'Registering Device...' : <><LogIn size={20} /> Login & Register Device</>}
          </button>
        </form>
        <p className="mt-4 text-xs text-gray-500 text-center">
          Logging in generates a unique cryptographic identity for this device.
        </p>
      </div>
    </div>
  );
}
