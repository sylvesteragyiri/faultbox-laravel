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
- **`php artisan faultbox:test`** to check the setup.
- **`Faultbox::fake()`** for application tests.
