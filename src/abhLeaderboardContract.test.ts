import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const kernel = read('../private_storage/app/Http/Kernel.php');
const repository = read('../private_storage/app/Database/AbhLeaderboardRepository.php');
const agentController = read('../private_storage/app/Domain/AbhAgentLeaderboardController.php');
const adminController = read('../private_storage/app/Domain/AbhAdminLeaderboardController.php');
const periodRepository = read('../private_storage/app/Database/AbhAdminPeriodRepository.php');
const submissionRepository = read('../private_storage/app/Database/AbhSubmissionRepository.php');
const agentShim = read('../public_html/api/v1/abh/agents/leaderboard.php');
const adminShim = read('../public_html/api/v1/abh/admin/leaderboard.php');
const api = read('api/client.ts');
const agentPage = read('pages/AbhAgentPortalPage.tsx');
const adminPage = read('pages/AdminPortalPage.tsx');

describe('ABH monthly leaderboard', () => {
  it('protects the agent and administrator leaderboard endpoints independently', () => {
    expect(kernel).toContain("'abh.agent.leaderboard'");
    expect(kernel).toContain("'abh.agent.leaderboard' => 'signed'");
    expect(kernel).toContain("'abh.admin.leaderboard'");
    expect(kernel).toContain("'abh.admin.leaderboard' => 'admin'");
    expect(agentShim).toContain("Kernel::handle('abh.agent.leaderboard')");
    expect(adminShim).toContain("Kernel::handle('abh.admin.leaderboard')");
    expect(agentController).toContain('requireAuth()');
    expect(adminController).toContain("requirePermission('submissions.read')");
  });

  it('uses live approved revisions while a reporting month is open', () => {
    expect(repository).toContain('abh_enrollment_submissions');
    expect(repository).toContain('SUM(r.claimed_quantity) AS approved_quantity');
    expect(repository).toContain('s.status = "APPROVED"');
    expect(repository).toContain('RANK() OVER (ORDER BY ranked.approved_quantity DESC)');
    expect(repository).toContain("ahead.approved_quantity > own.approved_quantity");
  });

  it('uses immutable month-end snapshots after a reporting month is closed', () => {
    expect(repository).toContain('abh_monthly_leaderboard_snapshots');
    expect(repository).toContain('agent_code_snapshot AS agent_code');
    expect(repository).toContain('display_name_snapshot AS display_name');
    expect(repository).toContain("=== 'CLOSED'");
    expect(repository).toContain('ORDER BY s.rank_position ASC');
  });

  it('assigns competition ranks consistently for equal approved totals', () => {
    expect(submissionRepository).toContain('ranked.approved_quantity > :own_quantity');
    expect(submissionRepository).not.toContain('tie_quantity');
    expect(periodRepository).toContain('$positionIndex++');
    expect(periodRepository).toContain('$quantity !== $previousQuantity');
    expect(periodRepository).toContain("'rank_position' => $rankPosition");
  });

  it('returns the authenticated agent position even when the agent is outside the first page', () => {
    expect(repository).toContain("'viewer' => $viewerResult");
    expect(repository).toContain("'is_viewer' => $viewerAgentId !== null");
    expect(repository).toContain('LIMIT ' + "' . $limit . ' OFFSET ' . $offset");
    expect(agentController).toContain('$agent->agentId()');
  });

  it('connects agent and administrator month-filtered leaderboard screens', () => {
    expect(api).toContain('fetchAbhAgentLeaderboard');
    expect(api).toContain('fetchAbhAdminLeaderboard');
    expect(api).toContain('/abh/agents/leaderboard.php');
    expect(api).toContain('/abh/admin/leaderboard.php');
    expect(agentPage).toContain('Monthly leaderboard');
    expect(agentPage).toContain('Your position');
    expect(agentPage).toContain('Final frozen ranking');
    expect(adminPage).toContain('AdminLeaderboardView');
    expect(adminPage).toContain('Approved agent performance');
  });
});
