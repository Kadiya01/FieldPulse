<?php

declare(strict_types=1);

namespace FieldPulse\Verification;

use FieldPulse\Config\Config;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Imaging\Hamming;
use FieldPulse\Imaging\PHash;

/**
 * Duplicate detection (§11).
 *
 * Two independent mechanisms, because they catch different fraud:
 *
 *   Exact match       The file is byte-identical. Unambiguous, and it is the
 *                     right test for a re-uploaded file or a screenshot of an
 *                     earlier upload.
 *   Perceptual match  A re-encoded, cropped, or re-saved copy of the same
 *                     photo. Byte comparison misses every one of these, which
 *                     is why the pHash exists.
 *
 * Band-indexed candidate retrieval means a perceptual match is only found when
 * the two hashes share at least one 16-bit band. With a threshold of 8 that
 * still leaves a blind spot for pairs 9-15 bits apart whose bands happen not to
 * align; those are recorded as "not assessed" rather than claimed as unique.
 */
final class DuplicateDetector
{
    public const NONE           = 'NONE';
    public const EXACT          = 'EXACT';
    public const PERCEPTUAL     = 'PERCEPTUAL';
    public const POSSIBLE       = 'POSSIBLE';
    public const NOT_ASSESSED   = 'NOT_ASSESSED';

    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed> $submission `submissions` row (excludes phash_hex when the
     *                                        current submission is being re-processed)
     * @param  string             $phashHex    freshly computed hash of the stored file
     * @param  list<int>          $bands
     * @return array{
     *   status:string, distance:?int, matched_id:?int, matched_uuid:?string,
     *   same_agent:bool, candidates_considered:int, threshold:int, message:string
     * }
     */
    public static function evaluate(
        SubmissionRepository $submissions,
        array $submission,
        string $phashHex,
        array $bands,
        string $periodStartUtc,
        string $periodEndUtc
    ): array {
        $c = Config::instance();
        $threshold = $c->int('phash.hamming_threshold');
        $possible  = $threshold + $c->int('phash.possible_margin');

        $submissionId  = (int) $submission['id'];
        $agentId       = (int) $submission['agent_id'];
        $fileSha       = (string) $submission['file_sha256'];

        // --- Exact ------------------------------------------------------------
        // No agent is passed: the search is global on purpose, so a
        // byte-identical file from a different agent is caught too. Whether the
        // match was same-agent or cross-agent is read off the returned row.
        $exact = $submissions->findExactDuplicate(
            $fileSha,
            $periodStartUtc,
            $periodEndUtc,
            $submissionId
        );

        if ($exact !== null) {
            return [
                'status'              => self::EXACT,
                'distance'            => 0,
                'matched_id'          => (int) $exact['id'],
                'matched_uuid'        => (string) $exact['submission_uuid'],
                'same_agent'          => (int) $exact['agent_id'] === $agentId,
                'candidates_considered' => 0,
                'threshold'           => $threshold,
                'message'             => 'file is byte-identical to submission ' . $exact['submission_uuid'],
            ];
        }

        // --- Perceptual -------------------------------------------------------
        $candidates = $submissions->findPerceptualCandidates($bands, $submissionId, $c->int('phash.candidate_limit'));

        if ($candidates === []) {
            return [
                'status'              => self::NONE,
                'distance'            => null,
                'matched_id'          => null,
                'matched_uuid'        => null,
                'same_agent'          => false,
                'candidates_considered' => 0,
                'threshold'           => $threshold,
                'message'             => 'no duplicate candidates in the band index',
            ];
        }

        $nearest = Hamming::nearest($phashHex, $candidates);

        if ($nearest === null) {
            return [
                'status'              => self::NOT_ASSESSED,
                'distance'            => null,
                'matched_id'          => null,
                'matched_uuid'        => null,
                'same_agent'          => false,
                'candidates_considered' => count($candidates),
                'threshold'           => $threshold,
                'message'             => 'candidates retrieved but none carried a usable hash',
            ];
        }

        $matched = $nearest['id'];

        $row = $submissions->findById($matched);
        $sameAgent = $row !== null && (int) $row['agent_id'] === $agentId;

        if ($nearest['distance'] <= $threshold) {
            return [
                'status'              => self::PERCEPTUAL,
                'distance'            => $nearest['distance'],
                'matched_id'          => $matched,
                'matched_uuid'        => $row['submission_uuid'] ?? null,
                'same_agent'          => $sameAgent,
                'candidates_considered' => count($candidates),
                'threshold'           => $threshold,
                'message'             => 'perceptually identical to submission '
                    . ($row['submission_uuid'] ?? $matched) . ' (distance ' . $nearest['distance'] . ')',
            ];
        }

        if ($nearest['distance'] <= $possible) {
            return [
                'status'              => self::POSSIBLE,
                'distance'            => $nearest['distance'],
                'matched_id'          => $matched,
                'matched_uuid'        => $row['submission_uuid'] ?? null,
                'same_agent'          => $sameAgent,
                'candidates_considered' => count($candidates),
                'threshold'           => $threshold,
                'message'             => 'near-duplicate within margin (distance ' . $nearest['distance'] . ')',
            ];
        }

        return [
            'status'              => self::NONE,
            'distance'            => $nearest['distance'],
            'matched_id'          => $matched,
            'matched_uuid'        => $row['submission_uuid'] ?? null,
            'same_agent'          => $sameAgent,
            'candidates_considered' => count($candidates),
            'threshold'           => $threshold,
            'message'             => 'nearest candidate is ' . $nearest['distance'] . ' bits away, beyond the ' . $possible . ' margin',
        ];
    }
}
