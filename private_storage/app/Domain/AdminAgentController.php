<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Config\Config;
use FieldPulse\Security\AuthContext;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\PairingCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * ADMIN account-management endpoints.
 *
 *   GET  /api/v1/admin/agents    the account directory
 *   POST /api/v1/admin/agents    create an account
 *   POST /api/v1/admin/agent      update one account (role / status / password / pairing)
 *
 * The routes are gated by Http\Kernel's `admin` requirement, so the check is
 * visible in one table and a controller mistake cannot leave the surface open.
 *
 * Account lifecycle:
 *   create   always lands ACTIVE with a credential, so an agent can log in on
 *            the first try. A created account is a promise to a person, not a
 *            draft.
 *   suspend  the account is unusable immediately (credentials and devices are
 *            revoked) but is reinstatable later with a fresh device pairing.
 *   retire   terminal soft delete: devices and credential are revoked, the row
 *            and its history survive for compliance, and DELETED can never be
 *            set back to anything. Nobody comes back from retire.
 *
 * Two guards apply to the state-changing actions, chosen so that the surface
 * can never be used to lock itself out:
 *   self   an ADMIN cannot re-role, suspend, retire or revoke their own row.
 *          Password reset is the exception — changing your own password locks
 *          nothing; those four would.
 *   last   an ACTIVE ADMIN who is the only remaining ACTIVE ADMIN cannot be
 *          demoted, suspended or retired. Every action below runs the check
 *          against the CURRENT row, so the guard holds even in a race.
 */
final class AdminAgentController implements ActionInterface
{
    private const ROLES       = ['AGENT', 'SUPERVISOR', 'ADMIN'];
    private const STATUS_TARGETS = ['ACTIVE', 'SUSPENDED', 'DELETED'];

