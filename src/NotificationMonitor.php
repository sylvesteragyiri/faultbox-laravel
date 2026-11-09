<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Support\Uuid;

/**
 * Records the Laravel notifications the app sends, one item per notification
 * per channel, and hands them to the client when it flushes (after the
 * response, after each queue job, at shutdown).
 *
 * Only class names are sent: the notification, the channel and the
 * notifiable's type. Never the notifiable's attributes, the recipient's
 * address or the message content.
 *
 * Some channels report failures with NotificationFailed; others, such as
 * mail, throw. A send that started but never confirmed counts as failed when
 * an exception was reported after it began, with that exception as the
 * reason. Otherwise a listener cancelled it, and it isn't counted.
 */
final class NotificationMonitor
{
    /** Items kept per flush at most, so a runaway loop can't use unbounded memory. */
    private const MAX_PER_FLUSH = 2_000;

    /** @var array<string, array{item: array, seq: int}> sends that started but haven't confirmed */
    private array $pending = [];

    /** @var list<array> */
    private array $items = [];

    private int $seq = 0;

    /**
     * Sends that reported NotificationFailed. A channel that fires it and
     * then returns normally still gets a NotificationSent from Laravel,
     * which must not count the same notification again as sent.
     *
     * @var array<string, true>
     */
    private array $failedKeys = [];

    /** @var array{type: string, message: string, seq: int}|null */
    private ?array $lastError = null;

    private int $dropped = 0;

    public function __construct(private readonly EventFactory $events)
    {
    }

    public function sending(object $notifiable, object $notification, string $channel): void
    {
        $this->pending[$this->key($notifiable, $notification, $channel)] = [
            'item' => $this->item($notifiable, $notification, $channel),
            'seq' => ++$this->seq,
        ];
    }

    public function sent(object $notifiable, object $notification, string $channel): void
    {
        $key = $this->key($notifiable, $notification, $channel);
        if (isset($this->failedKeys[$key])) {
            unset($this->failedKeys[$key]);

            return;
        }
        $item = $this->take($notifiable, $notification, $channel);
        $item['status'] = 'sent';
        $this->keep($item);
    }

    /** @param array<string, mixed> $data the event's channel-specific data */
    public function failed(object $notifiable, object $notification, string $channel, array $data): void
    {
        $this->failedKeys[$this->key($notifiable, $notification, $channel)] = true;
        $item = $this->take($notifiable, $notification, $channel);
        $item['status'] = 'failed';
        $error = ['type' => 'NotificationFailed', 'message' => 'The ' . $item['channel'] . ' channel reported a failure.'];
        if (($data['exception'] ?? null) instanceof \Throwable) {
            $error = self::errorOf($data['exception']);
        } elseif (is_string($data['message'] ?? null) && $data['message'] !== '') {
            $error['message'] = mb_strcut($data['message'], 0, 1000);
        }
        $item['error'] = $error;
        $this->keep($item);
    }

    /** An exception was reported: unconfirmed sends that started before it failed because of it. */
    public function noteException(\Throwable $e): void
    {
        $this->lastError = self::errorOf($e) + ['seq' => $this->seq];
    }

    /**
     * Settles unconfirmed sends and returns the items, ready for a
     * telemetry batch.
     *
     * @return list<array>
     */
    public function drainItems(): array
    {
        foreach ($this->pending as $p) {
            if ($this->lastError !== null && $this->lastError['seq'] >= $p['seq']) {
                $item = $p['item'];
                $item['status'] = 'failed';
                $item['error'] = ['type' => $this->lastError['type'], 'message' => $this->lastError['message']];
                $this->keep($item);
            }
        }
        $items = $this->items;
        $this->reset();

        return $items;
    }

    /** Forget everything without sending (Octane request start, fakes). */
    public function reset(): void
    {
        $this->pending = [];
        $this->items = [];
        $this->failedKeys = [];
        $this->lastError = null;
    }

    /** Items dropped because one flush had more than MAX_PER_FLUSH. */
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

    private function take(object $notifiable, object $notification, string $channel): array
    {
        $key = $this->key($notifiable, $notification, $channel);
        $item = $this->pending[$key]['item'] ?? $this->item($notifiable, $notification, $channel);
        unset($this->pending[$key]);

        return $item;
    }

    private function key(object $notifiable, object $notification, string $channel): string
    {
        return spl_object_id($notifiable) . '|' . spl_object_id($notification) . '|' . $channel;
    }

    private function item(object $notifiable, object $notification, string $channel): array
    {
        $item = [
            'id' => Uuid::v7(),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'notification' => get_class($notification),
            'channel' => self::channelName($channel),
            'notifiable' => get_class($notifiable),
            'status' => 'sent',
        ];
        if (($correlation = $this->events->currentCorrelationId()) !== null) {
            $item['correlation_id'] = $correlation;
        }

        return $item;
    }

    /** "mail" stays "mail"; a custom channel class becomes its short name: TelegramChannel -> "telegram". */
    public static function channelName(string $channel): string
    {
        if (str_contains($channel, '\\')) {
            $short = substr($channel, strrpos($channel, '\\') + 1);
            $short = preg_replace('/Channel$/', '', $short) ?: $short;
            $channel = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        }
        $channel = (string) preg_replace('/[^A-Za-z0-9_.:-]/', '-', $channel);

        return substr($channel, 0, 64) ?: 'unknown';
    }

    /** @return array{type: string, message: string} */
    private static function errorOf(\Throwable $e): array
    {
        return ['type' => get_class($e), 'message' => mb_strcut($e->getMessage(), 0, 1000)];
    }
}
