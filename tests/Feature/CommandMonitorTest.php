<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

class CommandMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Laravel forwards the Symfony console events everywhere except in unit tests.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();

        Artisan::command('app:sync-orders', fn () => 0);
        Artisan::command('app:half-done', fn () => 3);
        Artisan::command('app:explode', function () {
            throw new \RuntimeException('Gateway timed out');
        });
        Artisan::command('app:nested', function () {
            $this->call('app:sync-orders');

            return 0;
        });
        Artisan::command('horizon:snapshot', fn () => 0);
    }

    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    public function test_a_successful_run_is_recorded_without_arguments(): void
    {
        $fake = $this->fake();
        Artisan::call('app:sync-orders', ['--verbose' => true]);
        $this->client()->flush();

        $runs = $fake->commands();
        $this->assertCount(1, $runs);
        $this->assertSame('app:sync-orders', $runs[0]['command']);
        $this->assertSame(0, $runs[0]['exit_code']);
        $this->assertIsInt($runs[0]['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $runs[0]['duration_ms']);
        $this->assertUuidV7($runs[0]['id']);
        $this->assertArrayNotHasKey('error', $runs[0]);
        $this->assertStringNotContainsString('verbose', json_encode($fake->telemetryBatches()));
    }

    public function test_a_non_zero_exit_is_recorded(): void
    {
        $fake = $this->fake();
        Artisan::call('app:half-done');
        $this->client()->flush();

        $this->assertSame(3, $fake->commands()[0]['exit_code']);
    }

    public function test_the_exception_is_attached_to_the_failed_run(): void
    {
        $fake = $this->fake();
        try {
            Artisan::call('app:explode');
            $this->fail('the command should throw');
        } catch (\RuntimeException $e) {
            report($e); // what the console kernel does next
        }
        $this->client()->flush();

        $runs = $fake->commands();
        $this->assertCount(1, $runs);
        $this->assertSame(1, $runs[0]['exit_code']);
        $this->assertSame(['type' => \RuntimeException::class, 'message' => 'Gateway timed out'], $runs[0]['error']);
    }

    public function test_a_nested_call_is_part_of_the_outer_run(): void
    {
        $fake = $this->fake();
        Artisan::call('app:nested'); // $this->call() inside runs without console events
        $this->client()->flush();

        $this->assertSame(['app:nested'], array_column($fake->commands(), 'command'));
    }

    public function test_ignored_commands_are_not_recorded(): void
    {
        $fake = $this->fake();
        Artisan::call('horizon:snapshot');
        Artisan::call('list');
        $this->client()->flush();

        $this->assertSame([], $fake->commands());
    }

    public function test_the_ignore_list_is_configurable(): void
    {
        $this->app['config']->set('faultbox.commands.ignore', ['app:sync-*']);
        $client = $this->refreshClient();
        $fake = $client->fake();
        Artisan::call('app:sync-orders');
        Artisan::call('horizon:snapshot');
        $client->flush();

        $this->assertSame(['horizon:snapshot'], array_column($fake->commands(), 'command'));
    }
}
