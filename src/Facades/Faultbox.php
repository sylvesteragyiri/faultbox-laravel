<?php

namespace Faultbox\Laravel\Facades;

use Faultbox\Laravel\Client;
use Faultbox\Laravel\Transport\FakeTransport;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null captureException(\Throwable $e, array $context = [])
 * @method static string|null captureMessage(string $message, string $level = 'info', array $context = [])
 * @method static void setContext(array $context)
 * @method static void setUser(?array $user)
 * @method static void clearContext()
 * @method static void flush()
 * @method static bool isEnabled()
 * @method static FakeTransport fake(int|\Closure $respond = 202)
 *
 * @see Client
 */
class Faultbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
