# Faultbox for Laravel

Reports exceptions and failed queue jobs from Laravel 12 and 13 to a self-hosted [Faultbox](https://github.com/sylvesteragyiri/faultbox) server.

- **Automatic reporting:** everything Laravel reports, through `report()`, uncaught exceptions or the exception handler, is sent. Your `dontReport` rules still apply.
- **Queue failures:** captured on every driver (database, Redis, SQS, sync) through Laravel's queue events. The package tells three cases apart:
  - a job that used up its retries,
  - a job that called `$this->fail()`,
  - an attempt that will be retried.
- **Never slows down a request:** it only sends when something goes wrong, and in web requests only after the response has gone to the browser. A circuit breaker and strict time limits mean a Faultbox outage costs at most one short timeout per minute. See [Safety](#safety-it-never-slows-down-or-breaks-your-app).
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

## Laravel notifications

Every notification your app sends is reported automatically, per channel: mail, database, broadcast, Slack, SMS and custom channels. Faultbox shows them under **App notifications**, with failures and their reasons.

- **Failures are caught either way:** a channel that throws (the mail channel does when SMTP fails) counts as failed, with the exception as the reason. So does one that fires `NotificationFailed`.
- **Cancelled sends don't count:** a send stopped by a `NotificationSending` listener isn't counted.
- **Private:** only class names are sent: the notification, the channel and the notifiable's type. Never recipients, addresses or message content.
- **Cheap:** notifications are batched and sent with the other events after the response, or after each queue job, in one request per flush. The circuit breaker and time budget apply.

Turn it off with `FAULTBOX_NOTIFICATIONS_ENABLED=false`.

## Mail

Mail your app sends directly, with Mailables (`Mail::to()->send()`, `queue()`) or `Mail::raw`, is shown under **Mail**: sent and failed counts per Mailable and mailer, and recent failures with their reasons.

- **No double counting:** mail sent by a notification's mail channel is left out here; it's counted under App notifications.
- **Failures:** a transport that throws (SMTP answering 550, an API mailer timing out) counts as failed, with the exception as the reason. A send stopped by a `MessageSending` listener isn't counted.
- **Private:** only the Mailable's class, the mailer's name and the number of recipients are sent. Never addresses, subjects or content.

Turn it off with `FAULTBOX_MAIL_ENABLED=false`.

## Artisan commands

Every artisan command run is shown under **Commands**: its exit code, how long it took and, when it threw, the exception. Each command the scheduler starts is a run of its own, so failing scheduled tasks show up here. Only the command's name is sent, never its arguments or options.

Long-running and noisy commands are ignored: `queue:work`, `queue:listen`, `schedule:run`, `schedule:work`, `horizon`, `octane:*`, `reverb:*`, `pulse:*`, `serve`, `tinker`, `list` and a few more. Change the list in `config/faultbox.php` (`commands.ignore`; `*` is a wildcard). A command called with `$this->call()` inside another is part of the outer run: Laravel fires no events for it.

Turn it off with `FAULTBOX_COMMANDS_ENABLED=false`.

## Cache

Cache hits, misses, writes, deletes and failed writes are shown under **Cache**, per store and key pattern, with the hit rate of each. That makes it easy to spot a key that's almost always missed, or a store whose writes fail.

- **Key patterns, not keys:** keys are reduced in your app before anything is sent. Numbers, UUIDs, emails, and long tokens or hashes that contain a digit become `*`, so `users:42:profile` is counted as `users:*:profile`. Values are never sent.
- **Once a minute, not once a request:** operations are counted in memory, and the totals of every PHP process on the server are pooled in one small file in the temp directory. Whichever process finds the pool more than a minute old sends it, after its response. So a busy server sends about one small batch a minute, however many requests read the cache (sessions included). If the temp directory isn't writable, the totals are dropped, and `stats()['dropped_cache']` counts them.
- **Laravel's bookkeeping is ignored:** `illuminate:*` (read by every queue worker loop), `framework/schedule*` and a few more. Change the list in `config/faultbox.php` (`cache.ignore`; matched against the raw key, `*` is a wildcard).

Turn it off with `FAULTBOX_CACHE_ENABLED=false`.

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
| `FAULTBOX_RETRIES` | `1` | Retries on `429`, `503` or fast network errors (never after a timeout). Each retry waits at most 1 s. |
| `FAULTBOX_FLUSH_BUDGET` | `3.0` s | The most time one request, job or command spends sending. |
| `FAULTBOX_BREAKER_COOLDOWN` | `60` s | How long sending pauses after Faultbox fails. `0` turns the breaker off. |
| `FAULTBOX_BREAKER_STORE` | `auto` | Where the pause is shared: `auto`, `apcu`, `file` or `memory`. |
| `FAULTBOX_SEND_USER_ID` | `true` | Sends `auth()->id()` only. |
| `FAULTBOX_QUEUE_ENABLED` | `true` | |
| `FAULTBOX_NOTIFICATIONS_ENABLED` | `true` | Report Laravel notifications. |
| `FAULTBOX_MAIL_ENABLED` | `true` | Report mail sent directly (Mailables, `Mail::raw`). |
| `FAULTBOX_CACHE_ENABLED` | `true` | Report cache totals per store and key pattern, about once a minute. Ignored keys: `cache.ignore` in the config file. |
| `FAULTBOX_COMMANDS_ENABLED` | `true` | Report artisan command runs. Ignored commands: `commands.ignore` in the config file. |
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

## Safety: it never slows down or breaks your app

An error tracker must never become the outage. These are the package's guarantees, and the tests that check them.

### No traffic unless there's something to report

The package makes an HTTP call only when it has something to report: an exception, a message, a failed job, or a notification, email or artisan command run. Cache totals, the only busy signal, are pooled across processes and sent at most about once a minute per server. A request that throws nothing, sends nothing and only reads the cache makes **no network call**. Nothing is ever sent per query or per cache operation, so a misconfigured DSN can't slow down healthy traffic, and every send happens after the response.

### Your users never wait for it

In web requests, including Octane, events are buffered and sent **after the response has been sent**. Console commands and queue workers send straight away, within the limits below.

### Package errors never reach your code

Every hook into Laravel runs inside a `try/catch`, and so does building the client:
- the exception handler;
- queue events;
- Octane events;
- request termination and shutdown.

A bug in the package, a broken config value or a strange response from Faultbox is dropped silently (`ResilienceTest::test_a_broken_client_never_breaks_exception_handling`).

### Strict limits on every send

| Limit | Default | Setting |
|---|---|---|
| Connect timeout | 1 s | `FAULTBOX_CONNECT_TIMEOUT` |
| Whole request | 2 s | `FAULTBOX_TIMEOUT` |
| Retries | 1, only on `429`, `503` or a *fast* network error; never after a timeout | `FAULTBOX_RETRIES` |
| Time spent sending per request, job or command | 3 s, then the rest is dropped | `FAULTBOX_FLUSH_BUDGET` |
| Events per request | 50 | `max_events_per_request` |
| Redirects | never followed | — |

### Circuit breaker: an outage costs one timeout, not one per event

When Faultbox doesn't answer (a network failure, a timeout or a `5xx`), the package **stops sending for 60 seconds**. Events during the pause are dropped and counted, and no network call is attempted. After the pause, the next event is a trial: success resumes sending, failure pauses again. A rate limit (`429`) pauses only for as long as Faultbox asks. Client errors such as a revoked key (`401`) don't pause sending, because Faultbox is up and answers instantly.

What an outage costs your app, per worker:

| Faultbox is | Without the breaker | With the breaker |
|---|---|---|
| Stopped (connection refused) | ~0.2 s per event | one ~0.2 s hit, then nothing for 60 s |
| Unreachable (packets dropped) | ~2 s per event | one ~1 s hit (connect timeout) per 60 s |
| Hanging (accepts, never answers) | ~4 s per event | one ~2 s hit (no retry after a timeout) per 60 s |

Tested against real sockets:
- 50 events with nothing listening finish in under 1.5 s;
- 20 events against a server that never answers cost a single timeout.

The pause is shared between processes where possible, so short-lived PHP-FPM workers also learn that Faultbox is down:

| `FAULTBOX_BREAKER_STORE` | Shared by |
|---|---|
| `auto` (default) | APCu if enabled (a PHP-FPM pool), otherwise a small file in the system temp directory (every process on the machine or container) |
| `apcu` | The PHP-FPM pool; per process if APCu is off |
| `file` | Every process on the machine or container |
| `memory` | This process only: fine for Octane and queue workers, which are long-lived |

The store is always local (memory, APCu or a temp file), never Redis or a database. A broken store must not slow the app down either.

`FAULTBOX_BREAKER_COOLDOWN` sets the pause in seconds (default `60`); `0` turns the breaker off.

`php artisan faultbox:test` ignores a pause, so you can check the setup while Faultbox is recovering. A successful test resumes sending.

### Turning it off

- `FAULTBOX_ENABLED=false` turns everything off.
- Removing `FAULTBOX_DSN` or `FAULTBOX_API_KEY` does the same: the package then never opens a connection.

### Checking delivery health

```php
app(\Faultbox\Laravel\Client::class)->stats();
// ['breaker_open' => false, 'paused_until' => null, 'dropped_by_breaker' => 0, 'dropped_by_budget' => 0]
```

Install the `curl` extension. Without it, the package falls back to PHP streams, which is slower but still capped at the connect timeout.

## Laravel Octane

Octane is supported with no configuration. One Octane worker serves many requests with the same objects, so the package listens to Octane's `RequestReceived` and `RequestTerminated` events:

- **Fresh state per request:** context, user and correlation ID are reset at the start of every request, so nothing from one request reaches another's events. Context set at boot, for example in a service provider, is kept.
- **The right request and user:** both are read from the current request's sandbox, not from the worker's base container.
- **Sent after the response:** events are buffered during the request and delivered on `RequestTerminated`.

After installing or upgrading, reload the workers with `php artisan octane:reload`.

## How events are sent

| Where the code runs | When events are sent |
|---|---|
| Web request | Buffered, then sent after the response (`terminating`). |
| Octane request | Buffered, then sent on Octane's `RequestTerminated`. |
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
