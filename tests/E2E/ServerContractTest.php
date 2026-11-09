<?php

namespace Faultbox\Laravel\Tests\E2E;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Tests\Fixtures\FailingJob;
use Faultbox\Laravel\Tests\Fixtures\ManualFailJob;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Sends real events to a running Faultbox server and checks that each one is
 * accepted. The server validates strictly, so a 202 proves the payload
 * matches the v1 contract.
 *
 *   FAULTBOX_E2E_DSN=http://localhost:8080 FAULTBOX_E2E_KEY=fbx_... vendor/bin/phpunit --testsuite E2E
 */
class ServerContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('FAULTBOX_E2E_DSN') || ! getenv('FAULTBOX_E2E_KEY')) {
            $this->markTestSkipped('Set FAULTBOX_E2E_DSN and FAULTBOX_E2E_KEY to run against a real server.');
        }
        $this->faultboxConfig = [
            'faultbox.dsn' => getenv('FAULTBOX_E2E_DSN'),
            'faultbox.api_key' => getenv('FAULTBOX_E2E_KEY'),
            'faultbox.environment' => getenv('FAULTBOX_E2E_ENVIRONMENT') ?: 'testing',
            'faultbox.release' => 'e2e-' . date('Ymd'),
            'faultbox.retries' => 2,
        ];
        parent::setUp();
    }

    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    private function assertAccepted(string $what): void
    {
        $response = $this->client()->lastResponse();
        $this->assertNotNull($response, "$what: nothing was sent");
        $this->assertSame(202, $response->status, "$what: server answered {$response->status} {$response->body} {$response->error}");
    }

    public function test_exception_from_an_http_request(): void
    {
        Route::post('/checkout/{cart}', fn () => throw new \RuntimeException('Card declined for cart 991'))->name('checkout.store');

        $this->post('/checkout/991?coupon=XMAS&token=abc', ['card' => '4242424242424242'], ['User-Agent' => 'faultbox-e2e'])
            ->assertStatus(500);

        $this->assertAccepted('HTTP exception');
    }

    public function test_message_with_context(): void
    {
        Faultbox::setContext(['tenant' => 'acme', 'nested' => ['deep' => ['deeper' => [1, 2, 3]]]]);
        Faultbox::captureMessage('Payment reconciliation failed', 'warning');

        $this->assertAccepted('message');
    }

    public function test_large_event_is_gzipped_and_accepted(): void
    {
        Faultbox::captureException(new \RuntimeException(str_repeat('long message ', 500)), [
            'blob' => str_repeat('x', 20_000),
        ]);

        $this->assertAccepted('large event');
    }

    public function test_chained_exception(): void
    {
        report(new \RuntimeException('outer', 0, new \PDOException('SQLSTATE[HY000] [2002] Connection refused')));

        $this->assertAccepted('chained exception');
    }

    public function test_terminal_queue_failure(): void
    {
        $this->createJobsTable();
        FailingJob::dispatch('E2E job failure')->onConnection('database');
        $this->work();
        $this->work();

        $this->assertAccepted('terminal queue failure');
    }

    public function test_manual_queue_failure(): void
    {
        $this->createJobsTable();
        ManualFailJob::dispatch()->onConnection('database');
        $this->work();

        $this->assertAccepted('manual queue failure');
    }

    public function test_artisan_command(): void
    {
        $this->artisan('faultbox:test')->assertSuccessful();
    }

    public function test_notifications_batch_is_accepted_once(): void
    {
        require_once __DIR__ . '/../Fixtures/Notifications.php';
        $this->app['config']->set('mail.default', 'array');
        \Illuminate\Support\Facades\Notification::route('mail', 'customer@example.com')
            ->notifyNow(new \Faultbox\Laravel\Tests\Fixtures\InvoicePaid());
        $batches = $this->client()->telemetryBatches();
        $this->assertCount(1, $batches);

        $first = $this->client()->sendNow(Client::TELEMETRY_PATH, $batches[0]);
        $this->assertSame(202, $first->status, "notifications: {$first->status} {$first->body}");
        $this->assertStringContainsString('"status":"accepted"', $first->body);
        // A retry of the same batch is acknowledged, not counted again.
        $again = $this->client()->sendNow(Client::TELEMETRY_PATH, $batches[0]);
        $this->assertStringContainsString('"status":"duplicate"', $again->body);
    }

    public function test_mail_and_commands_are_accepted(): void
    {
        require_once __DIR__ . '/../Fixtures/Mail.php';
        $this->app['config']->set('mail.default', 'array');
        $this->app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->rerouteSymfonyCommandEvents();
        \Illuminate\Support\Facades\Artisan::command('app:e2e-sync', fn () => 0);

        \Illuminate\Support\Facades\Mail::to('customer@example.com')->send(new \Faultbox\Laravel\Tests\Fixtures\OrderShipped());
        \Illuminate\Support\Facades\Mail::raw('Plain', fn ($m) => $m->to('ops@example.com'));
        \Illuminate\Support\Facades\Artisan::call('app:e2e-sync');
        $batches = $this->client()->telemetryBatches();
        $this->assertCount(1, $batches);
        $this->assertCount(2, $batches[0]['mails']);
        $this->assertCount(1, $batches[0]['commands']);

        $response = $this->client()->sendNow(Client::TELEMETRY_PATH, $batches[0]);
        $this->assertSame(202, $response->status, "telemetry: {$response->status} {$response->body}");
        $this->assertStringContainsString('"count":3', $response->body);
    }

    public function test_wrong_key_is_rejected_without_throwing(): void
    {
        $this->app['config']->set('faultbox.api_key', 'fbx_' . str_repeat('A', 43));
        $client = $this->refreshClient();

        report(new \RuntimeException('with a revoked key'));

        $this->assertSame(401, $client->lastResponse()?->status);
        $this->assertSame('invalid_api_key', $client->lastResponse()?->errorCode());
    }
}
