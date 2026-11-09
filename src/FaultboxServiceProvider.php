<?php

namespace Faultbox\Laravel;

use Faultbox\Laravel\Console\TestCommand;
use Faultbox\Laravel\Support\Sanitizer;
use Faultbox\Laravel\Transport\HttpTransport;
use Faultbox\Laravel\Transport\Response;
use Faultbox\Laravel\Transport\Transport;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;

class FaultboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/faultbox.php', 'faultbox');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']->get('faultbox', []);
            $enabled = (bool) ($config['enabled'] ?? true) && ! empty($config['dsn']) && ! empty($config['api_key']);

            $transport = $enabled
                ? new HttpTransport((string) $config['dsn'], (string) $config['api_key'],
                    (float) ($config['timeout'] ?? 2.0), (float) ($config['connect_timeout'] ?? 1.0))
                : new class implements Transport {
                    public function send(string $path, array $payload): Response
                    {
                        return new Response(0, error: 'Faultbox is disabled: set FAULTBOX_DSN and FAULTBOX_API_KEY');
                    }
                };

            $sanitizer = new Sanitizer((string) ($config['sanitize_pattern'] ?? '/password|secret|token/i'));

            return new Client($app, new EventFactory($app, $sanitizer, $config), $transport, $enabled, $config);
        });
        $this->app->alias(Client::class, 'faultbox');

        $this->app->singleton(QueueMonitor::class, fn ($app) => new QueueMonitor(
            $app->make(Client::class),
            $app['config']->get('faultbox.queue', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/faultbox.php' => $this->app->configPath('faultbox.php')], 'faultbox-config');
            $this->commands([TestCommand::class]);
        }

        $this->registerExceptionReporting();
        $this->registerQueueMonitoring();
        $this->registerOctane();

        // Web requests buffer events; send them once the response is out.
        $this->app->terminating(fn () => $this->app->make(Client::class)->flush());
        // A fatal error can skip "terminating", so flush at shutdown too.
        register_shutdown_function(function () {
            if ($this->app->resolved(Client::class)) {
                $this->app->make(QueueMonitor::class)->flushPending();
                $this->app->make(Client::class)->flush();
            }
        });
    }

    /**
     * Laravel Octane keeps the app in memory and serves many requests per
     * worker. Reset per-request state when each request starts and deliver
     * buffered events once its response has been sent. The events are
     * matched by name, so the package doesn't depend on Octane.
     */
    private function registerOctane(): void
    {
        if (class_exists(self::OCTANE_REQUEST_RECEIVED)) {
            // Resolve now, in the worker's base app, so every request's
            // sandbox shares this one instance instead of building its own.
            $this->app->make(Client::class);
        }
        $events = $this->app->make(Dispatcher::class);
        $events->listen(self::OCTANE_REQUEST_RECEIVED, function ($event) {
            $this->app->make(Client::class)->beginOctaneRequest(
                $event->sandbox ?? null,
                ($event->request ?? null) instanceof Request ? $event->request : null,
            );
        });
        $events->listen(self::OCTANE_REQUEST_TERMINATED, function () {
            $this->app->make(Client::class)->endOctaneRequest();
        });
    }

    private const OCTANE_REQUEST_RECEIVED = 'Laravel\Octane\Events\RequestReceived';

    private const OCTANE_REQUEST_TERMINATED = 'Laravel\Octane\Events\RequestTerminated';

    /**
     * Hooks into the app's exception handler, so everything Laravel reports
     * (respecting $dontReport and ->dontReport()) reaches Faultbox, and
     * normal logging carries on.
     */
    private function registerExceptionReporting(): void
    {
        $register = function ($handler) {
            if (is_object($handler) && method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e) {
                    $this->app->make(Client::class)->reportFromHandler($e);
                });
            }
        };

        $this->app->resolved(ExceptionHandler::class)
            ? $register($this->app->make(ExceptionHandler::class))
            : $this->app->afterResolving(ExceptionHandler::class, $register);
    }

    private function registerQueueMonitoring(): void
    {
        if (! $this->app['config']->get('faultbox.queue.enabled', true)) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $monitor = fn () => $this->app->make(QueueMonitor::class);

        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $monitor()->processing($e));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $monitor()->failed($e));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $e) => $monitor()->exceptionOccurred($e));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $monitor()->processed($e));
        $events->listen(WorkerStopping::class, function () use ($monitor) {
            $monitor()->flushPending();
            $this->app->make(Client::class)->flush();
        });
    }
}
