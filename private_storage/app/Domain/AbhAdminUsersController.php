<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminUserRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET/POST /api/v1/abh/admin/users.php */
final class AbhAdminUsersController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('admin.users.manage');

        if ($request->method() === 'GET') {
            return Response::json(['data' => AbhAdminUserRepository::listing()]);
        }
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        $action = strtoupper((string) ($body['action'] ?? ''));
        if ($action === 'CREATE') {
            Validator::assertNoUnknownKeys($body, ['action', 'full_name', 'email', 'password', 'role_key'], 'administrator creation');
            $name = trim(Validator::string($body['full_name'] ?? null, 2, 160, 'full_name'));
            $email = Validator::email($body['email'] ?? null, 'email');
            if ($email === null) throw ApiException::validation('A valid email is required.', ['field' => 'email']);
            $password = Validator::password($body['password'] ?? null);
            $role = strtoupper(Validator::string($body['role_key'] ?? null, 3, 64, 'role_key'));
            try {
                $result = AbhAdminUserRepository::create($admin->id(), $name, $email, $password, $role);
            } catch (\PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'An administrator with this email already exists.');
                }
                throw $error;
            }
            if (($result['error'] ?? null) === 'role_not_found') {
                throw ApiException::validation('Select a valid administrator role.', ['field' => 'role_key']);
            }
            return Response::json(['data' => $result], 201);
        }
        if ($action === 'UPDATE') {
            Validator::assertNoUnknownKeys($body, ['action', 'admin_id', 'status', 'role_key'], 'administrator update');
            $id = Validator::intRange($body['admin_id'] ?? null, 1, 2147483647, 'admin_id');
            $status = strtoupper(Validator::string($body['status'] ?? null, 6, 20, 'status'));
            if (!in_array($status, ['ACTIVE', 'SUSPENDED'], true)) {
                throw ApiException::validation('Status must be ACTIVE or SUSPENDED.', ['field' => 'status']);
            }
            $role = strtoupper(Validator::string($body['role_key'] ?? null, 3, 64, 'role_key'));
            $result = AbhAdminUserRepository::update($admin->id(), $id, $status, $role);
            if (($result['error'] ?? null) === 'admin_not_found') {
                throw new ApiException(404, ErrorCode::NOT_FOUND, 'Administrator not found.');
            }
            if (($result['error'] ?? null) === 'role_not_found') {
                throw ApiException::validation('Select a valid administrator role.', ['field' => 'role_key']);
            }
            if (($result['error'] ?? null) === 'last_super_admin') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The last active Super Administrator cannot be suspended or reassigned.');
            }
            if (($result['error'] ?? null) === 'self_lockout') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'You cannot remove your own Super Administrator access.');
            }
            return Response::json(['data' => $result]);
        }
        throw ApiException::validation('Action must be CREATE or UPDATE.', ['field' => 'action']);
    }
}
