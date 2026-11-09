<?php

namespace Faultbox\Laravel\Transport;

interface Transport
{
    /**
     * Send one JSON payload to an API path such as /api/v1/events.
     *
     * Implementations must never throw: network problems are reported as a
     * Response with status 0.
     */
    public function send(string $path, array $payload): Response;
}
