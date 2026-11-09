<?php

namespace Faultbox\Laravel;

/**
 * Counts what the app does with its cache: hits, misses, writes, deletes and
 * failed writes or deletes, per store and key pattern. Only totals leave the
 * app, pooled across processes and sent at most once a minute
 * (Support\CachePool); never one request per operation, never values.
 *
 * Keys are reduced to patterns before they're counted, so IDs, emails,
 * UUIDs and hashes never leave the app and the number of patterns stays
 * small: users:42:profile becomes users:*:profile.
 */
final class CacheMonitor
{
    /** Distinct patterns per flush; the rest are counted under OTHER. */
    private const MAX_KEYS = 200;

    public const OTHER = '(other)';

    /** Laravel's own bookkeeping keys, read on every worker loop or scheduler tick. */
    public const DEFAULT_IGNORE = [
        'illuminate:*', 'framework/schedule*', 'laravel:pulse:*', 'telescope:*', 'faultbox*',
    ];

    /** @var array<string, array{store: string, key: string, hits: int, misses: int, writes: int, deletes: int, failures: int}> */
    private array $counts = [];

    /** @var array<string, string> raw key => pattern, so busy keys are normalized once */
    private array $patterns = [];

    /** @param list<string> $ignore raw key patterns; * is a wildcard */
    public function __construct(private readonly array $ignore = [])
    {
    }

    /** @param 'hits'|'misses'|'writes'|'deletes'|'failures' $counter */
    public function record(string $counter, ?string $store, mixed $key): void
    {
        if (! is_string($key) || $key === '') {
            return;
        }
        $pattern = $this->pattern($key);
        if ($pattern === null) {
            return;
        }
        $store = self::storeName($store);
        $id = $store . "\0" . $pattern;
        if (! isset($this->counts[$id])) {
            if (count($this->counts) >= self::MAX_KEYS) {
                $pattern = self::OTHER;
                $id = $store . "\0" . $pattern;
            }
            $this->counts[$id] ??= ['store' => $store, 'key' => $pattern, 'hits' => 0, 'misses' => 0, 'writes' => 0, 'deletes' => 0, 'failures' => 0];
        }
        $this->counts[$id][$counter]++;
    }

    /**
     * The totals since the last drain, one per store and pattern. The client
     * pools them (Support\CachePool) before sending.
     *
     * @return list<array{store: string, key: string, hits: int, misses: int, writes: int, deletes: int, failures: int}>
     */
    public function drainCounts(): array
    {
        $counts = array_values($this->counts);
        $this->counts = [];
        if (count($this->patterns) > 5_000) {
            $this->patterns = []; // long-lived workers: keep the memo bounded
        }

        return $counts;
    }

    /** The key's pattern, or null when it's ignored. */
    private function pattern(string $key): ?string
    {
        if (array_key_exists($key, $this->patterns)) {
            return $this->patterns[$key];
        }
        $pattern = null;
        if (! $this->ignored($key)) {
            $pattern = self::normalize($key);
        }
        if (count($this->patterns) < 5_000) {
            $this->patterns[$key] = $pattern;
        }

        return $pattern;
    }

    private function ignored(string $key): bool
    {
        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces the parts of a key that identify someone or something with *:
     * emails, UUIDs, long tokens and hashes that contain a digit, and
     * numbers. Words stay, so the pattern still says what's cached.
     */
    public static function normalize(string $key): string
    {
        $key = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $key);
        $key = (string) preg_replace('/[^\s:\/|@]+@[^\s:\/|@]+/', '*', $key);
        $key = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '*', $key);
        $key = (string) preg_replace_callback('/[A-Za-z0-9+=]{16,}/', static fn (array $m) => preg_match('/\d/', $m[0]) ? '*' : $m[0], $key);
        $key = (string) preg_replace('/(?<![A-Za-z])\d+(?![A-Za-z])/', '*', $key);
        $key = (string) preg_replace('/\*{2,}/', '*', $key);
        $key = mb_strcut($key, 0, 200);

        return $key === '' ? '*' : $key;
    }

    private static function storeName(?string $store): string
    {
        $store = is_string($store) && $store !== '' ? $store : 'default';

        return substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '-', $store), 0, 64);
    }
}
