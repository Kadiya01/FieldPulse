import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(root, path), 'utf8');

describe('ABH reward programme configuration contract', () => {
  const kernel = read('../private_storage/app/Http/Kernel.php');
  const programs = read('../private_storage/app/Domain/AbhAdminRewardProgramsController.php');
  const rules = read('../private_storage/app/Domain/AbhAdminRewardRulesController.php');
  const repository = read('../private_storage/app/Database/AbhAdminRewardProgramRepository.php');
  const qualificationRepository = read('../private_storage/app/Database/AbhAdminRewardQualificationRepository.php');
  const qualificationController = read('../private_storage/app/Domain/AbhAdminRewardQualificationController.php');
  const lifecycleRepository = read('../private_storage/app/Database/AbhAdminRewardLifecycleRepository.php');
  const lifecycleController = read('../private_storage/app/Domain/AbhAdminRewardLifecycleController.php');
  const client = read('api/client.ts');
  const adminUi = read('pages/AdminPortalPage.tsx');

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

  it('edits only draft programmes in open periods and records before/after audit data', () => {
    expect(repository).toContain('updateDraftProgram');
    expect(repository).toContain("'reward.program.updated'");
    expect(repository).toContain("before['status'] !== 'DRAFT'");
    expect(repository).toContain("before['period_status'] !== 'OPEN'");
    expect(programs).toContain("($body['action'] ?? null) === 'UPDATE'");
    expect(client).toContain('updateAbhRewardProgram');
    expect(adminUi).toContain('saveProgramEdits');
    expect(adminUi).toContain('Save programme changes');
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


  it('previews only closed-period frozen leaderboard snapshots without writing qualifications', () => {
    expect(kernel).toContain("'abh.admin.reward.qualifications' => \\FieldPulse\\Domain\\AbhAdminRewardQualificationController::class");
    expect(kernel).toContain("'abh.admin.reward.qualifications' => 'admin'");
    expect(qualificationController).toContain("$admin->requirePermission('rewards.manage')");
    expect(qualificationController).toContain("AbhAdminRewardQualificationRepository::preview");
    expect(qualificationRepository).toContain("period_status'] !== 'CLOSED'");
    expect(qualificationRepository).toContain('abh_monthly_leaderboard_snapshots');
    expect(qualificationRepository).toContain("'read_only' => true");
    expect(qualificationRepository).toContain('private static function buildPreview');
    expect(qualificationRepository.split('public static function preview')[1].split('public static function evaluate')[0]).not.toContain('INSERT INTO abh_reward_qualifications');
  });

  it('evaluates once from frozen snapshots, stores rule versions and audits the result', () => {
    expect(qualificationController).toContain("($body['action'] ?? null) !== 'EVALUATE'");
    expect(qualificationRepository).toContain('INSERT INTO abh_reward_qualifications');
    expect(qualificationRepository).toContain("'INELIGIBLE'");
    expect(qualificationRepository).toContain("'ELIGIBLE'");
    expect(qualificationRepository).toContain("'evaluated_rules'");
    expect(qualificationRepository).toContain("'matched_rule_id'");
    expect(qualificationRepository).toContain('qualification_snapshot_incomplete');
    expect(qualificationRepository).toContain('reward.qualification.evaluated');
    expect(qualificationRepository).toContain('abh_notifications');
    expect(qualificationRepository).toContain("'idempotent_replay' => true");
  });

  it('exposes the preview and explicit evaluation to reward managers', () => {
    expect(client).toContain('fetchAbhRewardQualificationPreview');
    expect(client).toContain('evaluateAbhRewardQualifications');
    expect(adminUi).toContain('Qualification preview');
    expect(adminUi).toContain('Preview qualification');
    expect(adminUi).toContain('Save qualification evaluation');
    expect(adminUi).toContain('qualificationPreview.items.map');
  });

  it('exposes typed frontend methods for programme and rule management', () => {
    expect(client).toContain('fetchAbhRewardPrograms');
    expect(client).toContain('createAbhRewardProgram');
    expect(client).toContain('fetchAbhRewardRules');
    expect(client).toContain('createAbhRewardRule');
  });

  it('registers and protects the gift, award and fulfilment API', () => {
    expect(kernel).toContain("'abh.admin.reward.lifecycle' =>");
    expect(kernel).toContain("'abh.admin.reward.lifecycle' => 'admin'");
    expect(lifecycleController).toContain("requirePermission('rewards.manage')");
    expect(lifecycleController).toContain("requirePermission('rewards.approve')");
    expect(lifecycleController).toContain("hasPermission('rewards.approve')");
    expect(lifecycleController).toContain("CREATE_GIFT");
    expect(lifecycleController).toContain("ASSIGN_GIFT");
    expect(lifecycleController).toContain("APPROVE_AWARD");
  });

  it('assigns only evaluated eligible agents and snapshots gift details', () => {
    expect(lifecycleRepository).toContain("qualification_status'] !== 'ELIGIBLE'");
    expect(lifecycleRepository).toContain("'PENDING_APPROVAL'");
    expect(lifecycleRepository).toContain('gift_title_snapshot');
    expect(lifecycleRepository).toContain('public static function updateGift');
    expect(lifecycleRepository).toContain("'reward.gift.updated'");
    expect(lifecycleRepository).toContain('assigned_by_admin_id');
    expect(lifecycleRepository).toContain('award_already_assigned');
    expect(lifecycleRepository).toContain('insufficient_stock');
  });

  it('enforces independent award approval and decrements finite stock under a transaction', () => {
    expect(lifecycleRepository).toContain('separation_of_duties');
    expect(lifecycleRepository).toContain('assigned_by_admin_id');
    expect(lifecycleRepository).toContain('available_quantity = available_quantity - :quantity');
    expect(lifecycleRepository).toContain("'reward.award.approved'");
  });

  it('records payment and delivery as separate append-only idempotent events', () => {
    expect(lifecycleRepository).toContain('abh_reward_fulfilment_events');
    expect(lifecycleRepository).toContain('idempotency_key');
    expect(lifecycleRepository).toContain("event_type = \"PAYMENT\"");
    expect(lifecycleRepository).toContain("event_type = \"DELIVERY\"");
    expect(lifecycleRepository).toContain('delivery_exceeds_award');
    expect(lifecycleRepository).toContain('payment_exceeds_value');
    expect(lifecycleRepository).toContain('idempotency_conflict');
    expect(lifecycleRepository).toContain('private static function canonicalAmount');
    expect(lifecycleRepository).toContain('private static function minorUnits');
    expect(lifecycleRepository).toContain('currency_code, reference_no, note FROM abh_reward_fulfilment_events');
    expect(lifecycleRepository).toContain("'reward.fulfilment.' . strtolower($type)");
  });

  it('exposes typed lifecycle client methods and admin controls', () => {
    expect(client).toContain('fetchAbhRewardLifecycle');
    expect(client).toContain('createAbhRewardGift');
    expect(client).toContain('updateAbhRewardGift');
    expect(client).toContain('assignAbhRewardGift');
    expect(client).toContain('approveAbhRewardAward');
    expect(client).toContain('recordAbhRewardPayment');
    expect(client).toContain('recordAbhRewardDelivery');
    expect(adminUi).toContain('Gift catalogue, awards and fulfilment');
    expect(adminUi).toContain('Assign to eligible agent');
    expect(adminUi).toContain('Approve award');
    expect(adminUi).toContain('Record payment');
    expect(adminUi).toContain('Record delivery');
    expect(adminUi).toContain('Append-only fulfilment history');
    expect(adminUi).toContain('const canRead = canManage || canApprove');
    expect(adminUi).toContain('const key = paymentKeys[a.id] || crypto.randomUUID()');
    expect(adminUi).toContain('const key = deliveryKeys[a.id] || crypto.randomUUID()');
  });

});
