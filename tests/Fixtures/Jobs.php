<?php

namespace Faultbox\Laravel\Tests\Fixtures;

use Faultbox\Laravel\Facades\Faultbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public string $message = 'Payment provider timed out', public string $apiToken = 'secret-token')
    {
    }

    public function handle(): void
    {
        throw new \RuntimeException($this->message);
    }
}

class ManualFailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public ?\Throwable $reason = null)
    {
    }

    public function handle(): void
    {
        $this->fail($this->reason ?? 'Card expired');
    }
}

class ContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $tenant, public bool $fail = false)
    {
    }

    public function handle(): void
    {
        Faultbox::setContext(['job_tenant' => $this->tenant]);
        if ($this->fail) {
            throw new \LogicException('job failed for ' . $this->tenant);
        }
        report(new \DomainException('handled inside job'));
    }
}

class SucceedingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
    }
}
