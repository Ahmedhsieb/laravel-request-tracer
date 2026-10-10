<?php

declare(strict_types=1);

namespace RequestTracer;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RequestTracer\Collectors\CacheCollector;
use RequestTracer\Collectors\Collector;
use RequestTracer\Collectors\EventCollector;
use RequestTracer\Collectors\ExceptionCollector;
use RequestTracer\Collectors\HttpClientCollector;
use RequestTracer\Collectors\JobCollector;
use RequestTracer\Collectors\LogCollector;
use RequestTracer\Collectors\ModelCollector;
use RequestTracer\Collectors\QueryCollector;
use RequestTracer\Collectors\RequestCollector;
use RequestTracer\Collectors\TransactionCollector;
use RequestTracer\Collectors\ValidationCollector;
use RequestTracer\Http\Middleware\RecordRequest;
use RequestTracer\Proxy\ProxyGenerator;
use RequestTracer\Proxy\ProxyRegistry;
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
    /** @var list<class-string<Collector>> */
    private const COLLECTORS = [
        RequestCollector::class,
        ValidationCollector::class,
        QueryCollector::class,
        TransactionCollector::class,
        ModelCollector::class,
        LogCollector::class,
        ExceptionCollector::class,
        HttpClientCollector::class,
        CacheCollector::class,
        EventCollector::class,
        JobCollector::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/'.Brand::NAME.'.php', Brand::NAME);

        $this->app->singleton(Tracer::class);
        $this->app->alias(Tracer::class, Brand::CONTAINER_KEY);
        $this->app->singleton(RecordingDecider::class);
        $this->app->singleton(ConnectionResolver::class);
        $this->app->singleton(ProxyRegistry::class, function (Application $app): ProxyRegistry {
            $path = App::config()->get(Brand::NAME.'.proxies.cache_path');

            return new ProxyRegistry(new ProxyGenerator(is_string($path) && $path !== '' ? $path : $app->storagePath('framework/'.Brand::STORAGE_DIR.'/proxies')));
        });
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
        $tracer = $this->app->make(Tracer::class);
        $this->registerCollectors($tracer);
        $this->registerProxyHook($tracer);

        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            if (method_exists($kernel, 'prependMiddleware') && method_exists($kernel, 'hasMiddleware') && ! $kernel->hasMiddleware(RecordRequest::class)) {
                $kernel->prependMiddleware(RecordRequest::class);
            }
        });
    }

    /** Built-in collectors (each switchable under `collectors`) plus custom ones mapped to a class name. */
    private function registerCollectors(Tracer $tracer): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) App::config()->get(Brand::NAME.'.collectors', []);

        foreach (self::COLLECTORS as $class) {
            if (($config[$class::key()] ?? true) === false) {
                continue;
            }
            Failure::guard(fn () => (new $class)->register($tracer, $this->app), 'registering collector '.$class);
        }

        foreach ($config as $key => $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Collector::class)) {
                Failure::guard(fn () => $this->app->make($class)->register($tracer, $this->app), 'registering collector '.$key);
            }
        }
    }

    /** Layer 2/3: swap container bindings for traced subclasses while a trace is recorded. */
    private function registerProxyHook(Tracer $tracer): void
    {
        $registry = $this->app->make(ProxyRegistry::class);

        $this->app->beforeResolving(static function (string $abstract, array $parameters, Container $container) use ($registry, $tracer): void {
            if (Hot::$on) {
                $registry->handle($abstract, $container, $tracer);
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
