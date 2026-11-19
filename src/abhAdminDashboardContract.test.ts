import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const page = read('pages/AdminPortalPage.tsx');
const api = read('api/client.ts');
const kernel = read('../private_storage/app/Http/Kernel.php');
const controller = read('../private_storage/app/Domain/AbhAdminDashboardController.php');
const repository = read('../private_storage/app/Database/AbhAdminDashboardRepository.php');
const route = read('../public_html/api/v1/abh/admin/dashboard.php');

describe('ABH administrator dashboard contract', () => {
  it('is a protected ABH administrator route, not a legacy agent route', () => {
    expect(kernel).toContain("'abh.admin.dashboard'");
    expect(kernel).toContain("'abh.admin.dashboard' => 'admin'");
    expect(controller).toContain('requireAbhAdminContext');
    expect(controller).toContain('array_intersect($allowed, $permissions)');
    expect(route).toContain("Kernel::handle('abh.admin.dashboard')");
  });

  it('limits returned dashboard sections to the administrator permissions', () => {
    expect(controller).toContain('array_intersect($allowed, $permissions)');
    expect(repository).toContain("in_array('agents.read', $permissions, true)");
    expect(repository).toContain("in_array('submissions.read', $permissions, true)");
    expect(repository).toContain("in_array('targets.manage', $permissions, true)");
    expect(repository).toContain("in_array('rewards.manage', $permissions, true)");
  });

  it('calculates summaries from ABH-owned tables only', () => {
    for (const table of [
      'abh_agents',
      'abh_reporting_periods',
      'abh_enrollment_submissions',
      'abh_submission_revisions',
      'abh_monthly_agent_totals',
      'abh_monthly_targets',
      'abh_reward_programs',
      'abh_reward_qualifications',
      'abh_reward_awards',
      'abh_reward_fulfilment_events'
    ]) {
      expect(repository).toContain(table);
    }
    expect(repository).not.toMatch(/\bFROM\s+(agents|submissions|monthly_targets|reward_programs)\b/i);
    expect(repository).toContain('approved_quantity');
    expect(repository).toContain('PENDING_REVIEW');
  });

  it('does not display claimed submissions as approved performance', () => {
    expect(repository).toContain("'claimed_quantity'");
    expect(repository).toContain("'approved_quantity'");
    expect(page).toContain('Ranked by approved enrollments, not unreviewed submissions.');
    expect(page).toContain('Approved enrollments');
  });

  it('provides a selectable reporting month and live summary screen', () => {
    expect(api).toContain('export interface AbhAdminDashboardData');
    expect(api).toContain('fetchAbhAdminDashboard(month: string)');
    expect(api).toContain('/abh/admin/dashboard.php');
    expect(page).toContain('type="month"');
    expect(page).toContain("setActiveSection('overview')");
    expect(page).toContain('<AdminOverview />');
    expect(page).toContain('Top agents this month');
  });
});
