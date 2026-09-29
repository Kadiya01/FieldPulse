<?php

declare(strict_types=1);

namespace FieldPulse\Console;

use FieldPulse\Config\Config;
use FieldPulse\Database\JobRepository;
use FieldPulse\Support\Logger;
use FieldPulse\Support\Uuid;
use FieldPulse\Verification\VerificationService;

/**
 * The cron-driven queue worker (§5).
 *
 * Invoked every minute by cPanel Cron:
 *
 *   * * * * * /usr/local/bin/php /home/USER/private_storage/workers/process_queue.php
 *
 * The database is the queue, so this process holds no state between ticks: it
 * claims a batch, processes it, exits. That is what makes it safe to have two
 * ticks overlap — which cPanel will happily do if one run exceeds 60 seconds,
 * and which happens on a slow shared host.
 *
 * Two runtime guards matter more than the happy path:
 *
 *   max_runtime  A tick stops claiming new work after this many seconds. Without
 *                it, a pathological batch can run past the next cron tick and
 *                two workers will fight over the same queue. Bounded batches
 *                with a second overlapping tick are fine; unbounded ones are not.
 *
 *   recoverStaleLocks  A worker killed mid-job (timeout, OOM kill, deploy)
 *                leaves rows in PROCESSING forever unless someone returns them.
 *                This runs at the start of every tick, which is what guarantees
 *                no submission can be stranded.
 */
final class QueueWorker
{
    private readonly JobRepository $jobs;

    private bool $stopRequested = false;

    public function __construct(
        private readonly VerificationService $verification = new VerificationService()
    ) {
        $this->jobs = new JobRepository();
    }

    /**
     * @return array<string,mixed> run summary
     */
    public function run(?int $maxJobs = null): array
    {
        $c          = Config::instance();
        $workerId   = 'cron-' . substr(Uuid::v4(), 0, 8);
        $startedAt  = microtime(true);
        $maxRuntime = $c->int('worker.max_runtime');

        $recovered = $this->jobs->recoverStaleLocks();

        if ($recovered > 0) {
            Logger::warning('worker.stale_locks_recovered', ['count' => $recovered, 'worker' => $workerId]);
        }

        $summary = [
            'worker'    => $workerId,
            'recovered' => $recovered,
            'claimed'   => 0,
            'completed' => 0,
            'retried'   => 0,
            'failed'    => 0,
            'duration'  => 0.0,
        ];

        $this->installSignalHandlers();

        while (true) {
            if ($maxJobs !== null && $summary['completed'] + $summary['failed'] >= $maxJobs) {
                break;
            }

            $elapsed = microtime(true) - $startedAt;

            if ($elapsed >= $maxRuntime) {
                Logger::info('worker.runtime_budget_reached', [
                    'worker'  => $workerId,
                    'elapsed' => round($elapsed, 1),
                ]);
                break;
            }

            if ($this->stopRequested) {
                break;
            }

            $batch = $this->jobs->claimBatch($workerId);

            if ($batch === []) {
                break;   // queue drained
            }

            foreach ($batch as $job) {
                $summary['claimed']++;

                $jobId         = (int) $job['id'];
                $submissionId  = (int) $job['submission_id'];
                $attempts      = (int) $job['attempts'];
                $maxAttempts   = (int) $job['max_attempts'];

                try {
                    $this->process($job);

                    $this->jobs->complete($jobId);
                    $summary['completed']++;
                } catch (\Throwable $e) {
                    $willRetry = $this->jobs->fail(
                        $jobId,
                        'JOB_EXCEPTION',
                        mb_substr($e->getMessage(), 0, 2000),
                        $attempts,
                        $maxAttempts
                    );

                    if ($willRetry) {
                        $summary['retried']++;
                    } else {
                        $summary['failed']++;
                    }

                    Logger::error('worker.job_failed', [
                        'job_id'        => $jobId,
                        'submission_id' => $submissionId,
                        'attempts'      => $attempts,
                        'will_retry'    => $willRetry,
                        'error'         => $e->getMessage(),
                    ]);

                    $this->markStranded($submissionId, $e->getMessage(), $willRetry);
                }
            }
        }

        $summary['duration'] = round(microtime(true) - $startedAt, 2);

        return $summary;
    }

    /**
     * @param array<string,mixed> $job
     */
    private function process(array $job): void
    {
        $submissionId = (int) $job['submission_id'];
        $jobType      = (string) $job['job_type'];

        switch ($jobType) {
            case JobRepository::VERIFICATION:
                $this->verification->verify($submissionId);
                break;

            case JobRepository::AGGREGATION:
                // Aggregation is performed inline by the verification pass, so an
                // AGGREGATION job is a no-op acknowledgement. It exists so a
                // re-aggregation can be scheduled independently later without a
                // schema change.
                break;

            default:
                throw new \RuntimeException('unknown job type: ' . $jobType);
        }
    }

    /**
     * A job that has exhausted its attempts must not leave the submission
     * looking like it is still being worked on.
     */
    private function markStranded(int $submissionId, string $error, bool $willRetry): void
    {
        if ($willRetry) {
            return;   // a retry is still pending, so PROCESSING is accurate
        }

        \FieldPulse\Database\Connection::execute(
            'UPDATE submissions
                SET status = :status, updated_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN (:processing, :queued)',
            [
                'status'     => \FieldPulse\Database\SubmissionRepository::REQUIRES_REVIEW,
                'id'         => $submissionId,
                'processing' => \FieldPulse\Database\SubmissionRepository::PROCESSING,
                'queued'     => \FieldPulse\Database\SubmissionRepository::QUEUED,
            ]
        );

        Logger::error('worker.submission_stranded', [
            'submission_id' => $submissionId,
            'error'         => mb_substr($error, 0, 500),
        ]);
    }

    /**
     * Cron has no interactive Ctrl-C, but a SIGTERM from a cPanel process killer
     * is real. The handler only sets a flag: exiting mid-batch would leave the
     * job locked, and the stale-lock recovery is the correct owner of that
     * problem.
     */
    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->stopRequested = true;
            });
        }
    }

    /**
     * Housekeeping tick: prune everything that has a TTL, for use from cron
     * rather than from the hot path.
     */
    public function prune(): array
    {
        return [
            'nonces'        => \FieldPulse\Security\NonceGuard::prune(),
            'challenges'    => \FieldPulse\Security\ChallengeService::pruneExpired(),
            'refresh_tokens' => (new \FieldPulse\Database\RefreshTokenRepository())->pruneExpired(),
            'login_attempts' => \FieldPulse\Security\RateLimiter::pruneOlderThanDays(),
            'jobs_completed' => $this->jobs->purgeCompleted(),
        ];
    }
}
