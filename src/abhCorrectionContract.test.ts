import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/009_revision_idempotency.sql');
const correctionRepository = read('../private_storage/app/Database/AbhSubmissionCorrectionRepository.php');
const submissionRepository = read('../private_storage/app/Database/AbhSubmissionRepository.php');
const controller = read('../private_storage/app/Domain/AbhAgentSubmissionsController.php');
const api = read('api/client.ts');
const portal = read('pages/AbhAgentPortalPage.tsx');
const schemaTest = read('../private_storage/bin/abh_schema_test.php');

describe('ABH immutable correction workflow', () => {
  it('adds a per-submission revision idempotency key without overwriting old revisions', () => {
    expect(migration).toContain('ALTER TABLE abh_submission_revisions');
    expect(migration).toContain('idempotency_key CHAR(36)');
    expect(migration).toContain('uq_abh_submission_revisions_submission_idempotency');
    expect(submissionRepository).toContain('(submission_id, revision_no, idempotency_key, claimed_quantity');
    expect(schemaTest).toContain("'idempotency_key', $revisionColumns");
  });

  it('requires the same authenticated owner, correction-requested state and open original month', () => {
    expect(correctionRepository).toContain('s.agent_id = :agent_id');
    expect(correctionRepository).toContain('LIMIT 1 FOR UPDATE');
    expect(correctionRepository).toContain("!== 'CORRECTION_REQUESTED'");
    expect(correctionRepository).toContain("!== 'OPEN'");
    expect(correctionRepository).toContain("!== $periodKey");
    expect(controller).toContain("'submission_id'");
    expect(controller).toContain('AbhSubmissionCorrectionRepository::create');
  });

  it('creates a new revision and evidence, then returns the same submission to review', () => {
    expect(correctionRepository).toContain("(int) $submission['revision_no'] + 1");
    expect(correctionRepository).toContain('INSERT INTO abh_submission_revisions');
    expect(correctionRepository).toContain('INSERT INTO abh_submission_evidence');
    expect(correctionRepository).toContain('SET current_revision_id = :revision_id');
    expect(correctionRepository).toContain('status = "PENDING_REVIEW"');
    expect(correctionRepository).toContain('submission.corrected');
    expect(correctionRepository).toContain('abh_notifications');
  });

  it('makes identical correction retries safe and rejects a reused key with changed data', () => {
    expect(correctionRepository).toContain('findByIdempotencyKey');
    expect(correctionRepository).toContain('idempotency_conflict');
    expect(correctionRepository).toContain('idempotent_replay');
    expect(controller).toContain('$this->removeEvidence($storageKey)');
    expect(controller).toContain('This revision key was already used for different correction data.');
  });

  it('lets agents see the review note and submit a corrected revision from the portal', () => {
    expect(submissionRepository).toContain('r.agent_note');
    expect(submissionRepository).toContain('AS review_note');
    expect(api).toContain('review_note: string | null');
    expect(api).toContain('export async function submitAbhCorrection');
    expect(api).toContain('submission_id: input.submission_id');
    expect(portal).toContain("item.status === 'CORRECTION_REQUESTED'");
    expect(portal).toContain('item.review_note');
    expect(portal).toContain('Correct and resubmit');
    expect(portal).toContain('Submit corrected revision');
    expect(portal).toContain('setCorrectionSubmissionId(item.id)');
  });
});
