<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Config\Config;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Uuid;

/**
 * The database-backed job queue (§4/§5).
 *
 * The database is the queue. That is the whole point: a basic cPanel plan has no
 * Redis, no beanstalkd, and no long-running process supervisor, so a cron tick
 * has to be able to find its work by asking the database.
 *
 * Claiming prefers SELECT ... FOR UPDATE SKIP LOCKED, which is the primitive
 * that says exactly what it means: lock the head-of-queue rows, and let a
 * concurrent worker skip the ones already locked instead of blocking behind
 * them. SKIP LOCKED needs MySQL 8.0.1+ or MariaDB 10.6+, and the deployment
 * target includes older MariaDB releases, so capability is probed once from
 * SERVER_VERSION() and the UPDATE ... ORDER BY ... LIMIT form is used as the
 * fallback.
 *
 * Both forms are made safe by the same trick: rows are claimed with a unique
 * per-claim worker token, and the SELECT that follows can only return rows
 * bearing that token. A crashed worker's rows become eligible again once
 * locked_at exceeds the timeout. The token also guards completion: a worker
 * whose lease expired and was reaped cannot complete or fail the row a second
 * worker now owns.
 */
final class JobRepository extends Repository
{
    public const VERIFICATION = 'VERIFICATION';
    public const AGGREGATION  = 'AGGREGATION';

    public const PENDING    = 'PENDING';
    public const PROCESSING = 'PROCESSING';
    public const COMPLETED  = 'COMPLETED';
    public const FAILED     = 'FAILED';

