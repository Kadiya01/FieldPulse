<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminRewardProgramRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET/POST /api/v1/abh/admin/reward-rules.php?program_id= */
final class AbhAdminRewardRulesController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('rewards.manage');

        if ($request->method() === 'GET') {
            $programId = Validator::intRange($request->query('program_id'), 1, 2147483647, 'program_id');
            return Response::json(['data' => AbhAdminRewardProgramRepository::listRules($programId)]);
        }
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys(
            $body,
            ['program_id', 'rule_key', 'rank_from', 'rank_to', 'minimum_approved_quantity'],
            'reward rule'
        );
        $programId = Validator::intRange($body['program_id'] ?? null, 1, 2147483647, 'program_id');
        $ruleKey = strtoupper(Validator::string($body['rule_key'] ?? null, 2, 64, 'rule_key'));
        if (preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/', $ruleKey) !== 1) {
            throw ApiException::validation('Rule key may contain only letters, numbers, dots, hyphens and underscores.', ['field' => 'rule_key']);
        }

        $rankFrom = array_key_exists('rank_from', $body) && $body['rank_from'] !== null
            ? Validator::intRange($body['rank_from'], 1, 1000000, 'rank_from') : null;
        $rankTo = array_key_exists('rank_to', $body) && $body['rank_to'] !== null
            ? Validator::intRange($body['rank_to'], 1, 1000000, 'rank_to') : null;
        $minimum = array_key_exists('minimum_approved_quantity', $body) && $body['minimum_approved_quantity'] !== null
            ? Validator::intRange($body['minimum_approved_quantity'], 1, 1000000000, 'minimum_approved_quantity') : null;

        if ($rankFrom === null && $rankTo === null && $minimum === null) {
            throw ApiException::validation('Set at least one rank boundary or a minimum approved quantity.', ['field' => 'rank_from']);
        }
        if ($rankFrom !== null && $rankTo !== null && $rankFrom > $rankTo) {
            throw ApiException::validation('rank_from cannot be greater than rank_to.', ['field' => 'rank_from']);
        }

        $result = AbhAdminRewardProgramRepository::addRule(
            $admin->id(), $programId, $ruleKey, $rankFrom, $rankTo, $minimum
        );
        if (($result['error'] ?? null) === 'program_not_found') {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Reward programme not found.');
        }
        if (($result['error'] ?? null) === 'program_locked') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Rules can only be added to a draft programme in an open reporting period.');
        }
        return Response::json(['data' => $result], 201);
    }
}
