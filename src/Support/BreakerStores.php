<?php

namespace Faultbox\Laravel\Support;

/** Picks a breaker store. See config('faultbox.circuit_breaker.store'). */
final class BreakerStores
{
    /**
     * @param  string  $mode  auto, apcu, file or memory
     * @param  string  $key  identifies the Faultbox server (derived from the DSN)
     */
    public static function make(string $mode, string $key): ?BreakerStore
    {
        $apcu = function_exists('apcu_enabled') && apcu_enabled();

        return match ($mode) {
            'memory' => null,
            'apcu' => $apcu ? new ApcuBreakerStore($key) : null,
            'file' => new FileBreakerStore(self::path($key)),
            // auto: APCu is shared by a PHP-FPM pool; the CLI usually has it
            // turned off, so queue workers and Octane fall back to the file.
            default => $apcu ? new ApcuBreakerStore($key) : new FileBreakerStore(self::path($key)),
        };
    }

    private static function path(string $key): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'faultbox-breaker-' . $key;
    }
}
