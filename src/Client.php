<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Support\CircuitBreaker;
use Faultbox\Laravel\Transport\FakeTransport;
use Faultbox\Laravel\Transport\Response;
use Faultbox\Laravel\Transport\Transport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The Faultbox client. Everything public here is safe to call anywhere: it
 * never throws, and a tracker outage can't fail a request or a job (PRD §5).
 */
class Client
{
    public const EVENTS_PATH = '/api/v1/events';

    public const QUEUE_PATH = '/api/v1/queue-failures';

    public const TELEMETRY_PATH = '/api/v1/telemetry';

    /** @deprecated The first telemetry endpoint; TELEMETRY_PATH accepts the same body. */
    public const NOTIFICATIONS_PATH = '/api/v1/notifications';

    /** The server's limit per list in one telemetry batch. */
    private const MAX_PER_LIST = 500;

    /** @var list<array{path: string, payload: array}> */
    private array $buffer = [];

    /** Exceptions already reported, so report() + captureException() send once. */
    private \WeakMap $reported;

    /** Exceptions the queue worker will also pass to the exception handler. */
    private \WeakMap $jobExceptions;

    private bool $deferred;

    private bool $sending = false;

    private ?Response $lastResponse = null;

    /**
     * Octane: the context, user and correlation ID as they were before the
     * first request, restored at the start and end of every request so
     * nothing leaks from one request to the next in a long-lived worker.
     */
    private ?array $bootState = null;

    /**
     * A failed delivery that took at least this long (seconds) was a timeout
     * or an unreachable host, so retrying would only cost the same again.
     */
    private const SLOW_FAILURE = 0.5;

    private CircuitBreaker $breaker;

    /** @var \Closure(): float */
    private \Closure $clock;

    private int $droppedByBudget = 0;

    /** Records Laravel notifications; null when notification monitoring is off. */
    private ?NotificationMonitor $notifications = null;

    /** Records mail sent directly; null when mail monitoring is off. */
    private ?MailMonitor $mails = null;

    /** Records artisan commands; null when command monitoring is off. */
    private ?CommandMonitor $commands = null;

