<?php

namespace Faultbox\Laravel\Tests\Feature;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Exceptions\TestException;
use Faultbox\Laravel\Tests\TestCase;
use Faultbox\Laravel\Transport\Response;

class TestCommandTest extends TestCase
{
    public function test_sends_a_test_exception(): void
    {
        $fake = $this->fake();

        $this->artisan('faultbox:test')
            ->expectsOutputToContain('Faultbox accepted the event')
            ->assertSuccessful();

        $fake->assertExceptionSent(TestException::class);
        $this->assertSame('faultbox:test', $fake->events()[0]['context']['source']);
    }

    public function test_explains_a_rejected_key(): void
    {
        $this->app->make(Client::class)->fake(fn () => new Response(401, '{"error":{"code":"invalid_api_key"}}'));

        $this->artisan('faultbox:test')
            ->expectsOutputToContain('The API key was rejected')
            ->assertFailed();
    }

    public function test_requires_configuration(): void
    {
        $this->app['config']->set('faultbox.api_key', null);
        $this->refreshClient();

        $this->artisan('faultbox:test')
            ->expectsOutputToContain('Set FAULTBOX_DSN and FAULTBOX_API_KEY')
            ->assertFailed();
    }
}
