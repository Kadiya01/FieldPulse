<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Config\Config;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\JobRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Imaging\ImageInspector;
use FieldPulse\Security\AuthContext;
use FieldPulse\Storage\StorageState;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;
use FieldPulse\Support\Paths;
use FieldPulse\Support\Uuid;

/**
 * POST /api/v1/submit — file receipt (§10).
 *
 * This endpoint does the minimum possible. It authenticates, validates, stores
 * the file in quarantine, records the ledger row, and enqueues verification. It
 * makes no attempt to decide whether the submission is good: pHash, EXIF,
 * geofence, and duplicate checks all run in the queue worker, because a request
 * that does a full verification pass is a request that can time out on shared
 * hosting, and a client that gets no response will retry a submission that was
 * in fact accepted.
 *
 * The response is 202 Accepted, not 201 Created, because the resource exists but
 * its state is undetermined. The client is expected to poll or to be pushed to.
 *
 * Idempotency is the delicate part. A mobile client on a flaky connection will
 * retry, and three different outcomes are all correct depending on who owns the
 * submission_uuid:
 *
 *   unknown                    -> 202, this is a new submission
 *   already this agent's       -> 200 ALREADY_RECEIVED, return the original
 *   another agent's            -> 409 IDEMPOTENCY_CONFLICT, refuse
 *
 * The third case is a UUID collision or a replay attempt, and it must never
 * return the other agent's submission details, which is why the lookup that
 * detects it returns nothing but the conflict itself.
 *
 * Ownership is never taken from the request
 * -----------------------------------------
 * There is no agent_id, no IMEI, and no device_uuid in the payload, and adding
 * any of them would be a vulnerability rather than a convenience: a client that
 * can name its own owner can submit against another agent's ledger, and
 * idempotency keys would stop meaning anything. $context->agentId() is resolved
 * by Http\Kernel from the bearer token and the device record, so the bytes on
 * the wire have no say in whose submission this becomes. ALLOWED_PAYLOAD_KEYS
 * enforces that as a hard allowlist, so an agent_id smuggled in alongside a
 * valid payload is rejected rather than ignored.
 */
final class SubmitController implements ActionInterface
{
    /**
     * Reported in the 200 body only. SubmissionRepository::QUEUED and friends
     * are the row's lifecycle; this is the fact that the request was a retry.
     */
    public const STATUS_ALREADY_RECEIVED = 'ALREADY_RECEIVED';

    /**
     * Fields a client may send. Deliberately an allowlist and not a denylist:
     * a field that is not on this list is rejected with 422, which is the only
     * way an ownership claim from the client can be guaranteed to fail loudly
     * rather than be quietly dropped.
     */
    private const ALLOWED_PAYLOAD_KEYS = [
        'submission_uuid',
        'count_claimed',
        'captured_at',
        'latitude',
        'longitude',
        'accuracy_m',
        'file_sha256',
        'notes',
    ];

