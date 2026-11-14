import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/008_admin_sessions_and_permissions.sql');
const bootstrapController = read('../private_storage/app/Domain/AbhAdminBootstrapController.php');
const authRepository = read('../private_storage/app/Database/AbhAdminAuthRepository.php');
const loginController = read('../private_storage/app/Domain/AbhAdminLoginController.php');
const authenticator = read('../private_storage/app/Security/AbhAdminAuthenticator.php');
const agentRepository = read('../private_storage/app/Database/AbhAdminAgentRepository.php');
const decisionController = read('../private_storage/app/Domain/AbhAdminAgentDecisionController.php');
const deviceController = read('../private_storage/app/Domain/AbhAdminDeviceDecisionController.php');
const detailsController = read('../private_storage/app/Domain/AbhAdminAgentDetailsController.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const request = read('../private_storage/app/Http/Request.php');
const api = read('api/client.ts');
const app = read('App.tsx');
const page = read('pages/AdminPortalPage.tsx');

describe('ABH administrator security and management contract', () => {
  it('creates independent administrator sessions and attempt throttling', () => {
    expect(migration).toContain('CREATE TABLE abh_admin_sessions');
    expect(migration).toContain('CREATE TABLE abh_admin_auth_attempts');
    expect(migration).toContain('access_token_hash');
    expect(migration).toContain('refresh_token_hash');
    expect(migration).toContain('token_family');
    expect(migration).toContain('CREATE TABLE abh_admin_bootstrap_guard');
    expect(migration).toContain('SUPER_ADMIN');
    expect(migration).toContain('AGENT_REVIEWER');
    expect(migration).toContain('devices.approve');
  });

  it('permits only one secret-gated first administrator setup', () => {
    expect(bootstrapController).toContain('ABH_ADMIN_BOOTSTRAP_KEY');
    expect(bootstrapController).toContain('hash_equals');
    expect(bootstrapController).toContain('bootstrapFirstAdmin');
    expect(authRepository).toContain('abh_admin_bootstrap_guard');
    expect(authRepository).toContain('FOR UPDATE');
    expect(authRepository).toContain('already_initialized');
    expect(authRepository).toContain("'SUPER_ADMIN'");
    expect(page).toContain('First-time setup');
    expect(page).toContain('bootstrap_key: bootstrapKey');
  });

  it('does not mix administrator credentials with agent or legacy users', () => {
    expect(loginController).toContain("['email', 'password']");
    expect(loginController).toContain('AbhAdminAuthRepository::findLoginIdentity');
    expect(authRepository).toContain('FROM abh_admin_users');
    expect(authRepository).toContain('abh_admin_sessions');
    expect(authRepository).not.toMatch(/\b(?:FROM|JOIN|INTO|UPDATE)\s+(?:agents|users|refresh_tokens|login_attempts)\b/i);
    expect(authenticator).toContain('AbhAdminAuthRepository::findByAccessToken');
    expect(authenticator).toContain('AbhAdminContext');
    expect(request).toContain('requireAbhAdminContext');
  });

  it('authorizes administration using live database roles and permissions', () => {
    expect(authRepository).toContain('abh_admin_user_roles');
    expect(authRepository).toContain('abh_admin_role_permissions');
    expect(authenticator).toContain("['roles'] === []");
    expect(kernel).toContain("'abh.admin.auth.login'");
    expect(kernel).toContain("'abh.admin.agents'");
    expect(kernel).toContain("str_starts_with($route, 'abh.admin.')");
    expect(page).toContain("admin.permissions.includes('agents.read')");
  });

  it('reviews applications transactionally and invalidates sessions on suspension', () => {
    expect(agentRepository).toContain('Connection::transaction');
    expect(agentRepository).toContain('UPDATE abh_agents');
    expect(agentRepository).toContain('UPDATE abh_agent_sessions');
    expect(agentRepository).toContain('INSERT INTO abh_audit_events');
    expect(agentRepository).toContain('INSERT INTO abh_notifications');
    expect(decisionController).toContain("agents.approve");
    expect(decisionController).toContain("agents.reject");
    expect(decisionController).toContain("agents.suspend");
    expect(decisionController).toContain('A reason is required');
  });

  it('authorizes and revokes devices separately from agent accounts', () => {
    expect(agentRepository).toContain('UPDATE abh_agent_devices');
    expect(agentRepository).toContain('UPDATE abh_agent_sessions');
    expect(agentRepository).toContain('DEVICE_REVOKED');
    expect(deviceController).toContain("devices.approve");
    expect(deviceController).toContain("devices.revoke");
    expect(agentRepository).toContain("['agent_status'] !== 'ACTIVE'");
    expect(detailsController).toContain("$admin->hasPermission('devices.read')");
  });

  it('uses a separate administrator browser session and portal route', () => {
    expect(api).toContain('/abh/admin/auth/login.php');
    expect(api).toContain('/abh/admin/auth/refresh.php');
    expect(api).toContain('/abh/admin/auth/logout.php');
    expect(api).toContain("'abh:admin:refresh'");
    expect(api).toContain("new BroadcastChannel('abh-admin-session')");
    expect(api).toContain('fetchAdminAgents');
    expect(api).toContain('decideAdminDevice');
    expect(app).toContain('useLocation');
    expect(app).toContain('<AdminPortalPage />');
    expect(page).toContain('Administrator sign in');
    expect(page).toContain('Agent applications');
  });
});
