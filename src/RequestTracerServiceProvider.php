<?php

declare(strict_types=1);

namespace RequestTracer;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RequestTracer\Collectors\CacheCollector;
use RequestTracer\Collectors\Collector;
use RequestTracer\Collectors\CommandCollector;
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
use RequestTracer\Console\InstallCommand;
use RequestTracer\Console\PruneCommand;
use RequestTracer\Console\TokenCommand;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Http\Middleware\Authorize;
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
        CommandCollector::class,
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

        $this->loadViewsFrom(__DIR__.'/../resources/views', Brand::NAME);
        $this->publishResources();
        $this->registerCommands();

        // The master switch makes the whole package inert: no listeners, middleware, autoloader, container hook or routes.
        if (! App::config()->get(Brand::NAME.'.enabled', true)) {
            return;
        }

        // Make sure the singleton exists so `Tracer::$instance` is set for trace() and probes.
        $tracer = $this->app->make(Tracer::class);
        $this->registerCollectors($tracer);
        $this->registerProxyHook($tracer);
        $this->registerDashboard();
        $this->registerSchedule();

        if (App::config()->get(Brand::NAME.'.deep_trace.enabled', true)) {
            Loader::ensureRegistered();
        }

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

        $this->publishes([
            __DIR__.'/../dist' => public_path('vendor/'.Brand::NAME),
        ], [Brand::NAME.'-assets', 'laravel-assets']);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class, InstallCommand::class, TokenCommand::class]);
        }
    }

    /**
     * Dashboard + JSON API. Protected by the `viewRequestTracer` gate (see Authorize): in the local environment a permissive
     * default is defined; anywhere else the application has to define it.
     */
    private function registerDashboard(): void
    {
        $config = (array) App::config()->get(Brand::NAME.'.dashboard', []);
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $gate = (string) ($config['gate'] ?? Brand::GATE);
        if (! Gate::has($gate) && App::container()->make(RecordingDecider::class)->isLocal()) {
            Gate::define($gate, static fn (?Authenticatable $user = null): bool => true);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => $config['domain'] ?? null,
            'prefix' => trim((string) ($config['path'] ?? Brand::NAME), '/'),
            'middleware' => (array) ($config['middleware'] ?? ['web', Authorize::class]),
            'as' => Brand::NAME.'.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /** Prune old traces on a schedule, unless the application opted out. */
    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! App::config()->get(Brand::NAME.'.retention.schedule', true)) {
                return;
            }
            $at = (string) App::config()->get(Brand::NAME.'.retention.schedule_at', 'hourly');
            $frequency = in_array($at, ['everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly', 'daily', 'twiceDaily'], true) ? $at : 'hourly';
            $schedule->command(PruneCommand::class)->{$frequency}()->withoutOverlapping()->runInBackground();
        });
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
