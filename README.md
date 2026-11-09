# Faultbox for Laravel

Reports exceptions and failed queue jobs from Laravel 12 and 13 to a self-hosted [Faultbox](https://github.com/sylvesteragyiri/faultbox) server.

- **Automatic reporting:** everything Laravel reports, through `report()`, uncaught exceptions or the exception handler, is sent. Your `dontReport` rules still apply.
- **Queue failures:** captured on every driver (database, Redis, SQS, sync) through Laravel's queue events. The package tells three cases apart:
  - a job that used up its retries,
  - a job that called `$this->fail()`,
  - an attempt that will be retried.
- **Never slows down a request:** in web requests, events are sent after the response has gone to the browser. Delivery uses short timeouts and never throws, so a tracker outage can't break a request or a job.
- **Private by default:**
  - Only an allowlist of request headers is sent, and only the user's ID.
  - Values under keys like `password`, `token` and `secret` are redacted everywhere.
  - Serialized job commands are never sent.

## Install

```bash
composer require faultbox/laravel
```

Add your Faultbox server and the project's API key to `.env`. The key is shown once, when you create the project in the dashboard.

```env
FAULTBOX_DSN=https://errors.example.com
FAULTBOX_API_KEY=fbx_...
FAULTBOX_RELEASE=1.4.2          # optional: git tag or commit
# FAULTBOX_ENVIRONMENT=production  (defaults to APP_ENV)
```

Check the setup:

```bash
php artisan faultbox:test
```

Reporting stays off until both `FAULTBOX_DSN` and `FAULTBOX_API_KEY` are set, so local machines and CI stay quiet unless you configure them.

## Manual reporting and context

```php
use Faultbox\Laravel\Facades\Faultbox;

Faultbox::captureException($e, ['order_id' => $order->id]);
Faultbox::captureMessage('Payment reconciliation failed', 'error');

// Sent with every later event in this request or job.
Faultbox::setContext([
    'payment_reference' => 'REF-123',
    'operation' => 'payment-reconciliation',
]);
Faultbox::setUser(['id' => (string) $user->id]); // overrides the automatic auth()->id()
```

## Queue failures

You don't need to add anything to your jobs.

| What happened | Reported as | When |
|---|---|---|
| The job threw on its last attempt | `terminal` | Always |
| The job called `$this->fail()` | `manual` | Always |
| The job threw and will be retried | `attempt` | Only with `FAULTBOX_QUEUE_REPORT_ATTEMPTS=true` |

The worker also passes job exceptions to the exception handler. Faultbox skips those, so a failing job shows up once, in **Queue Failures**, rather than again as an ordinary exception on every attempt. To get them as ordinary exceptions too, set `FAULTBOX_QUEUE_REPORT_JOB_EXCEPTIONS=true`.

Context set inside a job is cleared when the job ends. Context you set at boot, for example in a service provider, is kept.

## Configuration

To publish the config file:

```bash
php artisan vendor:publish --tag=faultbox-config
```

| Variable | Default | |
|---|---|---|
| `FAULTBOX_ENABLED` | `true` | Master switch. |
| `FAULTBOX_DSN` / `FAULTBOX_API_KEY` | — | Both are needed to turn reporting on. |
| `FAULTBOX_ENVIRONMENT` | `APP_ENV` | If the project lists environments, only those are accepted. |
| `FAULTBOX_RELEASE` | — | |
| `FAULTBOX_TIMEOUT` / `FAULTBOX_CONNECT_TIMEOUT` | `2.0` / `1.0` s | Per delivery. |
| `FAULTBOX_RETRIES` | `1` | Retries on `429`, `503` or network errors. Each retry waits at most 1 s. |
| `FAULTBOX_SEND_USER_ID` | `true` | Sends `auth()->id()` only. |
| `FAULTBOX_QUEUE_ENABLED` | `true` | |
| `FAULTBOX_QUEUE_REPORT_ATTEMPTS` | `false` | |
| `FAULTBOX_QUEUE_REPORT_JOB_EXCEPTIONS` | `false` | |

The config file also sets:
- `request_headers`, the header allowlist;
- `sanitize_pattern`, which decides the redacted keys;
- `ignore_exceptions`, the classes never to send;
- `max_events_per_request`, which defaults to 50.

## Testing your application

```php
$fake = Faultbox::fake();

$this->post('/checkout')->assertStatus(500);

$fake->assertExceptionSent(PaymentFailed::class);
$fake->assertSent(fn (array $event) => $event['context']['order_id'] === 42);
$this->assertCount(1, $fake->queueFailures());
// $fake->assertNothingSent();
```

## How events are sent

| Where the code runs | When events are sent |
|---|---|
| Web request | Buffered, then sent after the response (`terminating`). |
| Console command or queue worker | Sent immediately, and flushed after each job. |
| Fatal error | Flushed by a shutdown handler. |

Bodies over 1 KB are gzip-compressed. Every event has a UUIDv7 `event_id`, and a retry reuses the same ID, so the server stores it only once.

The wire format is documented in the server repository under [`docs/api`](https://github.com/sylvesteragyiri/faultbox/tree/main/docs/api).

## Development

```bash
composer install
vendor/bin/phpunit --testsuite Unit,Feature

# Redis driver test
REDIS_HOST=127.0.0.1 vendor/bin/phpunit --filter redis

# Contract test against a running server
FAULTBOX_E2E_DSN=http://localhost:8080 FAULTBOX_E2E_KEY=fbx_... vendor/bin/phpunit --testsuite E2E
```

## License

Apache-2.0
