<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\CacheMonitor;
use Faultbox\Laravel\Client;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheMonitorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
        $app['config']->set('cache.stores.sessions', ['driver' => 'array', 'serialize' => false]);
        $app['config']->set('faultbox.cache.enabled', true);
        $app['config']->set('faultbox.cache.interval', 0); // send with every flush, except in the pooling tests
    }

    private function pooled(string $path, float &$now): Client
    {
        $this->app['config']->set('faultbox.cache.interval', 60);
        $this->app['config']->set('faultbox.cache.pool_path', $path);
        $client = $this->refreshClient();
        $client->setClock(static function () use (&$now): float {
            return $now;
        });

        return $client;
    }

    public function test_totals_are_pooled_across_processes_and_sent_once_a_minute(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fbx-pool-');
        @unlink($path);
        $now = 1_000.0;
        $sent = [];

        // Three PHP-FPM requests, each with a fresh client, as in production.
        foreach ([[1_000.0, 1], [1_030.0, 2], [1_061.0, 1]] as [$at, $reads]) {
            $now = $at;
            $client = $this->pooled($path, $now);
            $fake = $client->fake();
            for ($i = 0; $i < $reads; $i++) {
                Cache::get('users:42:profile');
            }
            $client->flush();
            $sent[] = $fake->cache();
        }

        $this->assertSame([], $sent[0], 'the first request starts the pool');
        $this->assertSame([], $sent[1], 'still inside the minute: no request at all');
        $this->assertCount(1, $sent[2], 'the minute is up: one batch for all three requests');
        $this->assertSame(4, $sent[2][0]['misses']);
        $this->assertSame('users:*:profile', $sent[2][0]['key']);
        @unlink($path);
    }

    public function test_a_pool_that_cannot_be_used_drops_totals_instead_of_sending_each_request(): void
    {
        $now = 1_000.0;
        $client = $this->pooled(sys_get_temp_dir() . '/faultbox-missing-dir/pool.json', $now);
        $fake = $client->fake();
        Cache::get('settings');
        $client->flush();

        $this->assertSame([], $fake->telemetryBatches());
        $this->assertSame(1, $client->stats()['dropped_cache']);
    }

    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    /** @param array<string, array{int, int, int, int, int}> $want */
    private function assertCounts(array $want, array $items): void
    {
        $got = [];
        foreach ($items as $item) {
            $got[$item['store'] . ' ' . $item['key']] = [$item['hits'], $item['misses'], $item['writes'], $item['deletes'], $item['failures']];
        }
        ksort($got);
        ksort($want);
        $this->assertSame($want, $got);
    }

    public function test_operations_are_counted_per_store_and_pattern(): void
    {
        $fake = $this->fake();
        Cache::remember('users:42:profile', 60, fn () => 'a'); // miss, write
        Cache::remember('users:42:profile', 60, fn () => 'a'); // hit
        Cache::get('users:7:profile');                          // miss: same pattern
        Cache::forget('users:42:profile');                      // delete
        Cache::store('sessions')->put('cart:9f1c2a4e-1b2c-4d5e-8f90-123456789abc', [1], 60);

        $this->assertSame([], $fake->telemetryBatches(), 'nothing is sent before the flush');
        $this->client()->flush();

        $this->assertCounts([
            'array users:*:profile' => [1, 2, 1, 1, 0],
            'sessions cart:*' => [0, 0, 1, 0, 0],
        ], $fake->cache());
        $batches = $fake->telemetryBatches();
        $this->assertCount(1, $batches, 'one request per flush');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $fake->cache()[0]['timestamp']);
        $json = json_encode($batches);
        $this->assertStringNotContainsString('users:42', $json, 'IDs never leave the app');
        $this->assertStringNotContainsString('9f1c2a4e', $json);
    }

    public function test_counters_reset_after_each_flush(): void
    {
        $fake = $this->fake();
        Cache::get('settings');
        $this->client()->flush();
        Cache::get('settings');
        Cache::get('settings');
        $this->client()->flush();

        $this->assertSame([1, 2], array_column($fake->cache(), 'misses'));
    }

    public function test_failed_writes_are_counted(): void
    {
        $fake = $this->fake();
        Event::dispatch(new KeyWriteFailed('redis', 'reports:2026:q3', 'x', 60));
        $this->client()->flush();

        $this->assertCounts(['redis reports:*:q3' => [0, 0, 0, 0, 1]], $fake->cache());
    }

    public function test_framework_keys_are_ignored(): void
    {
        $fake = $this->fake();
        Cache::get('illuminate:queue:restart'); // read on every worker loop
        Cache::put('framework/schedule-abc123', true, 60);
        $this->client()->flush();

        $this->assertSame([], $fake->cache());
    }

    public function test_past_the_cap_new_patterns_are_counted_as_other(): void
    {
        $fake = $this->fake();
        for ($i = 0; $i < 210; $i++) {
            Cache::get("feature-{$this->word($i)}");
        }
        $this->client()->flush();

        $items = $fake->cache();
        $this->assertCount(201, $items);
        $other = array_values(array_filter($items, fn ($i) => $i['key'] === CacheMonitor::OTHER));
        $this->assertSame(10, $other[0]['misses']);
    }

    public function test_cache_monitoring_can_be_turned_off(): void
    {
        $this->app['config']->set('faultbox.cache.enabled', false);
        $client = $this->refreshClient();
        $fake = $client->fake();
        Cache::get('settings');
        $client->flush();

        $this->assertNull($client->cache());
        $this->assertSame([], $fake->telemetryBatches());
    }

    #[DataProvider('keys')]
    public function test_keys_are_reduced_to_patterns(string $key, string $pattern): void
    {
        $this->assertSame($pattern, CacheMonitor::normalize($key));
    }

    public static function keys(): array
    {
        return [
            'numbers' => ['users:42:profile', 'users:*:profile'],
            'dotted' => ['posts.page.3', 'posts.page.*'],
            'underscored' => ['user_42_orders', 'user_*_orders'],
            'words stay' => ['settings', 'settings'],
            'versions stay' => ['api:v2:routes', 'api:v2:routes'],
            'uuid' => ['order:0192f3a4-5b6c-7d8e-9f01-23456789abcd', 'order:*'],
            'email' => ['otp:jane.doe@example.com', 'otp:*'],
            'md5 hash' => ['query:5d41402abc4b2a76b9719d911017c592', 'query:*'],
            'sha1 of a tagged key' => ['a94a8fe5ccb19ba61c4c0873d391e987982fbbd3:popular', '*:popular'],
            'token' => ['session:Xk29fLq0Pz8RwT1mNb4c', 'session:*'],
            'long word stays' => ['notificationpreferences', 'notificationpreferences'],
            'mixed ids' => ['tenant:12:user:34', 'tenant:*:user:*'],
        ];
    }

    /** Distinct words without digits, so each is its own pattern. */
    private function word(int $i): string
    {
        $s = '';
        do {
            $s .= chr(97 + $i % 26);
            $i = intdiv($i, 26);
        } while ($i > 0);

        return $s;
    }
}
