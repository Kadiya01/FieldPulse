import { test, expect, type Page } from '@playwright/test';
import {
  captureAndSave,
  captureDeviceToken,
  firstPage,
  launchApp,
  openLeaderboard,
  openReviews,
  openRewards,
  signIn,
  signInOperator
} from './helpers';
import {
  bumpLiveSummary,
  processQueue,
  provisionCredentials,
  readSubmissions,
  seedReward
} from './fixtures';

/**
 * Monthly periods, end to end.
 *
 * The monthly engine shares a table, a key and a date with the weekly engine, so
 * the failures worth catching are the ones that only appear when both are live:
 * a weekly row being mistaken for a monthly one on the same start date, a
 * monthly entitlement moving after a late live-summary rewrite, and a monthly
 * total that counts a rejected capture.
 *
 * These tests use real identities, a real browser, the real cron worker and the
 * real API. The two seed helpers arrange a closed period through RewardService;
 * the submission test uploads through the shipped capture path and lets the
 * production worker make the review decision.
 */

const COLLISION_DATE = '2026-06-01';

test.describe('monthly rewards and standings', () => {
  test('monthly and weekly rewards can share a start date without overwriting each other', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const operator = provisionCredentials('SUPERVISOR');

    const weekly = seedReward(agent.agentCode, {
      period: COLLISION_DATE,
      verified: 12,
      amount: '50000',
      currency: 'NGN'
    });

    const monthly = seedReward(agent.agentCode, {
      periodType: 'MONTHLY',
      period: COLLISION_DATE,
      verified: 34,
      amount: '60000',
      currency: 'NGN'
    });

    expect(weekly.period_type, 'the weekly fixture stayed weekly').toBe('WEEKLY');
    expect(monthly.period_type, 'the monthly fixture stayed monthly').toBe('MONTHLY');
    expect(weekly.reward!.total_verified_count).toBe(12);
    expect(monthly.reward!.total_verified_count).toBe(34);

    const context = await launchApp(testInfo, 'monthly-reward-collision');
    const page = await firstPage(context);
    const token = captureDeviceToken(page);

    await signIn(page, agent);
    await openRewards(page);

    const weeklyCard = page.locator('article').filter({ hasText: `Week of ${COLLISION_DATE}` });
    const monthlyCard = page.locator('article').filter({ hasText: `Month of ${COLLISION_DATE}` });

    await expect(weeklyCard).toBeVisible();
    await expect(monthlyCard).toBeVisible();
    await expect(weeklyCard.getByText(/12 verified/)).toBeVisible();
    await expect(monthlyCard.getByText(/34 verified/)).toBeVisible();
    await expect(weeklyCard.getByText(/NGN\s*50,000/)).toBeVisible();
    await expect(monthlyCard.getByText(/NGN\s*60,000/)).toBeVisible();

    const monthlySelf = await page.request.get('/api/v1/rewards/self.php?period_type=MONTHLY', {
      headers: { Authorization: `Bearer ${await token}` }
    });

    expect(monthlySelf.status()).toBe(200);

    const monthlyRows = ((await monthlySelf.json()) as {
      data: Array<{ period_start_date: string; period_type: string; total_verified_count: number }>;
    }).data.filter((row) => row.period_start_date === COLLISION_DATE);

    expect(monthlyRows, 'the monthly filter returns exactly the monthly row').toHaveLength(1);
    expect(monthlyRows[0].period_type).toBe('MONTHLY');
    expect(monthlyRows[0].total_verified_count).toBe(34);

    const weeklySelf = await page.request.get('/api/v1/rewards/self.php?period_type=WEEKLY', {
      headers: { Authorization: `Bearer ${await token}` }
    });

    expect(weeklySelf.status()).toBe(200);

    const weeklyRows = ((await weeklySelf.json()) as {
      data: Array<{ period_start_date: string; period_type: string; total_verified_count: number }>;
    }).data.filter((row) => row.period_start_date === COLLISION_DATE);

    expect(weeklyRows, 'the weekly filter returns exactly the weekly row').toHaveLength(1);
    expect(weeklyRows[0].period_type).toBe('WEEKLY');
    expect(weeklyRows[0].total_verified_count).toBe(12);

    // A late live rewrite of the monthly standing must not reach the monthly
    // entitlement that was frozen at close, and must not touch the weekly one.
    bumpLiveSummary(agent.agentCode, COLLISION_DATE, 99, 'MONTHLY');
    await page.getByRole('button', { name: 'Refresh' }).click();

    await expect(monthlyCard.getByText(/34 verified/)).toBeVisible();
    await expect(monthlyCard.getByText(/99 verified/)).toHaveCount(0);
    await expect(weeklyCard.getByText(/12 verified/)).toBeVisible();
    await expect(weeklyCard.getByText(/99 verified/)).toHaveCount(0);

    await context.close();

    // The operator's monthly board is a separate reader of the same frozen
    // rows. It must also show the monthly entitlement, not the weekly one.
    const opContext = await launchApp(testInfo, 'monthly-reward-operator');
    const opPage = await firstPage(opContext);

    await signInOperator(opPage, operator);
    await openRewards(opPage);

    await opPage.getByRole('button', { name: 'Monthly' }).click();

    const selector = opPage.locator('#period');
    await expect(selector).toBeVisible();
    await selector.selectOption(COLLISION_DATE);

    const opCard = opPage
      .locator('article')
      .filter({ hasText: agent.agentCode })
      .filter({ hasText: `month of ${COLLISION_DATE}` });

    await expect(opCard).toBeVisible();
    await expect(opCard.getByText(/34 verified/)).toBeVisible();
    await expect(opCard.getByText(/NGN\s*60,000/)).toBeVisible();
    await expect(opCard.getByText(/Published .* awaiting approval/)).toBeVisible();

    await opContext.close();
  });

  test('monthly standings keep their own history and never borrow a weekly row', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');

    const olderMonthly = seedReward(agent.agentCode, {
      periodType: 'MONTHLY',
      period: '2026-06-01',
      verified: 21,
      amount: '50000',
      currency: 'NGN'
    });

    const newerMonthly = seedReward(agent.agentCode, {
      periodType: 'MONTHLY',
      period: '2026-07-01',
      verified: 47,
      amount: '50000',
      currency: 'NGN'
    });

    // Same start date as the June monthly period, deliberately: if the board
    // forgot period_type it would show this count as the monthly standing.
    const collisionWeekly = seedReward(agent.agentCode, {
      period: '2026-06-01',
      verified: 5,
      amount: '50000',
      currency: 'NGN'
    });

    expect(olderMonthly.period_type).toBe('MONTHLY');
    expect(newerMonthly.period_type).toBe('MONTHLY');
    expect(collisionWeekly.period_type).toBe('WEEKLY');

    const context = await launchApp(testInfo, 'monthly-leaderboard');
    const page = await firstPage(context);

    await signIn(page, agent);
    await openLeaderboard(page);

    await page.getByRole('button', { name: 'Monthly' }).click();
    await expect(page.getByRole('heading', { name: 'Monthly standing' })).toBeVisible();

    const selector = page.locator('#period');
    const standing = page
      .locator('div.bg-white.rounded-lg.shadow.border-l-4')
      .filter({ hasText: 'Your standing' });

    await expect(selector).toBeVisible();

    await selector.selectOption('2026-06-01');
    await expect(page.getByRole('heading', { name: /month of 2026-06-01/ })).toBeVisible();
    await expect(standing.getByText(/21 verified/)).toBeVisible();
    await expect(standing.getByText(/5 verified/)).toHaveCount(0);

    // Switch on the shared start date, so the weekly and monthly boards are
    // reading the same date without one borrowing the other's row.
    await page.getByRole('button', { name: 'Weekly' }).click();
    await expect(page.getByRole('heading', { name: 'Weekly standing' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /week of 2026-06-01/ })).toBeVisible();
    await expect(standing.getByText(/5 verified/)).toBeVisible();
    await expect(standing.getByText(/21 verified/)).toHaveCount(0);

    // Switching back must read the monthly row again, not the weekly cache.
    await page.getByRole('button', { name: 'Monthly' }).click();
    await expect(page.getByRole('heading', { name: /month of 2026-06-01/ })).toBeVisible();
    await expect(standing.getByText(/21 verified/)).toBeVisible();
    await expect(standing.getByText(/5 verified/)).toHaveCount(0);

    await selector.selectOption('2026-07-01');
    await expect(page.getByRole('heading', { name: /month of 2026-07-01/ })).toBeVisible();
    await expect(standing.getByText(/47 verified/)).toBeVisible();
    await expect(standing.getByText(/21 verified/)).toHaveCount(0);

    await context.close();
  });

  test('a monthly total counts approved captures and not rejected ones', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const operator = provisionCredentials('SUPERVISOR');

    const agentContext = await launchApp(testInfo, 'monthly-submission-agent');
    const agentPage = await firstPage(agentContext);
    const token = captureDeviceToken(agentPage);

    await signIn(agentPage, agent);

    // No site is assigned to this identity, so the first capture cannot be
    // auto-verified. The second is the same frame from the same device, so the
    // production pipeline may reject it as a duplicate before a reviewer sees
    // it; either outcome is still a rejected capture for the count assertion.
    await captureAndSave(agentPage, 7);

    await agentPage.goto('/queue');
    await agentPage.getByRole('button', { name: 'Sync now' }).click();

    const firstRows = await waitForSent(agentPage, [7]);
    const approvedUuid = firstRows.find((row) => row.count_claimed === 7)!.submission_uuid;

    // The worker is the production entry point, not a test-only verifier.
    processQueue(20);

    await expect
      .poll(async () => serverStatus(agentPage, approvedUuid, await token), { timeout: 30_000 })
      .toMatch(/REQUIRES_REVIEW|VERIFIED/);

    const approvedStatus = await serverStatus(agentPage, approvedUuid, await token);

    await agentPage.goto('/');
    await captureAndSave(agentPage, 3);

    await agentPage.goto('/queue');
    await agentPage.getByRole('button', { name: 'Sync now' }).click();

    const rows = await waitForSent(agentPage, [7, 3]);
    const rejectedUuid = rows.find((row) => row.count_claimed === 3)!.submission_uuid;

    processQueue(20);

    await expect
      .poll(async () => serverStatus(agentPage, rejectedUuid, await token), { timeout: 30_000 })
      .toMatch(/REQUIRES_REVIEW|REJECTED/);

    const rejectedStatus = await serverStatus(agentPage, rejectedUuid, await token);

    const opContext = await launchApp(testInfo, 'monthly-submission-operator');
    const opPage = await firstPage(opContext);

    await signInOperator(opPage, operator);
    await openReviews(opPage);

    if (approvedStatus === 'REQUIRES_REVIEW') {
      const approvedCard = opPage
        .locator('article')
        .filter({ hasText: agent.agentCode })
        .filter({ hasText: '7 claimed' });

      await expect(approvedCard).toBeVisible();
      await approvedCard.locator('textarea').fill('Evidence matches the claimed count.');
      await approvedCard.getByRole('button', { name: 'Approve' }).click();
      await expect(approvedCard).toHaveCount(0);
    }

    if (rejectedStatus === 'REQUIRES_REVIEW') {
      const rejectedCard = opPage
        .locator('article')
        .filter({ hasText: agent.agentCode })
        .filter({ hasText: '3 claimed' });

      await expect(rejectedCard).toBeVisible();
      await rejectedCard.locator('textarea').fill('The claimed count is not supported by the photo.');
      await rejectedCard.getByRole('button', { name: 'Reject' }).click();
      await expect(rejectedCard).toHaveCount(0);
    }

    await opContext.close();

    expect(await serverStatus(agentPage, approvedUuid, await token)).toBe('VERIFIED');
    expect(await serverStatus(agentPage, rejectedUuid, await token)).toBe('REJECTED');

    await openLeaderboard(agentPage);
    await agentPage.getByRole('button', { name: 'Monthly' }).click();

    await expect(agentPage.getByRole('heading', { name: 'Monthly standing' })).toBeVisible();

    const standing = agentPage
      .locator('div.bg-white.rounded-lg.shadow.border-l-4')
      .filter({ hasText: 'Your standing' });

    await expect(standing.getByText(/7 verified/)).toBeVisible();
    await expect(standing.getByText(/10 verified/)).toHaveCount(0);

    const board = await agentPage.request.get('/api/v1/leaderboard.php?period_type=MONTHLY', {
      headers: { Authorization: `Bearer ${await token}` }
    });

    expect(board.status()).toBe(200);

    const body = await board.json();
    expect(body.data.you, 'the approved capture created a monthly standing').not.toBeNull();
    expect(body.data.you.total_verified_count, 'the rejected capture is not counted').toBe(7);

    await agentContext.close();
  });
});

