import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const read = (path: string) => readFileSync(resolve(here, path), 'utf8');

const migration = read('../private_storage/abh_migrations/005_audit_notifications.sql');
const repository = read('../private_storage/app/Database/AbhNotificationRepository.php');
const agentController = read('../private_storage/app/Domain/AbhAgentNotificationsController.php');
const adminController = read('../private_storage/app/Domain/AbhAdminNotificationsController.php');
const kernel = read('../private_storage/app/Http/Kernel.php');
const agentShim = read('../public_html/api/v1/abh/agents/notifications.php');
const adminShim = read('../public_html/api/v1/abh/admin/notifications.php');
const api = read('api/client.ts');
const agentPage = read('pages/AbhAgentPortalPage.tsx');
const adminPage = read('pages/AdminPortalPage.tsx');

describe('ABH recipient-scoped notification inboxes', () => {
  it('uses the ABH notification table with mutually exclusive recipient identities', () => {
    expect(migration).toContain('CREATE TABLE abh_notifications');
    expect(migration).toContain('recipient_admin_user_id');
    expect(migration).toContain('recipient_agent_id');
    expect(migration).toContain('chk_abh_notifications_recipient');
    expect(repository).toContain('abh_notifications');
  });

  it('keeps agent and admin notification endpoints on separate authentication paths', () => {
    expect(kernel).toContain("'abh.agent.notifications' => 'signed'");
    expect(kernel).toContain("'abh.admin.notifications' => 'admin'");
    expect(agentShim).toContain("Kernel::handle('abh.agent.notifications')");
    expect(adminShim).toContain("Kernel::handle('abh.admin.notifications')");
    expect(agentController).toContain('requireAuth()');
    expect(adminController).toContain('requireAbhAdminContext()');
  });

  it('derives recipient identity from the authenticated context, not request data', () => {
    expect(agentController).toContain('$agent->agentId()');
    expect(adminController).toContain('$admin->id()');
    expect(repository).toContain('recipient_agent_id = :agent_id');
    expect(repository).toContain('recipient_admin_user_id = :admin_id');
    expect(repository).not.toContain("$body['agent_id']");
    expect(repository).not.toContain("$body['admin_id']");
  });

  it('supports pagination and individual or all-notification read state', () => {
    expect(repository).toContain('read_at IS NULL');
    expect(repository).toContain('SET read_at = UTC_TIMESTAMP(6)');
    expect(repository).toContain('notification_id');
    expect(agentController).toContain("['notification_id', 'mark_all']");
    expect(adminController).toContain("['notification_id', 'mark_all']");
    expect(agentController).toContain('markAgentRead');
    expect(adminController).toContain('markAdminRead');
  });

  it('exposes both inboxes in their respective portals', () => {
    expect(api).toContain('fetchAbhAgentNotifications');
    expect(api).toContain('markAbhAgentNotificationRead');
    expect(api).toContain('markAllAbhAgentNotificationsRead');
    expect(api).toContain('fetchAbhAdminNotifications');
    expect(api).toContain('markAbhAdminNotificationRead');
    expect(api).toContain('markAllAbhAdminNotificationsRead');
    expect(agentPage).toContain('Notifications');
    expect(agentPage).toContain('Mark all read');
    expect(adminPage).toContain('Administrator notifications');
    expect(adminPage).toContain('Mark all read');
  });
});
