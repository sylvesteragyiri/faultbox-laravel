<?php

namespace Faultbox\Laravel;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\ManuallyFailedException;

/**
 * Reports failed queue jobs through Laravel's queue events (PRD §4.5).
 *
 * It tells four situations apart:
 *  - terminal: the job threw and has no retries left (JobFailed followed by
 *    JobExceptionOccurred for the same exception);
 *  - manual: the job called $this->fail() and returned (JobFailed followed by
 *    JobProcessed);
 *  - attempt: the job threw but will be retried (JobExceptionOccurred without
 *    JobFailed); only reported when queue.report_attempts is on;
 *  - a retry is never reported as a terminal failure.
 *
 * The worker fires JobFailed before JobExceptionOccurred, and the sync queue
 * fires them the other way round, so a failure stays pending until the
 * event that settles it arrives.
 */
final class QueueMonitor
{
    /** @var array<int, array{job: Job, exception: \Throwable}> keyed by spl_object_id($job) */
    private array $pending = [];

    /** Exceptions already seen in JobExceptionOccurred (sync-queue order). */
    private \WeakMap $occurred;

    /** Context from before the current job, restored when it ends. */
    private ?array $savedContext = null;

    public function __construct(private readonly Client $client, private readonly array $config)
    {
        $this->occurred = new \WeakMap();
    }

    public function processing(JobProcessing $event): void
    {
        $this->guard(function () use ($event) {
            // Context set inside one job must not leak into the next one in a
            // long-running worker, but context set at boot must survive.
            $this->savedContext = $this->client->events()->snapshot();
            $this->client->setContext(['queue_job' => [
                'name' => $event->job->resolveName(),
                'id' => (string) $event->job->getJobId(),
                'attempt' => $event->job->attempts(),
            ]]);
        });
    }

    private function endJob(): void
    {
        if ($this->savedContext !== null) {
            $this->client->events()->restore($this->savedContext);
            $this->savedContext = null;
        }
        // Send what the job produced (failures, notifications) now, not
        // when the next job happens to succeed.
        $this->client->flush();
    }

    public function failed(JobFailed $event): void
    {
        $this->guard(function () use ($event) {
            $this->client->markJobException($event->exception);
            if (isset($this->occurred[$event->exception])) {
                // Sync queue: the exception event already fired, so this was thrown.
                $this->report($event->job, $event->exception, 'terminal');
                $this->endJob();

                return;
            }
            $this->pending[spl_object_id($event->job)] = ['job' => $event->job, 'exception' => $event->exception];
        });
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->guard(function () use ($event) {
            $this->client->markJobException($event->exception);
            $id = spl_object_id($event->job);

            if (isset($this->pending[$id]) && $this->pending[$id]['exception'] === $event->exception) {
                unset($this->pending[$id]);
                $this->report($event->job, $event->exception, 'terminal');
                $this->endJob();

                return;
            }

            $this->occurred[$event->exception] = true;
            if (! $event->job->hasFailed() && ! empty($this->config['report_attempts'])) {
                $this->report($event->job, $event->exception, 'attempt');
            }
            if (! $event->job->hasFailed()) {
                $this->endJob(); // released for a retry
            }
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->guard(function () use ($event) {
            $id = spl_object_id($event->job);
            if (isset($this->pending[$id])) {
                // The job returned normally after calling $this->fail().
                $pending = $this->pending[$id];
                unset($this->pending[$id]);
                $this->report($pending['job'], $pending['exception'], 'manual');
            }
            $this->endJob();
            $this->client->flush();
        });
    }

    /** Settle anything left over, e.g. when the worker stops mid-job. */
    public function flushPending(): void
    {
        $this->guard(function () {
            foreach ($this->pending as $id => $pending) {
                unset($this->pending[$id]);
                $kind = $pending['exception'] instanceof ManuallyFailedException ? 'manual' : 'terminal';
                $this->report($pending['job'], $pending['exception'], $kind);
            }
        });
    }

    private function report(Job $job, \Throwable $e, string $kind): void
    {
        $payload = $this->client->events()->queueFailure($e, $this->jobData($job), $kind);
        $this->client->sendQueueFailure($payload);
        $this->client->flush();
    }

    /** @return array<string, mixed> */
    private function jobData(Job $job): array
    {
        $raw = $job->payload();
        $metadata = array_intersect_key($raw, array_flip([
            'uuid', 'displayName', 'job', 'maxTries', 'maxExceptions', 'failOnTimeout',
            'backoff', 'timeout', 'retryUntil', 'tags', 'pushedAt',
        ]));
        $command = $raw['data']['command'] ?? null;

        // Never unserialize the command: read the batch ID straight from it.
        $batchId = null;
        if (is_string($command) && preg_match('/s:7:"batchId";s:\d+:"([0-9a-f-]{36})"/', $command, $m)) {
            $batchId = $m[1];
        }

        $maxTries = $job->maxTries();

        return [
            'name' => $job->resolveName(),
            'id' => (string) ($job->getJobId() ?? $job->uuid() ?? ''),
            'queue' => (string) $job->getQueue(),
            'connection' => (string) $job->getConnectionName(),
            'attempts' => $job->attempts(),
            'max_tries' => is_numeric($maxTries) ? (int) $maxTries : null,
            'batch_id' => $batchId,
            'payload' => $metadata,
        ];
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
            // Monitoring must never break a worker.
        }
    }
}
