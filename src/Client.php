<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Transport\FakeTransport;
use Faultbox\Laravel\Transport\Response;
use Faultbox\Laravel\Transport\Transport;
use Illuminate\Contracts\Foundation\Application;

/**
 * The Faultbox client. Everything public here is safe to call anywhere: it
 * never throws, and a tracker outage can't fail a request or a job (PRD §5).
 */
class Client
{
    public const EVENTS_PATH = '/api/v1/events';

    public const QUEUE_PATH = '/api/v1/queue-failures';

    /** @var list<array{path: string, payload: array}> */
    private array $buffer = [];

    /** Exceptions already reported, so report() + captureException() send once. */
    private \WeakMap $reported;

    /** Exceptions the queue worker will also pass to the exception handler. */
    private \WeakMap $jobExceptions;

    private bool $deferred;

    private bool $sending = false;

    private ?Response $lastResponse = null;

    public function __construct(
        private readonly Application $app,
        private readonly EventFactory $events,
        private Transport $transport,
        private bool $enabled,
        private readonly array $config,
    ) {
        $this->reported = new \WeakMap();
        $this->jobExceptions = new \WeakMap();
        // In a web request, send after the response; elsewhere, send at once.
        $this->deferred = ! $app->runningInConsole();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Report an exception. Returns the event ID, or null if nothing was sent.
     */
    public function captureException(\Throwable $e, array $context = []): ?string
    {
        return $this->guard(function () use ($e, $context) {
            if (! $this->enabled || isset($this->reported[$e]) || $this->ignored($e)) {
                return null;
            }
            $this->reported[$e] = true;
            $payload = $this->events->exception($e, $context);
            $this->send(self::EVENTS_PATH, $payload);

            return $payload['event_id'];
        });
    }

    /**
     * Report a message, e.g. Faultbox::captureMessage('Payment reconciliation failed', 'error').
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): ?string
    {
        return $this->guard(function () use ($message, $level, $context) {
            if (! $this->enabled) {
                return null;
            }
            $level = strtolower($level);
            if (! in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)) {
                $level = 'info';
            }
            $payload = $this->events->message($message, $level, $context);
            $this->send(self::EVENTS_PATH, $payload);

            return $payload['event_id'];
        });
    }

    /**
     * Called by the exception handler for every reported exception.
     */
    public function reportFromHandler(\Throwable $e): void
    {
        if (isset($this->jobExceptions[$e]) && empty($this->config['queue']['report_job_exceptions'])) {
            return; // already reported as a queue failure (or a retried attempt)
        }
        $this->captureException($e);
    }

    /** Merge data into the context sent with every following event. */
    public function setContext(array $context): void
    {
        $this->guard(fn () => $this->events->setContext($context));
    }

    /** Override the user context, e.g. ['id' => '42']. Pass null to reset. */
    public function setUser(?array $user): void
    {
        $this->guard(fn () => $this->events->setUser($user));
    }

    public function clearContext(): void
    {
        $this->guard(fn () => $this->events->clearContext());
    }

    /**
     * Send buffered events now. Runs automatically after each request, after
     * each queue job, and at shutdown.
     */
    public function flush(): void
    {
        $this->guard(function () {
            $buffer = $this->buffer;
            $this->buffer = [];
            foreach ($buffer as $item) {
                $this->deliver($item['path'], $item['payload']);
            }
        });
    }

    /**
     * Replace the transport with a fake that records events. For tests:
     *
     *     $fake = Faultbox::fake();
     *     // ...
     *     $fake->assertExceptionSent(PaymentFailed::class);
     */
    public function fake(int|\Closure $respond = 202): FakeTransport
    {
        $fake = new FakeTransport($respond);
        $this->transport = $fake;
        $this->enabled = true;
        $this->deferred = false;
        $this->buffer = [];

        return $fake;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function setDeferred(bool $deferred): void
    {
        $this->deferred = $deferred;
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /** Send a payload synchronously and return the server's answer. */
    public function sendNow(string $path, array $payload): Response
    {
        $this->deliver($path, $payload);

        return $this->lastResponse ?? new Response(0, error: 'not sent');
    }

    // ---- internal, used by QueueMonitor and EventFactory ----

    /** @internal */
    public function events(): EventFactory
    {
        return $this->events;
    }

    /** @internal Marks an exception the worker will pass to the handler again. */
    public function markJobException(\Throwable $e): void
    {
        $this->jobExceptions[$e] = true;
    }

    /** @internal */
    public function sendQueueFailure(array $payload): void
    {
        $this->guard(function () use ($payload) {
            if ($this->enabled) {
                $this->send(self::QUEUE_PATH, $payload);
            }
        });
    }

    private function ignored(\Throwable $e): bool
    {
        foreach ($this->config['ignore_exceptions'] ?? [] as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function send(string $path, array $payload): void
    {
        if (! $this->deferred) {
            $this->deliver($path, $payload);

            return;
        }
        if (count($this->buffer) < (int) ($this->config['max_events_per_request'] ?? 50)) {
            $this->buffer[] = ['path' => $path, 'payload' => $payload];
        }
    }

    private function deliver(string $path, array $payload): void
    {
        if ($this->sending) {
            return; // never report problems that happen while reporting
        }
        $this->sending = true;
        try {
            $retries = max(0, (int) ($this->config['retries'] ?? 1));
            for ($attempt = 0; ; $attempt++) {
                $response = $this->transport->send($path, $payload);
                $this->lastResponse = $response;
                if ($response->accepted() || ! $response->retryable() || $attempt >= $retries) {
                    break;
                }
                // Honour Retry-After, but never hold a worker for more than a second.
                usleep((int) (min(max($response->retryAfter ?? 0, 0.2), 1.0) * 1_000_000));
            }
        } finally {
            $this->sending = false;
        }
    }

    /**
     * Runs $fn and swallows anything it throws. A broken tracker must never
     * break the application.
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if (! empty($this->config['debug'])) {
                error_log('[faultbox] ' . get_class($e) . ': ' . $e->getMessage());
            }

            return null;
        }
    }
}
