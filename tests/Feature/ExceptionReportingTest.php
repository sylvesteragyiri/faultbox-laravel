<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\EventFactory;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Support\Sanitizer;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;

class ExceptionReportingTest extends TestCase
{
    private int $throwLine = 0;

    private function makeException(string $message = 'Payment failed for order 4821'): \RuntimeException
    {
        try {
            $this->throwLine = __LINE__ + 1;
            throw new \RuntimeException($message);
        } catch (\RuntimeException $e) {
            return $e;
        }
    }

    public function test_reported_exceptions_reach_faultbox_in_the_wire_format(): void
    {
        $fake = $this->fake();

        report($e = $this->makeException());

        $events = $fake->events();
        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertUuidV7($event['event_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $event['timestamp']);
        $this->assertSame('testing', $event['environment']);
        $this->assertSame('error', $event['level']);
        $this->assertSame(\RuntimeException::class, $event['exception']['type']);
        $this->assertSame('Payment failed for order 4821', $event['exception']['message']);

        // frames[0] is the throw site, inside the function that threw.
        $top = $event['exception']['frames'][0];
        $this->assertStringEndsWith('ExceptionReportingTest.php', $top['file']);
        $this->assertSame($this->throwLine, $top['line']);
        $this->assertSame('makeException', $top['function']);
        $this->assertSame(self::class, $top['class']);
        $this->assertTrue($top['in_app']);

        // Laravel's own frames are marked as dependencies.
        $vendor = array_filter($event['exception']['frames'], fn ($f) => str_contains($f['file'] ?? '', 'vendor/'));
        $this->assertNotEmpty($vendor);
        foreach ($vendor as $frame) {
            $this->assertFalse($frame['in_app'], $frame['file']);
        }

        // The payload survives a JSON round trip.
        $this->assertSame($event, json_decode(json_encode($event), true));
    }

    public function test_exceptions_laravel_does_not_report_are_not_sent(): void
    {
        $fake = $this->fake();

        report(new AuthorizationException('nope')); // in Laravel's internal dontReport list

        $fake->assertNothingSent();
    }

    public function test_ignore_exceptions_config(): void
    {
        $this->app['config']->set('faultbox.ignore_exceptions', [\LogicException::class]);
        $this->refreshClient();
        $fake = $this->fake();

        report(new \InvalidArgumentException('subclass of LogicException'));
        report(new \RuntimeException('reported'));

        $this->assertCount(1, $fake->events());
        $fake->assertExceptionSent(\RuntimeException::class);
    }

    public function test_the_same_exception_is_sent_once(): void
    {
        $fake = $this->fake();
        $e = $this->makeException();

        report($e);
        Faultbox::captureException($e);
        report($e);

        $this->assertCount(1, $fake->events());
    }

    public function test_http_request_context_is_captured_and_sanitized(): void
    {
        $fake = $this->fake();
        Route::get('/orders/{id}', fn () => throw new \RuntimeException('Order lookup failed'))->name('orders.show');

        $this->get('/orders/5?token=abc123&page=2', [
            'Authorization' => 'Bearer real-secret',
            'Cookie' => 'laravel_session=xyz',
            'User-Agent' => 'phpunit',
            'X-Request-Id' => 'req-123',
        ])->assertStatus(500);

        $event = $fake->events()[0];
        $request = $event['request'];
        $this->assertSame('http://localhost/orders/5?page=2&token=%5Bredacted%5D', $request['url']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame(500, $request['status_code']);
        $this->assertSame('orders.show', $request['route']);
        $this->assertSame('phpunit', $request['headers']['User-Agent']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
        $this->assertArrayNotHasKey('Cookie', $request['headers']);
        $this->assertSame('req-123', $event['correlation_id']);
        $this->assertStringNotContainsString('real-secret', json_encode($event));
        $this->assertStringNotContainsString('abc123', json_encode($event));
    }

    public function test_context_and_user(): void
    {
        $fake = $this->fake();
        $this->actingAs(new GenericUser(['id' => 42, 'email' => 'jane@example.com']));

        Faultbox::setContext(['tenant' => 'acme', 'stripe' => ['secret' => 'sk_live_x', 'customer' => 'cus_1']]);
        Faultbox::captureException($this->makeException(), ['order_id' => 4821]);

        $event = $fake->events()[0];
        $this->assertSame(['id' => '42'], $event['user'], 'only the ID is sent');
        $this->assertSame('acme', $event['context']['tenant']);
        $this->assertSame(4821, $event['context']['order_id']);
        $this->assertSame(Sanitizer::REDACTED, $event['context']['stripe']['secret']);
        $this->assertSame('cus_1', $event['context']['stripe']['customer']);

        Faultbox::setUser(['id' => 'override']);
        Faultbox::captureMessage('after setUser');
        $this->assertSame(['id' => 'override'], $fake->events()[1]['user']);

        $this->app['config']->set('faultbox.send_user_id', false);
        Faultbox::clearContext();
        $this->refreshClient();
        $fake = $this->fake();
        Faultbox::captureMessage('no user');
        $this->assertArrayNotHasKey('user', $fake->events()[0]);
        $this->assertArrayNotHasKey('context', $fake->events()[0]);
    }

    public function test_capture_message(): void
    {
        $fake = $this->fake();

        $id = Faultbox::captureMessage('Payment reconciliation failed', 'error', ['batch' => 7]);

        $event = $fake->events()[0];
        $this->assertSame($id, $event['event_id']);
        $this->assertSame('Payment reconciliation failed', $event['message']);
        $this->assertSame('error', $event['level']);
        $this->assertArrayNotHasKey('exception', $event);
        $this->assertSame(7, $event['context']['batch']);

        Faultbox::captureMessage('odd level', 'LOUD');
        $this->assertSame('info', $fake->events()[1]['level']);
    }

    public function test_chained_exceptions_are_summarized(): void
    {
        $fake = $this->fake();
        $root = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $wrapped = new \RuntimeException('Could not load orders', 0, new \LogicException('Repository failed', 0, $root));

        report($wrapped);

        $previous = $fake->events()[0]['context']['previous'];
        $this->assertSame([\LogicException::class, \PDOException::class], array_column($previous, 'type'));
        $this->assertSame('SQLSTATE[HY000] [2002] Connection refused', $previous[1]['message']);
    }

    public function test_environment_and_release(): void
    {
        $this->app['config']->set('faultbox.environment', 'Staging EU');
        $this->app['config']->set('faultbox.release', '2026.11.1');
        $this->refreshClient();
        $fake = $this->fake();

        report($this->makeException());

        $this->assertSame('staging-eu', $fake->events()[0]['environment']);
        $this->assertSame('2026.11.1', $fake->events()[0]['release']);
    }

    public function test_deep_traces_are_trimmed_like_the_server_expects(): void
    {
        $fake = $this->fake();
        $recurse = function (int $n) use (&$recurse) {
            if ($n === 0) {
                throw new \RuntimeException('deep');
            }
            $recurse($n - 1);
        };
        try {
            $recurse(260);
        } catch (\RuntimeException $e) {
            report($e);
        }

        $ex = $fake->events()[0]['exception'];
        $this->assertCount(200, $ex['frames']);
        $this->assertGreaterThan(0, $ex['frames_omitted']);
    }

    public function test_http_status_comes_from_http_exceptions(): void
    {
        $this->assertSame(503, EventFactory::statusFor(new \Symfony\Component\HttpKernel\Exception\HttpException(503)));
        $this->assertSame(500, EventFactory::statusFor(new \RuntimeException()));
    }

    public function test_disabled_without_dsn_and_key(): void
    {
        $this->app['config']->set('faultbox.dsn', null);
        $client = $this->refreshClient();

        $this->assertFalse($client->isEnabled());
        report(new \RuntimeException('nobody listens'));
        $this->assertNull($client->captureException(new \RuntimeException('x')));
        $this->assertNull($client->lastResponse(), 'nothing was attempted');
    }
}
