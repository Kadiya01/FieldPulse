<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Config\Config;

/**
 * submissions access.
 *
 * All status transitions funnel through markStatus() so that aggregation_applied_at
 * and verified_at can never be left inconsistent with status by a hand-written
 * UPDATE somewhere in a controller.
 */
final class SubmissionRepository extends Repository
{
    public const RECEIVED         = 'RECEIVED';
    public const QUEUED           = 'QUEUED';
    public const PROCESSING       = 'PROCESSING';
    public const VERIFIED         = 'VERIFIED';
    public const REQUIRES_REVIEW  = 'REQUIRES_REVIEW';
    public const REJECTED         = 'REJECTED';

    public const TERMINAL = [
        self::VERIFIED,
        self::REQUIRES_REVIEW,
        self::REJECTED,
    ];

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->one('SELECT * FROM submissions WHERE submission_uuid = :u', ['u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->one('SELECT * FROM submissions WHERE id = :id', ['id' => $id]);
    }

    /**
     * The verification row for a submission, or null if it has not been
     * processed yet.
     *
     * Separate from findById because the disposition, the reason codes, and the
     * review provenance live in submission_verifications, not on the submission
     * itself. Joining is the caller's business — a status read wants a couple of
     * columns, a review-queue read wants everything, and neither should pay for
     * the other's columns or risk loading a large evidence_json blob.
     */
    public function findVerification(int $submissionId): ?array
    {
        return $this->one(
            'SELECT id, submission_id, final_disposition, review_reason, evidence_json,
                    verification_version, reviewed_by_agent_id, reviewed_at, review_note,
                    created_at, updated_at
               FROM submission_verifications
              WHERE submission_id = :id',
            ['id' => $submissionId]
        );
    }

    /**
     * Insert the ledger row. The submission_uuid UNIQUE index is the authority
     * for idempotency; this method may legitimately throw a duplicate-key error
     * and the caller is expected to handle it as a retry, not a failure.
     */
    public function insert(
        string $submissionUuid,
        int $agentId,
        int $deviceId,
        int $countClaimed,
        ?float $clientLat,
        ?float $clientLng,
        ?string $clientCapturedAt,
        string $filePath,
        string $fileSha256,
        string $fileMime,
        int $fileSize,
        int $imageWidth,
        int $imageHeight
    ): int {
        $this->exec(
            'INSERT INTO submissions (
                submission_uuid, agent_id, device_id, count_claimed,
                client_latitude, client_longitude, client_captured_at,
                file_path, file_sha256, file_mime, file_size, image_width, image_height,
                server_received_at, status, created_at, updated_at
             ) VALUES (
                :uuid, :agent_id, :device_id, :count_claimed,
                :lat, :lng, :captured_at,
                :file_path, :sha256, :mime, :size, :width, :height,
                UTC_TIMESTAMP(), :status, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             )',
            [
                'uuid'         => $submissionUuid,
                'agent_id'     => $agentId,
                'device_id'    => $deviceId,
                'count_claimed' => $countClaimed,
                'lat'          => $clientLat,
                'lng'          => $clientLng,
                'captured_at'  => $clientCapturedAt,
                'file_path'    => $filePath,
                'sha256'       => $fileSha256,
                'mime'         => $fileMime,
                'size'         => $fileSize,
                'width'        => $imageWidth,
                'height'       => $imageHeight,
                'status'       => self::QUEUED,
            ]
        );

        return (int) Connection::lastInsertId();
    }

    public function markProcessing(int $id): void
    {
        $this->exec(
            'UPDATE submissions SET status = :status, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN (:queued, :processing)',
            ['status' => self::PROCESSING, 'id' => $id, 'queued' => self::QUEUED, 'processing' => self::PROCESSING]
        );
    }

