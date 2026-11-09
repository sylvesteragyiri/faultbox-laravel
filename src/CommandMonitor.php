<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Support\Uuid;

/**
 * Records the artisan commands the app runs, scheduled ones included (the
 * scheduler starts each in its own process): name, exit code and duration.
 * Long-running workers and noisy built-ins are ignored (config
 * faultbox.commands.ignore). Arguments and options are never sent.
 *
 * When a command throws, Symfony fires its TERMINATE event, and with it
 * Laravel's CommandFinished (exit code 1), before Laravel reports the
 * exception. So the error is attached to the failed run after it finished.
 */
final class CommandMonitor
{
    private const MAX_PER_FLUSH = 500;

    /** Long-running workers and noisy built-ins, ignored unless configured otherwise. */
    public const DEFAULT_IGNORE = [
        'schedule:run', 'schedule:work', 'schedule:finish', 'schedule:interrupt',
        'queue:work', 'queue:listen', 'horizon', 'horizon:*', 'octane:*', 'reverb:*', 'pulse:*',
        'serve', 'tinker', 'list', 'help', 'completion', 'about', 'package:discover', 'faultbox:*',
    ];

    /** @var list<array{id: string, command: string, timestamp: string, started: int|float}|null> running commands; null when ignored */
    private array $running = [];

    /** @var list<array> */
    private array $items = [];

    /** Index in $items of the latest failed run still waiting for its error. */
    private ?int $awaitingError = null;

    /** @var array{type: string, message: string}|null an exception reported while a command was running */
    private ?array $runningError = null;

    private int $dropped = 0;

    /** @param list<string> $ignore command names; * is a wildcard */
    public function __construct(private readonly array $ignore = [])
    {
    }

    public function starting(string $command): void
    {
        if ($command === '' || $this->ignored($command)) {
            $this->running[] = null;

            return;
        }
        $this->running[] = [
            'id' => Uuid::v7(),
            'command' => substr($command, 0, 128),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'started' => hrtime(true),
        ];
    }

    public function finished(string $command, int $exitCode): void
    {
        $run = array_pop($this->running);
        if ($run === null) {
            return;
        }
        $item = [
            'id' => $run['id'],
            'timestamp' => $run['timestamp'],
            'command' => $run['command'],
            'exit_code' => max(-1, min(255, $exitCode)),
            'duration_ms' => intdiv((int) (hrtime(true) - $run['started']), 1_000_000),
        ];
        if ($exitCode !== 0 && $this->runningError !== null) {
            $item['error'] = $this->runningError;
        }
        $this->runningError = null;
        if (count($this->items) >= self::MAX_PER_FLUSH) {
            $this->dropped++;

            return;
        }
        $this->items[] = $item;
        $this->awaitingError = ($exitCode !== 0 && ! isset($item['error'])) ? array_key_last($this->items) : null;
    }

    public function noteException(\Throwable $e): void
    {
        $error = ['type' => get_class($e), 'message' => mb_strcut($e->getMessage(), 0, 1000)];
        if ($this->awaitingError !== null && isset($this->items[$this->awaitingError])) {
            $this->items[$this->awaitingError]['error'] = $error;
            $this->awaitingError = null;

            return;
        }
        if ($this->running !== []) {
            $this->runningError = $error;
        }
    }

    /** @return list<array> finished runs, ready for a telemetry batch */
    public function drainItems(): array
    {
        $items = $this->items;
        $this->items = [];
        $this->awaitingError = null;

        return $items;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    private function ignored(string $command): bool
    {
        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $command)) {
                return true;
            }
        }

        return false;
    }
}
