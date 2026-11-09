<?php

namespace Faultbox\Laravel\Support;

/**
 * Pools cache totals across every PHP process on the machine (or container)
 * in one small file, so they're sent at most once per interval instead of
 * once per request. PHP-FPM keeps nothing between requests, so without this
 * a cached session would make every request send a batch.
 *
 * Each flush adds its totals under an exclusive lock: one local file read
 * and write, after the response. The flush that finds the pool older than
 * the interval takes everything and sends it.
 */
final class CachePool
{
    /** Distinct store and pattern pairs in the pool; the rest go under (other). */
    private const MAX_KEYS = 500;

    private const COUNTERS = ['hits', 'misses', 'writes', 'deletes', 'failures'];

    public function __construct(
        private readonly string $path,
        private readonly float $interval,
        private readonly string $other,
    ) {
    }

    /**
     * Adds totals to the pool. Returns the whole pool when it's due (and
     * empties it), an empty list otherwise.
     *
     * @param  list<array{store: string, key: string, hits: int, misses: int, writes: int, deletes: int, failures: int}>  $counts
     * @return list<array{store: string, key: string, hits: int, misses: int, writes: int, deletes: int, failures: int}>
     *
     * @throws \RuntimeException when the pool file can't be used
     */
    public function add(array $counts, float $now): array
    {
        if ($this->interval <= 0) {
            return $counts; // pooling off: send with every flush
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('The cache pool file cannot be opened.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('The cache pool file cannot be locked.');
            }
            $pool = json_decode((string) stream_get_contents($handle), true);
            if (! is_array($pool) || ! is_array($pool['counts'] ?? null)) {
                $pool = ['since' => $now, 'counts' => []];
            }
            foreach ($counts as $count) {
                $this->merge($pool['counts'], $count);
            }
            $due = $now - (float) $pool['since'] >= $this->interval || $now < (float) $pool['since'];
            $out = [];
            if ($due) {
                $out = array_values($pool['counts']);
                $pool = ['since' => $now, 'counts' => []];
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($pool));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $out;
        } finally {
            fclose($handle);
        }
    }

    private function merge(array &$pool, array $count): void
    {
        $id = $count['store'] . "\0" . $count['key'];
        if (! isset($pool[$id]) && count($pool) >= self::MAX_KEYS) {
            $count['key'] = $this->other;
            $id = $count['store'] . "\0" . $count['key'];
        }
        if (! isset($pool[$id])) {
            $pool[$id] = ['store' => $count['store'], 'key' => $count['key']] + array_fill_keys(self::COUNTERS, 0);
        }
        foreach (self::COUNTERS as $counter) {
            $pool[$id][$counter] += (int) ($count[$counter] ?? 0);
        }
    }

    /** Where a pool lives: one file per Faultbox server, project key and environment. */
    public static function defaultPath(string $key): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'faultbox-cache-' . $key . '.json';
    }
}