    /**
     * Enqueue a job. Idempotent on (submission_id, job_type).
     *
     * Re-enqueueing a FAILED job resets it to PENDING, which is what makes a
     * manual re-run after a fix possible without deleting rows.
     */
    public function enqueue(int $submissionId, string $jobType = self::VERIFICATION, int $delaySeconds = 0): int
    {
        $availableAt = Clock::sql(Clock::shift($delaySeconds));

        return (int) Connection::transaction(function () use ($submissionId, $jobType, $availableAt): int {
            try {
                $this->exec(
                    'INSERT INTO processing_jobs (
                        submission_id, job_type, status, attempts, available_at, max_attempts, created_at, updated_at
                     ) VALUES (
                        :submission_id, :job_type, :status, 0, :available_at, :max_attempts, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                     )',
                    [
                        'submission_id' => $submissionId,
                        'job_type'      => $jobType,
                        'status'        => self::PENDING,
                        'available_at'  => $availableAt,
                        'max_attempts'  => Config::instance()->int('worker.max_attempts'),
                    ]
                );
            } catch (\PDOException $e) {
                // The (submission_id, job_type) UNIQUE index already has a job.
                //
                // This used to be an ON DUPLICATE KEY UPDATE ending in
                // `WHERE processing_jobs.status IN (?, ?)`, which is a syntax
                // error: ON DUPLICATE KEY UPDATE takes no WHERE clause in MySQL
                // or MariaDB, so every enqueue raised ER_PARSE_ERROR and the
                // submit transaction rolled back. Nothing could ever be
                // submitted. A plain INSERT plus a guarded UPDATE is also
                // clearer about intent, and does not depend on the per-column
                // IF() rewrite the ODKU form would have required.
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }

                // Re-open only a job that has finished or given up. A PENDING or
                // PROCESSING job for this submission is already queued, and
                // resetting it here would duplicate work in flight.
                $this->exec(
                    'UPDATE processing_jobs
                        SET status = :pending,
                            available_at = :available_at,
                            attempts = 0,
                            last_error_code = NULL,
                            last_error_message = NULL,
                            completed_at = NULL,
                            locked_at = NULL,
                            locked_by = NULL,
                            updated_at = UTC_TIMESTAMP()
                      WHERE submission_id = :submission_id
                        AND job_type = :job_type
                        AND status IN (:failed, :completed)',
                    [
                        'pending'        => self::PENDING,
                        'available_at'   => $availableAt,
                        'submission_id'  => $submissionId,
                        'job_type'       => $jobType,
                        'failed'         => self::FAILED,
                        'completed'      => self::COMPLETED,
                    ]
                );
            }

            $id = $this->value(
                'SELECT id FROM processing_jobs WHERE submission_id = :s AND job_type = :t',
                ['s' => $submissionId, 't' => $jobType]
            );

            return (int) $id;
        });
    }

    private static ?bool $supportsSkipLocked = null;

    private static ?bool $forcedSkipLocked = null;

    /**
     * Force the claim strategy, so both SQL paths can be exercised on one server.
     *
     * MariaDB gained SKIP LOCKED in 10.6, and the deployment target still
     * includes MariaDB 10.3-10.5, whose claim path is the single-UPDATE
     * fallback in claimWithToken(). That fallback is the one statement standing
     * between those operators and a queue that silently stops draining, and it
     * was previously only ever run on a server old enough to trigger it — which
     * no CI has. On MariaDB 10.11 both paths can be run by flipping this.
     *
     * Refused outside a non-production environment: this is a correctness
     * override, and leaving it available on a live deployment would let anyone
     * who can reach the process quietly move the queue off the path that
     * serialises concurrent claims.
     */
    public static function overrideSkipLocked(?bool $value): void
    {
        $env = strtolower((string) Config::instance()->str('app.env', 'production'));

        if ($env === 'production') {
            throw new \LogicException(
                'JobRepository::overrideSkipLocked() is a test seam and is refused when app.env=production.'
            );
        }

        self::$forcedSkipLocked = $value;
    }

    /**
     * Whether the connected server understands FOR UPDATE SKIP LOCKED.
     *
     * Probed once from SERVER_VERSION() and cached for the process. MySQL has
     * had SKIP LOCKED since 8.0.1; MariaDB since 10.6.
     */
    public static function supportsSkipLocked(): bool
    {
        if (self::$forcedSkipLocked !== null) {
            return self::$forcedSkipLocked;
        }

        if (self::$supportsSkipLocked === null) {
            self::$supportsSkipLocked = self::versionSupportsSkipLocked(Connection::serverVersion());
        }

        return self::$supportsSkipLocked;
    }

    /**
     * The strategy name actually in use, for diagnostics.
     */
    public static function claimStrategy(): string
    {
        return self::supportsSkipLocked() ? 'for-update-skip-locked' : 'single-update-token';
    }

    private static function versionSupportsSkipLocked(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return version_compare($version, '10.6.0', '>=');
        }

        return version_compare($version, '8.0.1', '>=');
    }

    /**
     * Atomically claim up to $limit jobs and return them.
     *
     * Each returned row carries the worker token it was claimed under, so the
     * caller can hand it back to complete()/fail() and prove ownership.
     *
     * @return list<array<string,mixed>>
     */
    public function claimBatch(string $workerId, ?int $limit = null): array
    {
        $limit = $limit ?? Config::instance()->int('worker.batch_size');
        $lock  = $workerId . ':' . Uuid::v4();

        if (self::supportsSkipLocked()) {
            return $this->claimWithSkipLocked($lock, $limit);
        }

        return $this->claimWithToken($lock, $limit);
    }

    /**
     * Preferred path: lock the head of the queue with SKIP LOCKED inside a
     * transaction, then stamp the same rows with the worker token. The locks
     * are held only for the two statements and released on commit, so a second
     * worker skips these rows and takes the next ones instead of blocking.
     *
     * @return list<array<string,mixed>>
     */
    private function claimWithSkipLocked(string $lock, int $limit): array
    {
        return Connection::transaction(function () use ($lock, $limit): array {
            $rows = $this->all(
                'SELECT id
                   FROM processing_jobs
                  WHERE status = :pending
                    AND available_at <= UTC_TIMESTAMP()
                    AND attempts < max_attempts
                  ORDER BY available_at ASC, id ASC'
                    . self::limitOnly($limit, 100)
                    . ' FOR UPDATE SKIP LOCKED',
                ['pending' => self::PENDING]
            );

            if ($rows === []) {
                return [];
            }

            // Ints, cast here, are the only thing concatenated: no caller
            // string can reach the statement.
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

            $this->exec(
                'UPDATE processing_jobs
                    SET status = :processing,
                        locked_at = UTC_TIMESTAMP(),
                        locked_by = :lock,
                        attempts = attempts + 1,
                        updated_at = UTC_TIMESTAMP()
                  WHERE id IN (' . implode(',', $ids) . ')',
                ['processing' => self::PROCESSING, 'lock' => $lock]
            );

            return $this->claimedRows($lock);
        });
    }

    /**
     * Fallback for MariaDB < 10.6: a single UPDATE claims the head of the queue
     * without a transaction. Two workers serialise on the row locks; the token
     * is what makes the read-back unambiguous.
     *
     * @return list<array<string,mixed>>
     */
    private function claimWithToken(string $lock, int $limit): array
    {
        // The token is unique per claim, so the SELECT that follows can only
        // return rows this statement won. Without the random suffix a
        // same-second restart of the same cron would match the previous run's
        // rows.
        $this->exec(
            'UPDATE processing_jobs
                SET status = :processing,
                    locked_at = UTC_TIMESTAMP(),
                    locked_by = :lock,
                    attempts = attempts + 1,
                    updated_at = UTC_TIMESTAMP()
              WHERE status = :pending
                AND available_at <= UTC_TIMESTAMP()
                AND attempts < max_attempts
              ORDER BY available_at ASC, id ASC'
                . self::limitOnly($limit, 100),
            ['processing' => self::PROCESSING, 'lock' => $lock, 'pending' => self::PENDING]
        );

        return $this->claimedRows($lock);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function claimedRows(string $lock): array
    {
        return $this->all(
            'SELECT id, submission_id, job_type, attempts, max_attempts, locked_by
               FROM processing_jobs
              WHERE locked_by = :lock AND status = :processing
              ORDER BY id ASC',
            ['lock' => $lock, 'processing' => self::PROCESSING]
        );
    }

    /**
     * Transition a claimed job to COMPLETED.
     *
     * The update is scoped to the worker that still owns the row. If the lease
     * expired and stale recovery handed the job to another worker, this affects
     * zero rows and returns false rather than clobbering the new owner's claim.
     *
     * @return bool true when the caller still owned the job
     */
    public function complete(int $jobId, string $lock): bool
    {
        return $this->exec(
            'UPDATE processing_jobs
                SET status = :completed, completed_at = UTC_TIMESTAMP(),
                    locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = :id
                AND locked_by = :lock
                AND status = :processing',
            [
                'completed'  => self::COMPLETED,
                'id'         => $jobId,
                'lock'       => $lock,
                'processing' => self::PROCESSING,
            ]
        ) > 0;
    }

    /**
     * Record a failure and schedule the retry.
     *
     * When the attempt budget is exhausted the job goes to FAILED and the
     * submission is left in PROCESSING for a human, which is deliberate: a
     * submission stuck mid-pipeline is visible, whereas a silently dropped job
     * is not.
     *
     * The write is scoped to the owning worker token, for the same reason as
     * complete(): a worker whose lease was reaped must not mutate a job another
     * worker has since claimed. A lost-ownership write returns false.
     *
     * @return bool true when the job will be retried
     */
    public function fail(int $jobId, string $lock, string $errorCode, string $message, int $attempts, int $maxAttempts): bool
    {
        $retry = $attempts < $maxAttempts;

        if ($retry) {
            $backoff = min(
                Config::instance()->int('worker.backoff_cap'),
                Config::instance()->int('worker.backoff_base') * (2 ** max(0, $attempts - 1))
            );

            $affected = $this->exec(
                'UPDATE processing_jobs
                    SET status = :pending,
                        available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :backoff SECOND),
                        last_error_code = :code,
                        last_error_message = :message,
                        locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE id = :id
                    AND locked_by = :lock
                    AND status = :processing',
                [
                    'pending'    => self::PENDING,
                    'backoff'    => $backoff,
                    'code'       => mb_substr($errorCode, 0, 100),
                    'message'    => $message,
                    'id'         => $jobId,
                    'lock'       => $lock,
                    'processing' => self::PROCESSING,
                ]
            );

            return $affected > 0;
        }

        $this->exec(
            'UPDATE processing_jobs
                SET status = :failed,
                    last_error_code = :code,
                    last_error_message = :message,
                    locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = :id
                AND locked_by = :lock
                AND status = :processing',
            [
                'failed'     => self::FAILED,
                'code'       => mb_substr($errorCode, 0, 100),
                'message'    => $message,
                'id'         => $jobId,
                'lock'       => $lock,
                'processing' => self::PROCESSING,
            ]
        );

        return false;
    }

    /**
     * Recover jobs abandoned by a killed worker (§5).
     *
     * A stale PROCESSING row is returned to PENDING so another worker can take
     * it — unless it has already burned its attempt budget, in which case it is
     * marked FAILED. Without that second branch a job that kills its worker
     * every time (a poison image, an OOM on a huge file) would be reclaimed
     * forever and never surface, which is exactly the silent-strand failure the
     * attempt cap exists to prevent.
     *
     * A job failed this way abandoned its submission mid-pipeline, so the
     * submission is moved to REQUIRES_REVIEW in the same transaction.
     *
     * @return int number of stale rows reclaimed (retried or failed)
     */
    public function recoverStaleLocks(?int $timeoutMinutes = null): int
    {
        $timeout = $timeoutMinutes ?? Config::instance()->int('worker.lock_timeout');

        return Connection::transaction(function () use ($timeout): int {
            $strandedCode = 'JOB_STALE_EXHAUSTED';

            $failed = $this->exec(
                'UPDATE processing_jobs
                    SET status = :failed,
                        last_error_code = :code,
                        last_error_message = :message,
                        locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE status = :processing
                    AND locked_at IS NOT NULL
                    AND attempts >= max_attempts
                    AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :timeout MINUTE)',
                [
                    'failed'     => self::FAILED,
                    'code'       => $strandedCode,
                    'message'    => 'lock expired after exhausting the attempt budget',
                    'processing' => self::PROCESSING,
                    'timeout'    => $timeout,
                ]
            );

            if ($failed > 0) {
                $this->exec(
                    'UPDATE submissions s
                       JOIN processing_jobs j ON j.submission_id = s.id
                        SET s.status = :review, s.updated_at = UTC_TIMESTAMP()
                      WHERE j.status = :failed
                        AND j.last_error_code = :code
                        AND s.status IN (:processing, :queued)',
                    [
                        'review'     => SubmissionRepository::REQUIRES_REVIEW,
                        'failed'     => self::FAILED,
                        'code'       => $strandedCode,
                        'processing' => SubmissionRepository::PROCESSING,
                        'queued'     => SubmissionRepository::QUEUED,
                    ]
                );
            }

            $recovered = $this->exec(
                'UPDATE processing_jobs
                    SET status = :pending, locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE status = :processing
                    AND locked_at IS NOT NULL
                    AND attempts < max_attempts
                    AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :timeout MINUTE)',
                ['pending' => self::PENDING, 'processing' => self::PROCESSING, 'timeout' => $timeout]
            );

            return $recovered + $failed;
        });
    }

    /**
     * @return array<string,int>
     */
    public function stats(): array
    {
        $rows = $this->all('SELECT status, COUNT(*) AS n FROM processing_jobs GROUP BY status');
        $out  = [self::PENDING => 0, self::PROCESSING => 0, self::COMPLETED => 0, self::FAILED => 0];

        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Housekeeping. Completed rows are kept briefly for forensics, then removed.
     */
    public function purgeCompleted(int $days = 7, int $limit = 10000): int
    {
        return $this->exec(
            'DELETE FROM processing_jobs
              WHERE status = :completed
                AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)' . self::limitOnly($limit),
            ['completed' => self::COMPLETED, 'days' => max(1, $days)]
        );
    }
}
