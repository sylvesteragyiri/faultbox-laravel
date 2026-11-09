<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Support\Uuid;

/**
 * Records the email the app sends directly: Mailables (Mail::to()->send(),
 * queue()) and Mail::raw. Mail sent by a notification's mail channel is left
 * to NotificationMonitor, so nothing is counted twice.
 *
 * Only the Mailable's class, the mailer's name and the number of recipients
 * are sent. Never addresses, subjects or content.
 *
 * Symfony clones the message before sending, so MessageSent doesn't carry
 * the object MessageSending did. Sends don't overlap, though: each
 * MessageSent completes the most recent MessageSending. A send that never
 * completes failed if an exception was reported after it started (the
 * transport threw), and was cancelled by a listener otherwise.
 */
final class MailMonitor
{
    private const MAX_PER_FLUSH = 2_000;

    /** @var list<array{item: array, seq: int}|null> started sends; null for notification mail */
    private array $pending = [];

    /** @var list<array> */
    private array $items = [];

    private int $seq = 0;

    /** @var array{type: string, message: string, seq: int}|null */
    private ?array $lastError = null;

    private int $dropped = 0;

    public function __construct(private readonly EventFactory $events)
    {
    }

    /** @param array<string, mixed> $data the event's view data */
    public function sending(object $message, array $data): void
    {
        if (isset($data['__laravel_notification'])) {
            $this->pending[] = null; // a notification: NotificationMonitor counts it

            return;
        }
        $this->pending[] = ['item' => $this->item($message, $data), 'seq' => ++$this->seq];
    }

    public function sent(): void
    {
        $started = array_pop($this->pending);
        if ($started === null) {
            return;
        }
        $item = $started['item'];
        $item['status'] = 'sent';
        $this->keep($item);
    }

    public function noteException(\Throwable $e): void
    {
        $this->lastError = ['type' => get_class($e), 'message' => mb_strcut($e->getMessage(), 0, 1000), 'seq' => $this->seq];
    }

    /** @return list<array> settled items, ready for a telemetry batch */
    public function drainItems(): array
    {
        foreach ($this->pending as $started) {
            if ($started !== null && $this->lastError !== null && $this->lastError['seq'] >= $started['seq']) {
                $item = $started['item'];
                $item['status'] = 'failed';
                $item['error'] = ['type' => $this->lastError['type'], 'message' => $this->lastError['message']];
                $this->keep($item);
            }
        }
        $items = $this->items;
        $this->reset();

        return $items;
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->items = [];
        $this->lastError = null;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    private function keep(array $item): void
    {
        if (count($this->items) >= self::MAX_PER_FLUSH) {
            $this->dropped++;

            return;
        }
        $this->items[] = $item;
    }

    private function item(object $message, array $data): array
    {
        $item = [
            'id' => Uuid::v7(),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'mailer' => self::mailerName($data['mailer'] ?? null),
            'recipients' => self::recipients($message),
            'status' => 'sent',
        ];
        if (is_string($data['__laravel_mailable'] ?? null)) {
            $item['mailable'] = $data['__laravel_mailable'];
        }
        if (($correlation = $this->events->currentCorrelationId()) !== null) {
            $item['correlation_id'] = $correlation;
        }

        return $item;
    }

    private static function mailerName(mixed $name): string
    {
        $name = is_string($name) && $name !== '' ? $name : 'default';

        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '-', $name), 0, 64);
    }

    /** To, Cc and Bcc, counted. Addresses never leave this method. */
    private static function recipients(object $message): int
    {
        $count = 0;
        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            if (method_exists($message, $method)) {
                $count += count((array) $message->{$method}());
            }
        }

        return $count;
    }
}
