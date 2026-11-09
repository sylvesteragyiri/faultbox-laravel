<?php

namespace Faultbox\Laravel\Tests\Feature;

require_once __DIR__ . '/../Fixtures/Mail.php';
require_once __DIR__ . '/../Fixtures/Notifications.php';

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Tests\Fixtures\InvoicePaid;
use Faultbox\Laravel\Tests\Fixtures\OrderShipped;
use Faultbox\Laravel\Tests\Fixtures\RejectingTransport;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;

class MailMonitorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('mail.mailers.broken', ['transport' => 'rejecting']);
        $app['config']->set('faultbox.release', '1.4.2');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::extend('rejecting', fn () => new RejectingTransport());
    }

    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    public function test_a_mailable_is_recorded_without_addresses(): void
    {
        $fake = $this->fake();
        Mail::to(['a@example.com', 'b@example.com'])->cc('c@example.com')->send(new OrderShipped());
        $this->client()->flush();

        $batches = $fake->telemetryBatches();
        $this->assertCount(1, $batches);
        $this->assertUuidV7($batches[0]['batch_id']);
        $this->assertSame('testing', $batches[0]['environment']);
        $this->assertSame('1.4.2', $batches[0]['release']);

        $mails = $fake->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(OrderShipped::class, $mails[0]['mailable']);
        $this->assertSame('array', $mails[0]['mailer']);
        $this->assertSame(3, $mails[0]['recipients']);
        $this->assertSame('sent', $mails[0]['status']);
        $this->assertUuidV7($mails[0]['id']);
        $json = json_encode($batches);
        $this->assertStringNotContainsString('example.com', $json, 'addresses are never sent');
        $this->assertStringNotContainsString('Your order shipped', $json, 'subjects are never sent');
    }

    public function test_raw_mail_has_no_mailable(): void
    {
        $fake = $this->fake();
        Mail::raw('Plain text', fn ($m) => $m->to('ops@example.com'));
        $this->client()->flush();

        $mails = $fake->mails();
        $this->assertCount(1, $mails);
        $this->assertArrayNotHasKey('mailable', $mails[0], 'the server files it under Raw mail');
        $this->assertSame(1, $mails[0]['recipients']);
    }

    public function test_a_transport_failure_is_recorded_with_its_exception(): void
    {
        $fake = $this->fake();
        try {
            Mail::mailer('broken')->to('a@example.com')->send(new OrderShipped());
            $this->fail('the transport should throw');
        } catch (TransportException $e) {
            report($e);
        }
        $this->client()->flush();

        $mails = $fake->mails();
        $this->assertCount(1, $mails);
        $this->assertSame('failed', $mails[0]['status']);
        $this->assertSame('broken', $mails[0]['mailer']);
        $this->assertSame(TransportException::class, $mails[0]['error']['type']);
        $this->assertStringContainsString('550', $mails[0]['error']['message']);
    }

    public function test_notification_mail_is_left_to_the_notification_monitor(): void
    {
        $fake = $this->fake();
        Notification::route('mail', 'a@example.com')->notifyNow(new InvoicePaid());
        $this->client()->flush();

        $this->assertSame([], $fake->mails(), 'counted once, under App notifications');
        $this->assertNotEmpty($fake->notifications());
        $this->assertCount(1, $fake->telemetryBatches(), 'notifications and mail share one batch');
    }

    public function test_a_send_cancelled_by_a_listener_is_not_recorded(): void
    {
        $fake = $this->fake();
        $cancel = true;
        Event::listen(MessageSending::class, function () use (&$cancel) {
            return $cancel ? false : null;
        });
        Mail::to('a@example.com')->send(new OrderShipped());
        $cancel = false;
        Mail::to('b@example.com')->send(new OrderShipped());
        $this->client()->flush();

        $this->assertCount(1, $fake->mails(), 'only the send that happened');
    }

    public function test_mail_monitoring_can_be_turned_off(): void
    {
        $this->app['config']->set('faultbox.mail.enabled', false);
        $client = $this->refreshClient();
        $fake = $client->fake();
        Mail::to('a@example.com')->send(new OrderShipped());
        $client->flush();

        $this->assertNull($client->mails());
        $this->assertSame([], $fake->telemetryBatches());
    }
}