    /**
     * Record the final disposition of a processed submission.
     *
     * aggregation_applied_at is intentionally left NULL here: the worker sets it
     * only once the summary has actually been recomputed, so a crash between the
     * two leaves the row visibly un-aggregated instead of silently undercounted.
     */
    public function markDisposition(
        int $id,
        string $status,
        ?string $verifiedAt,
        string $verificationVersion,
        ?string $phashHex = null,
        ?array $bands = null,
        ?float $exifLat = null,
        ?float $exifLng = null,
        ?string $exifCapturedAt = null
    ): void {
        $params = [
            'status'  => $status,
            'version' => $verificationVersion,
            'id'      => $id,
        ];

        $sets = [
            'status = :status',
            'verification_version = :version',
            'updated_at = UTC_TIMESTAMP()',
        ];

        if ($verifiedAt !== null) {
            $sets[] = 'verified_at = :verified_at';
            $params['verified_at'] = $verifiedAt;
        }

        if ($phashHex !== null) {
            $sets[] = 'phash_hex = :phash';
            $params['phash'] = $phashHex;
        }

        if ($bands !== null && count($bands) >= 4) {
            $sets[] = 'phash_band_1 = :b1';
            $sets[] = 'phash_band_2 = :b2';
            $sets[] = 'phash_band_3 = :b3';
            $sets[] = 'phash_band_4 = :b4';
            $params['b1'] = (int) $bands[0];
            $params['b2'] = (int) $bands[1];
            $params['b3'] = (int) $bands[2];
            $params['b4'] = (int) $bands[3];
        }

        if ($exifLat !== null) {
            $sets[] = 'server_exif_latitude = :exif_lat';
            $params['exif_lat'] = $exifLat;
        }

        if ($exifLng !== null) {
            $sets[] = 'server_exif_longitude = :exif_lng';
            $params['exif_lng'] = $exifLng;
        }

        if ($exifCapturedAt !== null) {
            $sets[] = 'server_exif_captured_at = :exif_at';
            $params['exif_at'] = $exifCapturedAt;
        }

        $this->exec('UPDATE submissions SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    public function markAggregated(int $id): void
    {
        $this->exec(
            'UPDATE submissions SET aggregation_applied_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND aggregation_applied_at IS NULL',
            ['id' => $id]
        );
    }

    /**
     * Exact-duplicate lookup, scoped per §11.
     *
     * Default scope is the same agent within the same business week: two
     * different agents photographing the same shop front is not fraud, while
     * the same agent re-submitting one photo is. The scope is a parameter so
     * this is a policy decision rather than a code change.
     *
     * @return array<string,mixed>|null
     */
    /**
     * Exact duplicate lookup by file digest.
     *
     * Deliberately NOT scoped to one agent, and there is no agent parameter to
     * tempt a future caller into believing otherwise. A byte-identical file
     * arriving from two different agents is the double-submission case, which is
     * exactly what this needs to catch, so narrowing the search to the
     * submitting agent would let it through. The caller distinguishes
     * same-agent from cross-agent afterwards by comparing the returned
     * agent_id — see Verification\DuplicateDetector.
     *
     * The period window is applied when supplied so an old identical file does
     * not permanently block a legitimate new submission, but note the current
     * caller passes the full range, so an exact match is assessed within the
     * period being verified.
     */
    public function findExactDuplicate(
        string $fileSha256,
        ?string $periodStartUtc = null,
        ?string $periodEndUtc = null,
        ?int $excludeId = null
    ): ?array {
        $sql = 'SELECT id, agent_id, submission_uuid, status, server_received_at
                  FROM submissions
                 WHERE file_sha256 = :sha AND id <> :exclude';

        $params = [
            'sha'     => $fileSha256,
            'exclude' => $excludeId ?? 0,
        ];

        if ($periodStartUtc !== null && $periodEndUtc !== null) {
            $sql .= ' AND server_received_at >= :start AND server_received_at < :end';
            $params['start'] = $periodStartUtc;
            $params['end']   = $periodEndUtc;
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        return $this->one($sql, $params);
    }

    /**
     * Perceptual-duplicate candidate retrieval via the band indexes (§11).
     *
     * A candidate must match at least one of the four 16-bit bands. With
     * threshold 8, a candidate up to 15 bits away can share no band at all and
     * is therefore invisible to this query — an inherent property of band
     * indexing, not a defect in the implementation. The candidate limit bounds
     * the PHP-side Hamming work regardless of table size.
     *
     * @param  list<int> $bands
     * @return list<array{id:int,phash_hex:string,agent_id:int}>
     */
    public function findPerceptualCandidates(
        array $bands,
        int $excludeId,
        int $limit,
        ?int $scopeAgentId = null
    ): array {
        $where = [
            's.id <> :exclude',
            's.phash_hex IS NOT NULL',
            '(s.phash_band_1 = :b1 OR s.phash_band_2 = :b2 OR s.phash_band_3 = :b3 OR s.phash_band_4 = :b4)',
        ];

        $params = [
            'exclude' => $excludeId,
            'b1'      => (int) ($bands[0] ?? 0),
            'b2'      => (int) ($bands[1] ?? 0),
            'b3'      => (int) ($bands[2] ?? 0),
            'b4'      => (int) ($bands[3] ?? 0),
        ];

        if ($scopeAgentId !== null) {
            $where[] = 's.agent_id = :agent_id';
            $params['agent_id'] = $scopeAgentId;
        }

        $limitClause = self::limitClause($limit, Config::instance()->int('phash.candidate_limit'));

        return $this->all(
            'SELECT s.id, s.phash_hex, s.agent_id
               FROM submissions s
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY s.id DESC' . $limitClause,
            $params
        );
    }

    /**
     * Running total of VERIFIED claimed counts for an agent inside a period.
     * Used for the weekly cap check and by the summary recompute.
     */
    public function verifiedCountForPeriod(int $agentId, string $startUtc, string $endUtc, ?int $excludeId = null): int
    {
        $value = $this->value(
            'SELECT COALESCE(SUM(count_claimed), 0)
               FROM submissions
              WHERE agent_id = :agent_id
                AND status = :status
                AND server_received_at >= :start
                AND server_received_at < :end
                AND id <> :exclude',
            [
                'agent_id' => $agentId,
                'status'   => self::VERIFIED,
                'start'    => $startUtc,
                'end'      => $endUtc,
                'exclude'  => $excludeId ?? 0,
            ]
        );

        return (int) $value;
    }

    /**
     * Full recompute of the summary counters for one agent/period.
     *
     * A recompute rather than an increment is what makes re-processing a job, or
     * replaying a day of cron, incapable of double-counting.
     *
     * @return array{total_verified_count:int,total_submissions:int,total_rejected:int,total_pending:int}
     */
    public function summarisePeriod(int $agentId, string $startUtc, string $endUtc): array
    {
        $row = $this->one(
            'SELECT
                COALESCE(SUM(CASE WHEN status = :verified THEN count_claimed ELSE 0 END), 0) AS verified_count,
                COUNT(*) AS total_submissions,
                COALESCE(SUM(CASE WHEN status = :rejected THEN 1 ELSE 0 END), 0) AS total_rejected,
                COALESCE(SUM(CASE WHEN status IN (:queued, :processing, :received) THEN 1 ELSE 0 END), 0) AS total_pending
             FROM submissions
             WHERE agent_id = :agent_id
               AND server_received_at >= :start
               AND server_received_at < :end',
            [
                'verified'   => self::VERIFIED,
                'rejected'   => self::REJECTED,
                'queued'     => self::QUEUED,
                'processing' => self::PROCESSING,
                'received'   => self::RECEIVED,
                'agent_id'   => $agentId,
                'start'      => $startUtc,
                'end'        => $endUtc,
            ]
        );

        return [
            'total_verified_count' => (int) ($row['verified_count'] ?? 0),
            'total_submissions'    => (int) ($row['total_submissions'] ?? 0),
            'total_rejected'       => (int) ($row['total_rejected'] ?? 0),
            'total_pending'        => (int) ($row['total_pending'] ?? 0),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForReview(int $limit, int $offset = 0): array
    {
        return $this->all(
            'SELECT s.*, v.review_reason, v.final_disposition, a.agent_code, a.full_name
               FROM submissions s
               LEFT JOIN submission_verifications v ON v.submission_id = s.id
               LEFT JOIN agents a ON a.id = s.agent_id
              WHERE s.status = :status
              ORDER BY s.created_at ASC' . self::limitClause($limit),
            ['status' => self::REQUIRES_REVIEW]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForAgent(int $agentId, int $limit = 50, int $offset = 0): array
    {
        return $this->all(
            'SELECT id, submission_uuid, count_claimed, status, server_received_at,
                    verified_at, file_path, image_width, image_height, file_size
               FROM submissions
              WHERE agent_id = :a
              ORDER BY id DESC' . self::limitClause($limit),
            ['a' => $agentId]
        );
    }
}
