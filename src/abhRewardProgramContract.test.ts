import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('ABH reward programme configuration contract', () => {
  const kernel = read('private_storage/app/Http/Kernel.php');
  const programs = read('private_storage/app/Domain/AbhAdminRewardProgramsController.php');
  const rules = read('private_storage/app/Domain/AbhAdminRewardRulesController.php');
  const repository = read('private_storage/app/Database/AbhAdminRewardProgramRepository.php');
  const client = read('src/api/client.ts');
  const adminUi = read('src/pages/AdminPortalPage.tsx');

  it('registers both configuration endpoints behind ABH administrator authentication', () => {
    expect(kernel).toContain("'abh.admin.reward.programs' => \\FieldPulse\\Domain\\AbhAdminRewardProgramsController::class");
    expect(kernel).toContain("'abh.admin.reward.rules' => \\FieldPulse\\Domain\\AbhAdminRewardRulesController::class");
    expect(kernel).toContain("'abh.admin.reward.programs' => 'admin'");
    expect(kernel).toContain("'abh.admin.reward.rules' => 'admin'");
  });

  it('registers reward endpoints in both the controller and authentication maps', () => {
    const routes = kernel.split('private const ROUTES = [')[1]?.split('];')[0] ?? '';
    const auth = kernel.split('private const AUTH = [')[1]?.split('];')[0] ?? '';
    expect(routes).toContain("'abh.admin.reward.programs' =>");
    expect(routes).toContain("'abh.admin.reward.rules' =>");
    expect(auth).toContain("'abh.admin.reward.programs' => 'admin'");
    expect(auth).toContain("'abh.admin.reward.rules' => 'admin'");
  });

  it('requires rewards.manage and rejects unknown programme or rule fields', () => {
    expect(programs).toContain("$admin->requirePermission('rewards.manage')");
    expect(programs).toContain("['period_key', 'program_code', 'name', 'description']");
    expect(rules).toContain("$admin->requirePermission('rewards.manage')");
    expect(rules).toContain("['program_id', 'rule_key', 'rank_from', 'rank_to', 'minimum_approved_quantity']");
  });

  it('creates draft programmes only in open periods and audits the creation', () => {
    expect(repository).toContain("p.status");
    expect(repository).toContain("periodRow['status'] !== 'OPEN'");
    expect(repository).toContain("'DRAFT'");
    expect(repository).toContain("'reward.program.created'");
    expect(repository).toContain('abh_audit_events');
  });

  it('versions rules instead of overwriting historical rule definitions', () => {
    expect(repository).toContain('MAX(rule_version)');
    expect(repository).toContain('SET is_active = 0');
    expect(repository).toContain("'reward.rule.version_created'");
    expect(repository).toContain('eligibility_conditions_json');
  });

  it('exposes reward management only to administrators with the permission', () => {
    expect(adminUi).toContain("admin.permissions.includes('rewards.manage')");
    expect(adminUi).toContain("setActiveSection('rewards')");
    expect(adminUi).toContain('<AdminRewardProgramManagement admin={admin} />');
    expect(adminUi).toContain('Create draft programme');
    expect(adminUi).toContain('Add rule version');
  });

  it('only activates a draft programme with active rules in an open period and audits it', () => {
    expect(repository).toContain("program['status'] !== 'DRAFT'");
    expect(repository).toContain("program['period_status'] !== 'OPEN'");
    expect(repository).toContain("'rules_required'");
    expect(repository).toContain("'reward.program.activated'");
    expect(programs).toContain("($body['action'] ?? null) === 'ACTIVATE'");
    expect(adminUi).toContain('activateAbhRewardProgram');
    expect(client).toContain('activateAbhRewardProgram');
  });

  it('exposes typed frontend methods for programme and rule management', () => {
    expect(client).toContain('fetchAbhRewardPrograms');
    expect(client).toContain('createAbhRewardProgram');
    expect(client).toContain('fetchAbhRewardRules');
    expect(client).toContain('createAbhRewardRule');
  });
});
