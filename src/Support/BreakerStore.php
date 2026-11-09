<?php

namespace Faultbox\Laravel\Support;

/**
 * Shares the circuit breaker's state between PHP processes. Implementations
 * must be fast, local and never throw: they are read before every delivery
 * attempt, and a broken store must never break or slow the app. That rules
 * out network stores such as Redis.
 */
interface BreakerStore
{
    /** Unix time until which sending is paused, or 0. */
    public function openUntil(): float;

    public function trip(float $until): void;

    public function clear(): void;
}
