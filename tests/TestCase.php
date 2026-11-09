<?php

namespace Faultbox\Laravel\Tests;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\FaultboxServiceProvider;
use Faultbox\Laravel\Transport\FakeTransport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** A DSN/key pair so the client counts as configured. Tests swap in a fake transport. */
    protected array $faultboxConfig = [
        'faultbox.dsn' => 'http://faultbox.test',
        'faultbox.api_key' => 'fbx_testkeytestkeytestkeytestkeytestkeytestk',
        // Keep breaker state per test: the default "auto" store would share
        // it between tests through a file in the temp directory.
        'faultbox.circuit_breaker.store' => 'memory',
    ];

    protected function getPackageProviders($app): array
    {
        return [FaultboxServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Faultbox' => \Faultbox\Laravel\Facades\Faultbox::class];
    }

    protected function defineEnvironment($app): void
    {
        foreach ($this->faultboxConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.failed.driver', 'null');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function fake(): FakeTransport
    {
        return $this->app->make(Client::class)->fake();
    }

    /** Rebuild the client after changing config at runtime. */
    protected function refreshClient(): Client
    {
        $this->app->forgetInstance(Client::class);
        $this->app->forgetInstance(\Faultbox\Laravel\QueueMonitor::class);
        \Faultbox\Laravel\Facades\Faultbox::clearResolvedInstance(Client::class);

        return $this->app->make(Client::class);
    }

    protected function createJobsTable(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    /** Process one job from the database queue, as `queue:work --once` would. */
    protected function work(string $connection = 'database', string $queue = 'default'): void
    {
        $this->app->make('queue.worker')->runNextJob($connection, $queue, new WorkerOptions(backoff: 0, sleep: 0));
    }

    /** A uuid v7: version nibble 7, RFC 4122 variant. */
    protected function assertUuidV7(string $id): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }
}