    public function __construct(
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly JobRepository $jobs = new JobRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Identity was resolved by Http\Kernel, which verified the bearer token
        // and the device PoP signature before this method was reached. Nothing
        // here re-derives it, so there is exactly one place where authentication
        // can be wrong.
        $context = $request->requireAuth();

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        if (!$request->isMultipart()) {
            throw new ApiException(
                415,
                ErrorCode::UNSUPPORTED_MEDIA,
                'Submissions must be sent as multipart/form-data.'
            );
        }

        $payload = $this->readPayload($request);
        $c       = Config::instance();

        $submissionUuid = Validator::uuid($payload['submission_uuid'] ?? null, 'submission_uuid');
        $countClaimed   = Validator::intRange(
            $payload['count_claimed'] ?? null,
            $c->int('limits.count_claimed_min'),
            $c->int('limits.count_claimed_max'),
            'count_claimed'
        );

        $capturedAt = Validator::capturedAt($payload['captured_at'] ?? null);
        $notes      = isset($payload['notes']) && $payload['notes'] !== ''
            ? Validator::string($payload['notes'], 0, 500, 'notes')
            : null;

        $coordinates = $this->readCoordinates($payload);

        // accuracy_m is validated here rather than in readCoordinates() because
        // it is independently optional: a capture with no position at all sends
        // none of the three, and a capture with a position but no reported
        // accuracy sends two. Tying it to the coordinate pair would reject a
        // perfectly good fix from a handset that simply omitted the field.
        $accuracyM = Validator::accuracy(
            $payload['accuracy_m'] ?? null,
            (float) $c->float('geofence.gps_accuracy_max_m')
        );

        // --- Idempotency, before any file work ---------------------------
        $existing = $this->submissions->findByUuid($submissionUuid);

        if ($existing !== null) {
            return $this->handleExisting($existing, $context);
        }

        // --- File ---------------------------------------------------------
        $file = $request->file('file');

        if ($file === null) {
            throw new ApiException(
                422,
                ErrorCode::NOT_UPLOADED_FILE,
                'A file part named "file" is required.'
            );
        }

        $inspection = ImageInspector::inspectUploaded($file);

        // file_sha256 is mandatory, not optional. The client signs the payload,
        // and the payload is what binds a signature to a file. A request that
        // simply omits the field would otherwise pass a check that is skipped
        // rather than failed, and could substitute the file after signing. The
        // file bytes that land in quarantine are the bytes the device signed.
        $assertedHash = Validator::sha256Hex($payload['file_sha256'] ?? null, 'file_sha256');

        $actualHash = ImageInspector::sha256($inspection['tmp_path']);

        // A mismatch means the bytes on the wire are not the bytes that were
        // signed. This is the control that makes the multipart signature
        // meaningful at all.
        if (!hash_equals($assertedHash, $actualHash)) {
            Logger::warning('submit.file_hash_mismatch', [
                'agent_id'         => $context->agentId(),
                'submission_uuid'  => $submissionUuid,
                'asserted'         => $assertedHash,
                'actual'           => $actualHash,
            ]);

            throw new ApiException(
                422,
                ErrorCode::FILE_HASH_MISMATCH,
                'Uploaded file does not match the signed file_sha256.'
            );
        }

        // --- Persist ------------------------------------------------------
        $storedPath = $this->storeInQuarantine($inspection);

        $jobId = 0;

        try {
            // The ledger row, the verification job, and the client notes all have
            // to land together. Without the transaction a failure to enqueue
            // leaves a row in QUEUED with a stored_path pointing at a file that
            // has just been deleted: the worker then fails on a missing file
            // instead of the submission simply never having been accepted.
            // Connection::transaction() rolls all of it back, so the only
            // outcomes are "submission and job exist" or "neither exists".
            $submissionId = Connection::transaction(function () use (
                $submissionUuid,
                $context,
                $countClaimed,
                $coordinates,
                $capturedAt,
                $accuracyM,
                $storedPath,
                $actualHash,
                $inspection,
                $notes,
                &$jobId
            ): int {
                $id = $this->submissions->insert(
                    $submissionUuid,
                    $context->agentId(),
                    $context->deviceId(),
                    $countClaimed,
                    $coordinates['latitude'],
                    $coordinates['longitude'],
                    $capturedAt,
                    $accuracyM,
                    $storedPath,
                    $actualHash,
                    $inspection['mime'],
                    $inspection['size'],
                    $inspection['width'],
                    $inspection['height']
                );

                $jobId = $this->jobs->enqueue($id, JobRepository::VERIFICATION);
                if ($notes !== null) {
                    Connection::execute(
                        'UPDATE submissions SET client_notes = :notes WHERE id = :id',
                        ['notes' => $notes, 'id' => $id]
                    );
                }

                return $id;
            });
        } catch (\Throwable $e) {
            // Do not leave an orphan file in quarantine behind a failed insert.
            // Safe to do unconditionally: nothing committed, so no row can
            // reference this path.
            $absolute = Paths::absoluteForStoredPath($storedPath);

            if (is_file($absolute)) {
                @unlink($absolute);
            }

            if ($this->isDuplicate($e)) {
                // Lost a race with a concurrent retry of the same UUID. The
                // winner's row is authoritative, so answer as the retry path.
                $winner = $this->submissions->findByUuid($submissionUuid);

                if ($winner !== null) {
                    return $this->handleExisting($winner, $context);
                }
            }

            throw $e;
        }

        $this->audit->recordSafe([
            'action'         => 'submission.received',
            'actor_agent_id' => $context->agentId(),
            'entity_type'    => 'submission',
            'entity_id'      => $submissionId,
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'submission_uuid' => $submissionUuid,
                'count_claimed'   => $countClaimed,
                'mime'            => $inspection['mime'],
                'size'            => $inspection['size'],
                'job_id'          => $jobId,
            ],
        ]);

