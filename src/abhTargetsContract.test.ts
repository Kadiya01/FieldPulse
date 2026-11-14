import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/003_targets_performance_snapshots.sql');
const kernel = read('../private_storage/app/Http/Kernel.php');
const repository = read('../private_storage/app/Database/AbhAdminTargetRepository.php');
const controller = read('../private_storage/app/Domain/AbhAdminTargetsController.php');
const shim = read('../public_html/api/v1/abh/admin/targets.php');
const api = read('api/client.ts');
const page = read('pages/AdminPortalPage.tsx');

describe('ABH monthly target management', () => {
  it('uses the dedicated organisation and agent target table', () => {
    expect(migration).toContain('CREATE TABLE abh_monthly_targets');
    expect(migration).toContain('uq_abh_monthly_targets_period_scope_owner');
    expect(migration).toContain('scope_owner_id');
    expect(repository).toContain('abh_monthly_targets');
    expect(repository).toContain('"ORGANISATION"');
    expect(repository).toContain('"AGENT"');
    expect(repository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:targets|agents|reporting_periods|audit_logs)\b/i);
  });

  it('requires the explicit targets.manage permission on GET and POST', () => {
    expect(kernel).toContain("'abh.admin.targets'");
    expect(kernel).toContain("'abh.admin.targets' => 'admin'");
    expect(shim).toContain("Kernel::handle('abh.admin.targets')");
    expect(controller).toContain("requireAbhAdminContext()");
    expect(controller).toContain("requirePermission('targets.manage')");
    expect(controller).toContain("method() === 'GET'");
    expect(controller).toContain("method() === 'POST'");
  });

  it('validates month, target scope, agent identity and bounded quantity', () => {
    expect(controller).toContain('Validator::periodMonth');
    expect(controller).toContain("['ORGANISATION', 'AGENT']");
    expect(controller).toContain("Validator::intRange($body['agent_id']");
    expect(controller).toContain("Validator::intRange($body['target_quantity']");
    expect(repository).toContain('status = "ACTIVE"');
  });

  it('creates a missing open month and locks it while saving the target', () => {
    expect(repository).toContain('INSERT INTO abh_reporting_periods');
    expect(repository).toContain('ON DUPLICATE KEY UPDATE period_key = VALUES(period_key)');
    expect(repository).toContain('LIMIT 1 FOR UPDATE');
    expect(repository).toContain("!== 'OPEN'");
    expect(repository).toContain('ON DUPLICATE KEY UPDATE');
    expect(repository).toContain('abh_audit_events');
    expect(repository).toContain('RequestId::current()');
  });

  it('presents organisation and individual target controls only to permitted admins', () => {
    expect(api).toContain('fetchAbhAdminTargets');
    expect(api).toContain('saveAbhAdminTarget');
    expect(api).toContain('/abh/admin/targets.php');
    expect(page).toContain('Monthly targets');
    expect(page).toContain("admin.permissions.includes('targets.manage')");
    expect(page).toContain('Save organisation target');
    expect(page).toContain('Save individual target');
    expect(page).toContain('Closed periods cannot be edited');
  });
});
