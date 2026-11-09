<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Support\Sanitizer;
use Faultbox\Laravel\Support\Uuid;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Builds payloads in the Faultbox v1 wire format (docs/api in the server repo).
 */
final class EventFactory
{
    /** The server keeps the first and last 100 of anything above 200. */
    private const MAX_FRAMES = 200;

    private const MAX_MESSAGE = 8192;

    private const MAX_OBJECT_BYTES = 30_000;

    /** @var array<string, mixed> */
    private array $context = [];

    private ?array $user = null;

    private ?string $correlationId = null;

    /**
     * Under Octane, the sandbox container and request of the request being
     * handled. The constructor's container is the worker's base app, which
     * outlives requests, so request and auth lookups must use these instead.
     */
    private ?Container $scope = null;

    private ?Request $scopeRequest = null;

    public function __construct(
        private readonly Container $app,
        private readonly Sanitizer $sanitizer,
        private readonly array $config,
    ) {
    }

    public function setContext(array $context): void
    {
        $this->context = array_replace_recursive($this->context, $context);
    }

    public function clearContext(): void
    {
        $this->context = [];
        $this->user = null;
        $this->correlationId = null;
    }

    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /** @internal Octane: point request and auth lookups at the current request's sandbox (null to clear). */
    public function useScope(?Container $sandbox, ?Request $request): void
    {
        $this->scope = $sandbox;
        $this->scopeRequest = $request;
    }

    /** @return array{0: array, 1: ?array, 2: ?string} */
    public function snapshot(): array
    {
        return [$this->context, $this->user, $this->correlationId];
    }

    /** @param array{0: array, 1: ?array, 2: ?string} $state */
    public function restore(array $state): void
    {
        [$this->context, $this->user, $this->correlationId] = $state;
    }

    public function exception(\Throwable $e, array $context = [], string $level = 'error'): array
    {
        $payload = $this->base($level, $context);
        $payload['exception'] = $this->exceptionData($e);
        if (isset($payload['request'])) {
            $payload['request']['status_code'] = self::statusFor($e);
        }
        if ($chain = $this->previous($e)) {
            $payload['context'] = ($payload['context'] ?? []) + ['previous' => $chain];
        }

        return $payload;
    }

    public function message(string $message, string $level = 'info', array $context = []): array
    {
        return $this->base($level, $context) + [
            'message' => mb_strcut($message, 0, self::MAX_MESSAGE),
        ];
    }

    public function queueFailure(\Throwable $e, array $job, string $kind): array
    {
        $payload = $this->exception($e, [], 'error');
        unset($payload['request']); // a worker isn't handling a request
        $job['kind'] = $kind;
        if (isset($job['payload'])) {
            $job['payload'] = $this->limitObject($this->sanitizer->clean($job['payload']));
        }
        $payload['job'] = array_filter($job, static fn ($v) => $v !== null && $v !== '');

        return $payload;
    }

    private function base(string $level, array $extraContext): array
    {
        $payload = [
            'event_id' => Uuid::v7(),
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => $level,
            'environment' => $this->environment(),
        ];
        if (! empty($this->config['release'])) {
            $payload['release'] = (string) $this->config['release'];
        }

        if ($request = $this->currentRequest()) {
            $payload['request'] = $this->requestData($request);
            $payload['correlation_id'] = $this->correlationId($request);
        }

        if ($user = $this->userData()) {
            $payload['user'] = $user;
        }

        $context = array_replace_recursive($this->context, $extraContext);
        if ($context !== []) {
            $payload['context'] = $this->limitObject($this->sanitizer->clean($context));
        }

        return $payload;
    }

    /** @internal The environment telemetry batches are reported under. */
    public function batchEnvironment(): string
    {
        return $this->environment();
    }

    /** @internal The release telemetry batches are reported under, or "". */
    public function batchRelease(): string
    {
        return (string) ($this->config['release'] ?? '');
    }

    /** @internal The current request's correlation ID, or null outside a request. */
    public function currentCorrelationId(): ?string
    {
        $request = $this->currentRequest();

        return $request !== null ? $this->correlationId($request) : null;
    }

    private function environment(): string
    {
        $env = $this->config['environment'] ?: $this->app->make('config')->get('app.env', 'production');
        $env = strtolower((string) preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $env));

