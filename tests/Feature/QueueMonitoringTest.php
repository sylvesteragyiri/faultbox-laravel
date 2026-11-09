<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Tests\Fixtures\ContextJob;
use Faultbox\Laravel\Tests\Fixtures\FailingJob;
use Faultbox\Laravel\Tests\Fixtures\ManualFailJob;
use Faultbox\Laravel\Tests\Fixtures\SucceedingJob;
use Faultbox\Laravel\Tests\TestCase;
use Faultbox\Laravel\Transport\FakeTransport;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Support\Facades\DB;

class QueueMonitoringTest extends TestCase
{
    private FakeTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createJobsTable();
        $this->fake = $this->fake();
    }

    private function remainingJobs(): int
    {
        return DB::table('jobs')->count();
    }

    public function test_terminal_failure_is_reported_once_after_the_last_retry(): void
    {
        FailingJob::dispatch()->onConnection('database');

        $this->work(); // attempt 1 of 2: released for a retry
        $this->assertSame(1, $this->remainingJobs(), 'job was released, not failed');
        $this->fake->assertNothingSent();

        $this->work(); // attempt 2 of 2: terminal
        $this->assertSame(0, $this->remainingJobs());

        $failures = $this->fake->queueFailures();
        $this->assertCount(1, $failures);
        $f = $failures[0];
        $this->assertUuidV7($f['event_id']);
        $this->assertSame(\RuntimeException::class, $f['exception']['type']);
        $this->assertSame('Payment provider timed out', $f['exception']['message']);
        $this->assertSame(FailingJob::class, $f['job']['name']);
        $this->assertSame('terminal', $f['job']['kind']);
        $this->assertSame('database', $f['job']['connection']);
        $this->assertSame('default', $f['job']['queue']);
        $this->assertSame(2, $f['job']['attempts']);
        $this->assertSame(2, $f['job']['max_tries']);
        $this->assertNotEmpty($f['job']['id']);
        $this->assertArrayNotHasKey('request', $f, 'a worker has no HTTP request');

        // Job metadata, never the serialized command (which holds $apiToken).
        $this->assertSame(FailingJob::class, $f['job']['payload']['displayName']);
        $this->assertStringNotContainsString('secret-token', json_encode($f));

        // The worker also hands the exception to the handler; it isn't sent again.
        $this->assertSame([], $this->fake->events());
    }

    public function test_intermediate_attempts_are_optional(): void
    {
        $this->app['config']->set('faultbox.queue.report_attempts', true);
        $this->refreshClient();
        $this->fake = $this->fake();

        FailingJob::dispatch()->onConnection('database');
        $this->work();
        $this->work();

        $this->assertSame(['attempt', 'terminal'], array_column(array_column($this->fake->queueFailures(), 'job'), 'kind'));
        $this->assertSame([1, 2], array_column(array_column($this->fake->queueFailures(), 'job'), 'attempts'));
    }

    public function test_job_exceptions_can_also_be_sent_as_ordinary_events(): void
    {
        $this->app['config']->set('faultbox.queue.report_job_exceptions', true);
        $this->refreshClient();
        $this->fake = $this->fake();

        FailingJob::dispatch()->onConnection('database');
        $this->work();
        $this->work();

        $this->assertCount(1, $this->fake->queueFailures());
        $this->assertCount(2, $this->fake->events(), 'one per attempt, via the exception handler');
    }

    public function test_manual_fail_with_a_message(): void
    {
        ManualFailJob::dispatch()->onConnection('database');
        $this->work();

        $failures = $this->fake->queueFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('manual', $failures[0]['job']['kind']);
        $this->assertSame(ManuallyFailedException::class, $failures[0]['exception']['type']);
        $this->assertSame('Card expired', $failures[0]['exception']['message']);
        $this->assertSame(1, $failures[0]['job']['attempts']);
        $this->assertSame(3, $failures[0]['job']['max_tries'], 'failed manually before using up its retries');
    }

    public function test_manual_fail_with_an_exception(): void
    {
        ManualFailJob::dispatch(new \DomainException('Customer closed their account'))->onConnection('database');
        $this->work();

        $failure = $this->fake->queueFailures()[0];
        $this->assertSame('manual', $failure['job']['kind']);
        $this->assertSame(\DomainException::class, $failure['exception']['type']);
    }

    public function test_successful_jobs_send_nothing(): void
    {
        SucceedingJob::dispatch()->onConnection('database');
        $this->work();

        $this->fake->assertNothingSent();
    }

    public function test_sync_queue_failures_are_terminal(): void
    {
        // The sync driver fires the queue events in the opposite order.
        try {
            FailingJob::dispatch('sync failure')->onConnection('sync');
            $this->fail('the sync queue rethrows job exceptions');
        } catch (\RuntimeException $e) {
            $this->assertSame('sync failure', $e->getMessage());
            report($e); // what the HTTP kernel would do next
        }

        $this->assertCount(1, $this->fake->queueFailures());
        $this->assertSame('terminal', $this->fake->queueFailures()[0]['job']['kind']);
        $this->assertSame('sync', $this->fake->queueFailures()[0]['job']['connection']);
        $this->assertSame([], $this->fake->events(), 'not reported twice');
    }

    public function test_job_context_does_not_leak_between_jobs(): void
    {
        Faultbox::setContext(['app' => 'set at boot']);

        ContextJob::dispatch('acme')->onConnection('database');
        ContextJob::dispatch('globex', fail: true)->onConnection('database');
        $this->work();
        $this->work();

        // Handled exception reported inside the first job: ordinary event with job context.
        $first = $this->fake->events()[0];
        $this->assertSame(\DomainException::class, $first['exception']['type']);
        $this->assertSame('acme', $first['context']['job_tenant']);
        $this->assertSame(ContextJob::class, $first['context']['queue_job']['name']);
        $this->assertSame('set at boot', $first['context']['app']);

        // The second job fails: its context is its own, not the first job's.
        $failure = $this->fake->queueFailures()[0];
        $this->assertSame('globex', $failure['context']['job_tenant']);
        $this->assertSame('set at boot', $failure['context']['app']);

        // After both jobs, only the boot context is left.
        Faultbox::captureMessage('after the jobs');
        $last = $this->fake->events()[array_key_last($this->fake->events())];
        $this->assertSame(['app' => 'set at boot'], $last['context']);
    }

    public function test_batch_id_is_read_without_unserializing(): void
    {
        $job = new FailingJob();
        $job->batchId = '9a1d3f6e-2b4c-4d8e-a7f0-1c2b3d4e5f60';
        dispatch($job)->onConnection('database');
        $this->work();
        $this->work();

        $this->assertSame('9a1d3f6e-2b4c-4d8e-a7f0-1c2b3d4e5f60', $this->fake->queueFailures()[0]['job']['batch_id']);
    }

    public function test_redis_queue_when_available(): void
    {
        if (! getenv('REDIS_HOST') || ! extension_loaded('redis')) {
            $this->markTestSkipped('Set REDIS_HOST to run the Redis driver test.');
        }
        $this->app['config']->set('database.redis.client', 'phpredis');
        $this->app['config']->set('database.redis.default', [
            'host' => getenv('REDIS_HOST'), 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 15,
        ]);
        $this->app['config']->set('queue.connections.redis', [
            'driver' => 'redis', 'connection' => 'default', 'queue' => 'faultbox-test', 'retry_after' => 90,
        ]);
        $this->app['redis']->connection()->flushdb();

        FailingJob::dispatch()->onConnection('redis');
        $this->work('redis', 'faultbox-test');
        $this->work('redis', 'faultbox-test');

        $failure = $this->fake->queueFailures()[0] ?? null;
        $this->assertNotNull($failure);
        $this->assertSame('redis', $failure['job']['connection']);
        $this->assertSame('terminal', $failure['job']['kind']);
    }
}
