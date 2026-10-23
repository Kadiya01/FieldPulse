import { createContext, useContext, useMemo } from 'react';
import type { SessionAgent } from '../api/client';
import { isOperatorRole, isAdminRole } from './roles';

/**
 * Who is signed in, shared by the whole tree.
 *
 * The agent is needed in three places that cannot each fetch it: the navigation
 * has to decide whether to offer the review queue, the header names the current
 * user, and `/reviews` has to refuse to render for a non-operator instead of
 * firing a request the server would answer with 403. Threading it down as props
 * would mean three copies and a fourth holder, and `App` already owns the only
 * copy that survives a re-render.
 *
 * This is context, not a store. The agent is set once when the session is
 * restored or logged in and read by everyone; there is no write path that could
 * drift from the token held in `auth/session.ts`.
 */
export interface SessionState {
  agent: SessionAgent | null;
  /** True for SUPERVISOR and ADMIN. False for anything unknown or absent. */
  isOperator: boolean;
  /** True only for ADMIN. False for anything unknown or absent. */
  isAdmin: boolean;
  /** `AG-001 · Ada Lovelace`, or null when the server did not say. */
  label: string | null;
}

export const SessionContext = createContext<SessionState>({
  agent: null,
  isOperator: false,
  isAdmin: false,
  label: null
});

export function useSession(): SessionState {
  return useContext(SessionContext);
}

/**
 * Derive the session state from the agent, memoised on the agent itself.
 *
 * Kept next to the context so the derivation is defined once: the header, the
 * navigation and `/reviews` must agree about whether this is an operator, and
 * three copies of that question is three chances to write it differently. The
 * test is `renderHook(() => useSessionValue(agent))`, which needs no DOM and so
 * costs nothing on a machine slow enough to make one matter.
 */
export function useSessionValue(agent: SessionAgent | null): SessionState {
  return useMemo<SessionState>(() => ({
    agent,
    isOperator: isOperatorRole(agent?.role),
    isAdmin: isAdminRole(agent?.role),
    label: agent && agent.agent_code
      ? `${agent.agent_code} · ${agent.full_name || 'Unnamed agent'}`
      : null
  }), [agent]);
}