    private const ACTIONS = [
        'SET_ROLE',
        'SET_STATUS',
        'SET_PASSWORD',
        'REVOKE_CREDENTIAL',
        'ISSUE_PAIRING_CODE',
    ];

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return $request->endpoint() === 'agent'
            ? $this->update($request)
            : ($request->method() === 'POST' ? $this->create($request) : $this->index($request));
    }

    /* -- List -------------------------------------------------------------- */

    private function index(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit  = $this->clamp($request->queryInt('limit'), 50, 1, 200);
        $offset = $this->clamp($request->queryInt('offset'), 0, 0, 100000);

        $items = $this->agents->listForAdmin($limit, $offset);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->present($row), $items),
            'meta' => [
                'pagination' => [
                    'total'  => $this->agents->countAll(),
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                'active_admins' => $this->agents->countActiveAdmins(),
                'requested_by'  => $context->agentCode(),
            ],
        ]);
    }

    /* -- Create ------------------------------------------------------------ */

    private function create(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();

        Validator::assertNoUnknownKeys(
            $body,
            ['agent_code', 'full_name', 'username', 'password', 'role', 'imei'],
            'admin.agent.create'
        );

        $agentCode = $this->agentCode($body['agent_code'] ?? null);
        $fullName  = Validator::string($body['full_name'] ?? null, 1, 191, 'full_name');
        $username  = Validator::username($body['username'] ?? null);
        $password  = Validator::password($body['password'] ?? null);
        $role      = $this->role($body['role'] ?? 'AGENT');
        $imei      = $body['imei'] ?? null;

        if ($imei !== null && $imei !== '') {
            $imei = Validator::imei($imei);
        }

        if ($this->agents->findByCode($agentCode) !== null) {
            throw ApiException::conflict(
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'An account with this agent code already exists.'
            );
        }

        if ($this->agents->findByUsername($username) !== null) {
            throw ApiException::conflict(
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'This username is already in use.'
            );
        }

        $agentId = $this->agents->create($agentCode, $fullName, $imei);

        try {
            $this->agents->setRole($agentId, $role);
            $this->agents->setCredentials($agentId, $username, Credentials::hash($password));
        } catch (\Throwable $e) {
            // A partial create would leave a directory row with no credential
            // and no way to know why. The row is cheap to remove and the caller
            // still needs a coherent answer, so tear down what the failure may
            // have left and rethrow.
            Connection::execute('DELETE FROM agents WHERE id = :id', ['id' => $agentId]);
            throw $e;
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.created',
            'entity_type'    => 'agent',
            'entity_id'      => $agentId,
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code' => $agentCode,
                'role'       => $role,
                'imei_bound' => $imei !== null,
            ],
        ]);

        Logger::info('admin.agent_created', [
            'agent_id'   => $agentId,
            'admin'      => $context->agentCode(),
            'role'       => $role,
        ]);

        return Response::json(
            ['data' => $this->present($this->requireAgent($agentId))],
            201
        );
    }

    /* -- Update ------------------------------------------------------------ */

    private function update(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();

        $id = Validator::intRange($body['id'] ?? null, 1, PHP_INT_MAX, 'id');
        $target = $this->requireAgent($id);

        $this->ensureNotRetired($target);

        $action = Validator::string($body['action'] ?? null, 3, 32, 'action');
        $action = strtoupper($action);

        if (!in_array($action, self::ACTIONS, true)) {
            throw ApiException::validation(
                'action must be one of ' . implode(', ', self::ACTIONS) . '.',
                ['field' => 'action', 'allowed' => self::ACTIONS]
            );
        }

        $allowed = [
            'SET_ROLE'           => ['id', 'action', 'role'],
            'SET_STATUS'         => ['id', 'action', 'status'],
            'SET_PASSWORD'       => ['id', 'action', 'password'],
            'REVOKE_CREDENTIAL'  => ['id', 'action'],
            'ISSUE_PAIRING_CODE' => ['id', 'action', 'label'],
        ];

        Validator::assertNoUnknownKeys($body, $allowed[$action], 'admin.agent.' . strtolower($action));

        // Changing your own password locks nothing; re-role, status and credential
        // revocation can lock an administrator out of their own account, and are
        // refused on self. Issuing a pairing code to yourself is the same class as
        // SET_PASSWORD: it adds a way in, it cannot remove the one you already hold.
        if (
            $action !== 'SET_PASSWORD'
            && $action !== 'ISSUE_PAIRING_CODE'
            && (int) $target['id'] === $context->agentId()
        ) {
            $this->denySelf();
        }

        $extra = match ($action) {
            'SET_ROLE'           => $this->setRole($request, $context, $target, $body),
            'SET_STATUS'         => $this->setStatus($request, $context, $target, $body),
            'SET_PASSWORD'       => $this->setPassword($request, $context, $target, $body),
            'REVOKE_CREDENTIAL'  => $this->revokeCredential($request, $context, $target),
            'ISSUE_PAIRING_CODE' => $this->issuePairingCode($request, $context, $target, $body),
        };

        $data = $this->present($this->requireAgent($id));
        if ($extra !== null) {
            $data = array_merge($data, $extra);
        }

        return Response::json(['data' => $data]);
    }

    private function setRole(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        $role = $this->role($body['role'] ?? null);

        if ($role === (string) $target['role']) {
            return null;
        }

        $this->guardLastActiveAdmin($target);

        $this->agents->setRole((int) $target['id'], $role);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.role_changed',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code' => (string) $target['agent_code'],
                'role'       => $role,
            ],
        ]);

        Logger::info('admin.agent_role_changed', [
            'agent_id' => (int) $target['id'],
            'admin'    => $context->agentCode(),
            'role'     => $role,
        ]);

        return null;
    }

    private function setStatus(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        $status = $this->statusTarget($body['status'] ?? null);

        if ($status === (string) $target['status']) {
            return null;
        }

        $action = match ($status) {
            'SUSPENDED' => 'admin.agent.suspended',
            'DELETED'   => 'admin.agent.retired',
            default     => 'admin.agent.reinstated',
        };

        // Only the destructive transitions can create a lockout; reinstating
        // somebody cannot. The guard is cheap, so it runs on every change.
        $this->guardLastActiveAdmin($target);

        // Every transition away from ACTIVE kills the gate to the account: a
        // suspended or retired user's token stops working on the next request,
        // so a device that outlived the account must stop working too.
        if ($status !== 'ACTIVE') {
            $this->devices->revokeAllForAgent((int) $target['id']);
        }

        if ($status === 'DELETED') {
            $this->agents->clearCredentials((int) $target['id']);
        }

        $this->agents->setStatus((int) $target['id'], $status);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => $action,
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code'      => (string) $target['agent_code'],
                'status'          => $status,
                'devices_revoked' => $status !== 'ACTIVE',
            ],
        ]);

        Logger::info('admin.agent_status_changed', [
            'agent_id' => (int) $target['id'],
            'admin'    => $context->agentCode(),
            'status'   => $status,
        ]);

        return null;
    }

    private function setPassword(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        $password = Validator::password($body['password'] ?? null);

        // Resetting a password addresses an existing person; issuing the first
        // credential to a row that never had one is create's job.
        $current = $target['username'] ?? null;

        if (!is_string($current) || $current === '') {
            throw ApiException::conflict(
                ErrorCode::STATE_CONFLICT,
                'This account has no username to reset a password for.'
            );
        }

        $this->agents->setCredentials((int) $target['id'], $current, Credentials::hash($password));

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.password_reset',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => ['agent_code' => (string) $target['agent_code']],
        ]);

        Logger::info('admin.agent_password_reset', [
            'agent_id' => (int) $target['id'],
            'admin'    => $context->agentCode(),
        ]);

        return null;
    }

    private function revokeCredential(Request $request, AuthContext $context, array $target): ?array
    {
        $this->agents->clearCredentials((int) $target['id']);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.credential_revoked',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => ['agent_code' => (string) $target['agent_code']],
        ]);

        Logger::info('admin.agent_credential_revoked', [
            'agent_id' => (int) $target['id'],
            'admin'    => $context->agentCode(),
        ]);

        return null;
    }

    private function issuePairingCode(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        // The pairing gate only lets a device bind while the account is
        // reachable (first device under FIRST_DEVICE_ONLY, every device under
        // ALWAYS). A suspended or retired account would never be able to
        // consume the code, so minting one for it is wasted secrets.
        if ((string) $target['status'] !== AgentRepository::ACTIVE) {
            throw ApiException::conflict(
                ErrorCode::STATE_CONFLICT,
                'Only active accounts can receive a pairing code.'
            );
        }

        $label = $body['label'] ?? null;

        if ($label !== null) {
            $label = Validator::string($label, 1, 100, 'label');
        } elseif (isset($target['agent_code'])) {
            $label = 'Mobile device for ' . $target['agent_code'];
        }

        $ttl      = (int) Config::instance()->int('security.pairing_code_ttl', 1800);
        $now      = Clock::now();
        $expires  = $now->modify(sprintf('+%d seconds', $ttl));
        $expiresIso = $expires->format('c'); // ISO-8601 with offset, parseable by the browser
        $code     = PairingCode::random();

        Connection::execute(
            'INSERT INTO pairing_codes
                (agent_id, code_hash, label, attempts, max_attempts, expires_at, created_by, created_at)
             VALUES (:agent_id, :hash, :label, 0, 5, :expires_at, :created_by, :created_at)',
            [
                'agent_id'   => (int) $target['id'],
                'hash'       => PairingCode::hash($code),
                'label'      => $label,
                'expires_at' => Clock::sql($expires),
                'created_by' => $context->agentCode(),
                'created_at' => Clock::sql($now),
            ]
        );

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.pairing_code_issued',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code'  => (string) $target['agent_code'],
                'ttl_seconds' => $ttl,
                'expires_at'  => Clock::sql($expires),
                'label'       => $label,
            ],
        ]);

        Logger::info('admin.agent_pairing_code_issued', [
            'agent_id'    => (int) $target['id'],
            'admin'       => $context->agentCode(),
            'ttl_seconds' => $ttl,
            'expires_at'  => Clock::sql($expires),
        ]);

        return [
            'pairing_code' => $code,
            'expires_at'   => $expiresIso,
            'ttl_seconds'  => $ttl,
        ];
    }

    /* -- Guards ------------------------------------------------------------ */

    /** @param array<string,mixed> $target */
    private function guardLastActiveAdmin(array $target): void
    {
        $isActiveAdmin = (string) $target['role'] === 'ADMIN'
            && (string) $target['status'] === AgentRepository::ACTIVE;

        if ($isActiveAdmin && $this->agents->countActiveAdmins() <= 1) {
            throw ApiException::conflict(
                ErrorCode::STATE_CONFLICT,
                'This is the last active administrator. Demoting, suspending or retiring it would lock everyone out.'
            );
        }
    }

    private function denySelf(): void
    {
        throw ApiException::conflict(
            ErrorCode::STATE_CONFLICT,
            'An administrator cannot re-role, suspend, retire or revoke their own account. Ask another administrator.'
        );
    }

    /** @param array<string,mixed> $target */
    private function ensureNotRetired(array $target): void
    {
        if ((string) $target['status'] === AgentRepository::DELETED) {
            throw ApiException::conflict(
                ErrorCode::STATE_CONFLICT,
                'Retired accounts cannot be changed. Create a new account instead.'
            );
        }
    }

    /** @return array<string,mixed>|never */
    private function requireAgent(int $id): array
    {
        $row = $this->agents->findById($id);

        if ($row === null) {
            throw ApiException::notFound(ErrorCode::UNKNOWN_AGENT, 'Account not found.');
        }

        return $row;
    }

    private function agentCode(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiException::validation('Field "agent_code" is required.', ['field' => 'agent_code']);
        }

        $code = trim($value);

        if (preg_match('/^[A-Za-z0-9._-]{2,64}$/', $code) !== 1) {
            throw ApiException::validation(
                'agent_code must be 2 to 64 characters using letters, digits, dot, underscore or hyphen.',
                ['field' => 'agent_code']
            );
        }

        return $code;
    }

    private function role(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiException::validation('Field "role" is required.', ['field' => 'role']);
        }

        $role = strtoupper(trim($value));

        if (!in_array($role, self::ROLES, true)) {
            throw ApiException::validation(
                'role must be one of ' . implode(', ', self::ROLES) . '.',
                ['field' => 'role', 'allowed' => self::ROLES]
            );
        }

        return $role;
    }

    private function statusTarget(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiException::validation('Field "status" is required.', ['field' => 'status']);
        }

        $status = strtoupper(trim($value));

        if (!in_array($status, self::STATUS_TARGETS, true)) {
            throw ApiException::validation(
                'status must be one of ' . implode(', ', self::STATUS_TARGETS) . '.',
                ['field' => 'status', 'allowed' => self::STATUS_TARGETS]
            );
        }

        return $status;
    }

    /**
     * Shape a row for the admin UI.
     *
     * Handles both sources: the directory query (listForAdmin) carries
     * has_credential and active_device_count, while a freshly written row
     * arrives from findById (SELECT *) with neither. Computing the fallback is
     * cheap and keeps one presentation path for every response.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id'                  => $id,
            'agent_code'          => (string) $row['agent_code'],
            'full_name'           => (string) $row['full_name'],
            'role'                => (string) $row['role'],
            'status'              => (string) $row['status'],
            'username'            => $row['username'] ?? null,
            'has_credential'      => (bool) ($row['has_credential'] ?? (($row['password_hash'] ?? null) !== null)),
            'active_device_count' => (int) ($row['active_device_count'] ?? $this->agents->countActiveDevices($id)),
            'created_at'          => (string) $row['created_at'],
        ];
    }

    private function clamp(?int $value, int $default, int $min, int $max): int
    {
        return $value === null ? $default : max($min, min($max, $value));
    }
}