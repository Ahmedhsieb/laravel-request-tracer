<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Support\Brand;

/**
 * Optional. The package needs nothing to be published or migrated: the tables create themselves and the dashboard is served from
 * the package. This publishes the config file (to change what is traced) and the dashboard files (to serve them from the web
 * server), and can run the migration right away instead of waiting for the first trace.
 */
final class InstallCommand extends Command
{
    protected $signature = 'request-tracer:install {--migrate : Create the tables now (they are created by themselves otherwise)} {--force : Overwrite files that were published before}';

    protected $description = 'Optional: publish the Request Tracer config and dashboard files';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        foreach (['config', 'assets'] as $tag) {
            $this->call('vendor:publish', ['--tag' => Brand::NAME.'-'.$tag, '--force' => $force]);
        }

        if ($this->option('migrate')) {
            $this->call('migrate');
        }

        $this->newLine();
        $this->components->info('Nothing more is needed. Start tracing with:');
        $this->line('    php artisan '.Brand::NAME.':listen');
        $this->newLine();
        $this->line('Then open /'.trim((string) config(Brand::NAME.'.dashboard.path', Brand::NAME), '/').' (open to everybody in the local environment).');
        $this->line('Elsewhere, say who may open it, in a service provider:');
        $this->line("    Gate::define('".Brand::GATE."', fn (\$user) => in_array(\$user->email, ['you@example.com']));");

        return self::SUCCESS;
    }
}
