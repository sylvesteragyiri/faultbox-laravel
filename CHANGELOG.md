# Changelog

## Unreleased: 1.0.0

First release, for Faultbox server API v1.

- **Exception reporting:** automatic, through Laravel's exception handler, and it respects `dontReport`.
- **Manual reporting:** `Faultbox::captureException()`, `captureMessage()`, `setContext()`, `setUser()` and `clearContext()`.
- **Queue failure monitoring** through queue events:
  - terminal, manual and (optional) intermediate attempt failures;
  - batch IDs;
  - per-job context that doesn't leak into the next job.
- **Delivery:**
  - In web requests, events are sent after the response.
  - Uses curl, or PHP streams if curl isn't available.
  - Gzip compression, short timeouts, and one retry on `429`/`503`.
  - Never throws.
- **Privacy:** an allowlist of request headers, the user ID only, and redaction of sensitive keys in context, URLs and job metadata.
- **Laravel Octane support:** per-request state (context, user, correlation ID) is reset when each request starts, the request and user are read from that request's sandbox, and events are sent after the response. Context set in service providers is kept. No dependency on `laravel/octane`.
- **Never slows down the app:**
  - **Circuit breaker:** after a network failure, timeout or `5xx`, sending pauses for 60 s, so an outage costs one timeout per minute instead of one per event. The pause is shared across PHP-FPM processes through APCu or a temp file. A `429` pauses only for its `Retry-After`.
  - **No retry after a timeout.**
  - **Time budget:** at most 3 s of sending per request, job or command.
  - **Guarded hooks:** every Laravel hook, including building the client, runs inside a `try/catch`.
  - **Streams fallback:** without curl, connecting is capped at the connect timeout.
  - **`Client::stats()`** reports breaker state and dropped events.
- **Laravel notification monitoring:** every notification per channel, sent or failed, batched after the response to `POST /api/v1/notifications`.
  - Thrown channel failures are attributed to their exception.
  - Cancelled sends aren't counted.
  - Only class names are sent.
  - `FakeTransport::notifications()` and `notificationBatches()` for tests.
- **`php artisan faultbox:test`** to check the setup. It tests even while sending is paused.
- **`Faultbox::fake()`** for application tests.
