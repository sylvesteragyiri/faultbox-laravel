<?php

namespace Faultbox\Laravel\Support;

/** Breaker state shared by every PHP-FPM worker in the pool, in APCu. */
final class ApcuBreakerStore implements BreakerStore
{
    public function __construct(private readonly string $key)
    {
    }

    public function openUntil(): float
    {
        $value = @apcu_fetch($this->name());

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function trip(float $until): void
    {
        @apcu_store($this->name(), $until, (int) ceil(max(1.0, $until - microtime(true))) + 1);
    }

    public function clear(): void
    {
        @apcu_delete($this->name());
    }

    private function name(): string
    {
        return 'faultbox_breaker_' . $this->key;
    }
}
