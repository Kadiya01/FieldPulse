<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AdminDashboardRepository;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\MonthlyTargetRepository;
use FieldPulse\Database\NotificationRepository;
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
    private const STATUS_TARGETS = ['PENDING', 'ACTIVE', 'REJECTED', 'SUSPENDED', 'DELETED'];
    private const FILTER_STATUSES = ['ACTIVE', 'INACTIVE', 'SUSPENDED', 'DELETED', 'PENDING', 'REJECTED'];

    private const ACTIONS = [
        'SET_ROLE',
        'SET_STATUS',
        'SET_PASSWORD',
        'REVOKE_CREDENTIAL',
        'ISSUE_PAIRING_CODE',
        'SET_MONTHLY_TARGET',
        'SET_PROFILE',
    ];

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
        private readonly MonthlyTargetRepository $targets = new MonthlyTargetRepository(),
        private readonly AdminDashboardRepository $reports = new AdminDashboardRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->endpoint() === 'agent') {
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                return $this->show($request);
            }

            return $this->update($request);
        }

        if ($request->method() === 'POST') {
            return $this->create($request);
        }

        return $this->index($request);
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

        $filters = $this->filters($request);

        $items = $this->agents->listForAdmin($limit, $offset, $filters);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->present($row), $items),
            'meta' => [
                'pagination' => [
                    'total'  => $this->agents->countAll($filters),
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                'filters'       => $filters,
                'locations'     => $this->agents->distinctLocations(),
                'active_admins' => $this->agents->countActiveAdmins(),
                'requested_by'  => $context->agentCode(),
            ],
        ]);
    }

    /* -- Detail ------------------------------------------------------------ */

    private function show(Request $request): Response
    {
        $request->requireAuth();

        $id = Validator::intRange($request->query('id'), 1, PHP_INT_MAX, 'id');
        $target = $this->requireAgent($id);

        $periodDate = PeriodResolver::monthlyPeriodStartDate();
        $effective = $this->targets->effectiveTarget(
            $id,
            $target['location'] ?? null,
            (int) ($target['monthly_target'] ?? 0),
            $periodDate
        );

        $data = $this->present($target);
        $data['devices'] = array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'device_uuid'   => (string) $row['device_uuid'],
            'status'        => (string) $row['status'],
            'registered_at' => $row['registered_at'] ?? null,
            'last_seen_at'  => $row['last_seen_at'] ?? null,
            'imei'          => $row['imei'] ?? null,
        ], $this->devices->listForAgent($id));
        $data['recent_audit'] = AdminPresenter::auditRows(
            $this->audit->forEntity('agent', $id, 20)
        );
        $data['targets'] = array_map(
            [AdminPresenter::class, 'target'],
            $this->targets->forAgent($id, 12)
        );
        $data['target'] = $effective + [
            'period_start_date' => $periodDate,
        ];
        $data['summary'] = $this->reports->agentPeriodSummary(
            $id,
            $periodDate,
            PeriodResolver::TYPE_MONTHLY
        );

        return Response::json(['data' => $data]);
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
            [
                'agent_code', 'full_name', 'username', 'password', 'role', 'status',
                'status_reason', 'nin', 'phone', 'email', 'location', 'monthly_target',
                'imei', 'device_type',
            ],
            'admin.agent.create'
        );

        $agentCode = $this->agentCode($body['agent_code'] ?? null);
        $fullName  = Validator::string($body['full_name'] ?? null, 1, 191, 'full_name');
        $username  = Validator::username($body['username'] ?? null);
        $password  = Validator::password($body['password'] ?? null);
        $role      = $this->role($body['role'] ?? 'AGENT');
        $status    = $this->statusTarget($body['status'] ?? 'ACTIVE');

        if ($status === 'DELETED') {
            throw ApiException::validation(
                'A new account cannot be created retired.',
                ['field' => 'status']
            );
        }

        $statusReason = $body['status_reason'] ?? null;
        if ($statusReason !== null && $statusReason !== '') {
            $statusReason = Validator::string($statusReason, 1, 500, 'status_reason');
        } else {
            $statusReason = null;
        }

        $nin = $body['nin'] ?? null;
        $nin = ($nin === null || $nin === '') ? null : Validator::nin($nin);

        $phone = $body['phone'] ?? null;
        $phone = ($phone === null || $phone === '') ? null : Validator::nigerianPhone($phone);

        $email = Validator::email($body['email'] ?? null, 'email');
        $location = Validator::location($body['location'] ?? null, 'location');
        $monthlyTarget = Validator::intRange(
            $body['monthly_target'] ?? 0,
            0,
            1000000,
            'monthly_target'
        );

        $imei = $body['imei'] ?? null;
        $imei = ($imei === null || $imei === '') ? null : Validator::imei($imei);

        $deviceType = $body['device_type'] ?? null;
        $deviceType = ($deviceType === null || $deviceType === '')
            ? null
            : Validator::deviceType($deviceType);

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

        if ($nin !== null && $this->agents->findByNin($nin) !== null) {
            throw ApiException::conflict(
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'An account with this NIN already exists.'
            );
        }

        if ($email !== null && $this->agents->findByEmail($email) !== null) {
            throw ApiException::conflict(
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'An account with this email already exists.'
            );
        }

        if ($imei !== null && $this->agents->findByImei($imei) !== null) {
            throw ApiException::conflict(
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'This device IMEI is already bound to an account.'
            );
        }

        try {
            $agentId = Connection::transaction(function () use (
                $agentCode,
                $fullName,
                $nin,
                $phone,
                $email,
                $location,
                $monthlyTarget,
                $role,
                $status,
                $statusReason,
                $imei,
                $deviceType,
                $username,
                $password
            ): int {
                $id = $this->agents->createManaged([
                    'agent_code'      => $agentCode,
                    'full_name'       => $fullName,
                    'nin'             => $nin,
                    'phone'           => $phone,
                    'email'           => $email,
                    'location'        => $location,
                    'monthly_target'  => $monthlyTarget,
                    'role'            => $role,
                    'status'          => $status,
                    'status_reason'   => $statusReason,
                    'imei'            => $imei,
                    'device_type'     => $deviceType,
                ]);

                $this->agents->setCredentials($id, $username, Credentials::hash($password));

                return $id;
            });
        } catch (\PDOException $e) {
            if ($this->isDuplicate($e)) {
                throw ApiException::conflict(
                    ErrorCode::IDEMPOTENCY_CONFLICT,
                    'An account with one of these unique details already exists.'
                );
            }

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
                'status'     => $status,
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
            'SET_STATUS'         => ['id', 'action', 'status', 'reason'],
            'SET_PASSWORD'       => ['id', 'action', 'password'],
            'REVOKE_CREDENTIAL'  => ['id', 'action'],
            'ISSUE_PAIRING_CODE' => ['id', 'action', 'label'],
            'SET_MONTHLY_TARGET' => ['id', 'action', 'monthly_target'],
            'SET_PROFILE'        => ['id', 'action', 'email', 'location', 'phone'],
        ];

        Validator::assertNoUnknownKeys($body, $allowed[$action], 'admin.agent.' . strtolower($action));

        // Changing your own password locks nothing; re-role, status and credential
        // revocation can lock an administrator out of their own account, and are
        // refused on self. Issuing a pairing code to yourself is the same class as
        // SET_PASSWORD: it adds a way in, it cannot remove the one you already hold.
        if (
            $action !== 'SET_PASSWORD'
            && $action !== 'ISSUE_PAIRING_CODE'
            && $action !== 'SET_MONTHLY_TARGET'
            && $action !== 'SET_PROFILE'
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
            'SET_MONTHLY_TARGET' => $this->setMonthlyTarget($request, $context, $target, $body),
            'SET_PROFILE'        => $this->setProfile($request, $context, $target, $body),
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

        $reason = $body['reason'] ?? null;
        $reason = ($reason === null || $reason === '')
            ? null
            : Validator::string($reason, 1, 500, 'reason');

        $action = match ($status) {
            'PENDING'  => 'admin.agent.approval_reset',
            'REJECTED' => 'admin.agent.registration_rejected',
            'ACTIVE'   => (string) $target['status'] === AgentRepository::PENDING
                ? 'admin.agent.registration_approved'
                : 'admin.agent.reinstated',
            'SUSPENDED' => 'admin.agent.suspended',
            'DELETED'   => 'admin.agent.retired',
            default     => 'admin.agent.status_changed',
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

        $this->agents->setStatus((int) $target['id'], $status, $reason);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => $action,
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code'      => (string) $target['agent_code'],
                'status'          => $status,
                'reason'          => $reason,
                'devices_revoked' => $status !== 'ACTIVE',
            ],
        ]);

        Logger::info('admin.agent_status_changed', [
            'agent_id' => (int) $target['id'],
            'admin'    => $context->agentCode(),
            'status'   => $status,
        ]);

        $this->notifyStatusChange($target, $status, $reason);

        return null;
    }

    /**
     * Give the agent a durable, in-app record of an account decision.
     *
     * Approval and rejection are the two moments where the agent has no way to
     * observe the outcome from their own screen, so those are the ones that
     * must survive a page reload. Suspension/reinstatement are recorded for the
     * same reason: an agent whose device stops working needs to be able to read
     * why once they can sign in again.
     *
     * @param array<string,mixed> $target
     */
    private function notifyStatusChange(array $target, string $status, ?string $reason = null): void
    {
        $previous = (string) $target['status'];

        [$type, $title, $message] = match ($status) {
            'ACTIVE' => $previous === AgentRepository::PENDING
                ? ['REGISTRATION_APPROVED', 'Registration approved', 'Your agent registration was approved. You can now sign in and submit records.']
                : ['ACCOUNT_REINSTATED', 'Account reinstated', 'Your account is active again. You can sign in and submit records.'],
            'REJECTED' => ['REGISTRATION_REJECTED', 'Registration rejected', 'Your agent registration was not approved. Contact an administrator if you believe this is wrong.'],
            'SUSPENDED' => ['ACCOUNT_SUSPENDED', 'Account suspended', 'Your account has been suspended. Contact an administrator for assistance.'],
            'PENDING' => ['REGISTRATION_REOPENED', 'Registration reopened', 'Your registration is awaiting review again.'],
            'DELETED' => ['ACCOUNT_RETIRED', 'Account retired', 'Your account has been retired and can no longer be used.'],
            default => ['ACCOUNT_UPDATED', 'Account updated', 'Your account status changed to ' . $status . '.'],
        };

        if ($reason !== null && $reason !== '') {
            $message .= ' Reason: ' . $reason;
        }

        try {
            $this->notifications->create(
                (int) $target['id'],
                $type,
                $title,
                $message,
                'agent',
                (int) $target['id']
            );
        } catch (\Throwable $e) {
            // The status change is already committed; a failed notification
            // must not make the admin retry and get a confusing conflict.
            Logger::error('admin.notification_failed', [
                'agent_id' => (int) $target['id'],
                'type'     => $type,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function setMonthlyTarget(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        $targetValue = Validator::intRange($body['monthly_target'] ?? null, 0, 1000000, 'monthly_target');

        if ($targetValue === (int) ($target['monthly_target'] ?? 0)) {
            return null;
        }

        $this->agents->setMonthlyTarget((int) $target['id'], $targetValue);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.monthly_target_changed',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code'    => (string) $target['agent_code'],
                'monthly_target' => $targetValue,
            ],
        ]);

        Logger::info('admin.agent_monthly_target_changed', [
            'agent_id'      => (int) $target['id'],
            'admin'         => $context->agentCode(),
            'monthly_target' => $targetValue,
        ]);

        return null;
    }

    private function setProfile(Request $request, AuthContext $context, array $target, array $body): ?array
    {
        $email = array_key_exists('email', $body)
            ? Validator::email($body['email'], 'email')
            : ($target['email'] ?? null);
        $location = array_key_exists('location', $body)
            ? Validator::location($body['location'], 'location')
            : ($target['location'] ?? null);

        $phone = $target['phone'] ?? null;
        if (array_key_exists('phone', $body)) {
            $rawPhone = $body['phone'];
            $phone = ($rawPhone === null || $rawPhone === '')
                ? null
                : Validator::nigerianPhone($rawPhone);
        }

        if ($email !== null) {
            $owner = $this->agents->findByEmail($email);

            if ($owner !== null && (int) $owner['id'] !== (int) $target['id']) {
                throw ApiException::conflict(
                    ErrorCode::IDEMPOTENCY_CONFLICT,
                    'This email is already in use by another account.'
                );
            }
        }

        $this->agents->updateAdminProfile((int) $target['id'], $email, $location, $phone);

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'admin.agent.profile_changed',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $target['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'agent_code' => (string) $target['agent_code'],
                'email'      => $email,
                'location'   => $location,
            ],
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
            'nin'                 => isset($row['nin']) ? (string) $row['nin'] : null,
            'phone'               => isset($row['phone']) ? (string) $row['phone'] : null,
            'email'               => $row['email'] ?? null,
            'location'            => $row['location'] ?? null,
            'monthly_target'      => (int) ($row['monthly_target'] ?? 0),
            'self_registered'     => (bool) ($row['self_registered'] ?? false),
            'role'                => (string) $row['role'],
            'status'              => (string) $row['status'],
            'status_reason'       => $row['status_reason'] ?? null,
            'status_changed_at'   => $row['status_changed_at'] ?? null,
            'imei'                => $row['imei'] ?? null,
            'device_type'         => $row['device_type'] ?? null,
            'username'            => $row['username'] ?? null,
            'has_credential'      => (bool) ($row['has_credential'] ?? (($row['password_hash'] ?? null) !== null)),
            'active_device_count' => (int) ($row['active_device_count'] ?? $this->agents->countActiveDevices($id)),
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return array{q?:string,status?:string,role?:string,location?:string,self_registered?:bool}
     */
    private function filters(Request $request): array
    {
        $filters = [];

        $q = trim((string) ($request->query('q') ?? ''));
        if ($q !== '') {
            $filters['q'] = Validator::string($q, 1, 191, 'q');
        }

        $status = $request->query('status');
        if ($status !== null && trim($status) !== '') {
            $status = strtoupper(trim($status));

            if (!in_array($status, self::FILTER_STATUSES, true)) {
                throw ApiException::validation(
                    'status filter must be one of ' . implode(', ', self::FILTER_STATUSES) . '.',
                    ['field' => 'status', 'allowed' => self::FILTER_STATUSES]
                );
            }

            $filters['status'] = $status;
        }

        $role = $request->query('role');
        if ($role !== null && trim($role) !== '') {
            $filters['role'] = $this->role($role);
        }

        $location = $request->query('location');
        if ($location !== null && trim($location) !== '') {
            $location = Validator::location($location, 'location');

            if ($location !== null) {
                $filters['location'] = $location;
            }
        }

        $selfRegistered = $request->query('self_registered');
        if ($selfRegistered !== null && $selfRegistered !== '') {
            $normalized = strtolower(trim($selfRegistered));

            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                $filters['self_registered'] = true;
            } elseif (in_array($normalized, ['0', 'false', 'no'], true)) {
                $filters['self_registered'] = false;
            } else {
                throw ApiException::validation(
                    'self_registered filter must be true or false.',
                    ['field' => 'self_registered']
                );
            }
        }

        return $filters;
    }

    private function isDuplicate(\PDOException $e): bool
    {
        $sqlState = (string) ($e->getCode() ?? '');

        return $sqlState === '23000'
            || $sqlState === '23505'
            || str_contains(strtolower($e->getMessage()), 'duplicate');
    }

    private function clamp(?int $value, int $default, int $min, int $max): int
    {
        return $value === null ? $default : max($min, min($max, $value));
    }
}