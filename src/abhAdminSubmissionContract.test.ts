import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/002_submissions_reviews.sql');
const totals = read('../private_storage/abh_migrations/003_targets_performance_snapshots.sql');
const repository = read('../private_storage/app/Database/AbhAdminSubmissionRepository.php');
const queueController = read('../private_storage/app/Domain/AbhAdminSubmissionsController.php');
const decisionController = read('../private_storage/app/Domain/AbhAdminSubmissionDecisionController.php');
const evidenceController = read('../private_storage/app/Domain/AbhAdminSubmissionEvidenceController.php');
const response = read('../private_storage/app/Http/Response.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const queueShim = read('../public_html/api/v1/abh/admin/submissions.php');
const decisionShim = read('../public_html/api/v1/abh/admin/submission-decision.php');
const evidenceShim = read('../public_html/api/v1/abh/admin/submission-evidence.php');
const client = read('api/client.ts');
const page = read('pages/AdminPortalPage.tsx');

describe('ABH administrator enrollment review contract', () => {
  it('uses only the ABH review and approved-total schema', () => {
    expect(migration).toContain('CREATE TABLE abh_submission_reviews');
    expect(migration).toContain('CREATE TABLE abh_submission_adjustments');
    expect(migration).toContain('UNIQUE KEY uq_abh_submission_reviews_idempotency');
    expect(totals).toContain('CREATE TABLE abh_monthly_agent_totals');
    expect(totals).toContain('PRIMARY KEY (reporting_period_id, agent_id)');
    expect(repository).toContain('abh_enrollment_submissions');
    expect(repository).toContain('abh_submission_reviews');
    expect(repository).toContain('abh_monthly_agent_totals');
    expect(repository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:submissions|agents|devices|audit_logs|notifications)\b/i);
  });

  it('protects the queue and evidence routes with administrator authentication and read permission', () => {
    expect(kernel).toContain("'abh.admin.submissions'");
    expect(kernel).toContain("'abh.admin.submission.evidence'");
    expect(kernel).toContain("'abh.admin.submissions' => 'admin'");
    expect(queueShim).toContain("Kernel::handle('abh.admin.submissions')");
    expect(evidenceShim).toContain("Kernel::handle('abh.admin.submission.evidence')");
    expect(queueController).toContain("requireAbhAdminContext()");
    expect(queueController).toContain("requirePermission('submissions.read')");
    expect(evidenceController).toContain("requirePermission('submissions.read')");
  });

  it('serves private evidence only after path, MIME, size and hash validation', () => {
    expect(evidenceController).toContain('Paths::absoluteForStoredPath');
    expect(evidenceController).toContain('realpath(Paths::storageRoot())');
    expect(evidenceController).toContain('is_link($absolute)');
    expect(evidenceController).toContain('ImageInspector::inspectPath');
    expect(evidenceController).toContain('ImageInspector::sha256');
    expect(evidenceController).toContain('Response::binary');
    expect(response).toContain('public static function binary');
    expect(response).toContain('$this->rawBody ?? Json::encode($this->payload)');
  });

  it('requires the review permission and reason for rejection or correction', () => {
    expect(decisionController).toContain("requirePermission('submissions.review')");
    expect(decisionController).toContain("'APPROVE', 'REJECT', 'REQUEST_CORRECTION'");
    expect(decisionController).toContain('A reason is required');
    expect(decisionController).toContain('Validator::uuid');
    expect(decisionShim).toContain("Kernel::handle('abh.admin.submission.decision')");
  });

  it('locks the submission row and applies approved quantity once in the same transaction', () => {
    expect(repository).toContain('Connection::transaction');
    expect(repository).toContain('LIMIT 1 FOR UPDATE');
    expect(repository).toContain("!== 'PENDING_REVIEW'");
    expect(repository).toContain("approved_quantity = approved_quantity + VALUES(approved_quantity)");
    expect(repository).toContain('abh_audit_events');
    expect(repository).toContain('abh_notifications');
    expect(repository).toContain('idempotent_replay');
    expect(repository).toContain('IDEMPOTENCY_CONFLICT');
  });

  it('uses a dedicated admin API and a permission-aware evidence review interface', () => {
    expect(client).toContain('fetchAbhAdminSubmissions');
    expect(client).toContain('fetchAbhAdminSubmissionEvidence');
    expect(client).toContain('decideAbhAdminSubmission');
    expect(client).toContain('/abh/admin/submissions.php');
    expect(page).toContain('Enrollment review');
    expect(page).toContain('Evidence image');
    expect(page).toContain("admin.permissions.includes('submissions.review')");
    expect(page).toContain("decide('REQUEST_CORRECTION')");
    expect(page).toContain("decide('APPROVE')");
    expect(page).toContain("decide('REJECT')");
  });
});
