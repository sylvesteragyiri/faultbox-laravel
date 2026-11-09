<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Tests\TestCase;
use Faultbox\Laravel\Transport\HttpTransport;
use Faultbox\Laravel\Transport\Response;
use Illuminate\Support\Facades\Route;

class DeliveryTest extends TestCase
{
    public function test_web_requests_send_after_the_response(): void
    {
        $fake = $this->fake();
        $client = $this->app->make(Client::class);
        $client->setDeferred(true);

        $sentDuringRequest = null;
        Route::get('/checkout', function () use ($fake) {
            report(new \RuntimeException('first'));
            report(new \RuntimeException('second'));
            $GLOBALS['faultbox_sent_during_request'] = count($fake->sent());

            return 'ok';
        });

        $this->get('/checkout')->assertOk();

        $this->assertSame(0, $GLOBALS['faultbox_sent_during_request'], 'nothing is sent before the response');
        $this->assertCount(2, $fake->events(), 'flushed when the app terminated');
        unset($GLOBALS['faultbox_sent_during_request'], $sentDuringRequest);
    }

    public function test_buffer_is_capped_per_request(): void
    {
        $this->app['config']->set('faultbox.max_events_per_request', 3);
        $client = $this->refreshClient();
        $fake = $client->fake();
        $client->setDeferred(true);

        for ($i = 0; $i < 10; $i++) {
            Faultbox::captureMessage("message $i");
        }
        $client->flush();

        $this->assertCount(3, $fake->events());
    }

    public function test_retries_once_on_503_then_gives_up(): void
    {
        $calls = 0;
        $fake = $this->app->make(Client::class)->fake(function () use (&$calls) {
            $calls++;

            return new Response($calls === 1 ? 503 : 202, retryAfter: 0);
        });

        Faultbox::captureMessage('retry me');
        $this->assertSame(2, $calls);

        $calls = 0;
        $this->app->make(Client::class)->fake(function () use (&$calls) {
            $calls++;

            return new Response(503);
        });
        Faultbox::captureMessage('always busy');
        $this->assertSame(2, $calls, 'one retry, then the event is dropped');
    }

    public function test_client_errors_are_not_retried(): void
    {
        $calls = 0;
        $this->app->make(Client::class)->fake(function () use (&$calls) {
            $calls++;

            return new Response(422, '{"error":{"code":"validation_failed"}}');
        });

        Faultbox::captureMessage('bad');

        $this->assertSame(1, $calls);
    }

    public function test_tracker_outage_never_breaks_the_app(): void
    {
        // Nothing listens on port 9: connections are refused immediately.
        $this->app['config']->set('faultbox.dsn', 'http://127.0.0.1:9');
        $this->app['config']->set('faultbox.retries', 0);
        $client = $this->refreshClient();
        Route::get('/fine', fn () => 'still works');
        Route::get('/broken', fn () => throw new \RuntimeException('app bug'));

        $start = microtime(true);
        $this->get('/fine')->assertOk()->assertSee('still works');
        report(new \RuntimeException('while the tracker is down'));
        $this->get('/broken')->assertStatus(500);
        $elapsed = microtime(true) - $start;

        $this->assertSame(0, $client->lastResponse()?->status, 'delivery was attempted and failed');
        $this->assertLessThan(5.0, $elapsed, 'timeouts keep the app responsive');
    }

    public function test_unreachable_host_respects_the_connect_timeout(): void
    {
        // 10.255.255.1 is non-routable: the TCP connect hangs until the timeout.
        $transport = new HttpTransport('http://10.255.255.1', 'fbx_x', timeout: 1.0, connectTimeout: 0.5);

        $start = microtime(true);
        $response = $transport->send('/api/v1/events', ['event_id' => 'x']);
        $elapsed = microtime(true) - $start;

        $this->assertSame(0, $response->status);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function test_events_from_one_request_share_a_correlation_id(): void
    {
        $fake = $this->fake();
        Route::get('/two', function () {
            report(new \RuntimeException('a'));
            report(new \RuntimeException('b'));

            return 'ok';
        });

        $this->get('/two');

        [$a, $b] = $fake->events();
        $this->assertNotEmpty($a['correlation_id']);
        $this->assertSame($a['correlation_id'], $b['correlation_id']);
    }
}
