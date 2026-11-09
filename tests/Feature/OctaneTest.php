<?php

namespace Faultbox\Laravel\Tests\Feature;

require_once __DIR__ . '/../Fixtures/OctaneEvents.php';

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

/**
 * Under Octane one worker serves many requests with the same singletons.
 * Nothing set during one request may reach the next one's events.
 */
class OctaneTest extends TestCase
{
    private function request(string $url, array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create($url, 'GET', server: $server);
    }

    /** Runs $handle as one Octane request and returns the events sent for it. */
    private function octaneRequest(Request $request, callable $handle): array
    {
        $fake = $this->app->make(Client::class)->transport();
        $before = count($fake->events());
        $this->app['events']->dispatch(new RequestReceived($this->app, $this->app, $request));
        $handle();
        $this->assertCount($before, $fake->events(), 'nothing is sent before the response');
        $this->app['events']->dispatch(new RequestTerminated($this->app, $this->app, $request));

        return array_slice($fake->events(), $before);
    }

    public function test_requests_in_one_worker_do_not_leak_context_user_or_correlation(): void
    {
        $this->fake();
        Faultbox::setContext(['app_region' => 'eu']); // set at boot: kept for every request

        $a = $this->octaneRequest($this->request('https://shop.test/orders/1', ['X-Request-Id' => 'req-a']), function () {
            Faultbox::setContext(['order_id' => 1]);
            Faultbox::setUser(['id' => '7']);
            report(new \RuntimeException('failed in A'));
        });
        $this->assertCount(1, $a);
        $this->assertSame('https://shop.test/orders/1', $a[0]['request']['url']);
        $this->assertSame('req-a', $a[0]['correlation_id']);
        $this->assertSame(['app_region' => 'eu', 'order_id' => 1], $a[0]['context']);
        $this->assertSame('7', $a[0]['user']['id']);

        $b = $this->octaneRequest($this->request('https://shop.test/cart'), function () {
            Faultbox::captureMessage('failed in B', 'error');
        });
        $this->assertCount(1, $b);
        $this->assertSame('https://shop.test/cart', $b[0]['request']['url']);
        $this->assertNotSame('req-a', $b[0]['correlation_id'], 'a new correlation ID per request');
        $this->assertSame(['app_region' => 'eu'], $b[0]['context'], 'request context is reset, boot context kept');
        $this->assertArrayNotHasKey('user', $b[0], 'the previous request\'s user is gone');
    }

    public function test_events_are_buffered_until_the_response_is_sent(): void
    {
        $this->fake();
        $events = $this->octaneRequest($this->request('https://shop.test/checkout'), function () {
            report(new \RuntimeException('one'));
            report(new \RuntimeException('two'));
        });
        $this->assertCount(2, $events);
    }

    public function test_the_client_is_shared_by_the_worker_and_its_requests(): void
    {
        // Resolved when the provider booted, so sandboxes cloned from the
        // base app all reuse the same instance and its listeners see it.
        $this->assertTrue($this->app->resolved(Client::class));
    }
}