/**
 * Wait until every expected local row has been acknowledged by the server.
 *
 * `SENT` is the client's proof that the upload completed. Verification is a
 * separate worker event, so the review assertions explicitly run the worker
 * after this returns.
 */
async function waitForSent(
  page: Page,
  counts: number[]
): Promise<Array<{ count_claimed: number; status: string; submission_uuid: string }>> {
  const deadline = Date.now() + 60_000;

  for (;;) {
    const rows = await readSubmissions(page);
    const complete = counts.every((count) =>
      rows.some((row) => row.count_claimed === count && row.status === 'SENT')
    );

    if (complete) {
      return rows;
    }

    if (Date.now() > deadline) {
      throw new Error(
        `never reached SENT for ${counts.join(', ')}; rows: ${JSON.stringify(
          rows.map((row) => ({ count: row.count_claimed, status: row.status }))
        )}`
      );
    }

    await page.waitForTimeout(250);
  }
}

/** Read one submission's server verdict through the same API the queue uses. */
async function serverStatus(page: Page, uuid: string, token: string): Promise<string | null> {
  const response = await page.request.get(`/api/v1/submission.php?uuid=${uuid}`, {
    headers: { Authorization: `Bearer ${token}` }
  });

  if (response.status() === 404) {
    return null;
  }

  expect(response.status()).toBe(200);

  return ((await response.json()) as { data: { status: string } }).data.status;
}
