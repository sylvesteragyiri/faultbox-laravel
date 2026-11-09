<?php

namespace Faultbox\Laravel\Support;

/**
 * Breaker state shared by every process on the machine (or container)
 * through one tiny file in the temp directory. Reading it is a local
 * filesystem call, and it is only read when there is an event to send.
 */
final class FileBreakerStore implements BreakerStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function openUntil(): float
    {
        if (! @is_file($this->path)) {
            return 0.0;
        }
        $value = @file_get_contents($this->path);

        return is_string($value) && is_numeric(trim($value)) ? (float) trim($value) : 0.0;
    }

    public function trip(float $until): void
    {
        @file_put_contents($this->path, sprintf('%.3F', $until), LOCK_EX);
    }

    public function clear(): void
    {
        if (@is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
