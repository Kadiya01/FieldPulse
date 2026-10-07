import { test, expect, type Page } from '@playwright/test';
import { launchApp, firstPage, signIn, signInOperator, openRewards, captureDeviceToken } from './helpers';
import { provisionCredentials, seedReward, bumpLiveSummary } from './fixtures';

/**
 * Rewards, end to end.
 *
 * The engine, the endpoints and the cutoff rule are each proven in isolation by
 * the PHP suites. What none of those can show is the thing the feature is
 * actually for: an agent opens their phone and sees what they are owed, and an
 * operator moves that entitlement through approve and pay. That needs a real
 * browser, a real session, and a real closed week — which is what these four
 * tests provide.
 *
 * Each test provisions its own identities. A pairing code is single-use and a
 * fresh profile is a fresh device, so a shared fixture would only survive the
 * first launch; separate identities also mean the tests cannot observe each
 * other's entitlements.
 */

const WEEKS = { publishOnClose: 2, decides: 3, freeze: 4, conflict: 5 } as const;

test.describe('rewards', () => {
  test('an agent sees a reward published when the week closed', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const seeded = seedReward(agent.agentCode, {
      weeksAgo: WEEKS.publishOnClose,
      verified: 42,
      amount: '50000',
      currency: 'NGN'
    });

    expect(seeded.closed, 'the period actually closed').toBe(true);
    expect(seeded.reward, 'an entitlement was published').not.toBeNull();
    expect(seeded.reward!.rank).toBe(1);

    const context = await launchApp(testInfo, 'rewards-publish');
    const page = await firstPage(context);

    const token = captureDeviceToken(page);
    await signIn(page, agent);

    await openRewards(page);

    const card = page.locator('article').filter({ hasText: seeded.period });

    await expect(card).toBeVisible();
    await expect(card.getByText(`Week of ${seeded.period}`)).toBeVisible();
    await expect(card.getByText(/rank\s*1/)).toBeVisible();
    await expect(card.getByText(/42 verified/)).toBeVisible();
    await expect(card.getByText('Published — awaiting approval')).toBeVisible();

    // The amount is the figure the organisation set on the band, not a zero the
    // page invented.
    await expect(card.getByText(/NGN\s*50,000/)).toBeVisible();

    // Publication is not payment, and the page says so in words rather than
    // leaving the agent to infer it from a status code.
    await expect(page.getByText(/Only PAID means the organisation has settled/)).toBeVisible();

    // The operator controls are not merely hidden behind the role check in the
    // UI; the agent was never offered them.
    await expect(page.locator('#period')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);

    /*
     * And the server refuses the operator endpoint to this same session, so the
     * UI check above is corroborated by the enforcement behind it rather than
     * being the only thing standing in the way.
     */
    const refused = await page.request.get('/api/v1/rewards/index.php', {
      headers: { Authorization: `Bearer ${await token}` }
    });

    expect(refused.status(), 'a plain agent cannot read a whole period').toBe(403);

    await context.close();
  });

  test('publication is not payment: an operator approves, then pays', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const operator = provisionCredentials('SUPERVISOR');

    const seeded = seedReward(agent.agentCode, {
      weeksAgo: WEEKS.decides,
      verified: 42,
      amount: '50000',
      currency: 'NGN'
    });

    expect(seeded.reward!.status).toBe('PENDING');

    // --- The operator decides -------------------------------------------
    const opContext = await launchApp(testInfo, 'rewards-decides-operator');
    const opPage = await firstPage(opContext);
    await signInOperator(opPage, operator);
    await openRewards(opPage);

    await expect(opPage.getByRole('heading', { name: 'Period entitlements' })).toBeVisible();
    await selectPeriod(opPage, seeded.period);

    const opCard = opPage.locator('article').filter({ hasText: agent.agentCode });
    await expect(opCard.getByText('Published — awaiting approval')).toBeVisible();

    await opCard.getByRole('button', { name: 'Approve' }).click();
    await expect(opCard.getByText('Approved — awaiting payment')).toBeVisible();
    await expect(opPage.getByText(new RegExp(`Approved ${agent.agentCode}'s reward`))).toBeVisible();

    await opCard.getByRole('button', { name: 'Mark paid' }).click();
    await expect(opCard.getByText('Paid', { exact: true })).toBeVisible();
    await expect(opCard.getByText(/This reward is settled/)).toBeVisible();
    await expect(opPage.getByText(new RegExp(`Marked ${agent.agentCode}'s reward paid`))).toBeVisible();

    await opContext.close();

    // --- The agent now sees a paid reward --------------------------------
    const agentContext = await launchApp(testInfo, 'rewards-decides-agent');
    const agentPage = await firstPage(agentContext);
    await signIn(agentPage, agent);
    await openRewards(agentPage);

    const agentCard = agentPage.locator('article').filter({ hasText: seeded.period });
    await expect(agentCard.getByText('Paid', { exact: true })).toBeVisible();
    await expect(agentCard.getByText(/NGN\s*50,000/)).toBeVisible();

    await agentContext.close();
  });

  test('a published reward does not move when the live summary is rewritten', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const seeded = seedReward(agent.agentCode, {
      weeksAgo: WEEKS.freeze,
      verified: 10,
      amount: '50000',
      currency: 'NGN'
    });

    expect(seeded.reward!.total_verified_count).toBe(10);

    // The mutation the cutoff rule forbids from reaching the entitlement: what a
    // late verification, or bin/reaggregate.php, would do to a historical week.
    bumpLiveSummary(agent.agentCode, seeded.period, 99);

    const context = await launchApp(testInfo, 'rewards-freeze');
    const page = await firstPage(context);
    const token = captureDeviceToken(page);
    await signIn(page, agent);
    await openRewards(page);

    const card = page.locator('article').filter({ hasText: seeded.period });

    // Frozen at the number the week closed with, not the rewritten one.
    await expect(card.getByText(/10 verified/)).toBeVisible();
    await expect(card.getByText(/99 verified/)).toHaveCount(0);

    /*
     * Read it back from the API too. A page can only render what it was given,
     * so this is the assertion that the figure did not move on the server — the
     * one place a regression would actually live.
     */
    const self = await page.request.get('/api/v1/rewards/self.php', {
      headers: { Authorization: `Bearer ${await token}` }
    });

    expect(self.status()).toBe(200);

    const body = await self.json();
    const row = (body.data as Array<{ period_start_date: string; total_verified_count: number }>).find(
      (r) => r.period_start_date === seeded.period
    );

    expect(row, 'the frozen entitlement is still served').toBeDefined();
    expect(row!.total_verified_count, 'the live rewrite did not touch it').toBe(10);

    await context.close();
  });

  test('two operators deciding the same reward: the second sees a conflict', async ({}, testInfo) => {
    const agent = provisionCredentials('AGENT');
    const operatorA = provisionCredentials('SUPERVISOR');
    const operatorB = provisionCredentials('SUPERVISOR');

    const seeded = seedReward(agent.agentCode, {
      weeksAgo: WEEKS.conflict,
      verified: 17,
      amount: '50000',
      currency: 'NGN'
    });

    const ctxA = await launchApp(testInfo, 'rewards-conflict-a');
    const pageA = await firstPage(ctxA);
    await signInOperator(pageA, operatorA);
    await openRewards(pageA);
    await selectPeriod(pageA, seeded.period);

    const ctxB = await launchApp(testInfo, 'rewards-conflict-b');
    const pageB = await firstPage(ctxB);
    await signInOperator(pageB, operatorB);
    await openRewards(pageB);
    await selectPeriod(pageB, seeded.period);

    const cardA = pageA.locator('article').filter({ hasText: agent.agentCode });
    const cardB = pageB.locator('article').filter({ hasText: agent.agentCode });

    // Both operators are looking at a PENDING entitlement.
    await expect(cardA.getByText('Published — awaiting approval')).toBeVisible();
    await expect(cardB.getByText('Published — awaiting approval')).toBeVisible();

    // A approves first.
    await cardA.getByRole('button', { name: 'Approve' }).click();
    await expect(cardA.getByText('Approved — awaiting payment')).toBeVisible();

    /*
     * B's screen still shows PENDING, because it loaded before the change. B
     * presses Approve against stale state; the server's guarded transition
     * refuses it and the page words the conflict rather than silently
     * succeeding or crashing.
     */
    await cardB.getByRole('button', { name: 'Approve' }).click();
    await expect(cardB.getByText(/Someone else changed this reward first/)).toBeVisible();

    await ctxA.close();
    await ctxB.close();
  });
});

/* -------------------------------------------------------------------------- */

/**
 * Choose a specific closed week from the operator's period selector.
 *
 * The default is the newest closed period, which is not necessarily this test's
 * week, so the selection is explicit and then waited on.
 */
async function selectPeriod(page: Page, period: string): Promise<void> {
  const selector = page.locator('#period');

  await expect(selector).toBeVisible();
  await selector.selectOption(period);

  // loadPeriod() re-fetches asynchronously; the notice region clearing is the
  // first render after the press, and the card assertion that follows waits for
  // the data.
  await expect(page.getByRole('heading', { name: 'Period entitlements' })).toBeVisible();
}