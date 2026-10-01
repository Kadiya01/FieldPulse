<?php

declare(strict_types=1);

namespace FieldPulse\Verification;

use FieldPulse\Config\Config;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Geo\Geofence;
use FieldPulse\Imaging\ExifExtractor;
use FieldPulse\Imaging\ImageInspector;
use FieldPulse\Imaging\PHash;
use FieldPulse\Storage\StorageState;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;
use FieldPulse\Support\Paths;

/**
 * The verification pipeline (§11).
 *
 * Runs entirely on the server from the stored file. It never trusts a value the
 * client sent, and it is idempotent: a job that is retried after a crash
 * re-derives the same verdict from the same inputs rather than double-counting.
 *
 * Order of operations matters here:
 *   1. Re-read the file from its stored path and re-verify its SHA-256. If the
 *      bytes changed since receipt, nothing else in this method is meaningful,
 *      so the submission is rejected for tampering immediately.
 *   2. Decode, so an undecodable artefact short-circuits the rest.
 *   3. pHash, because the duplicate check needs it and it is the most expensive
 *      step, so it runs only on a file that actually decoded.
 *   4. EXIF, timestamp evidence, geofence, weekly cap, decision.
 *   5. Persist, then move the file, then aggregate.
 */
final class VerificationService
{
    public function __construct(
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    /**
     * @return array{disposition:string,reason:string,submission_status:string,submission_id:int}
     */
    public function verify(int $submissionId): array
    {
        $c = Config::instance();

        $submission = $this->submissions->findById($submissionId);

        if ($submission === null) {
            throw new \RuntimeException('submission ' . $submissionId . ' not found');
        }

        $agentId    = (int) $submission['agent_id'];
        $filePath   = Paths::absoluteForStoredPath((string) $submission['file_path']);
        $storedHash = (string) $submission['file_sha256'];

        $this->submissions->markProcessing($submissionId);

        // --- 1. Integrity ----------------------------------------------------
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->finalise(
                $submission,
                DecisionMatrix::REJECTED,
                DecisionMatrix::IMAGE_UNREADABLE,
                [['code' => DecisionMatrix::IMAGE_UNREADABLE, 'detail' => 'stored file is missing or unreadable']],
                null,
                null,
                false
            );
        }

        $actualHash = hash_file('sha256', $filePath);

        if ($actualHash === false || !hash_equals($storedHash, $actualHash)) {
            Logger::error('verification.file_hash_mismatch', [
                'submission_id' => $submissionId,
                'expected'      => $storedHash,
                'actual'        => $actualHash,
            ]);

            return $this->finalise(
                $submission,
                DecisionMatrix::REJECTED,
                'FILE_TAMPERED',
                [['code' => 'FILE_TAMPERED', 'detail' => 'file bytes no longer match the digest recorded at receipt']],
                null,
                null,
                false
            );
        }

        // --- 2. Decode -------------------------------------------------------
        // inspectPath() throws an ApiException rather than returning a failure
        // shape, because the same call is used on the upload path where an
        // exception is the natural control flow. Here an exception is an
        // unreadable artefact, not an HTTP condition, so it is caught and
        // downgraded to a rejection.
        try {
            $inspection = ImageInspector::inspectPath($filePath);
        } catch (\FieldPulse\Http\ApiException $e) {
            return $this->finalise(
                $submission,
                DecisionMatrix::REJECTED,
                DecisionMatrix::IMAGE_UNREADABLE,
                [['code' => DecisionMatrix::IMAGE_UNREADABLE, 'detail' => $e->getMessage()]],
                null,
                null,
                false
            );
        }

        // --- 3. pHash --------------------------------------------------------
        $phash = PHash::fromFile($filePath, (string) $submission['file_mime']);

        if ($phash === null) {
            return $this->finalise(
                $submission,
                DecisionMatrix::REJECTED,
                DecisionMatrix::IMAGE_UNREADABLE,
                [['code' => DecisionMatrix::IMAGE_UNREADABLE, 'detail' => 'perceptual hash could not be computed']],
                null,
                null,
                false
            );
        }

        // --- 4. Evidence -----------------------------------------------------
        $exif = ExifExtractor::extract($filePath);

        $period   = PeriodResolver::periodFor((string) $submission['server_received_at']);
        $window   = PeriodResolver::utcRangeForPeriod($period['date']);

        $timestamp = TimestampVerifier::evaluate(
            (string) $submission['server_received_at'],
            $exif['captured_at'] ?? null,
            $submission['client_captured_at'] ?? null
        );

        $geofence = Geofence::evaluate(
            $agentId,
            $submission['client_latitude'] === null ? null : (float) $submission['client_latitude'],
            $submission['client_longitude'] === null ? null : (float) $submission['client_longitude'],
            $exif['latitude'] ?? null,
            $exif['longitude'] ?? null,
            $submission['client_accuracy_m'] === null ? null : (float) $submission['client_accuracy_m']
        );

        $geofence['message'] = self::describeGeofence($geofence);

        $duplicate = DuplicateDetector::evaluate(
            $this->submissions,
            $submission,
            $phash['hex'],
            $phash['bands'],
            $window['start_utc'],
            $window['end_utc']
        );

        $weeklyVerified = $this->submissions->verifiedCountForPeriod(
            $agentId,
            $window['start_utc'],
            $window['end_utc'],
            $submissionId
        );

        $exifStatus = ExifExtractor::statusOf($exif);

        $decision = DecisionMatrix::decide([
            'image_ok'              => true,
            'timestamp'             => $timestamp,
            'duplicate'             => $duplicate,
            'geofence'              => $geofence,
            'exif'                  => ['status' => $exifStatus],
            'weekly_verified_count' => $weeklyVerified,
            'count_claimed'         => (int) $submission['count_claimed'],
        ]);

        // --- 5. Persist and place the file -----------------------------------
        return $this->finalise(
            $submission,
            $decision['disposition'],
            $decision['reason'],
            $decision['reasons'],
            $phash,
            $exif,
            $decision['disposition'] !== DecisionMatrix::REJECTED,
            $geofence,
            $timestamp,
            $duplicate
        );
    }

