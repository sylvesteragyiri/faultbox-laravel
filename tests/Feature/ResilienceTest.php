<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Support\CircuitBreaker;
use Faultbox\Laravel\Support\FileBreakerStore;
use Faultbox\Laravel\Tests\TestCase;
use Faultbox\Laravel\Transport\HttpTransport;
use Faultbox\Laravel\Transport\Response;
use Faultbox\Laravel\Transport\Transport;
use Illuminate\Support\Facades\Route;

/**
 * Faultbox being down, slow or broken must never slow down or break the app.
 */
class ResilienceTest extends TestCase
{
    /** A fake clock, in seconds, shared by the client and its breaker. */
    private float $now = 1_000_000.0;

    private function clock(): \Closure
    {
        return fn (): float => $this->now;
    }

    /** A transport that fails every send and advances the clock by $cost seconds. */
    private function failingTransport(int $status, float $cost, int &$calls): Transport
    {
        return new class($status, $cost, $calls, $this) implements Transport {
            public function __construct(private int $status, private float $cost, private int &$calls, private ResilienceTest $test)
            {
            }

            public function send(string $path, array $payload): Response
            {
                $this->calls++;
                $this->test->advance($this->cost);

                return new Response($this->status, error: $this->status === 0 ? 'timed out' : null);
            }
        };
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    private function clientWith(Transport $transport, ?CircuitBreaker $breaker = null): Client
    {
        $client = $this->app->make(Client::class);
        $client->fake(); // resets state; then swap in the transport under test
        $property = new \ReflectionProperty(Client::class, 'transport');
        $property->setValue($client, $transport);
        $client->setClock($this->clock());
        $client->setBreaker($breaker ?? new CircuitBreaker(60, null, $this->clock()));

        return $client;
    }

    public function test_an_outage_costs_one_timeout_not_one_per_event(): void
    {
        $calls = 0;
        $client = $this->clientWith($this->failingTransport(0, 2.0, $calls)); // each attempt times out after 2 s

        for ($i = 0; $i < 100; $i++) {
            Faultbox::captureMessage("error $i", 'error');
        }

        $this->assertSame(1, $calls, 'one attempt, no retry after a timeout, then the breaker opens');
        $this->assertLessThanOrEqual(2.0, $this->now - 1_000_000.0, 'the app lost one timeout in total');
        $this->assertTrue($client->stats()['breaker_open']);
        $this->assertSame(99, $client->stats()['dropped_by_breaker']);
    }

    public function test_sending_resumes_after_the_cooldown(): void
    {
        $calls = 0;
        $client = $this->clientWith($this->failingTransport(503, 0.01, $calls));
        Faultbox::captureMessage('trips it');
        $this->assertTrue($client->stats()['breaker_open']);

        $this->advance(30);
        Faultbox::captureMessage('still paused');
        $this->assertSame(2, $calls, 'the 503 was retried once, then nothing during the cooldown');

        $this->advance(31);
        $fake = new \Faultbox\Laravel\Transport\FakeTransport();
        (new \ReflectionProperty(Client::class, 'transport'))->setValue($client, $fake);
        Faultbox::captureMessage('trial after the cooldown');
        $this->assertCount(1, $fake->events(), 'the cooldown ended, so it tries again');
        $this->assertFalse($client->stats()['breaker_open'], 'success closes the breaker');
    }

    public function test_client_errors_do_not_pause_sending(): void
    {
        $calls = 0;
        $client = $this->clientWith($this->failingTransport(401, 0.01, $calls));
        Faultbox::captureMessage('one');
        Faultbox::captureMessage('two');

        $this->assertSame(2, $calls, 'Faultbox answered, so it is up: no pause');
        $this->assertFalse($client->stats()['breaker_open']);
    }

    public function test_a_rate_limit_pauses_only_as_long_as_asked(): void
    {
        $calls = 0;
        $client = $this->clientWith(new class($calls) implements Transport {
            public function __construct(private int &$calls)
            {
            }

            public function send(string $path, array $payload): Response
            {
                $this->calls++;

                return new Response(429, retryAfter: 0);
            }
        });
        Faultbox::captureMessage('limited');
        $paused = $client->stats()['paused_until'];

        $this->assertNotNull($paused);
        $this->assertEqualsWithDelta($this->now + 1.0, $paused, 0.01, 'at least 1 s, far below the 60 s outage cooldown');
    }

    public function test_the_flush_budget_bounds_a_slow_server(): void
    {
        $this->app['config']->set('faultbox.flush_budget', 3.0);
        $client = $this->refreshClient();
        $sent = 0;
        $slow = new class($sent, $this) implements Transport {
            public function __construct(private int &$sent, private ResilienceTest $test)
            {
            }

            public function send(string $path, array $payload): Response
            {
                $this->test->advance(1.2); // slow, but it succeeds

                $this->sent++;

                return new Response(202);
            }
        };
        $client->fake();
        (new \ReflectionProperty(Client::class, 'transport'))->setValue($client, $slow);
        $client->setClock($this->clock());
        $client->setDeferred(true);

        for ($i = 0; $i < 10; $i++) {
            Faultbox::captureMessage("buffered $i");
        }
        $client->flush();

        $this->assertSame(3, $sent, '3 s budget, 1.2 s each: the third starts before the deadline');
        $this->assertSame(7, $client->stats()['dropped_by_budget']);
    }

    public function test_the_pause_is_shared_between_processes(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'faultbox-breaker-test-' . bin2hex(random_bytes(4));
        try {
            // "Process" A trips the breaker...
            $a = new CircuitBreaker(60, new FileBreakerStore($path), $this->clock());
            $a->failure();
            // ...and a fresh "process" B, with no memory of it, sees the pause.
            $b = new CircuitBreaker(60, new FileBreakerStore($path), $this->clock());
            $this->assertFalse($b->allows());
            $this->advance(61);
            $this->assertTrue($b->allows());
            $b->success();
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_faultbox_test_command_tests_even_while_paused(): void
    {
        $client = $this->app->make(Client::class);
        $fake = $client->fake();
        $breaker = new CircuitBreaker(60);
        $breaker->failure();
        $client->setBreaker($breaker);

        $this->artisan('faultbox:test')
            ->expectsOutputToContain('paused')
            ->expectsOutputToContain('accepted')
            ->assertSuccessful();
        $this->assertCount(1, $fake->events());
        $this->assertFalse($client->stats()['breaker_open'], 'a successful test resumes sending');
    }

    public function test_a_broken_client_never_breaks_exception_handling(): void
    {
        $this->app->forgetInstance(Client::class);
        Faultbox::clearResolvedInstance(Client::class);
        $this->app->singleton(Client::class, fn () => throw new \LogicException('broken Faultbox config'));

        Route::get('/fine', fn () => 'still works');
        Route::get('/boom', fn () => throw new \RuntimeException('app error'));

        report(new \RuntimeException('reported while the client is broken'));
        $this->get('/fine')->assertOk()->assertSee('still works');
        $this->get('/boom')->assertStatus(500); // the app's own error page, not a crash in the reporter
    }

    // ---- real sockets ----

    public function test_a_refused_connection_is_fast_and_pauses_sending(): void
    {
        $port = $this->freePort(); // nothing listens here
        $client = $this->realClient("http://127.0.0.1:$port", timeout: 2.0, connect: 1.0);

        $start = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            Faultbox::captureMessage("down $i", 'error');
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.5, $elapsed, '50 events with Faultbox down');
        $this->assertTrue($client->stats()['breaker_open']);
        $this->assertSame(49, $client->stats()['dropped_by_breaker']);
    }

    public function test_a_hanging_server_costs_one_timeout_for_many_events(): void
    {
        // Accepts connections (the OS completes the handshake from the
        // backlog) but never answers: the worst case for a client.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, $errstr);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        try {
            $client = $this->realClient("http://127.0.0.1:$port", timeout: 0.7, connect: 0.5);

            $start = microtime(true);
            for ($i = 0; $i < 20; $i++) {
                Faultbox::captureMessage("hang $i", 'error');
            }
            $elapsed = microtime(true) - $start;

            $this->assertGreaterThan(0.6, $elapsed, 'the first event did wait for the timeout');
            $this->assertLessThan(1.5, $elapsed, 'one timeout in total, not one per event or per retry');
            $this->assertSame(19, $client->stats()['dropped_by_breaker']);
        } finally {
            fclose($server);
        }
    }

    private function realClient(string $dsn, float $timeout, float $connect): Client
    {
        $client = $this->app->make(Client::class);
        $client->fake();
        (new \ReflectionProperty(Client::class, 'transport'))
            ->setValue($client, new HttpTransport($dsn, 'fbx_test', $timeout, $connect));
        $client->setBreaker(new CircuitBreaker(60));

        return $client;
    }

    private function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);

        return $port;
    }
}
