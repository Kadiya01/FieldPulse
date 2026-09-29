let accessToken: string | null = null;

/**
 * Cross-tab token handoff, for the refresh cookie's sake.
 *
 * The refresh token is a single cookie shared by every tab, and the server
 * treats presenting a rotated token as theft and kills the whole family. Two
 * tabs that each refresh independently therefore log the user out — not
 * eventually, on the second refresh, but immediately and confusingly.
 *
 * So a newly minted access token is announced on a BroadcastChannel rather than
 * written to storage. A BroadcastChannel is transient, in-memory, and scoped to
 * the origin: the token lives in another tab's memory exactly as long as that
 * tab is open, and a token left in localStorage or sessionStorage would still be
 * readable by any script that gets injected later, long after the tab that
 * received it is gone. It is the same confidentiality the access token already
 * relies on, extended across tabs instead of across sessions.
 */
const channel: BroadcastChannel | null = typeof BroadcastChannel === 'function'
  ? new BroadcastChannel('fieldpulse:auth')
  : null;

interface TokenBroadcast {
  type: 'token';
  access_token: string;
}

let onRemoteToken: ((token: string) => void) | null = null;

if (channel) {
  channel.onmessage = (event: MessageEvent<TokenBroadcast>) => {
    const data = event.data;
    if (data && data.type === 'token' && typeof data.access_token === 'string') {
      // Adopted without touching storage, and without a broadcast of its own:
      // re-announcing would bounce the same token between every open tab.
      accessToken = data.access_token;
      onRemoteToken?.(data.access_token);
    }
  };
}

export function getAccessToken(): string | null {
  return accessToken;
}

export function setAccessToken(token: string | null) {
  accessToken = token;
}

export function announceAccessToken(token: string): void {
  channel?.postMessage({ type: 'token', access_token: token } satisfies TokenBroadcast);
}

/**
 * Register a listener for tokens minted by another tab.
 *
 * Returns an unsubscribe function. Used to cancel in-flight work rather than
 * issue another refresh, so a tab woken by a sibling's refresh does not
 * immediately spend the cookie that sibling just rotated.
 */
export function onRemoteAccessToken(listener: (token: string) => void): () => void {
  onRemoteToken = listener;
  return () => {
    if (onRemoteToken === listener) {
      onRemoteToken = null;
    }
  };
}

export function handleAuthFailure(reason: 'UNREGISTERED' | 'FAILED_AUTH') {
  setAccessToken(null);

  window.dispatchEvent(new CustomEvent('auth_failure', { detail: { reason } }));
}