    /**
     * Write the verdict, record the evidence, move the file, and aggregate.
     *
     * @param  array<string,mixed>        $submission
     * @param  list<array{code:string,detail:string}> $reasons
     * @param  array<string,mixed>|null   $phash
     * @param  array<string,mixed>|null   $exif
     */
    private function finalise(
        array $submission,
        string $disposition,
        string $reason,
        array $reasons,
        ?array $phash,
        ?array $exif,
        bool $aggregate,
        ?array $geofence = null,
        ?array $timestamp = null,
        ?array $duplicate = null
    ): array {
        $submissionId = (int) $submission['id'];
        $agentId      = (int) $submission['agent_id'];
        $deviceId     = (int) $submission['device_id'];
        $status       = DecisionMatrix::toStatus($disposition);

        $verifiedAt = $disposition === DecisionMatrix::VERIFIED ? Clock::sql() : null;

        // Read once, here, and thread it through both writes. It used to be read
        // independently inside recordVerdict() while finalise() passed an
        // undefined $version to markDisposition() — markDisposition() types that
        // argument as a non-nullable string, so every verification ended in a
        // TypeError and the whole pipeline failed on first use. Resolving it once
        // also guarantees the version stamped on the submission and on the
        // verification row are identical even if config were re-read mid-flight.
        $version = Config::instance()->str('verification.version');

        /*
         * The bytes move BEFORE the ledger says the verdict, not after.
         *
         * The ordering is the whole point, and it is the opposite of what this
         * code did originally: it committed the terminal status first and then
         * moved the file, treating a failed move as a warning. That ordering
         * makes a real, silent failure mode.
         *
         * If the disk is read-only, full, or the rename fails, the original
         * order left the ledger claiming VERIFIED while the bytes sat in
         * quarantine forever. Nothing would ever retry — the job had already
         * completed successfully — so the agent is paid for evidence that is
         * filed in the wrong place and cannot be found by anyone reviewing the
         * submission. The row asserted a verdict that the storage could not
         * support.
         *
         * Moving first inverts the risk. If the move fails, this throws, the
         * disposition transaction never runs, and the job stays retryable —
         * so the terminal status can only ever be written for evidence that is
         * already in its final folder. The worst case is a verified submission
         * sitting in quarantine that gets picked up again, which is recoverable
         * work rather than a false claim.
         *
         * There is no transaction to enlist the rename in: StorageState defines
         * the crash windows, and each one leaves a state reconcile() can detect
         * and resolve. This ordering is what makes the retry safe, because a
         * retry re-reads file_path — which markStorageMoved() has already
         * rewritten to the final location — and finds the bytes.
         */
        $this->archiveFile($submission, $status);

        Connection::transaction(function () use (
            $submissionId,
            $agentId,
            $deviceId,
            $status,
            $disposition,
            $reason,
            $reasons,
            $verifiedAt,
            $version,
            $phash,
            $exif,
            $geofence,
            $timestamp,
            $duplicate
        ): void {

            $this->submissions->markDisposition(
                $submissionId,
                $status,
                $verifiedAt,
                $version,
                $phash['hex'] ?? null,
                $phash['bands'] ?? null,
                $exif['latitude'] ?? null,
                $exif['longitude'] ?? null,
                $exif['captured_at'] ?? null
            );

            $this->recordVerdict($submissionId, $disposition, $reason, $version, [
                'exif'      => $exif,
                'timestamp' => $timestamp,
                'geofence'  => $geofence,
                'duplicate' => $duplicate,
                'phash'     => $phash,
                'device'    => $this->deviceStatus($deviceId),
                'reasons'   => $reasons,
            ]);
        });

        if ($aggregate) {
            $this->aggregate($agentId, $submissionId);
        }

        $this->audit->recordSafe([
            'action'         => 'submission.disposition',
            'actor_agent_id' => null,
            'entity_type'    => 'submission',
            'entity_id'      => $submissionId,
            'ip_address'     => null,
            'metadata'       => [
                'disposition' => $disposition,
                'reason'      => $reason,
                'device_id'   => $deviceId,
                'reasons'     => $reasons,
            ],
        ]);

        Logger::info('verification.completed', [
            'submission_id' => $submissionId,
            'agent_id'      => $agentId,
            'disposition'   => $disposition,
            'reason'        => $reason,
        ]);

        return [
            'disposition'      => $disposition,
            'reason'           => $reason,
            'submission_status' => $status,
            'submission_id'    => $submissionId,
        ];
    }

