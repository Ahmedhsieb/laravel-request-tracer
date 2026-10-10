<?php

declare(strict_types=1);

namespace RequestTracer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RequestTracer\Http\Middleware\RecordRequest;
use RequestTracer\Recording\RecordingDecider;
use RequestTracer\Storage\ConnectionResolver;
use RequestTracer\Storage\DatabaseStorage;
use RequestTracer\Storage\PayloadCodec;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\Support\App;
use RequestTracer\Support\Brand;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Paths;

final class RequestTracerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/'.Brand::NAME.'.php', Brand::NAME);

        $this->app->singleton(Tracer::class);
        $this->app->alias(Tracer::class, Brand::CONTAINER_KEY);
        $this->app->singleton(RecordingDecider::class);
        $this->app->singleton(ConnectionResolver::class);
        $this->app->singleton(TraceStorage::class, function (Application $app): TraceStorage {
            /** @var array<string, mixed> $config */
            $config = (array) App::config()->get(Brand::NAME.'.storage', []);
            $driver = (string) ($config['driver'] ?? 'database');
            $custom = $config['drivers'][$driver] ?? null;
            if (is_string($custom) && $custom !== '') {
                return $app->make($custom);
            }

            return new DatabaseStorage(
                $app->make(ConnectionResolver::class),
                new PayloadCodec((int) ($config['compress_over_bytes'] ?? 1024)),
                (string) ($config['table_prefix'] ?? 'tracer_'),
                (int) ($config['insert_chunk'] ?? 500),
            );
        });
    }

    public function boot(): void
    {
        Paths::configure($this->app->basePath());
        $this->registerLogChannel();

        $this->publishResources();

        if (! App::config()->get(Brand::NAME.'.enabled', true)) {
            return;
        }

        // Make sure the singleton exists so `Tracer::$instance` is set for trace() and probes.
        $this->app->make(Tracer::class);

        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (method_exists($kernel, 'prependMiddleware') && method_exists($kernel, 'hasMiddleware') && ! $kernel->hasMiddleware(RecordRequest::class)) {
                $kernel->prependMiddleware(RecordRequest::class);
            }
        });
    }

    private function publishResources(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/'.Brand::NAME.'.php' => config_path(Brand::NAME.'.php'),
        ], Brand::NAME.'-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_10_01_000000_create_request_tracer_tables.php' => database_path('migrations/'.date('Y_m_d_His').'_create_request_tracer_tables.php'),
        ], Brand::NAME.'-migrations');
    }

    /** Internal failures go to their own log channel; define a sensible one when the app has none. */
    private function registerLogChannel(): void
    {
        $channel = (string) App::config()->get(Brand::NAME.'.log_channel', Brand::LOG_CHANNEL);
        if (App::config()->get('logging.channels.'.$channel) === null) {
            App::config()->set('logging.channels.'.$channel, [
                'driver' => 'single',
                'path' => storage_path('logs/'.Brand::NAME.'.log'),
                'level' => 'debug',
            ]);
        }

        Failure::useSink(function (string $message, array $context) use ($channel): void {
            $level = ($context['level'] ?? 'error') === 'debug' ? 'debug' : 'error';
            Log::channel($channel)->{$level}($message, $context);
        });
    }
}
