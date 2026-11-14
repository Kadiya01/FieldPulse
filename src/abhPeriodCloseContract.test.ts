import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/010_period_close_and_snapshot_precision.sql');
const schemaTest = read('../private_storage/bin/abh_schema_test.php');
const repository = read('../private_storage/app/Database/AbhAdminPeriodRepository.php');
const targetRepository = read('../private_storage/app/Database/AbhAdminTargetRepository.php');
const controller = read('../private_storage/app/Domain/AbhAdminPeriodCloseController.php');
const targetController = read('../private_storage/app/Domain/AbhAdminTargetsController.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const shim = read('../public_html/api/v1/abh/admin/period-close.php');
const api = read('api/client.ts');
const page = read('pages/AdminPortalPage.tsx');

describe('ABH reporting period finalisation', () => {
  it('adds and grants the separate period-close permission with safe snapshot precision', () => {
    expect(migration).toContain("'periods.close'");
    expect(migration).toContain("'SUPER_ADMIN', 'OPERATIONS_MANAGER'");
    expect(migration).toContain('DECIMAL(24,2)');
    expect(schemaTest).toContain("p.permission_key = 'periods.close'");
    expect(schemaTest).toContain("(int) $snapshotPrecision['NUMERIC_PRECISION'] === 24");
    expect(schemaTest).toContain("'periods.close')");
  });

  it('protects period close with the dedicated admin permission', () => {
    expect(kernel).toContain("'abh.admin.period.close'");
    expect(kernel).toContain("'abh.admin.period.close' => 'admin'");
    expect(shim).toContain("Kernel::handle('abh.admin.period.close')");
    expect(controller).toContain("requirePermission('periods.close')");
    expect(controller).toContain("method() !== 'POST'");
    expect(controller).toContain("Validator::periodMonth");
  });

  it('serializes closure against submissions, reviews, corrections and target edits', () => {
    expect(repository).toContain('LIMIT 1 FOR UPDATE');
    expect(repository).toContain('status IN ("PENDING_REVIEW", "CORRECTION_REQUESTED")');
    expect(repository).toContain("!== 'OPEN'");
    expect(targetRepository).toContain('LIMIT 1 FOR UPDATE');
  });

  it('blocks unfinished months and outstanding reviews, then reconciles approved totals', () => {
    expect(repository).toContain('period_not_ended');
    expect(repository).toContain('pending_reviews');
    expect(repository).toContain('pending_count');
    expect(repository).toContain("s.status = \"APPROVED\"");
    expect(repository).toContain('abh_monthly_agent_totals');
    expect(repository).toContain('totals_mismatch');
  });

  it('freezes agent rankings and organisation performance in one transaction', () => {
    expect(repository).toContain('Connection::transaction');
    expect(repository).toContain('abh_monthly_leaderboard_snapshots');
    expect(repository).toContain('abh_monthly_organisation_snapshots');
    expect(repository).toContain('agent_code_snapshot');
    expect(repository).toContain('display_name_snapshot');
    expect(repository).toContain('progress_percent');
    expect(repository).toContain('SET status = \"CLOSED\"');
    expect(repository).toContain('period.closed');
    expect(repository).toContain('abh_audit_events');
    expect(repository).toContain('abh_notifications');
  });

  it('returns pending-count and frozen organisation summary to the authorised admin view', () => {
    expect(targetRepository).toContain('pending_submissions_count');
    expect(targetRepository).toContain('organisation_snapshot');
    expect(targetController).toContain("hasPermission('periods.close')");
    expect(api).toContain('pending_submissions_count: number');
    expect(api).toContain('closeAbhReportingPeriod');
    expect(api).toContain('/abh/admin/period-close.php');
    expect(page).toContain('Finalise reporting month');
    expect(page).toContain('Confirm monthly finalisation');
    expect(page).toContain('Closed periods cannot be edited');
    expect(page).toContain('Frozen organisation result');
  });
});
