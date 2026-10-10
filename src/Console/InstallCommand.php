<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Support\Brand;

/** One-step setup: publishes config, migrations and the dashboard assets, optionally runs the migrations. */
final class InstallCommand extends Command
{
    protected $signature = 'request-tracer:install {--migrate : Run the migrations right away} {--force : Overwrite files that were published before}';

    protected $description = 'Publish the Request Tracer config, migrations and dashboard assets';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        foreach (['config', 'migrations', 'assets'] as $tag) {
            $this->call('vendor:publish', ['--tag' => Brand::NAME.'-'.$tag, '--force' => $force]);
        }

        if ($this->option('migrate')) {
            $this->call('migrate');
        } else {
            $this->components->warn('Run `php artisan migrate` to create the tracer tables.');
        }

        $path = (string) config(Brand::NAME.'.dashboard.path', Brand::NAME);
        $this->newLine();
        $this->components->info("Open /{$path} when you are in the local environment. Elsewhere, authorise yourself in a service provider:");
        $this->line("    Gate::define('".Brand::GATE."', fn (\$user) => in_array(\$user->email, ['you@example.com']));");

        return self::SUCCESS;
    }
}