    /**
     * Write the evidence record for a verdict.
     *
     * Upsert rather than insert: a job retried after a crash must not fail on a
     * duplicate, and must not leave two conflicting verdicts. Re-processing
     * overwrites the row, which is correct because the inputs — the file and the
     * database — are the same, so the re-derived verdict is the same verdict.
     *
     * The eight fixed *_status columns are short, stable strings the PWA can
     * switch on. Everything variable (every reason, every distance, the full
     * exif payload) goes into evidence_json, so a future reason code can be
     * added without a table rewrite on a host that cannot afford the metadata
     * lock.
     *
     * The reason list travels in $evidence['reasons'] rather than as its own
     * parameter, because the list is part of the evidence and used to be passed
     * twice under two different names — written as 'findings' here, read back as
     * 'reasons' by the status endpoint. Nothing reported a mismatch, so agents
     * were simply told their submission had no reasons. The key is now 'reasons',
     * matching docs/API.md, and there is one source for it.
     *
     * @param array<string,mixed> $evidence
     */
    private function recordVerdict(
        int $submissionId,
        string $disposition,
        string $reason,
        string $version,
        array $evidence
    ): void {
        $timestampStatus = (string) ($evidence['timestamp']['status'] ?? 'NOT_EVALUATED');
        $geofenceStatus  = (string) ($evidence['geofence']['status'] ?? 'NOT_EVALUATED');
        $duplicateStatus = (string) ($evidence['duplicate']['status'] ?? DuplicateDetector::NONE);
        $exifStatus      = ExifExtractor::statusOf((array) ($evidence['exif'] ?? []));

        $this->exec(
            'INSERT INTO submission_verifications (
                submission_id, verification_version,
                auth_status, device_status, client_gps_status, exif_status,
                timestamp_status, geofence_status,
                exact_duplicate_status, perceptual_duplicate_status,
                final_disposition, review_reason, evidence_json,
                created_at, updated_at
             ) VALUES (
                :submission_id, :version,
                :auth_status, :device_status, :client_gps_status, :exif_status,
                :timestamp_status, :geofence_status,
                :exact_duplicate_status, :perceptual_duplicate_status,
                :final_disposition, :review_reason, :evidence,
                UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )
             ON DUPLICATE KEY UPDATE
                verification_version       = VALUES(verification_version),
                auth_status                = VALUES(auth_status),
                device_status              = VALUES(device_status),
                client_gps_status          = VALUES(client_gps_status),
                exif_status                = VALUES(exif_status),
                timestamp_status           = VALUES(timestamp_status),
                geofence_status            = VALUES(geofence_status),
                exact_duplicate_status     = VALUES(exact_duplicate_status),
                perceptual_duplicate_status = VALUES(perceptual_duplicate_status),
                final_disposition          = VALUES(final_disposition),
                review_reason              = VALUES(review_reason),
                evidence_json              = VALUES(evidence_json),
                updated_at                 = UTC_TIMESTAMP()',
            [
                'submission_id'  => $submissionId,
                'version'        => $version,
                // A submission row only exists because the create request was
                // device-signed and authenticated, which the Kernel enforced
                // before the controller ran. There is no unsigned path here.
                'auth_status'    => 'VERIFIED',
                'device_status'  => (string) ($evidence['device']['status'] ?? 'UNKNOWN'),
                'client_gps_status' => $this->clientGpsStatus($evidence),
                'exif_status'    => $exifStatus,
                'timestamp_status' => $timestampStatus,
                'geofence_status'  => $geofenceStatus,
                'exact_duplicate_status' => $duplicateStatus === DuplicateDetector::EXACT ? 'DUPLICATE' : 'NONE',
                'perceptual_duplicate_status' => $duplicateStatus === DuplicateDetector::EXACT
                    ? 'NOT_EVALUATED'
                    : $duplicateStatus,
                'final_disposition' => $disposition,
                'review_reason'  => mb_substr($reason, 0, 255),
                'evidence'       => json_encode([
                    'reasons'   => array_values((array) ($evidence['reasons'] ?? [])),
                    'exif'      => $evidence['exif'] ?? null,
                    'timestamp' => $evidence['timestamp'] ?? null,
                    'geofence'  => $evidence['geofence'] ?? null,
                    'duplicate' => $evidence['duplicate'] ?? null,
                    'phash'     => $evidence['phash'] ?? null,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function clientGpsStatus(array $evidence): string
    {
        $geofenceSource = (string) ($evidence['geofence']['source'] ?? 'none');

        return match ($geofenceSource) {
            'client' => 'PRESENT',
            'exif'   => 'ABSENT',
            default  => 'ABSENT',
        };
    }

    private function exec(string $sql, array $params = []): int
    {
        return Connection::execute($sql, $params);
    }

    /**
     * The device status at the moment of verification.
     *
     * Recorded because a device revoked between submission and processing is a
     * materially different situation from one that was active throughout, and
     * the evidence row is where that question gets answered later.
     *
     * @return array{status:string,revoked_at:?string}
     */
    private function deviceStatus(int $deviceId): array
    {
        $row = Connection::fetchOne(
            'SELECT status, revoked_at FROM devices WHERE id = :id',
            ['id' => $deviceId]
        );

        return [
            'status'     => (string) ($row['status'] ?? 'UNKNOWN'),
            'revoked_at' => $row['revoked_at'] ?? null,
        ];
    }

    /**
     * Move the file out of quarantine into its disposition folder (§8), or fail.
     *
     * Delegates to StorageState, which owns the crash semantics for the
     * filesystem/ledger pair. This method owns the decision to abort: when the
     * bytes cannot be placed, the caller must not reach the disposition write,
     * because marking a submission VERIFIED while its evidence is still in
     * quarantine produces a ledger row claiming a verdict backed by a file
     * nobody can find.
     *
     * So it throws rather than returning. The exception is not an error in the
     * job-failure sense: QueueWorker::process() catches it, fails the job with
     * backoff, and leaves the submission retryable, which is the correct state
     * for "the disk said no". An earlier version logged a warning and returned
     * normally, so the job completed, the status committed, and the failure was
     * visible only in a log nobody reads.
     *
     * @throws \RuntimeException when the evidence cannot be placed
     */
    private function archiveFile(array $submission, string $status): void
    {
        if (StorageState::archive($this->submissions, $submission, $status)) {
            return;
        }

        Logger::error('verification.archive_deferred', [
            'submission_id' => (int) $submission['id'],
            'status'        => $status,
        ]);

        throw new \RuntimeException(
            'Evidence could not be moved to its disposition folder for submission '
            . (int) $submission['id'] . '; leaving it retryable rather than committing a verdict.'
        );
    }

    /**
     * Recompute the agent's weekly summary and mark the submission aggregated.
     */
    private function aggregate(int $agentId, int $submissionId): void
    {
        $submission = $this->submissions->findById($submissionId);

        if ($submission === null) {
            return;
        }

        $period = PeriodResolver::periodFor((string) $submission['server_received_at']);

        // Delegated rather than re-implemented. This summary write previously
        // existed in two places and they drifted apart — one copy had a column
        // the table does not have, so every verification would have failed on a
        // fresh install. One table, one writer.
        (new LeaderboardRepository())->refresh($agentId, $period['date']);

        $this->submissions->markAggregated($submissionId);
    }

    /**
     * @param array<string,mixed> $geofence
     */
    private static function describeGeofence(array $geofence): string
    {
        $status = (string) $geofence['status'];

        if ($status === Geofence::WITHIN_GEOFENCE) {
            return sprintf(
                'inside %s by %sm (radius %sm)',
                $geofence['site_name'] ?? 'site',
                $geofence['distance_m'] ?? '?',
                $geofence['radius_m'] ?? '?'
            );
        }

        if ($status === Geofence::OUTSIDE_GEOFENCE && $geofence['distance_m'] !== null) {
            return sprintf(
                '%sm from the nearest assigned site (radius %sm)',
                $geofence['distance_m'],
                $geofence['radius_m'] ?? '?'
            );
        }

        return match ($status) {
            Geofence::NO_GPS          => 'no position was supplied with the submission',
            Geofence::INVALID_GPS     => 'supplied coordinates are out of range or not a finite position',
            Geofence::SITE_UNASSIGNED => 'no active site is assigned to this agent',
            Geofence::GPS_UNRELIABLE  => 'supplied position is not plausible for a handset',
            default                   => 'geofence status: ' . $status,
        };
    }
}