    public function __construct(
        private readonly Application $app,
        private readonly EventFactory $events,
        private Transport $transport,
        private bool $enabled,
        private readonly array $config,
        ?CircuitBreaker $breaker = null,
    ) {
        $this->breaker = $breaker ?? new CircuitBreaker((float) ($config['circuit_breaker']['cooldown'] ?? 60));
        $this->clock = static fn (): float => microtime(true);
        if ($enabled) {
            $this->startMonitors();
        }
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
        $this->guard(fn () => $this->noteException($e));
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
     * each queue job, and at shutdown. Never spends more than the flush
     * budget: whatever is left when it runs out is dropped.
     */
    public function flush(): void
    {
        $this->guard(function () {
            // Notifications, mail and commands go out in the same flush, as
            // telemetry batches.
            foreach ($this->telemetryBatches() as $batch) {
                $this->buffer[] = ['path' => self::TELEMETRY_PATH, 'payload' => $batch];
            }
            $buffer = $this->buffer;
            $this->buffer = [];
            $budget = (float) ($this->config['flush_budget'] ?? 3.0);
            $deadline = ($this->clock)() + $budget;
            foreach ($buffer as $i => $item) {
                if ($budget > 0 && ($this->clock)() >= $deadline) {
                    $this->droppedByBudget += count($buffer) - $i;
                    break;
                }
                $this->deliver($item['path'], $item['payload']);
            }
        });
    }

    /**
     * Delivery health in this process: whether the circuit breaker is open,
     * until when, and how many events were dropped to protect the app.
     *
     * @return array{breaker_open: bool, paused_until: ?float, dropped_by_breaker: int, dropped_by_budget: int, dropped_notifications: int, dropped_mails: int, dropped_commands: int}
     */
    public function stats(): array
    {
        return [
            'breaker_open' => $this->breaker->isOpen(),
            'paused_until' => $this->breaker->openUntil(),
            'dropped_by_breaker' => $this->breaker->dropped(),
            'dropped_by_budget' => $this->droppedByBudget,
            'dropped_notifications' => $this->notifications?->dropped() ?? 0,
            'dropped_mails' => $this->mails?->dropped() ?? 0,
            'dropped_commands' => $this->commands?->dropped() ?? 0,
        ];
    }

    /** An exception was reported: the monitors attribute it to whatever it broke. */
    private function noteException(\Throwable $e): void
    {
        $this->notifications?->noteException($e);
        $this->mails?->noteException($e);
        $this->commands?->noteException($e);
    }

    /** @internal The notification monitor, or null when notification monitoring is off. */
    public function notifications(): ?NotificationMonitor
    {
        return $this->notifications;
    }

    /** @internal The mail monitor, or null when mail monitoring is off. */
    public function mails(): ?MailMonitor
    {
        return $this->mails;
    }

    /** @internal The command monitor, or null when command monitoring is off. */
    public function commands(): ?CommandMonitor
    {
        return $this->commands;
    }

    /**
     * @internal Settles the monitors and returns the telemetry batches to
     * send: usually one, more only when a list passes the server's limit.
     *
     * @return list<array>
     */
    public function telemetryBatches(): array
    {
        $lists = [
            'notifications' => array_chunk($this->notifications?->drainItems() ?? [], self::MAX_PER_LIST),
            'mails' => array_chunk($this->mails?->drainItems() ?? [], self::MAX_PER_LIST),
            'commands' => array_chunk($this->commands?->drainItems() ?? [], self::MAX_PER_LIST),
        ];
        $count = max(array_map('count', $lists));
        if ($count === 0) {
            return [];
        }
        $environment = $this->events->batchEnvironment();
        $release = $this->events->batchRelease();
        $batches = [];
        for ($i = 0; $i < $count; $i++) {
            $batch = ['batch_id' => Support\Uuid::v7(), 'environment' => $environment];
            if ($release !== '') {
                $batch['release'] = $release;
            }
            foreach ($lists as $name => $chunks) {
                if (isset($chunks[$i])) {
                    $batch[$name] = $chunks[$i];
                }
            }
            $batches[] = $batch;
        }

        return $batches;
    }

    private function startMonitors(): void
    {
        $config = $this->config;
        $this->notifications = ($config['notifications']['enabled'] ?? true) ? new NotificationMonitor($this->events) : null;
        $this->mails = ($config['mail']['enabled'] ?? true) ? new MailMonitor($this->events) : null;
        $this->commands = ($config['commands']['enabled'] ?? true)
            ? new CommandMonitor((array) ($config['commands']['ignore'] ?? CommandMonitor::DEFAULT_IGNORE))
            : null;
    }

    /** @internal Replaces the circuit breaker (tests, and the service provider). */
    public function setBreaker(CircuitBreaker $breaker): void
    {
        $this->breaker = $breaker;
    }

    /** @internal Replaces the clock used for budgets and slow-failure detection (tests). */
    public function setClock(\Closure $clock): void
    {
        $this->clock = $clock;
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
        $this->startMonitors();
        // A fresh, process-local breaker: a fake must not be paused by an
        // outage recorded in a shared store, or leave one behind.
        $this->breaker = new CircuitBreaker((float) ($this->config['circuit_breaker']['cooldown'] ?? 60));

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

    /**
     * @internal Octane: a request starts in a long-lived worker. Resets the
     * per-request state, scopes lookups to the request's sandbox, and
     * buffers events until the response has been sent.
     */
    public function beginOctaneRequest(?Container $sandbox, ?Request $request): void
    {
        $this->guard(function () use ($sandbox, $request) {
            if ($this->bootState === null) {
                [$context, $user] = $this->events->snapshot();
                $this->bootState = [$context, $user, null];
            }
            $this->buffer = [];
            $this->notifications?->reset();
            $this->mails?->reset();
            $this->events->restore($this->bootState);
            $this->events->useScope($sandbox, $request);
            $this->deferred = true;
        });
    }

    /** @internal Octane: the response has been sent. Delivers the buffer and resets state. */
    public function endOctaneRequest(): void
    {
        $this->guard(function () {
            $this->flush();
            if ($this->bootState !== null) {
                $this->events->restore($this->bootState);
            }
            $this->events->useScope(null, null);
        });
    }

    public function lastResponse(): ?Response
    {
        return $this->lastResponse;
    }

    /**
     * Send a payload synchronously and return the server's answer. Bypasses a
     * paused circuit breaker (faultbox:test uses it to check the setup), and
     * a success resumes sending.
     */
    public function sendNow(string $path, array $payload): Response
    {
        $this->deliver($path, $payload, force: true);

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
        $this->noteException($e);
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

    private function deliver(string $path, array $payload, bool $force = false): void
    {
        if ($this->sending) {
            return; // never report problems that happen while reporting
        }
        // Faultbox failed recently: don't spend another timeout finding out
        // it still does. The event is dropped and counted.
        if (! $force && ! $this->breaker->allows()) {
            $this->lastResponse = new Response(0, error: 'not sent: Faultbox failed recently, so sending is paused (circuit breaker)');

            return;
        }
        $this->sending = true;
        try {
            $retries = max(0, (int) ($this->config['retries'] ?? 1));
            for ($attempt = 0; ; $attempt++) {
                $started = ($this->clock)();
                $response = $this->transport->send($path, $payload);
                $elapsed = ($this->clock)() - $started;
                $this->lastResponse = $response;
                if ($response->accepted()) {
                    $this->breaker->success();

                    return;
                }
                if (! $response->retryable() || $attempt >= $retries) {
                    break;
                }
                // A slow network failure is a timeout or an unreachable host.
                // Retrying would hold the worker for the same time again.
                if ($response->status === 0 && $elapsed >= self::SLOW_FAILURE) {
                    break;
                }
                // Honour Retry-After, but never hold a worker for more than a second.
                usleep((int) (min(max($response->retryAfter ?? 0, 0.2), 1.0) * 1_000_000));
            }
            match (true) {
                // Rate limited: Faultbox is up, so pause only as long as it asks.
                $response->status === 429 => $this->breaker->failure(max(1.0, (float) ($response->retryAfter ?? 5))),
                // Unreachable, timing out or failing: pause for the cooldown.
                $response->status === 0 || $response->status >= 500 => $this->breaker->failure(),
                // It answered (401, 403, 422...): it's up; keep sending.
                default => $this->breaker->success(),
            };
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
