import { getDeviceIdentity, signPayload, hashRequestBody, generateNonce } from '../crypto/keys';
import { getAccessToken, setAccessToken, handleAuthFailure } from '../auth/session';

export const API_BASE = '/api/v1';

export interface ApiRequestOptions extends RequestInit {
  body?: string | FormData;
}

export async function authenticatedFetch(path: string, options: ApiRequestOptions = {}): Promise<Response> {
  const device = await getDeviceIdentity();
  if (!device) {
    // If the Web Crypto private key is cleared or missing from IndexedDB, transition to UNREGISTERED state
    handleAuthFailure('UNREGISTERED');
    throw new Error('Device not registered or private key missing');
  }

  const accessToken = getAccessToken();
  if (!accessToken) {
    throw new Error('No access token available');
  }

  const method = options.method || 'GET';
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = generateNonce();

  let requestBodyString = '';
  if (options.body) {
    if (options.body instanceof FormData) {
      // For multipart/form-data, standardizing the body hash is complex.
      // Usually, it's better to hash specific fields or a known serialization,
      // but if the spec requires SHA256(REQUEST_BODY), we have to be careful with boundary strings.
      // We'll leave this as a placeholder or empty string for the hash depending on server expectations,
      // or we can hash specific form fields if agreed in contract.
      // Assuming empty string hash for multipart for now unless server expects full binary hash including boundaries.
      requestBodyString = '';
    } else {
      requestBodyString = options.body;
    }
  }

  const bodyHash = await hashRequestBody(requestBodyString);

  // The canonical signing payload MUST include:
  // HTTP_METHOD
  // REQUEST_PATH
  // REQUEST_TIMESTAMP
  // REQUEST_NONCE
  // SHA256(REQUEST_BODY)
  const canonicalPayload = [
    method.toUpperCase(),
    path,
    timestamp,
    nonce,
    bodyHash
  ].join('\n');

  const signature = await signPayload(canonicalPayload, device.private_key);

  const headers = new Headers(options.headers || {});
  headers.set('Authorization', `Bearer ${accessToken}`);
  headers.set('X-Device-UUID', device.device_uuid);
  headers.set('X-Request-Timestamp', timestamp);
  headers.set('X-Request-Nonce', nonce);
  headers.set('X-Device-Signature', signature);

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
  });

  if (response.status === 401) {
    // Attempt token refresh
    const refreshed = await attemptTokenRefresh();
    if (refreshed) {
      // Retry original request
      return authenticatedFetch(path, options);
    } else {
      handleAuthFailure('FAILED_AUTH');
      throw new Error('Authentication failed');
    }
  }

  if (response.status === 403) {
    handleAuthFailure('FAILED_AUTH');
    throw new Error('Forbidden - Device Revoked');
  }

  return response;
}

async function attemptTokenRefresh(): Promise<boolean> {
  try {
    const response = await fetch(`${API_BASE}/auth/refresh.php`, {
      method: 'POST',
      // Cookies are automatically sent if withCredentials is true (or SameOrigin)
    });
    if (response.ok) {
      const data = await response.json();
      // The rotated access token has to be stored before the caller retries.
      // RefreshController returns it under access_token; discarding it here
      // made the retry re-send the token that had just been rejected, so the
      // 401 branch recursed instead of recovering.
      setAccessToken(data.access_token);
      return true;
    }
    return false;
  } catch (error) {
    return false;
  }
}
