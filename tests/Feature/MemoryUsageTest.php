<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Facades\Faultbox;
use Faultbox\Laravel\Tests\TestCase;

class MemoryUsageTest extends TestCase
{
    public function test_normal_and_worst_case_request_buffer_memory(): void
    {
        $client = $this->app->make(Client::class);
        $transport = $client->fake();
        $client->setDeferred(true);
        gc_collect_cycles();

        memory_reset_peak_usage();
        $normalBaseline = memory_get_usage(false);
        for ($i = 0; $i < 5; $i++) {
            Faultbox::captureMessage('Normal memory profile event.', 'warning', ['request' => $i]);
        }
        $normalPeak = max(0, memory_get_peak_usage(false) - $normalBaseline);
        $this->assertLessThan(8 * 1024 * 1024, $normalPeak);
        $this->assertSame(5, $this->bufferedEventCount($client));
        $client->flush();
        $transport->reset();

        $client->fake();
        $client->setDeferred(true);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $worstBaseline = memory_get_usage(false);
        for ($i = 0; $i < 50; $i++) {
            $largeContext = ['sample' => str_repeat('c', 27_990) . sprintf('%010d', $i)];
            $largeMessage = str_repeat('w', 7_990) . sprintf('%010d', $i);
            Faultbox::captureMessage($largeMessage, 'error', $largeContext);
        }
        $worstPeak = max(0, memory_get_peak_usage(false) - $worstBaseline);

        fwrite(STDOUT, sprintf(
            "\nFaultbox Laravel SDK memory (incremental PHP heap): normal 5-event peak %.2f MiB; worst 50-event peak %.2f MiB\n",
            $normalPeak / 1024 / 1024,
            $worstPeak / 1024 / 1024,
        ));

        $this->assertLessThan(16 * 1024 * 1024, $worstPeak);
        $this->assertSame(50, $this->bufferedEventCount($client));
        $client->flush();
        $this->assertCount(50, $client->transport()->events());
    }

    private function bufferedEventCount(Client $client): int
    {
        $buffer = new \ReflectionProperty(Client::class, 'buffer');

        return count($buffer->getValue($client));
    }
}
