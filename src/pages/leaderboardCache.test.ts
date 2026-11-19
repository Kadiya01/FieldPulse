// @vitest-environment jsdom
import { beforeEach, describe, expect, it } from 'vitest';
import {
  leaderboardCacheKey,
  readLeaderboardCache,
  writeLeaderboardCache
} from './leaderboardCache';

interface TestBoard {
  period_type?: 'WEEKLY' | 'MONTHLY';
  period_start_date: string;
  entries: string[];
}

beforeEach(() => {
  localStorage.clear();
});

describe('leaderboard cache', () => {
  it('uses a different key for each period type', () => {
    expect(leaderboardCacheKey('WEEKLY')).not.toBe(leaderboardCacheKey('MONTHLY'));
  });

  it('keeps weekly and monthly boards with the same start date apart', () => {
    const weekly: TestBoard = {
      period_type: 'WEEKLY',
      period_start_date: '2026-06-01',
      entries: ['weekly']
    };
    const monthly: TestBoard = {
      period_type: 'MONTHLY',
      period_start_date: '2026-06-01',
      entries: ['monthly']
    };

    writeLeaderboardCache(weekly);
    writeLeaderboardCache(monthly);

    expect(readLeaderboardCache<TestBoard>('WEEKLY')?.entries).toEqual(['weekly']);
    expect(readLeaderboardCache<TestBoard>('MONTHLY')?.entries).toEqual(['monthly']);
  });

  it('does not return a record carrying the other period type', () => {
    localStorage.setItem(
      leaderboardCacheKey('WEEKLY'),
      JSON.stringify({ period_type: 'MONTHLY', period_start_date: '2026-06-01', entries: [] })
    );

    expect(readLeaderboardCache<TestBoard>('WEEKLY')).toBeNull();
  });

  it('stamps the requested type when the response omits it', () => {
    writeLeaderboardCache<TestBoard>(
      { period_start_date: '2026-06-01', entries: [] },
      'MONTHLY'
    );

    expect(readLeaderboardCache<TestBoard>('MONTHLY')?.period_type).toBe('MONTHLY');
  });
});
