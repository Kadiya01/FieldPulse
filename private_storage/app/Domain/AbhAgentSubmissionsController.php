<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Config\Config;
use FieldPulse\Database\AbhSubmissionRepository;
use FieldPulse\Database\AbhSubmissionCorrectionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Imaging\ImageInspector;
use FieldPulse\Security\AuthContext;
use FieldPulse\Storage\StorageState;
use FieldPulse\Support\Paths;
use FieldPulse\Support\Uuid;

/**
 * GET/POST /api/v1/abh/agents/submissions.php
 *
 * An agent may read only their own submission history or create a new report.
 * Every write is device-signed by AbhAuthenticator before this controller runs.
 */
final class AbhAgentSubmissionsController implements ActionInterface
{
    private const ALLOWED_PAYLOAD_KEYS = [
        'idempotency_key',
        'submission_id',
        'reporting_month',
        'activity_date',
        'claimed_quantity',
        'agent_note',
        'evidence_sha256',
    ];

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->method() === 'GET') {
            return $this->list($request, $context);
        }

        if ($request->method() === 'POST') {
            return $this->submit($request, $context);
        }

        throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
    }

    private function list(Request $request, AuthContext $context): Response
    {
        $month = $request->query('month');
        $periodKey = null;

        if ($month !== null && $month !== '') {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                throw ApiException::validation('Month must be in YYYY-MM form.', ['field' => 'month']);
            }
            $periodKey = $month;
        }

        $limit = $request->queryInt('limit', 25) ?? 25;
        $offset = $request->queryInt('offset', 0) ?? 0;
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw ApiException::validation('Invalid pagination values.', ['field' => 'limit']);
        }

        $result = AbhSubmissionRepository::listForAgent($context->agentId(), $periodKey, $limit, $offset);

        $summary = $periodKey === null
            ? null
            : AbhSubmissionRepository::summaryForAgent($context->agentId(), $periodKey);

        return Response::json([
            'data' => $result['items'],
            'meta' => [
                'total' => $result['total'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
                'month' => $periodKey,
                'summary' => $summary,
            ],
        ]);
    }

    private function submit(Request $request, AuthContext $context): Response
    {
        if (!$request->isMultipart()) {
            throw new ApiException(
                415,
                ErrorCode::UNSUPPORTED_MEDIA,
                'Enrollment evidence must be sent as multipart/form-data.'
            );
        }

        $payload = $this->readPayload($request);
        $idempotencyKey = Validator::uuid($payload['idempotency_key'] ?? null, 'idempotency_key');
        $submissionId = array_key_exists('submission_id', $payload)
            ? Validator::intRange($payload['submission_id'], 1, 2147483647, 'submission_id')
            : null;
        $monthStart = Validator::periodMonth($payload['reporting_month'] ?? null, 'reporting_month', false);
        $periodKey = substr($monthStart, 0, 7);

        $activityDate = Validator::string($payload['activity_date'] ?? null, 10, 10, 'activity_date');
        if (!Validator::isIsoDate($activityDate)) {
            throw ApiException::validation('Activity date must be a real YYYY-MM-DD date.', ['field' => 'activity_date']);
        }
        if (substr($activityDate, 0, 7) !== $periodKey) {
            throw ApiException::validation('Activity date must fall within the selected reporting month.', ['field' => 'activity_date']);
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Lagos'));
        if ($activityDate > $today->format('Y-m-d')) {
            throw ApiException::validation('Activity date cannot be in the future.', ['field' => 'activity_date']);
        }

        $config = Config::instance();
        $quantity = Validator::intRange(
            $payload['claimed_quantity'] ?? null,
            $config->int('limits.count_claimed_min'),
            $config->int('limits.count_claimed_max'),
            'claimed_quantity'
        );

        $note = null;
        if (isset($payload['agent_note']) && $payload['agent_note'] !== '') {
            $note = trim(Validator::string($payload['agent_note'], 1, 1000, 'agent_note'));
            if ($note === '') {
                $note = null;
            }
        }

        $expectedHash = Validator::sha256Hex($payload['evidence_sha256'] ?? null, 'evidence_sha256');
        $inspection = ImageInspector::inspectUploaded($request->file('evidence') ?? []);
        $actualHash = ImageInspector::sha256($inspection['tmp_path']);

        if (!hash_equals($expectedHash, $actualHash)) {
            throw new ApiException(
                422,
                ErrorCode::FILE_HASH_MISMATCH,
                'Evidence file does not match the signed evidence hash.'
            );
        }

        // A retry with the same key must be the same logical submission. This
        // avoids duplicate rows and prevents a changed payload being smuggled
        // under a reused idempotency key.
        $existing = $submissionId === null
            ? AbhSubmissionRepository::findByIdempotencyKey($context->agentId(), $idempotencyKey)
            : AbhSubmissionCorrectionRepository::findByIdempotencyKey($context->agentId(), $submissionId, $idempotencyKey);
        if ($existing !== null) {
            return $this->existingResponse($existing, $periodKey, $activityDate, $quantity, $note, $actualHash);
        }

        $originalFilename = $this->safeOriginalFilename((string) ($request->file('evidence')['name'] ?? 'evidence'));
        $storageKey = $this->storeEvidence($inspection, $context->agentId(), $periodKey);

        try {
            $created = $submissionId === null
                ? AbhSubmissionRepository::create(
                    $context->agentId(),
                    $periodKey,
                    $idempotencyKey,
                    $activityDate,
                    $quantity,
                    $note,
                    $storageKey,
                    $originalFilename,
                    $inspection['mime'],
                    $inspection['size'],
                    $actualHash
                )
                : AbhSubmissionCorrectionRepository::create(
                    $context->agentId(),
                    $submissionId,
                    $periodKey,
                    $idempotencyKey,
                    $activityDate,
                    $quantity,
                    $note,
                    $storageKey,
                    $originalFilename,
                    $inspection['mime'],
                    $inspection['size'],
                    $actualHash
                );
        } catch (\Throwable $error) {
            $this->removeEvidence($storageKey);

            if ($this->isDuplicateKey($error)) {
                $winner = $submissionId === null
                    ? AbhSubmissionRepository::findByIdempotencyKey($context->agentId(), $idempotencyKey)
                    : AbhSubmissionCorrectionRepository::findByIdempotencyKey($context->agentId(), $submissionId, $idempotencyKey);
                if ($winner !== null) {
                    return $this->existingResponse($winner, $periodKey, $activityDate, $quantity, $note, $actualHash);
                }
            }

            throw $error;
        }

        if (isset($created['error'])) {
            $this->removeEvidence($storageKey);
            if ($created['error'] === 'idempotency_conflict') {
                throw new ApiException(409, ErrorCode::IDEMPOTENCY_CONFLICT, 'This revision key was already used for different correction data.');
            }
            if ($created['error'] === 'not_found') {
                throw new ApiException(404, ErrorCode::NOT_FOUND, 'Enrollment submission not found.');
            }
            if ($created['error'] === 'state_conflict') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'This submission is not awaiting a correction.');
            }
            $message = $created['error'] === 'date_outside_period' || $created['error'] === 'period_conflict'
                ? 'The corrected activity must remain within the original reporting month.'
                : 'This reporting month is not open for submissions.';
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, $message);
        }

        // When two identical correction requests race, the loser finds the
        // already committed revision inside the transaction and removes its
        // unreferenced upload before returning the idempotent result.
        if ($submissionId !== null && ($created['idempotent_replay'] ?? false)) {
            $this->removeEvidence($storageKey);
        }

        return Response::json([
            'data' => $created + ['idempotent_replay' => false],
        ], 202);
    }

    /** @return array<string,mixed> */
    private function readPayload(Request $request): array
    {
        $raw = $request->form('payload');
        if ($raw === null || trim($raw) === '') {
            throw ApiException::validation('A signed payload part is required.', ['field' => 'payload']);
        }
        if (strlen($raw) > 8192) {
            throw ApiException::validation('The signed payload is too large.', ['field' => 'payload']);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::validation('The signed payload is not valid JSON.', ['field' => 'payload']);
        }

        if (!is_array($payload)) {
            throw ApiException::validation('The signed payload must be a JSON object.', ['field' => 'payload']);
        }

        Validator::assertNoUnknownKeys($payload, self::ALLOWED_PAYLOAD_KEYS, 'ABH enrollment payload');

        return $payload;
    }

    private function storeEvidence(array $inspection, int $agentId, string $periodKey): string
    {
        $relative = sprintf(
            'abh_evidence/pending/agent-%d/%s/%s.%s',
            $agentId,
            str_replace('-', '/', $periodKey),
            Uuid::v4(),
            $inspection['extension']
        );

        try {
            $target = Paths::storagePath($relative);
        } catch (\Throwable $error) {
            throw new ApiException(503, ErrorCode::STORAGE_UNAVAILABLE, 'Private evidence storage is not available.');
        }

        if (!@move_uploaded_file($inspection['tmp_path'], $target)) {
            throw new ApiException(503, ErrorCode::STORAGE_UNAVAILABLE, 'Evidence could not be stored. Please retry.');
        }

        StorageState::hardenFile($target);
        $handle = @fopen($target, 'rb');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
        StorageState::fsyncDir(dirname($target));

        return $relative;
    }

    private function removeEvidence(string $relative): void
    {
        try {
            $absolute = Paths::absoluteForStoredPath($relative);
            if (is_file($absolute)) {
                @unlink($absolute);
                StorageState::fsyncDir(dirname($absolute));
            }
        } catch (\Throwable) {
            // A cleanup failure is recoverable through private-storage review;
            // never replace the original database error with a cleanup error.
        }
    }

    private function safeOriginalFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? 'evidence';
        $safe = trim($safe, " .\t\n\r\0\x0B");
        if ($safe === '') {
            $safe = 'evidence';
        }
        return mb_substr($safe, 0, 255);
    }

    private function existingResponse(
        array $existing,
        string $periodKey,
        string $activityDate,
        int $quantity,
        ?string $note,
        string $sha256
    ): Response {
        $same = (string) $existing['period_key'] === $periodKey
            && (string) $existing['activity_date'] === $activityDate
            && (int) $existing['claimed_quantity'] === $quantity
            && (string) $existing['evidence_sha256'] === $sha256
            && (($existing['agent_note'] ?? null) === $note);

        if (!$same) {
            throw new ApiException(
                409,
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'This idempotency key was already used for a different submission.'
            );
        }

        return Response::json([
            'data' => [
                'id' => (int) $existing['id'],
                'reference_no' => (string) $existing['reference_no'],
                'status' => (string) $existing['status'],
                'period_key' => (string) $existing['period_key'],
                'activity_date' => (string) $existing['activity_date'],
                'claimed_quantity' => (int) $existing['claimed_quantity'],
                'submitted_at' => (string) $existing['submitted_at'],
                'revision_no' => (int) ($existing['revision_no'] ?? 1),
                'idempotent_replay' => true,
            ],
        ]);
    }

    private function isDuplicateKey(\Throwable $error): bool
    {
        return $error instanceof \PDOException
            && (int) ($error->errorInfo[1] ?? 0) === 1062;
    }
}
