<?php

// Minimal stand-ins for Laravel Octane's request events, with the same
// public properties, so the Octane integration can be tested without
// installing laravel/octane. Skipped when the real classes exist.

namespace Laravel\Octane\Events;

use Illuminate\Http\Request;

if (! class_exists(RequestReceived::class)) {
    class RequestReceived
    {
        public function __construct(public $app, public $sandbox, public Request $request, public $context = null)
        {
        }
    }
}

if (! class_exists(RequestTerminated::class)) {
    class RequestTerminated
    {
        public function __construct(public $app, public $sandbox, public Request $request, public $response = null)
        {
        }
    }
}
