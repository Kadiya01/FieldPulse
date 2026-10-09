/**
 * Who may be shown the operator-only review queue and the admin directory.
 *
 * This lives apart from `api/client.ts` on purpose. The role decides what the UI
 * draws, and nothing else: the server re-checks it against the database row on
 * every request (`Kernel::assertOperator` for reviews, `Kernel::assertAdmin` for
 * the account directory), so a client that edited these values locally would see
 * the links and receive 403 from everything behind them.
 *
 * Keeping it out of the network client means the navigation does not have to
 * import Dexie, the crypto module and the request helpers in order to ask one
 * question about a role — which is what kept the render graph for a nav bar
 * large enough to dominate the component test suite's start-up.
 */

/** The three roles the schema allows. Anything else is treated as an agent. */
export type KnownRole = 'AGENT' | 'SUPERVISOR' | 'ADMIN';

export const OPERATOR_ROLES: readonly KnownRole[] = ['SUPERVISOR', 'ADMIN'];

export const ADMIN_ROLES: readonly KnownRole[] = ['ADMIN'];

/**
 * Is this role allowed to see the review queue?
 *
 * Defaults to false for anything missing or unrecognised. That direction matters:
 * an unknown identity must render the *narrower* interface, because the failure
 * mode of guessing "yes" is a screen that loads and then 403s, while guessing
 * "no" only ever hides a link that reappears on the next authenticated load.
 */
export function isOperatorRole(role: string | null | undefined): boolean {
  return typeof role === 'string' && (OPERATOR_ROLES as readonly string[]).includes(role);
}

/**
 * Is this role allowed to manage accounts?
 *
 * Narrower than `isOperatorRole`, and for the same reason: an unknown identity
 * must be offered the *fewer* destinations, because the account pages are where
 * one administrator can remove another. The server's `requireAdmin` tier is the
 * enforcement; this only decides whether the link renders.
 */
export function isAdminRole(role: string | null | undefined): boolean {
  return typeof role === 'string' && (ADMIN_ROLES as readonly string[]).includes(role);
}