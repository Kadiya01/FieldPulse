export type PeriodType = 'WEEKLY' | 'MONTHLY';

export interface CachedLeaderboardShape {
  period_type?: PeriodType;
  period_start_date: string;
}

/**
 * Cache keys are period-type scoped.
 *
 * A month and a week can share the same period_start_date (when the first of a
 * month is a Monday). One shared key would let the monthly response overwrite
 * the weekly one and the offline screen would show the wrong board without any
 * failed request to explain it.
 */
const CACHE_PREFIX = 'fieldpulse_leaderboard_v3';

export function leaderboardCacheKey(periodType: PeriodType): string {
  return `${CACHE_PREFIX}_${periodType}`;
}

export function readLeaderboardCache<T extends CachedLeaderboardShape>(
  periodType: PeriodType
): T | null {
  try {
    const raw = localStorage.getItem(leaderboardCacheKey(periodType));

    if (!raw) {
      return null;
    }

    const parsed = JSON.parse(raw) as T;

    // A record carrying a different type is treated as absent rather than
    // rendered under the wrong heading.
    if (parsed.period_type && parsed.period_type !== periodType) {
      return null;
    }

    return parsed;
  } catch {
    // A corrupt or unreadable cache is not worth surfacing: the only thing it
    // can cost is a stale board, and a parse failure is not the agent's problem.
    return null;
  }
}

export function writeLeaderboardCache<T extends CachedLeaderboardShape>(
  board: T,
  periodType?: PeriodType
): void {
  const type = periodType ?? board.period_type ?? 'WEEKLY';

  try {
    localStorage.setItem(
      leaderboardCacheKey(type),
      JSON.stringify({ ...board, period_type: type })
    );
  } catch {
    // Private mode or a full quota must not break a board that was just fetched.
  }
}
