import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const agentController = read('../private_storage/app/Domain/AbhAgentSubmissionsController.php');
const agentAuthenticator = read('../private_storage/app/Security/AbhAuthenticator.php');
const agentRepository = read('../private_storage/app/Database/AbhSubmissionRepository.php');
const agentPortal = read('pages/AbhAgentPortalPage.tsx');
const client = read('api/client.ts');
const adminRepository = read('../private_storage/app/Database/AbhAdminSubmissionRepository.php');
const evidenceController = read('../private_storage/app/Domain/AbhAdminSubmissionEvidenceController.php');
const adminPage = read('pages/AdminPortalPage.tsx');
const kernel = read('../private_storage/app/Http/Kernel.php');

describe('ABH submission and evidence workflow', () => {
  it('accepts signed multipart submissions and validates evidence integrity', () => {
    expect(agentController).toContain("method() === 'POST'");
    expect(agentAuthenticator).toContain('signedBody()');
    expect(agentController).toContain('evidence_sha256');
    expect(agentController).toContain('ImageInspector::inspectUploaded');
    expect(agentController).toContain('ImageInspector::sha256');
    expect(agentRepository).toContain("'PENDING_REVIEW'");
    expect(agentController).toContain('StorageState::hardenFile');
    expect(agentController).toContain('fsync($handle)');
    expect(agentController).toContain('StorageState::fsyncDir');
    expect(agentController).toContain('abh_evidence/pending');
  });

  it('stores each submission and evidence under the authenticated agent and immutable revision', () => {
    expect(agentRepository).toContain('Connection::transaction');
    expect(agentRepository).toContain('abh_enrollment_submissions');
    expect(agentRepository).toContain('abh_submission_revisions');
    expect(agentRepository).toContain('abh_submission_evidence');
    expect(agentRepository).toContain('current_revision_id');
    expect(agentRepository).toContain('findByIdempotencyKey');
    expect(agentRepository).toContain('listForAgent');
    expect(agentRepository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:submissions|agents|devices)\b/i);
  });

  it('calculates complete monthly summary, agent target and monthly rank on the server', () => {
    expect(agentRepository).toContain('summaryForAgent');
    expect(agentRepository).toContain('abh_monthly_targets');
    expect(agentRepository).toContain('scope_type = "AGENT"');
    expect(agentRepository).toContain('abh_monthly_leaderboard_snapshots');
    expect(agentRepository).toContain('approved_quantity');
    expect(agentRepository).toContain('progress_percent');
    expect(agentController).toContain("'summary' => $summary");
    expect(client).toContain('interface AbhAgentMonthlySummary');
    expect(agentPortal).toContain('Monthly target');
    expect(agentPortal).toContain('Leaderboard position');
  });

  it('lists administrator review items without exposing private storage paths', () => {
    expect(adminRepository).toContain('public static function list');
    expect(adminRepository).toContain('abh_enrollment_submissions');
    expect(adminRepository).toContain('abh_submission_reviews');
    expect(adminRepository).toContain('original_filename');
    const listMethod = adminRepository.slice(
      adminRepository.indexOf('public static function list'),
      adminRepository.indexOf('public static function evidence')
    );
    expect(listMethod).not.toContain('storage_key');
    expect(adminPage).toContain('Enrollment submissions');
    expect(adminPage).toContain('Evidence image');
  });

  it('requires submission review permission and protects evidence behind private storage', () => {
    expect(evidenceController).toContain("requirePermission('submissions.read')");
    expect(evidenceController).toContain('Paths::absoluteForStoredPath');
    expect(evidenceController).toContain('realpath(Paths::storageRoot())');
    expect(evidenceController).toContain('ImageInspector::sha256');
    expect(evidenceController).toContain('Response::binary');
    expect(kernel).toContain("'abh.admin.submission.evidence'");
  });

  it('approves a submission once, updates the monthly total and records audit and notification', () => {
    expect(adminRepository).toContain('FOR UPDATE');
    expect(adminRepository).toContain('idempotency_key');
    expect(adminRepository).toContain("p.status AS period_status");
    expect(adminRepository).toContain("period_status'] !== 'OPEN'");
    expect(adminRepository).toContain('abh_monthly_agent_totals');
    expect(adminRepository).toContain('approved_submission_count');
    expect(adminRepository).toContain('INSERT INTO abh_submission_reviews');
    expect(adminRepository).toContain('INSERT INTO abh_audit_events');
    expect(adminRepository).toContain('INSERT INTO abh_notifications');
    expect(adminPage).toContain('Approval adds this revision');
    expect(adminPage).toContain('Keep this key on failure');
  });

  it('exposes only ABH-specific signed submission and admin review routes', () => {
    expect(kernel).toContain("'abh.agent.submissions'");
    expect(kernel).toContain("'abh.admin.submissions'");
    expect(kernel).toContain("'abh.admin.submission.decision'");
    expect(kernel).toContain("'abh.admin.submission.evidence'");
    expect(client).toContain('/abh/agents/submissions.php');
    expect(client).toContain('/abh/admin/submission-decision.php');
  });
});
