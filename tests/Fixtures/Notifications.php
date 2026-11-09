<?php

// Notifications and channels for the notification monitoring tests.

namespace Faultbox\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail', RecordingChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Invoice paid')->line('Thanks!');
    }
}

class SmsAlert extends Notification
{
    public function via(object $notifiable): array
    {
        return [ThrowingSmsChannel::class];
    }
}

class PushAlert extends Notification
{
    public function via(object $notifiable): array
    {
        return [BouncingPushChannel::class];
    }
}

/** Accepts everything. */
class RecordingChannel
{
    public static int $sent = 0;

    public function send(object $notifiable, Notification $notification): void
    {
        self::$sent++;
    }
}

/** Throws, like the mail channel when SMTP is down. */
class ThrowingSmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        throw new \RuntimeException('SMS gateway returned 503');
    }
}

/** Reports a failure with NotificationFailed instead of throwing. */
class BouncingPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        event(new NotificationFailed($notifiable, $notification, self::class, [
            'exception' => new \DomainException('Device token is no longer registered'),
        ]));
    }
}
