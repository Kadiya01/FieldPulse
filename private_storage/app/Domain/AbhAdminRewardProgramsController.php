<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminRewardProgramRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET/POST /api/v1/abh/admin/reward-programs.php */
final class AbhAdminRewardProgramsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();

        if ($request->method() === 'GET') {
            $admin->requirePermission('rewards.manage');
            $month = Validator::periodMonth(
                $request->query('month') ?? (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Lagos')))->format('Y-m'),
                'month',
                true
            );
            return Response::json(['data' => AbhAdminRewardProgramRepository::listForMonth(substr($month, 0, 7))]);
        }

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin->requirePermission('rewards.manage');
        $body = $request->json();
        if (($body['action'] ?? null) === 'UPDATE') {
            Validator::assertNoUnknownKeys($body, ['action', 'program_id', 'program_code', 'name', 'description'], 'reward programme update');
            $programId = Validator::intRange($body['program_id'] ?? null, 1, 2147483647, 'program_id');
            $code = strtoupper(Validator::string($body['program_code'] ?? null, 2, 48, 'program_code'));
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $code) !== 1) {
                throw ApiException::validation('Programme code may contain only letters, numbers, hyphens and underscores.', ['field' => 'program_code']);
            }
            $name = Validator::string($body['name'] ?? null, 2, 160, 'name');
            $description = null;
            if (array_key_exists('description', $body) && $body['description'] !== null && trim((string) $body['description']) !== '') {
                $description = Validator::string($body['description'], 1, 2000, 'description');
            }
            try {
                $result = AbhAdminRewardProgramRepository::updateDraftProgram($admin->id(), $programId, $code, $name, $description);
            } catch (\PDOException $error) {
                if ((string) $error->getCode() === '23000') {
                    throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'A programme with this code already exists for the selected month.');
                }
                throw $error;
            }
            if (($result['error'] ?? null) === 'program_not_found') {
                throw new ApiException(404, ErrorCode::NOT_FOUND, 'Reward programme not found.');
            }
            if (($result['error'] ?? null) === 'program_locked') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Only draft programmes in open reporting periods can be edited.');
            }
            return Response::json(['data' => $result]);
        }
        if (($body['action'] ?? null) === 'ACTIVATE') {
            Validator::assertNoUnknownKeys($body, ['action', 'program_id'], 'reward programme activation');
            $programId = Validator::intRange($body['program_id'] ?? null, 1, 2147483647, 'program_id');
            $result = AbhAdminRewardProgramRepository::activateProgram($admin->id(), $programId);
            if (($result['error'] ?? null) === 'program_not_found') {
                throw new ApiException(404, ErrorCode::NOT_FOUND, 'Reward programme not found.');
            }
            if (($result['error'] ?? null) === 'program_locked') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Only a draft programme in an open reporting period can be activated.');
            }
            if (($result['error'] ?? null) === 'rules_required') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Add at least one active eligibility rule before activating this programme.');
            }
            return Response::json(['data' => $result]);
        }
        Validator::assertNoUnknownKeys($body, ['period_key', 'program_code', 'name', 'description'], 'reward programme');

        $month = substr(Validator::periodMonth($body['period_key'] ?? null, 'period_key', true), 0, 7);
        $code = strtoupper(Validator::string($body['program_code'] ?? null, 2, 48, 'program_code'));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $code) !== 1) {
            throw ApiException::validation('Programme code may contain only letters, numbers, hyphens and underscores.', ['field' => 'program_code']);
        }
        $name = Validator::string($body['name'] ?? null, 2, 160, 'name');
        $description = null;
        if (array_key_exists('description', $body) && $body['description'] !== null && trim((string) $body['description']) !== '') {
            $description = Validator::string($body['description'], 1, 2000, 'description');
        }

        try {
            $result = AbhAdminRewardProgramRepository::createProgram($admin->id(), $month, $code, $name, $description);
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'A programme with this code already exists for the selected month.');
            }
            throw $error;
        }
        if (($result['error'] ?? null) === 'period_not_found') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Create the monthly reporting period and target before configuring its rewards.');
        }
        if (($result['error'] ?? null) === 'period_not_open') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Rewards can only be configured for an open reporting period.');
        }
        return Response::json(['data' => $result], 201);
    }
}