        return substr($env, 0, 64) ?: 'production';
    }

    private function exceptionData(\Throwable $e): array
    {
        $frames = $this->frames($e);
        $omitted = 0;
        if (count($frames) > self::MAX_FRAMES) {
            $omitted = count($frames) - self::MAX_FRAMES;
            $frames = array_merge(array_slice($frames, 0, 100), array_slice($frames, -100));
        }

        $data = [
            'type' => get_class($e),
            'message' => mb_strcut($e->getMessage(), 0, self::MAX_MESSAGE),
            'frames' => $frames,
        ];
        if ($omitted > 0) {
            $data['frames_omitted'] = $omitted;
        }

        return $data;
    }

    /**
     * Turns PHP's trace into frames, most recent first. PHP records, for each
     * call, the file and line of the call site plus the function being called,
     * so frame N pairs the location from trace[N-1] (or the throw site) with
     * the function from trace[N].
     *
     * @return list<array<string, mixed>>
     */
    public function frames(\Throwable $e): array
    {
        $trace = $e->getTrace();
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();

        foreach ($trace as $call) {
            $frames[] = $this->frame($file, $line, $call['function'] ?? null, $call['class'] ?? null);
            $file = $call['file'] ?? null;
            $line = $call['line'] ?? null;
        }
        if ($file !== null) {
            $frames[] = $this->frame($file, $line, '{main}', null);
        }

        return $frames;
    }

    private function frame(?string $file, ?int $line, ?string $function, ?string $class): array
    {
        $frame = [];
        if ($file !== null && $file !== '') {
            [$frame['file'], $frame['in_app']] = $this->relativeFile($file);
        } else {
            $frame['in_app'] = false;
        }
        if ($line) {
            $frame['line'] = $line;
        }
        if ($function !== null && $function !== '') {
            $frame['function'] = $function;
        }
        if ($class !== null && $class !== '') {
            $frame['class'] = $class;
        }

        return $frame;
    }

    /**
     * Paths inside the app are sent relative to base_path(), so frames look
     * the same on every server and deploy directory.
     *
     * @return array{0: string, 1: bool}
     */
    private function relativeFile(string $file): array
    {
        $file = str_replace('\\', '/', $file);
        $base = rtrim(str_replace('\\', '/', $this->basePath()), '/') . '/';

        if ($base !== '/' && str_starts_with($file, $base)) {
            $relative = substr($file, strlen($base));

            return [$relative, ! str_starts_with($relative, 'vendor/')];
        }

        return [$file, ! str_contains($file, '/vendor/')];
    }

    private function basePath(): string
    {
        return method_exists($this->app, 'basePath') ? $this->app->basePath() : '';
    }

    /**
     * Summaries of chained exceptions, newest first, for context.previous.
     *
     * @return list<array{type: string, message: string, location: string}>
     */
    private function previous(\Throwable $e): array
    {
        $chain = [];
        for ($p = $e->getPrevious(), $i = 0; $p !== null && $i < 5; $p = $p->getPrevious(), $i++) {
            $chain[] = [
                'type' => get_class($p),
                'message' => mb_strcut($p->getMessage(), 0, 500),
                'location' => $this->relativeFile($p->getFile())[0] . ':' . $p->getLine(),
            ];
        }

        return $chain;
    }

    private function currentRequest(): ?Request
    {
        if ($this->scopeRequest !== null) {
            return $this->scopeRequest->getHost() !== '' ? $this->scopeRequest : null;
        }
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return null;
        }
        if (! $this->app->bound('request')) {
            return null;
        }
        $request = $this->app->make('request');

        return $request instanceof Request && $request->getHost() !== '' ? $request : null;
    }

    private function requestData(Request $request): array
    {
        $data = [
            'url' => mb_strcut($this->sanitizer->url($request->fullUrl()), 0, 2048),
            'method' => $request->getMethod(),
        ];

        if ($route = $request->route()) {
            $name = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
            if ($name) {
                $data['route'] = $name;
            }
        }

        $headers = [];
        foreach ($this->config['request_headers'] ?? [] as $name) {
            $value = $request->headers->get($name);
            if ($value !== null && $value !== '') {
                $headers[$name] = $this->sanitizer->isSensitive($name) ? Sanitizer::REDACTED : mb_strcut($value, 0, 1000);
            }
        }
        if ($headers !== []) {
            $data['headers'] = $headers;
        }

        return $data;
    }

    private function correlationId(Request $request): string
    {
        if ($this->correlationId === null) {
            $header = $this->config['correlation_header'] ?? 'X-Request-Id';
            $this->correlationId = substr((string) ($request->headers->get($header) ?: Uuid::v7()), 0, 200);
        }

        return $this->correlationId;
    }

    private function userData(): ?array
    {
        if ($this->user !== null) {
            return $this->limitObject($this->sanitizer->clean($this->user));
        }
        $container = $this->scope ?? $this->app;
        if (empty($this->config['send_user_id']) || ! $container->bound('auth')) {
            return null;
        }

        try {
            $id = $container->make('auth')->id();
        } catch (\Throwable) {
            return null; // no session store or guard available here
        }

        return $id === null ? null : ['id' => (string) $id];
    }

    /** Keeps objects under the server's 32 KB limit by dropping them if needed. */
    private function limitObject(mixed $value): array
    {
        $value = is_array($value) ? $value : ['value' => $value];
        $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json !== false && strlen($json) <= self::MAX_OBJECT_BYTES) {
            return $value;
        }

        return ['_faultbox' => 'dropped: larger than ' . intdiv(self::MAX_OBJECT_BYTES, 1000) . ' KB'];
    }

    /** HTTP status for exceptions that carry one; 500 otherwise. */
    public static function statusFor(\Throwable $e): int
    {
        return $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
    }
}
