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
 * Claiming uses UPDATE ... ORDER BY ... LIMIT rather than SELECT ... FOR UPDATE
 * SKIP LOCKED. SKIP LOCKED would be the more elegant primitive, but it needs
 * MySQL 8.0 or MariaDB 10.6 and the deployment target includes older MariaDB
 * releases. The UPDATE form takes the same row locks, and the combination of
 * (a) claiming to PENDING-in-one-statement and (b) a unique worker token that
 * identifies exactly which rows this worker won gives the same guarantee
 * without a version dependency.
 *
 * Concurrency safety, concretely: two workers running the same statement
 * serialise on the head-of-queue rows. Worker A claims ids 1-10, worker B then
 * re-evaluates the WHERE clause and takes 11-20. A worker can therefore only
 * ever read back rows bearing its own token, and a crashed worker's rows become
 * eligible again once locked_at exceeds the timeout.
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

    /**
     * Atomically claim up to $limit jobs and return them.
     *
     * @return list<array<string,mixed>>
     */
    public function claimBatch(string $workerId, ?int $limit = null): array
    {
        $limit = $limit ?? Config::instance()->int('worker.batch_size');
        $lock  = $workerId . ':' . Uuid::v4();

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
              ORDER BY available_at ASC, id ASC'
                . self::limitOnly($limit, 100),
            ['processing' => self::PROCESSING, 'lock' => $lock, 'pending' => self::PENDING]
        );

        return $this->all(
            'SELECT id, submission_id, job_type, attempts, max_attempts
               FROM processing_jobs
              WHERE locked_by = :lock AND status = :processing
              ORDER BY id ASC',
            ['lock' => $lock, 'processing' => self::PROCESSING]
        );
    }

    public function complete(int $jobId): void
    {
        $this->exec(
            'UPDATE processing_jobs
                SET status = :completed, completed_at = UTC_TIMESTAMP(),
                    locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = :id',
            ['completed' => self::COMPLETED, 'id' => $jobId]
        );
    }

    /**
     * Record a failure and schedule the retry.
     *
     * When the attempt budget is exhausted the job goes to FAILED and the
     * submission is left in PROCESSING for a human, which is deliberate: a
     * submission stuck mid-pipeline is visible, whereas a silently dropped job
     * is not.
     *
     * @return bool true when the job will be retried
     */
    public function fail(int $jobId, string $errorCode, string $message, int $attempts, int $maxAttempts): bool
    {
        $retry = $attempts < $maxAttempts;

        if ($retry) {
            $backoff = min(
                Config::instance()->int('worker.backoff_cap'),
                Config::instance()->int('worker.backoff_base') * (2 ** max(0, $attempts - 1))
            );

            $this->exec(
                'UPDATE processing_jobs
                    SET status = :pending,
                        available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :backoff SECOND),
                        last_error_code = :code,
                        last_error_message = :message,
                        locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
                  WHERE id = :id',
                [
                    'pending' => self::PENDING,
                    'backoff' => $backoff,
                    'code'    => mb_substr($errorCode, 0, 100),
                    'message' => $message,
                    'id'      => $jobId,
                ]
            );

            return true;
        }

        $this->exec(
            'UPDATE processing_jobs
                SET status = :failed,
                    last_error_code = :code,
                    last_error_message = :message,
                    locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
              WHERE id = :id',
            [
                'failed'  => self::FAILED,
                'code'    => mb_substr($errorCode, 0, 100),
                'message' => $message,
                'id'      => $jobId,
            ]
        );

        return false;
    }

    /**
     * Return jobs abandoned by a killed worker to PENDING (§5).
     *
     * @return int number of rows recovered
     */
    public function recoverStaleLocks(?int $timeoutMinutes = null): int
    {
        $timeout = $timeoutMinutes ?? Config::instance()->int('worker.lock_timeout');

        return $this->exec(
            'UPDATE processing_jobs
                SET status = :pending, locked_at = NULL, locked_by = NULL, updated_at = UTC_TIMESTAMP()
              WHERE status = :processing
                AND locked_at IS NOT NULL
                AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :timeout MINUTE)',
            ['pending' => self::PENDING, 'processing' => self::PROCESSING, 'timeout' => $timeout]
        );
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
