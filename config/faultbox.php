<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable reporting
    |--------------------------------------------------------------------------
    |
    | Reporting is off unless a DSN and an API key are set, so local
    | development and test suites stay quiet by default.
    |
    */

    'enabled' => env('FAULTBOX_ENABLED', true),

    // Base URL of your Faultbox server, e.g. https://errors.example.com
    'dsn' => env('FAULTBOX_DSN'),

    // The project API key shown once when the project was created (fbx_...).
    'api_key' => env('FAULTBOX_API_KEY'),

    // Defaults to APP_ENV.
    'environment' => env('FAULTBOX_ENVIRONMENT'),

    // Your application version, e.g. a git tag or commit SHA.
    'release' => env('FAULTBOX_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | During HTTP requests, events are buffered and sent after the response
    | has gone to the browser. In the console and in queue workers, they are
    | sent straight away. A tracker outage never throws: events are dropped
    | after the retry below.
    |
    */

    'timeout' => (float) env('FAULTBOX_TIMEOUT', 2.0),          // seconds, whole request
    'connect_timeout' => (float) env('FAULTBOX_CONNECT_TIMEOUT', 1.0),
    'retries' => (int) env('FAULTBOX_RETRIES', 1),               // on 429, 503 or network errors
    'max_events_per_request' => 50,

    /*
    |--------------------------------------------------------------------------
    | Queue monitoring
    |--------------------------------------------------------------------------
    */

    'queue' => [
        // Terminal failures (retries exhausted) and manual $this->fail() calls
        // are always reported to the Queue Failures view.
        'enabled' => env('FAULTBOX_QUEUE_ENABLED', true),

        // Also report each failed attempt that will be retried.
        'report_attempts' => env('FAULTBOX_QUEUE_REPORT_ATTEMPTS', false),

        // The worker also passes job exceptions to the exception handler. By
        // default those are skipped so a failing job shows up once, as a
        // queue failure, not again as an ordinary exception on every attempt.
        'report_job_exceptions' => env('FAULTBOX_QUEUE_REPORT_JOB_EXCEPTIONS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context and privacy
    |--------------------------------------------------------------------------
    */

    // Send the authenticated user's ID (only the ID, never other attributes).
    'send_user_id' => env('FAULTBOX_SEND_USER_ID', true),

    // Request headers to include. Everything else is left out.
    'request_headers' => ['User-Agent', 'Accept', 'Accept-Language', 'Content-Type', 'Referer', 'X-Request-Id', 'Host'],

    // Header used as the correlation ID; one is generated if absent.
    'correlation_header' => 'X-Request-Id',

    // Keys matching this pattern are replaced with [redacted] in context,
    // job payload metadata and URL query strings.
    'sanitize_pattern' => '/pass(word|wd)?|secret|token|api[-_]?key|auth|cookie|session|credential|card[-_]?(number|no)?|cc[-_]?num|cvv|cvc|iban|ssn|private[-_]?key/i',

    // Exception classes never to report, on top of the app's $dontReport.
    'ignore_exceptions' => [],

];
