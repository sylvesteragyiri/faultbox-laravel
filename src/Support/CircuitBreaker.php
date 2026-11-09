<?php

namespace Faultbox\Laravel\Support;

/**
 * Stops delivery for a cooldown after Faultbox fails to answer, so an outage
 * costs the app at most one timeout per cooldown instead of one per event.
 *
 * - Closed: events are sent.
 * - Open: after a network failure, timeout or 5xx, nothing is sent until
 *   the cooldown ends. Events are dropped and counted.
 * - After the cooldown, the next event is a trial: success closes the
 *   breaker, failure opens it again.
 *
 * The state is kept in memory, which covers long-lived Octane and queue
 * workers, and in an optional shared store, so PHP-FPM processes that each
 * live for a few requests also learn that Faultbox is down.
 */
final class CircuitBreaker
{
    private float $openUntil = 0.0;

    private int $dropped = 0;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param  float  $cooldown  seconds to stop sending after a failure; 0 turns the breaker off
     * @param  (\Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly float $cooldown = 60.0,
        private readonly ?BreakerStore $store = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Whether a delivery may be attempted now. Counts the drop when not. */
    public function allows(): bool
    {
        if ($this->cooldown <= 0) {
            return true;
        }
        $shared = $this->store?->openUntil() ?? 0.0;
        $this->openUntil = max($this->openUntil, $shared);
        if (($this->clock)() < $this->openUntil) {
            $this->dropped++;

            return false;
        }

        return true;
    }

    /** Faultbox answered: close the breaker. */
    public function success(): void
    {
        if ($this->openUntil > 0.0) {
            $this->openUntil = 0.0;
            $this->store?->clear();
        }
    }

    /**
     * Faultbox failed: stop sending for $seconds, or for the cooldown when
     * null (outages: network failures, timeouts, 5xx).
     */
    public function failure(?float $seconds = null): void
    {
        if ($this->cooldown <= 0) {
            return;
        }
        $this->openUntil = ($this->clock)() + max(0.0, $seconds ?? $this->cooldown);
        $this->store?->trip($this->openUntil);
    }

    public function isOpen(): bool
    {
        return $this->cooldown > 0 && ($this->clock)() < max($this->openUntil, $this->store?->openUntil() ?? 0.0);
    }

    /** Unix time when sending resumes, or null when closed. */
    public function openUntil(): ?float
    {
        return $this->isOpen() ? max($this->openUntil, $this->store?->openUntil() ?? 0.0) : null;
    }

    /** Events dropped by this process while the breaker was open. */
    public function dropped(): int
    {
        return $this->dropped;
    }
}
