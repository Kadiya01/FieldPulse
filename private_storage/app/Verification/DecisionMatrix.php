<?php

declare(strict_types=1);

namespace FieldPulse\Verification;

use FieldPulse\Database\SubmissionRepository;

/**
 * The decision matrix (§11).
 *
 * Design rule: the system only auto-approves when every check passes with no
 * caveats. Every doubt becomes REQUIRES_REVIEW. A false approval pays an agent,
 * so a false rejection only costs a human a minute, and the asymmetry justifies
 * erring toward review in every ambiguous case.
 *
 * Rejection is reserved for evidence of fraud or an unusable artefact:
 *   - a byte-identical or perceptually identical re-upload by the same agent
 *   - an EXIF capture time in the future beyond tolerance
 *   - a file that failed decode or safety inspection
 *
 * Everything else that is not a clean pass is a review.
 */
final class DecisionMatrix
{
    public const VERIFIED        = 'VERIFIED';
    public const REQUIRES_REVIEW = 'REQUIRES_REVIEW';
    public const REJECTED        = 'REJECTED';

    /* Finding codes. These are the values a reviewer and the API client see. */
    public const ALL_CHECKS_PASSED      = 'ALL_CHECKS_PASSED';
    public const EXACT_DUPLICATE        = 'EXACT_DUPLICATE';
    public const PERCEPTUAL_DUPLICATE   = 'PERCEPTUAL_DUPLICATE';
    public const CROSS_AGENT_DUPLICATE  = 'CROSS_AGENT_DUPLICATE';
    public const POSSIBLE_DUPLICATE     = 'POSSIBLE_DUPLICATE';
    public const TIMESTAMP_FUTURE       = 'TIMESTAMP_FUTURE';
    public const TIMESTAMP_MISSING      = 'TIMESTAMP_MISSING';
    public const TIMESTAMP_TOO_OLD      = 'TIMESTAMP_TOO_OLD';
    public const TIMESTAMP_DISCREPANCY  = 'TIMESTAMP_DISCREPANCY';
    public const TIMESTAMP_UNPARSEABLE  = 'TIMESTAMP_UNPARSEABLE';
    public const OUTSIDE_GEOFENCE       = 'OUTSIDE_GEOFENCE';
    public const NO_GPS                 = 'NO_GPS';
    public const SITE_UNASSIGNED        = 'SITE_UNASSIGNED';
    public const GPS_UNRELIABLE         = 'GPS_UNRELIABLE';
    public const HIGH_VOLUME_CLAIM      = 'HIGH_VOLUME_CLAIM';
    public const IMAGE_UNREADABLE       = 'IMAGE_UNREADABLE';

    private function __construct()
    {
    }

