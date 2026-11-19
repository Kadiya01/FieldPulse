import { test, expect, type Page } from '@playwright/test';
import {
  captureAndSave,
  captureDeviceToken,
  firstPage,
  latestUuid,
  launchApp,
  signIn,
  signInOperator
} from './helpers';
import {
  processQueue,
  provisionCredentials,
  waitForStatus
} from './fixtures';

const MONTH = new Date().toISOString().slice(0, 7);

test.describe('agent enrollment workflow', () => {
  test('self-registration, approval, profile, dashboard and suspension use real server state', async ({}, testInfo) => {
    const admin = provisionCredentials('ADMIN');
    const nin = randomDigits(11);
    const imei = luhnImei();
    const phone = '080' + randomDigits(8);
    const email = `phase3-${Date.now()}-${randomDigits(4)}@example.test`;
    const location = 'Lagos';
    const username = `phase3.agent.${Date.now()}.${randomDigits(4)}`;
    const password = 'phase3-agent-password';

    const adminContext = await launchApp(testInfo, 'phase3-admin');
    const adminPage = await firstPage(adminContext);
    adminPage.on('dialog', (dialog) => void dialog.accept());

    await adminPage.goto('/register');
    await expect(adminPage.getByRole('heading', { name: 'Agent registration' })).toBeVisible();

    const registration = adminPage.waitForResponse(
      (response) =>
        response.url().includes('/api/v1/agents/register.php') &&
        response.request().method() === 'POST'
    );

    await adminPage.locator('#full-name').fill('Phase Three Agent');
    await adminPage.locator('#username').fill(username);
    await adminPage.locator('#password').fill(password);
    await adminPage.locator('#confirm-password').fill(password);
    await adminPage.locator('#phone').fill(phone);
    await adminPage.locator('#nin').fill(nin);
    await adminPage.locator('#imei').fill(imei);
    await adminPage.locator('#email').fill(email);
    await adminPage.locator('#location').fill(location);
    await adminPage.locator('#device-type').selectOption('SAMSUNG');
    await adminPage.getByRole('button', { name: 'Submit for approval' }).click();

    await expect(adminPage.getByRole('heading', { name: 'Registration submitted' })).toBeVisible();

    const registered = await registration;
    expect(registered.status()).toBe(201);

    const pendingLogin = await adminPage.request.post('/api/v1/auth/login.php', {
      data: { username, password }
    });

    expect(pendingLogin.status()).toBe(401);

    const adminTokenPromise = captureDeviceToken(adminPage);
    await signInOperator(adminPage, admin);
    const adminToken = await adminTokenPromise;

    const agentsResponse = await adminPage.request.get('/api/v1/admin/agents.php?limit=200', {
      headers: { Authorization: `Bearer ${adminToken}` }
    });
    expect(agentsResponse.ok()).toBeTruthy();

    const agentsBody = (await agentsResponse.json()) as {
      data: Array<{
        id: number;
        agent_code: string;
        email: string | null;
        location: string | null;
        status: string;
      }>;
    };
    const registeredAgent = agentsBody.data.find((agent) => agent.email === email);

    expect(registeredAgent?.status).toBe('PENDING');
    expect(registeredAgent?.location).toBe(location);
    if (!registeredAgent) {
      throw new Error(`registered agent ${email} was not returned by the admin directory`);
    }

    const agentId = registeredAgent.id;
    const agentCode = registeredAgent.agent_code;

    await adminPage.getByRole('link', { name: 'Admin' }).click();
    await expect(adminPage.getByRole('heading', { name: 'Account management' })).toBeVisible();

    const card = adminPage.locator('article').filter({ hasText: agentCode });
    await expect(card).toBeVisible();

    await card.getByRole('button', { name: 'Approve registration' }).click();
    await expect(card.getByText('Active', { exact: true })).toBeVisible();

    await card.getByRole('button', { name: 'Set target' }).click();
    await card.locator(`#target-${agentId}`).fill('40');
    await card.getByRole('button', { name: 'Save target' }).click();
    await expect(card.getByText(/monthly target 40/)).toBeVisible();

    await card.getByRole('button', { name: 'Registration code' }).click();
    const pairingCode = (await card.locator('code').textContent())?.trim();

    expect(pairingCode, 'the administrator issued a pairing code').toMatch(/^\d{6,}$/);

    const agentContext = await launchApp(testInfo, 'phase3-agent');
    const agentPage = await firstPage(agentContext);
    const agentTokenPromise = captureDeviceToken(agentPage);

    await signIn(agentPage, {
      agentCode,
      username,
      password,
      pairingCode: pairingCode!,
      imei,
      role: 'AGENT'
    });

    const agentToken = await agentTokenPromise;

    await agentPage.getByRole('link', { name: 'Profile' }).click();
    await expect(agentPage.getByRole('heading', { name: 'Profile' })).toBeVisible();
    await expect(agentPage.locator('#profile-email')).toHaveValue(email);
    await expect(agentPage.locator('#profile-location')).toHaveValue(location);

    await agentPage.locator('#profile-location').fill('Kano');
    await agentPage.locator('#profile-phone').fill(phone);
    await agentPage.getByRole('button', { name: 'Save changes' }).click();
    await expect(agentPage.getByText('Profile updated.')).toBeVisible();

    await agentPage.getByRole('link', { name: 'Dashboard' }).click();
    await expect(agentPage.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(agentPage.getByText('No monthly target has been set for your account yet.')).toHaveCount(0);

    const targetCard = agentPage.locator('article').filter({ hasText: 'Monthly target' });
    await expect(targetCard.getByText('0%', { exact: true })).toBeVisible();
    await expect(targetCard.getByText(/0 of 40/)).toBeVisible();
    await expect(agentPage.getByText('Registration approved')).toBeVisible();

    await agentPage.getByRole('button', { name: 'Mark all read' }).click();
    await expect(agentPage.getByText('Notifications marked as read.')).toBeVisible();

    const firstDashboard = await dashboard(agentPage, agentToken);

    expect(firstDashboard.target.configured).toBe(true);
    expect(firstDashboard.target.monthly_target).toBe(40);
    expect(firstDashboard.target.achievement_percent).toBe(0);
    expect(firstDashboard.totals.approved_enrollments).toBe(0);

    await agentPage.getByTestId('site-nav').getByRole('link', { name: 'Capture' }).click();
    await captureAndSave(agentPage, 5);

    const uuid = await latestUuid(agentPage);

    await agentPage.goto('/queue');
    await agentPage.getByRole('button', { name: 'Sync now' }).click();
    await waitForStatus(agentPage, uuid, ['SENT'], 60_000);

    processQueue(20);

    await expect
      .poll(async () => (await submission(agentPage, uuid, agentToken))?.status, { timeout: 30_000 })
      .toBe('REQUIRES_REVIEW');

    const pendingSubmission = await submission(agentPage, uuid, agentToken);

    expect(pendingSubmission?.reporting_month).toMatch(/^\d{4}-\d{2}-01$/);
    expect(String(pendingSubmission?.reporting_month)).toContain(MONTH);

    const queue = await adminPage.request.get(
      `/api/v1/reviews/index.php?agent_code=${encodeURIComponent(agentCode)}`,
      { headers: { Authorization: `Bearer ${adminToken}` } }
    );

    expect(queue.status()).toBe(200);

    const queueBody = (await queue.json()) as {
      data: Array<{ submission_id: number; submission_uuid: string }>;
    };
    const review = queueBody.data.find((row) => row.submission_uuid === uuid);

    expect(review, 'the submission reached the real review queue').toBeTruthy();

    const decision = await adminPage.request.post('/api/v1/reviews/decide.php', {
      headers: { Authorization: `Bearer ${adminToken}` },
      data: {
        submission_id: review!.submission_id,
        decision: 'APPROVE',
        note: 'Evidence matches the claimed enrollment count.'
      }
    });

    expect(decision.status()).toBe(200);

    await agentPage.getByRole('link', { name: 'Dashboard' }).click();
    await agentPage.getByRole('button', { name: 'Refresh' }).click();

    await expect(targetCard.getByText('12.5%', { exact: true })).toBeVisible();
    await expect(targetCard.getByText(/5 of 40/)).toBeVisible();
    await expect(agentPage.getByText('Submission approved')).toBeVisible();

    const approvedDashboard = await dashboard(agentPage, agentToken);

    expect(approvedDashboard.totals.approved_enrollments).toBe(5);
    expect(approvedDashboard.totals.pending).toBe(0);
    expect(approvedDashboard.totals.rejected).toBe(0);
    expect(approvedDashboard.target.achievement_percent).toBe(12.5);

    await card.getByRole('button', { name: 'Suspend' }).click();
    await expect(card.getByText('Suspended', { exact: true })).toBeVisible();

    const suspendedDashboard = await agentPage.request.get('/api/v1/dashboard.php', {
      headers: { Authorization: `Bearer ${agentToken}` }
    });

    expect([401, 403]).toContain(suspendedDashboard.status());

    const suspendedLogin = await agentPage.request.post('/api/v1/auth/login.php', {
      data: { username, password }
    });

    expect(suspendedLogin.status()).toBe(401);

    await agentContext.close();
    await adminContext.close();
  });
});

function randomDigits(length: number): string {
  let value = '';

  for (let i = 0; i < length; i += 1) {
    value += Math.floor(Math.random() * 10);
  }

  return value;
}

function luhnImei(): string {
  let base = '35693803';

  for (let i = 0; i < 6; i += 1) {
    base += Math.floor(Math.random() * 10);
  }

  let sum = 0;

  for (let i = 0; i < 14; i += 1) {
    let digit = Number(base[i]);

    if (i % 2 === 1) {
      digit *= 2;

      if (digit > 9) {
        digit -= 9;
      }
    }

    sum += digit;
  }

  return base + ((10 - (sum % 10)) % 10);
}

async function dashboard(page: Page, token: string) {
  const response = await page.request.get('/api/v1/dashboard.php', {
    headers: { Authorization: `Bearer ${token}` }
  });

  expect(response.status()).toBe(200);

  return ((await response.json()) as {
    data: {
      totals: { approved_enrollments: number; pending: number; rejected: number };
      target: { configured: boolean; monthly_target: number; achievement_percent: number | null };
    };
  }).data;
}

async function submission(page: Page, uuid: string, token: string) {
  const response = await page.request.get(`/api/v1/submission.php?uuid=${uuid}`, {
    headers: { Authorization: `Bearer ${token}` }
  });

  if (!response.ok()) {
    return null;
  }

  return ((await response.json()) as {
    data: { status: string; reporting_month: string | null };
  }).data;
}
