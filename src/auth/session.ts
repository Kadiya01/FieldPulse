let accessToken: string | null = null;

export function getAccessToken(): string | null {
  return accessToken;
}

export function setAccessToken(token: string | null) {
  accessToken = token;
}

export function handleAuthFailure(reason: 'UNREGISTERED' | 'FAILED_AUTH') {
  setAccessToken(null);
  
  // Dispatch an event so the React application can update the UI
  // and prompt the user to re-authenticate or re-register.
  window.dispatchEvent(new CustomEvent('auth_failure', { detail: { reason } }));
}
