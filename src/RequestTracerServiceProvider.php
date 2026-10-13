<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

use Ahmedhsieb\RequestTracer\Collectors\CacheCollector;
use Ahmedhsieb\RequestTracer\Collectors\Collector;
use Ahmedhsieb\RequestTracer\Collectors\CommandCollector;
use Ahmedhsieb\RequestTracer\Collectors\EventCollector;
use Ahmedhsieb\RequestTracer\Collectors\ExceptionCollector;
use Ahmedhsieb\RequestTracer\Collectors\HttpClientCollector;
use Ahmedhsieb\RequestTracer\Collectors\JobCollector;
use Ahmedhsieb\RequestTracer\Collectors\LogCollector;
use Ahmedhsieb\RequestTracer\Collectors\ModelCollector;
use Ahmedhsieb\RequestTracer\Collectors\QueryCollector;
use Ahmedhsieb\RequestTracer\Collectors\RequestCollector;
use Ahmedhsieb\RequestTracer\Collectors\TransactionCollector;
use Ahmedhsieb\RequestTracer\Collectors\ValidationCollector;
use Ahmedhsieb\RequestTracer\Console\InstallCommand;
use Ahmedhsieb\RequestTracer\Console\ListenCommand;
use Ahmedhsieb\RequestTracer\Console\PruneCommand;
use Ahmedhsieb\RequestTracer\Console\ReplayCommand;
use Ahmedhsieb\RequestTracer\Console\TokenCommand;
use Ahmedhsieb\RequestTracer\Console\UndoCommand;
use Ahmedhsieb\RequestTracer\DeepTrace\Loader;
use Ahmedhsieb\RequestTracer\Http\Middleware\Authorize;
use Ahmedhsieb\RequestTracer\Http\Middleware\LightWeb;
use Ahmedhsieb\RequestTracer\Http\Middleware\RecordRequest;
use Ahmedhsieb\RequestTracer\Proxy\ProxyGenerator;
use Ahmedhsieb\RequestTracer\Proxy\ProxyRegistry;
use Ahmedhsieb\RequestTracer\Recording\RecordingDecider;
use Ahmedhsieb\RequestTracer\Storage\ConnectionResolver;
use Ahmedhsieb\RequestTracer\Storage\DatabaseStorage;
use Ahmedhsieb\RequestTracer\Storage\PayloadCodec;
use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Brand;
use Ahmedhsieb\RequestTracer\Support\ConfigMerger;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Support\Paths;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
        $this->mergeConfig();

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

    /** The application's config file only has to hold what it changes: see ConfigMerger. */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;                                                   // the cached file already holds the merged result
        }
        $config = $this->app->make('config');
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/'.Brand::NAME.'.php';
        $config->set(Brand::NAME, ConfigMerger::merge($defaults, ConfigMerger::modernize((array) $config->get(Brand::NAME, []))));
    }

    public function boot(): void
    {
        Paths::configure($this->app->basePath());
        $this->registerLogChannel();

        $this->loadViewsFrom(__DIR__.'/../resources/views', Brand::NAME);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');          // `php artisan migrate` creates the tables; so does the package, when they are missing
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
            $this->commands([ListenCommand::class, ReplayCommand::class, UndoCommand::class, PruneCommand::class, InstallCommand::class, TokenCommand::class]);
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
            'middleware' => $this->dashboardMiddleware($config),
            'as' => Brand::NAME.'.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * The dashboard's middleware, always with the gate: a config that lists its own middleware must not be able to open the
     * dashboard to everybody by leaving Authorize out.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function dashboardMiddleware(array $config): array
    {
        $middleware = array_values(array_filter((array) ($config['middleware'] ?? [LightWeb::class]), 'is_string'));

        return in_array(Authorize::class, $middleware, true) ? $middleware : [...$middleware, Authorize::class];
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
