<?php

namespace Faultbox\Laravel\Support;

/**
 * Redacts secrets before anything leaves the application (PRD §5, §8).
 * The server repeats a similar pass, but data should never leave in the
 * first place.
 */
final class Sanitizer
{
    public const REDACTED = '[redacted]';

    private const MAX_DEPTH = 10;

    public function __construct(private readonly string $pattern)
    {
    }

    public function isSensitive(string $key): bool
    {
        return (bool) preg_match($this->pattern, $key);
    }

    /**
     * Recursively redact values under sensitive keys and turn objects into
     * JSON-safe values.
     */
    public function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[too deep]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key) && $this->isSensitive($key) && $item !== null
                    ? self::REDACTED
                    : $this->clean($item, $depth + 1);
            }

            return $out;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->clean($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : get_class($value);
        }

        if (is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Redact sensitive query-string values, keeping the rest of the URL.
     */
    public function url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['query'])) {
            return $url;
        }

        $pairs = [];
        foreach (explode('&', $parts['query']) as $pair) {
            [$key] = explode('=', $pair, 2) + [1 => null];
            $pairs[] = $this->isSensitive(urldecode($key)) ? $key . '=' . rawurlencode(self::REDACTED) : $pair;
        }

        return substr($url, 0, strpos($url, '?') + 1) . implode('&', $pairs)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
