import { test, expect } from '@playwright/test';
import { captureDeviceToken, firstPage, launchApp, signInOperator } from './helpers';
import { provisionCredentials } from './fixtures';

const MONTH = new Date().toISOString().slice(0, 7);

test.describe('admin management', () => {
  test('dashboard, create, filter, target, detail, profile, status reason and audit use real server state', async ({}, testInfo) => {
    const admin = provisionCredentials('ADMIN');
    const suffix = `${Date.now()}`.slice(-8);
    const agentCode = `e2e_p4_${suffix}`;
    const username = `e2e_p4_${suffix}`;
    const password = `E2e-${suffix}-pw`;
    const email = `phase4-${suffix}@example.test`;
    const reason = 'Evidence mismatch confirmed by administrator';

    const context = await launchApp(testInfo, 'phase4-admin');
    const page = await firstPage(context);
    page.on('dialog', dialog => void dialog.accept());

    const tokenPromise = captureDeviceToken(page);
    await signInOperator(page, admin);
    const token = await tokenPromise;
    const auth = { Authorization: `Bearer ${token}` };

    await page.getByRole('link', { name: 'Admin' }).click();
    await expect(page.getByRole('heading', { name: 'Account management' })).toBeVisible();
    await expect(page.getByTestId('admin-dashboard')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('Management overview')).toBeVisible();
    await expect(page.getByTestId('admin-targets')).toBeVisible();
    await expect(page.getByTestId('admin-periods')).toBeVisible();
    await expect(page.getByTestId('admin-audit')).toBeVisible();

    await page.getByRole('button', { name: 'Create account' }).first().click();
    const createForm = page.getByRole('form', { name: 'Create an account' });
    await expect(createForm).toBeVisible();

    await createForm.getByLabel('Agent code').fill(agentCode);
    await createForm.getByLabel('Full name').fill('Phase Four Agent');
    await createForm.getByLabel('Username').fill(username);
    await createForm.getByLabel('Password').fill(password);
    await createForm.getByLabel('Email (optional)').fill(email);
    await createForm.getByLabel('Location (optional)').fill('Lagos');
    await createForm.getByLabel('Monthly target').fill('25');
    await createForm.getByRole('button', { name: 'Create account' }).click();

    await expect(page.getByText(`${agentCode} created.`)).toBeVisible();
    await expect(createForm).toBeHidden();

    const directory = page.getByRole('form', { name: 'Filter accounts' });
    await directory.getByLabel('Search').fill(agentCode);
    await directory.getByRole('button', { name: 'Apply filters' }).click();

    const card = page.locator('article').filter({ hasText: agentCode });
    await expect(card).toBeVisible();
    await expect(card.getByText('Phase Four Agent')).toBeVisible();

    const agentsResponse = await page.request.get(
      `/api/v1/admin/agents.php?q=${encodeURIComponent(agentCode)}`,
      { headers: auth }
    );
    expect(agentsResponse.ok()).toBeTruthy();
    const agentsBody = (await agentsResponse.json()) as {
      data: Array<{ id: number; agent_code: string; email: string | null; status: string }>;
      meta: { filters: { q: string | null }; locations: string[] };
    };
    const created = agentsBody.data.find(agent => agent.agent_code === agentCode);
    expect(created?.status).toBe('ACTIVE');
    expect(created?.email).toBe(email);
    expect(agentsBody.meta.filters.q).toBe(agentCode);

    const agentId = created!.id;
    const targets = page.getByTestId('admin-targets');
    await targets.locator('select').nth(0).selectOption('AGENT');

    const agentSelect = targets.locator('select').nth(1);
    const option = agentSelect.locator('option').filter({ hasText: agentCode });
    const optionValue = await option.getAttribute('value');
    expect(optionValue).toBeTruthy();
    await agentSelect.selectOption(optionValue!);
    await targets.getByLabel('Target', { exact: true }).fill('40');
    await targets.getByRole('button', { name: 'Save target' }).click();

    await expect(page.getByText(/Target saved for/)).toBeVisible();
    await expect(targets.locator('li').filter({ hasText: agentCode }).getByText('40')).toBeVisible();

    await card.getByRole('button', { name: 'View details' }).click();
    const detail = page.getByTestId('admin-agent-detail');
    await expect(detail).toBeVisible();
    await expect(detail.getByText(`${agentCode} · Phase Four Agent`)).toBeVisible();
    await expect(detail.getByText(/monthly target 40/)).toBeVisible();

    await detail.getByLabel('Location').fill('Kano');
    await detail.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText(`${agentCode} updated.`)).toBeVisible();
    await expect(detail.getByLabel('Location')).toHaveValue('Kano');

    const detailResponse = await page.request.get(`/api/v1/admin/agent.php?id=${agentId}`, {
      headers: auth
    });
    expect(detailResponse.ok()).toBeTruthy();
    const detailBody = (await detailResponse.json()) as {
      data: {
        id: number;
        location: string | null;
        target: { target: number; configured: boolean };
        devices: Array<Record<string, unknown>>;
        targets: Array<{ scope_type: string; target: number }>;
        recent_audit: Array<{ action: string }>;
      };
    };
    expect(detailBody.data.id).toBe(agentId);
    expect(detailBody.data.location).toBe('Kano');
    expect(detailBody.data.target.target).toBe(40);
    expect(detailBody.data.target.configured).toBe(true);
    expect(detailBody.data.targets.some(row => row.scope_type === 'AGENT' && row.target === 40)).toBe(true);
    expect(detailBody.data.recent_audit.some(row => row.action === 'admin.agent.profile_changed')).toBe(true);
    expect(detailBody.data.devices.some(device => 'public_key_jwk' in device)).toBe(false);

    await detail.getByRole('button', { name: 'Close agent details' }).click();
    await expect(detail).toBeHidden();

    await card.getByLabel('Reason for status change (optional)').fill(reason);
    await card.getByRole('button', { name: 'Suspend' }).click();
    await expect(card.getByText('Suspended', { exact: true })).toBeVisible();
    await expect(card.getByText(`Status reason: ${reason}`)).toBeVisible();

    const suspendedResponse = await page.request.get(
      `/api/v1/admin/agents.php?q=${encodeURIComponent(agentCode)}`,
      { headers: auth }
    );
    const suspendedBody = (await suspendedResponse.json()) as {
      data: Array<{ status: string; status_reason: string | null; status_changed_at: string | null }>;
    };
    expect(suspendedBody.data[0].status).toBe('SUSPENDED');
    expect(suspendedBody.data[0].status_reason).toBe(reason);
    expect(suspendedBody.data[0].status_changed_at).toBeTruthy();

    const dashboard = await page.request.get(
      `/api/v1/admin/dashboard.php?period=${MONTH}&period_type=MONTHLY`,
      { headers: auth }
    );
    expect(dashboard.ok()).toBeTruthy();
    const dashboardBody = (await dashboard.json()) as {
      data: {
        period: { period_start_date: string };
        agents: { total: number };
        devices: { total: number };
        enrollments: { approved: number };
        target: { configured: boolean; monthly_target: number };
        locations: Array<{ location: string; agent_count: number }>;
        top_agents: Array<{ agent_code: string }>;
      };
    };
    expect(dashboardBody.data.period.period_start_date).toContain(MONTH);
    expect(dashboardBody.data.agents.total).toBeGreaterThan(0);
    expect(dashboardBody.data.locations.some(row => row.location === 'Lagos')).toBe(true);

    const targetsResponse = await page.request.get(
      `/api/v1/admin/targets.php?period=${MONTH}`,
      { headers: auth }
    );
    expect(targetsResponse.ok()).toBeTruthy();
    const targetsBody = (await targetsResponse.json()) as {
      data: Array<{ scope_type: string; scope_key: string; target: number }>;
      meta: { period: string; agents: Array<{ id: number; agent_code: string }> };
    };
    expect(targetsBody.meta.period).toContain(MONTH);
    expect(targetsBody.meta.agents.every(agent => agent.id > 0)).toBe(true);
    expect(
      targetsBody.data.some(
        row => row.scope_type === 'AGENT' && row.scope_key === String(agentId) && row.target === 40
      )
    ).toBe(true);

    const periods = await page.request.get('/api/v1/admin/periods.php?period_type=MONTHLY&limit=6', {
      headers: auth
    });
    expect(periods.ok()).toBeTruthy();
    const periodsBody = (await periods.json()) as {
      data: Array<{ period_start_date: string; due: boolean; frozen: boolean }>;
      meta: { business_timezone: string; grace_hours: number };
    };
    expect(periodsBody.data.some(row => row.period_start_date.includes(MONTH))).toBe(true);
    expect(periodsBody.meta.grace_hours).toBeGreaterThanOrEqual(0);

    const notDue = await page.request.post('/api/v1/admin/periods.php', {
      headers: auth,
      data: { period: MONTH, period_type: 'MONTHLY', action: 'CLOSE' }
    });
    expect(notDue.status()).toBe(200);
    const notDueBody = (await notDue.json()) as { data: { closed: boolean; reason: string } };
    expect(notDueBody.data.closed).toBe(false);
    expect(notDueBody.data.reason.length).toBeGreaterThan(0);

    const auditSection = page.getByTestId('admin-audit');
    await auditSection.getByLabel('Action').fill('admin.target.set');
    await auditSection.getByRole('button', { name: 'Filter audit' }).click();
    await expect(auditSection.getByText('admin.target.set').first()).toBeVisible();

    await auditSection.getByLabel('Action').fill('admin.agent.suspended');
    await auditSection.getByRole('button', { name: 'Filter audit' }).click();
    await expect(auditSection.getByText('admin.agent.suspended').first()).toBeVisible();
    await expect(auditSection.locator('li').filter({ hasText: agentCode }).filter({ hasText: reason })).toBeVisible();

    const auditResponse = await page.request.get(
      '/api/v1/admin/audit.php?action=admin.agent.suspended&limit=50',
      { headers: auth }
    );
    expect(auditResponse.ok()).toBeTruthy();
    const auditBody = (await auditResponse.json()) as {
      data: Array<{ action: string; entity_id: number | null; metadata: Record<string, unknown> }>;
    };
    const suspension = auditBody.data.find(row => row.entity_id === agentId);
    expect(suspension?.action).toBe('admin.agent.suspended');
    expect(suspension?.metadata.reason).toBe(reason);

    await context.close();
  });
});
