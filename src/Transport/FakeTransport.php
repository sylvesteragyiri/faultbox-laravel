<?php

namespace Faultbox\Laravel\Transport;

use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Records payloads instead of sending them. Returned by Faultbox::fake() for
 * use in your own application's tests.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{path: string, payload: array}> */
    private array $sent = [];

    /** @param int|\Closure(string, array): Response $respond */
    public function __construct(private int|\Closure $respond = 202)
    {
    }

    public function send(string $path, array $payload): Response
    {
        $this->sent[] = ['path' => $path, 'payload' => $payload];

        return $this->respond instanceof \Closure
            ? ($this->respond)($path, $payload)
            : new Response($this->respond, '{"status":"accepted"}');
    }

    /** @return list<array> payloads, optionally only those sent to $path */
    public function sent(?string $path = null): array
    {
        return array_values(array_map(
            static fn (array $s) => $s['payload'],
            array_filter($this->sent, static fn (array $s) => $path === null || $s['path'] === $path),
        ));
    }

    /** @return list<array> exception and message events */
    public function events(): array
    {
        return $this->sent('/api/v1/events');
    }

    /** @return list<array> queue failure events */
    public function queueFailures(): array
    {
        return $this->sent('/api/v1/queue-failures');
    }

    /** @return list<array> notification batches */
    public function notificationBatches(): array
    {
        return $this->sent('/api/v1/notifications');
    }

    /** @return list<array> every notification item, across batches */
    public function notifications(): array
    {
        return array_merge([], ...array_map(static fn (array $b) => $b['notifications'] ?? [], $this->notificationBatches()));
    }

    public function assertSent(?callable $filter = null, ?int $times = null): void
    {
        $matching = array_filter($this->sent(), $filter ?? static fn () => true);
        $times === null
            ? PHPUnit::assertNotEmpty($matching, 'Expected Faultbox to send an event, but none matched.')
            : PHPUnit::assertCount($times, $matching, "Expected Faultbox to send {$times} matching event(s).");
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertEmpty($this->sent, 'Faultbox sent ' . count($this->sent) . ' event(s): ' . json_encode(array_column($this->sent, 'path')));
    }

    public function assertExceptionSent(string $class): void
    {
        $this->assertSent(static fn (array $p) => ($p['exception']['type'] ?? null) === $class);
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
