<?php

namespace Faultbox\Laravel\Tests\Feature;

require_once __DIR__ . '/../Fixtures/Notifications.php';
require_once __DIR__ . '/../Fixtures/OctaneEvents.php';

use Faultbox\Laravel\Client;
use Faultbox\Laravel\NotificationMonitor;
use Faultbox\Laravel\Tests\Fixtures\InvoicePaid;
use Faultbox\Laravel\Tests\Fixtures\PushAlert;
use Faultbox\Laravel\Tests\Fixtures\SmsAlert;
use Faultbox\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;

class NotificationMonitorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('faultbox.release', '1.4.2');
    }

    private function client(): Client
    {
        return $this->app->make(Client::class);
    }

    public function test_sent_notifications_are_batched_until_the_flush(): void
    {
        $fake = $this->fake();
        $this->client()->setDeferred(true);

        Notification::route('mail', 'customer@example.com')->notifyNow(new InvoicePaid());

        $this->assertSame([], $fake->notificationBatches(), 'nothing is sent before the flush');
        $this->client()->flush();

        $batches = $fake->notificationBatches();
        $this->assertCount(1, $batches, 'one request for the whole flush');
        $batch = $batches[0];
        $this->assertUuidV7($batch['batch_id']);
        $this->assertSame('testing', $batch['environment']);
        $this->assertSame('1.4.2', $batch['release']);

        $items = $batch['notifications'];
        $this->assertSame(['mail', 'recording'], array_column($items, 'channel'), 'one item per channel; a channel class becomes its short name');
        foreach ($items as $item) {
            $this->assertSame(InvoicePaid::class, $item['notification']);
            $this->assertSame(AnonymousNotifiable::class, $item['notifiable']);
            $this->assertSame('sent', $item['status']);
            $this->assertUuidV7($item['id']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $item['timestamp']);
        }
        $this->assertStringNotContainsString('customer@example.com', json_encode($batch), 'recipients are never sent');
    }

    public function test_a_channel_that_throws_is_a_failure_with_its_exception(): void
    {
        $fake = $this->fake();
        try {
            Notification::route('vonage', '+15550100')->notifyNow(new SmsAlert());
            $this->fail('the channel should throw');
        } catch (\RuntimeException $e) {
            report($e); // what Laravel's handler does with an uncaught exception
        }
        $this->client()->flush();

        $items = $fake->notifications();
        $this->assertCount(1, $items);
        $this->assertSame('failed', $items[0]['status']);
        $this->assertSame('throwing_sms', $items[0]['channel']);
        $this->assertSame(['type' => \RuntimeException::class, 'message' => 'SMS gateway returned 503'], $items[0]['error']);
    }

    public function test_notification_failed_events_are_failures(): void
    {
        $fake = $this->fake();
        Notification::route('push', 'token')->notifyNow(new PushAlert());
        $this->client()->flush();

        $items = $fake->notifications();
        $this->assertCount(1, $items, 'NotificationFailed, then no NotificationSent: counted once');
        $this->assertSame('failed', $items[0]['status']);
        $this->assertSame(\DomainException::class, $items[0]['error']['type']);
        $this->assertSame('Device token is no longer registered', $items[0]['error']['message']);
    }

    public function test_a_send_cancelled_by_a_listener_is_not_counted(): void
    {
        $fake = $this->fake();
        Event::listen(NotificationSending::class, fn () => false);
        Notification::route('mail', 'customer@example.com')->notifyNow(new InvoicePaid());
        $this->client()->flush();

        $this->assertSame([], $fake->notifications(), 'cancelled, not failed');
    }

    public function test_it_can_be_turned_off(): void
    {
        $this->app['config']->set('faultbox.notifications.enabled', false);
        $client = $this->refreshClient();
        $fake = $client->fake();
        $this->assertNull($client->notifications());

        Notification::route('mail', 'customer@example.com')->notifyNow(new InvoicePaid());
        $client->flush();
        $this->assertSame([], $fake->notificationBatches());
    }

    public function test_octane_requests_flush_their_own_notifications(): void
    {
        $fake = $this->fake();
        $request = Request::create('https://shop.test/checkout', 'POST', server: ['HTTP_X_REQUEST_ID' => 'req-42']);
        $this->app['events']->dispatch(new RequestReceived($this->app, $this->app, $request));
        Notification::route('mail', 'customer@example.com')->notifyNow(new InvoicePaid());
        $this->assertSame([], $fake->notificationBatches(), 'sent after the response');
        $this->app['events']->dispatch(new RequestTerminated($this->app, $this->app, $request));

        $items = $fake->notifications();
        $this->assertCount(2, $items);
        $this->assertSame('req-42', $items[0]['correlation_id'], 'linked to the request that sent it');
    }

    public function test_channel_names_are_normalized(): void
    {
        $this->assertSame('mail', NotificationMonitor::channelName('mail'));
        $this->assertSame('telegram', NotificationMonitor::channelName('NotificationChannels\\Telegram\\TelegramChannel'));
        $this->assertSame('web_push', NotificationMonitor::channelName('NotificationChannels\\WebPush\\WebPushChannel'));
        $this->assertSame(64, strlen(NotificationMonitor::channelName(str_repeat('x', 100))));
    }
}
