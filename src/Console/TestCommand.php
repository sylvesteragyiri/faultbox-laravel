<?php

namespace Faultbox\Laravel\Console;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Exceptions\TestException;
use Illuminate\Console\Command;

/**
 * php artisan faultbox:test
 *
 * Checks the configuration, verifies the API key against the server and
 * sends one test exception, reporting each step's result.
 */
class TestCommand extends Command
{
    protected $signature = 'faultbox:test';

    protected $description = 'Verify the Faultbox configuration and send a test exception';

    public function handle(Client $client): int
    {
        $config = $this->laravel['config']->get('faultbox', []);

        if (empty($config['dsn']) || empty($config['api_key'])) {
            $this->components->error('Set FAULTBOX_DSN and FAULTBOX_API_KEY in your .env file.');

            return self::FAILURE;
        }
        if (! $client->isEnabled()) {
            $this->components->error('Faultbox is disabled (FAULTBOX_ENABLED=false).');

            return self::FAILURE;
        }

        $this->components->info('Sending a test exception to ' . $config['dsn']);

        try {
            throw new TestException('This is a test exception from php artisan faultbox:test.');
        } catch (TestException $e) {
            $payload = $client->events()->exception($e, ['source' => 'faultbox:test']);
        }

        $paused = $client->stats()['paused_until'];
        if ($paused !== null) {
            $this->components->warn(sprintf(
                'Sending was paused until %s because Faultbox failed recently (circuit breaker). Testing anyway.',
                date('H:i:s', (int) $paused),
            ));
        }

        $response = $client->sendNow(Client::EVENTS_PATH, $payload);

        if ($response->accepted()) {
            $this->components->twoColumnDetail('Event ID', $payload['event_id']);
            $this->components->twoColumnDetail('Environment', $payload['environment']);
            $this->components->info('Faultbox accepted the event. It will appear in the dashboard in a moment.');

            return self::SUCCESS;
        }

        $reason = match (true) {
            $response->status === 0 => 'Could not reach the server: ' . ($response->error ?? 'unknown error') . '. Check FAULTBOX_DSN.',
            $response->status === 401 => 'The API key was rejected. Copy it again from the project page, or regenerate it.',
            $response->status === 403 => 'The project is disabled in the Faultbox dashboard.',
            $response->status === 422 => 'The server rejected the event: ' . $response->body,
            default => "Unexpected response {$response->status}: {$response->body}",
        };
        $this->components->error($reason);

        return self::FAILURE;
    }
}
