import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const kernel = read('../private_storage/app/Http/Kernel.php');
const controller = read('../private_storage/app/Domain/AbhAgentSubmissionsController.php');
const repository = read('../private_storage/app/Database/AbhSubmissionRepository.php');
const shim = read('../public_html/api/v1/abh/agents/submissions.php');
const migration = read('../private_storage/abh_migrations/002_submissions_reviews.sql');
const client = read('api/client.ts');
const app = read('App.tsx');
const page = read('pages/AbhAgentPortalPage.tsx');

describe('ABH monthly enrollment submission contract', () => {
  it('routes submissions through ABH device-signed authentication', () => {
    expect(kernel).toContain("'abh.agent.submissions'");
    expect(kernel).toContain("'abh.agent.submissions' => 'signed'");
    expect(shim).toContain("Kernel::handle('abh.agent.submissions')");
    expect(kernel).toContain("str_starts_with($route, 'abh.')");
  });

  it('accepts a signed multipart evidence payload, not client-supplied ownership', () => {
    expect(controller).toContain("if (!$request->isMultipart())");
    expect(controller).toContain("$request->form('payload')");
    expect(controller).toContain("$request->file('evidence')");
    expect(controller).toContain('ImageInspector::inspectUploaded');
    expect(controller).toContain('ImageInspector::sha256');
    expect(controller).toContain('FILE_HASH_MISMATCH');
    expect(controller).toContain("'idempotency_key'");
    expect(controller).toContain("'claimed_quantity'");
    expect(controller).not.toContain("'agent_id'");
    expect(controller).toContain("Validator::periodMonth");
    expect(repository).toContain("'PENDING_REVIEW'");
    expect(controller).toContain('Paths::storagePath');
    expect(controller).toContain('StorageState::hardenFile');
  });

  it('writes only ABH submission, revision, evidence, review, audit and notification tables', () => {
    expect(repository).toContain('abh_enrollment_submissions');
    expect(repository).toContain('abh_submission_revisions');
    expect(repository).toContain('abh_submission_evidence');
    expect(repository).toContain('abh_reporting_periods');
    expect(repository).toContain('abh_audit_events');
    expect(repository).toContain('abh_notifications');
    expect(repository).toContain('FOR UPDATE');
    expect(repository).toContain("status = \"OPEN\"");
    expect(repository).toContain('submissions.review');
    expect(repository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:submissions|agents|devices|audit_logs|notifications)\b/i);
  });

  it('keeps an idempotent retry bound to the same payload and agent', () => {
    expect(migration).toContain('UNIQUE KEY uq_abh_enrollment_submissions_agent_idempotency');
    expect(controller).toContain('findByIdempotencyKey');
    expect(controller).toContain('idempotent_replay');
    expect(controller).toContain('IDEMPOTENCY_CONFLICT');
  });

  it('signs the identical JSON payload that is uploaded with the evidence file', () => {
    expect(client).toContain('sha256Hex(input.evidence)');
    expect(client).toContain("form.append('payload', rawPayload)");
    expect(client).toContain("form.append('evidence', input.evidence");
    expect(client).toContain("signedBody: rawPayload");
    expect(client).toContain("authenticatedFetch('/abh/agents/submissions.php");
  });

  it('shows a dedicated ABH agent submission portal, not legacy FieldPulse screens', () => {
    expect(app).toContain("import AbhAgentPortalPage from './pages/AbhAgentPortalPage'");
    expect(app).toContain('<AbhAgentPortalPage agent={agent} onSignOut={endSession} />');
    expect(app).not.toContain('triggerSync');
    expect(page).toContain('submitAbhEnrollment');
    expect(page).toContain('fetchAbhAgentSubmissions');
    expect(page).toContain('Approved enrollments');
    expect(page).toContain('Pending review');
  });

  it('has the ABH revision and evidence schema required by the upload flow', () => {
    expect(migration).toContain('current_revision_id');
    expect(migration).toContain('claimed_quantity');
    expect(migration).toContain('activity_date');
    expect(migration).toContain('storage_key');
    expect(migration).toContain('sha256');
    expect(migration).toContain('reviewer_admin_id');
  });
});