        Logger::info('submit.accepted', [
            'submission_id'   => $submissionId,
            'submission_uuid' => $submissionUuid,
            'agent_id'        => $context->agentId(),
            'device_id'       => $context->deviceId(),
            'count_claimed'   => $countClaimed,
        ]);

        return Response::json([
            'data' => [
                'status'          => SubmissionRepository::QUEUED,
                'submission_uuid' => $submissionUuid,
                'submission_id'   => $submissionId,
                // The polling target for the verdict, and the one a client must
                // use. It sits at the top level of `data` rather than inside a
                // `links` object: there is exactly one of it, and a client that
                // has to guess that it is nested one level down is a client that
                // will eventually guess wrong and never learn what happened to the
                // photo the agent just took.
                'self'            => $this->selfLink($submissionUuid),
                'count_claimed'   => $countClaimed,
                'received_at'     => Clock::sql(),
                // The worker runs on a one-minute cron, so this is an honest
                // lower bound rather than a promise.
                'estimated_review_seconds' => 120,
            ],
        ], 202);
    }

    /**
     * The payload is a JSON string inside a multipart part (§10), so that the
     * canonical body used for the device signature is deterministic.
     */
    private function readPayload(Request $request): array
    {
        $raw = $request->form('payload');

        if ($raw === null || trim($raw) === '') {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'A "payload" part containing JSON is required.',
                ['field' => 'payload']
            );
        }

        if (strlen($raw) > 8192) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'The payload part is too large.',
                ['field' => 'payload']
            );
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'The "payload" part is not valid JSON.',
                ['field' => 'payload']
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'The "payload" part must be a JSON object.',
                ['field' => 'payload']
            );
        }

        Validator::assertNoUnknownKeys($decoded, self::ALLOWED_PAYLOAD_KEYS, 'payload');

        return $decoded;
    }

    /**
     * @return array{latitude:?float,longitude:?float}
     */
    private function readCoordinates(array $payload): array
    {
        $hasLat = isset($payload['latitude']) && $payload['latitude'] !== '';
        $hasLng = isset($payload['longitude']) && $payload['longitude'] !== '';

        if (!$hasLat && !$hasLng) {
            return ['latitude' => null, 'longitude' => null];
        }

        if (!$hasLat || !$hasLng) {
            throw new ApiException(
                422,
                ErrorCode::VALIDATION_FAILED,
                'latitude and longitude must be supplied together.',
                ['field' => 'coordinates']
            );
        }

        return Validator::coordinates($payload['latitude'], $payload['longitude']);
    }

    /**
     * Move the validated upload out of PHP's tmp directory into quarantine.
     *
     * move_uploaded_file() first, because only it enforces the is_uploaded_file
     * check that prevents an attacker from moving an arbitrary server file. The
     * destination name is generated, never derived from the client's filename.
     *
     * Deliberately returns only after the bytes are on disk in private storage
     * with restrictive permissions. The caller then commits the ledger row, and
     * those two operations are NOT atomic with respect to each other — see
     * StorageState for the crash windows and which reconciler closes each one.
     * The ordering is what makes them safe: a file that exists is the state a
     * row may reference, never the reverse.
     */
    private function storeInQuarantine(array $inspection): string
    {
        $c         = Config::instance();
        $directory = Paths::quarantineDir();
        $name      = Uuid::v4() . '.' . $inspection['extension'];
        $target    = $directory . '/' . $name;

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ApiException(
                500,
                ErrorCode::INTERNAL_ERROR,
                'Storage is not available.'
            );
        }

        if (!@move_uploaded_file($inspection['tmp_path'], $target)) {
            Logger::error('submit.quarantine_move_failed', ['name' => $name]);

            throw new ApiException(
                500,
                ErrorCode::INTERNAL_ERROR,
                'The upload could not be stored.'
            );
        }

        @chmod($target, $c->int('storage.file_perms', 0640));

        // fsync the file itself before the directory entry. Without this the
        // bytes can be in the page cache and the name on disk, and still be lost
        // to a power cut between this point and the ledger commit — a row
        // pointing at a file full of zeros.
        $handle = @fopen($target, 'r');

        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }

        StorageState::fsyncDir($directory);

        return Paths::DIR_QUARANTINE . '/' . $name;
    }

    /**
     * @param  array<string,mixed> $existing
     */
    private function handleExisting(array $existing, AuthContext $context): Response
    {
        $ownerId = (int) $existing['agent_id'];

        if ($ownerId !== $context->agentId()) {
            // Deliberately reveals nothing about the other agent's submission.
            Logger::warning('submit.idempotency_conflict', [
                'submission_uuid' => $existing['submission_uuid'],
                'owner_agent_id'  => $ownerId,
                'requester_agent_id' => $context->agentId(),
            ]);

            throw new ApiException(
                409,
                ErrorCode::IDEMPOTENCY_CONFLICT,
                'This submission_uuid is already in use.'
            );
        }

        $this->audit->recordSafe([
            'action'         => 'submission.duplicate_request',
            'actor_agent_id' => $context->agentId(),
            'entity_type'    => 'submission',
            'entity_id'      => (int) $existing['id'],
            'metadata'       => ['submission_uuid' => $existing['submission_uuid']],
        ]);

        // 200, not 202: the client asked "did you get it", and the answer is
        // yes, with the current state of the original submission.
        //
        // `status` is the literal ALREADY_RECEIVED and deliberately NOT the
        // row's own status. A retrying client has to be able to branch on
        // "was this accepted" without knowing the six-value submission state
        // machine, and collapsing the two would make a VERIFIED replay
        // indistinguishable from a fresh acceptance at the same status value.
        // The underlying state is available separately as `submission_status`.
        return Response::json([
            'data' => [
                'status'           => self::STATUS_ALREADY_RECEIVED,
                'submission_uuid'  => (string) $existing['submission_uuid'],
                'submission_id'    => (int) $existing['id'],
                'idempotent_replay' => true,
                'submission_status' => (string) $existing['status'],
                'count_claimed'    => (int) $existing['count_claimed'],
                'received_at'      => (string) $existing['server_received_at'],
                'verified_at'      => $existing['verified_at'],
                'self'             => $this->selfLink((string) $existing['submission_uuid']),
            ],
        ], 200);
    }

    /**
     * The canonical polling target for a submission's verdict.
     *
     * One method, so the 202 and 200 bodies cannot drift apart. The bare UUID
     * goes through rawurlencode even though a UUID needs no escaping: this
     * returns a value a client will put straight into a fetch URL, and the
     * encoding is what makes that safe for whatever the identifier turns out to
     * be.
     */
    private function selfLink(string $submissionUuid): string
    {
        return '/api/v1/submission.php?uuid=' . rawurlencode($submissionUuid);
    }

    private function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof \PDOException
            && ((int) ($e->errorInfo[1] ?? 0) === 1062
                || (string) ($e->errorInfo[0] ?? '') === '23000');
    }
}