    /**
     * @param  array{
     *   image_ok:bool,
     *   timestamp:array<string,mixed>,
     *   duplicate:array<string,mixed>,
     *   geofence:array<string,mixed>,
     *   weekly_verified_count:int,
     *   count_claimed:int
     * } $checks
     * @return array{
     *   disposition:string, reason:string, reasons:list<array{code:string,detail:string}>,
     *   weekly_cap_exceeded:bool
     * }
     */
    public static function decide(array $checks): array
    {
        $reasons = [];

        // --- Unreadable artefact: reject, nothing further is meaningful -------
        if ($checks['image_ok'] === false) {
            return [
                'disposition'        => self::REJECTED,
                'reason'             => self::IMAGE_UNREADABLE,
                'reasons'            => [['code' => self::IMAGE_UNREADABLE, 'detail' => 'file could not be decoded for verification']],
                'weekly_cap_exceeded' => false,
            ];
        }

        // --- Rejection-grade evidence ----------------------------------------
        $duplicate = $checks['duplicate'];
        $timestamp = $checks['timestamp'];

        if (($duplicate['status'] ?? '') === DuplicateDetector::EXACT) {
            $reasons[] = [
                'code'   => self::EXACT_DUPLICATE,
                'detail' => $duplicate['message'] ?? 'byte-identical file already submitted',
            ];
        } elseif (($duplicate['status'] ?? '') === DuplicateDetector::PERCEPTUAL) {
            $code = ($duplicate['same_agent'] ?? true) ? self::PERCEPTUAL_DUPLICATE : self::CROSS_AGENT_DUPLICATE;
            $reasons[] = ['code' => $code, 'detail' => $duplicate['message'] ?? 'perceptual match found'];
        }

        if (($timestamp['status'] ?? '') === TimestampVerifier::FUTURE) {
            $reasons[] = [
                'code'   => self::TIMESTAMP_FUTURE,
                'detail' => $timestamp['message'] ?? 'capture timestamp in the future',
            ];
        }

        if ($reasons !== []) {
            return [
                'disposition'        => self::REJECTED,
                'reason'             => $reasons[0]['code'],
                'reasons'            => $reasons,
                'weekly_cap_exceeded' => false,
            ];
        }

        // --- Review-grade uncertainty ----------------------------------------
        if (($duplicate['status'] ?? '') === DuplicateDetector::POSSIBLE) {
            $reasons[] = [
                'code'   => self::POSSIBLE_DUPLICATE,
                'detail' => $duplicate['message'] ?? 'near-duplicate within margin',
            ];
        }

        $timestampReviews = [
            TimestampVerifier::MISSING     => [self::TIMESTAMP_MISSING, 'no usable capture timestamp'],
            TimestampVerifier::UNPARSEABLE => [self::TIMESTAMP_UNPARSEABLE, 'capture timestamp could not be parsed'],
            TimestampVerifier::TOO_OLD     => [self::TIMESTAMP_TOO_OLD, 'capture timestamp predates the allowed window'],
            TimestampVerifier::DISCREPANCY => [self::TIMESTAMP_DISCREPANCY, 'EXIF and client timestamps disagree'],
        ];

        $timestampStatus = (string) ($timestamp['status'] ?? '');

        if (isset($timestampReviews[$timestampStatus])) {
            [$code, $detail] = $timestampReviews[$timestampStatus];
            $reasons[] = [
                'code'   => $code,
                'detail' => $timestamp['message'] ?? $detail,
            ];
        } elseif (($timestamp['review'] ?? false) === true) {
            $reasons[] = [
                'code'   => self::TIMESTAMP_MISSING,
                'detail' => $timestamp['message'] ?? 'capture timestamp flagged for review',
            ];
        }

        $geofenceStatus = (string) ($checks['geofence']['status'] ?? '');

        $geofenceReviews = [
            \FieldPulse\Geo\Geofence::OUTSIDE_GEOFENCE => [self::OUTSIDE_GEOFENCE, 'position outside every assigned site radius'],
            \FieldPulse\Geo\Geofence::NO_GPS           => [self::NO_GPS, 'no position supplied'],
            \FieldPulse\Geo\Geofence::SITE_UNASSIGNED  => [self::SITE_UNASSIGNED, 'agent has no site assignment to check against'],
            \FieldPulse\Geo\Geofence::GPS_UNRELIABLE   => [self::GPS_UNRELIABLE, 'supplied position is not plausible for a handset'],
        ];

        if (isset($geofenceReviews[$geofenceStatus])) {
            [$code, $detail] = $geofenceReviews[$geofenceStatus];
            $reasons[] = ['code' => $code, 'detail' => $checks['geofence']['message'] ?? $detail];
        }

        // --- Weekly cap (§13) -------------------------------------------------
        $cap         = (int) \FieldPulse\Config\Config::instance()->int('limits.weekly_cap');
        $projected   = (int) $checks['weekly_verified_count'] + (int) $checks['count_claimed'];
        $capExceeded = $projected > $cap;

        if ($capExceeded) {
            $reasons[] = [
                'code'   => self::HIGH_VOLUME_CLAIM,
                'detail' => 'weekly total would reach ' . $projected . ', above the cap of ' . $cap,
            ];
        }

        if ($reasons !== []) {
            return [
                'disposition'        => self::REQUIRES_REVIEW,
            'reason'             => $reasons[0]['code'],
            'reasons'            => $reasons,
            'weekly_cap_exceeded' => false,
        ];
        }

        return [
            'disposition'        => self::VERIFIED,
            'reason'             => self::ALL_CHECKS_PASSED,
            'reasons'            => [['code' => self::ALL_CHECKS_PASSED, 'detail' => 'all automated checks passed']],
            'weekly_cap_exceeded' => false,
        ];
    }

    /**
     * Map a matrix disposition onto the submissions status vocabulary.
     */
    public static function toStatus(string $disposition): string
    {
        return match ($disposition) {
            self::VERIFIED        => SubmissionRepository::VERIFIED,
            self::REQUIRES_REVIEW => SubmissionRepository::REQUIRES_REVIEW,
            self::REJECTED        => SubmissionRepository::REJECTED,
            default               => SubmissionRepository::REQUIRES_REVIEW,
        };
    }
}
