<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use RequestTracer\Support\CodeScope;
use RequestTracer\Tests\Fixtures\Jobs\RecalculateStats;
use RequestTracer\Tests\Fixtures\Locked\FinalThing;
use RequestTracer\Tests\Fixtures\Models\User;
use RequestTracer\Tests\Fixtures\Services\PricingService;

class ScopeDemoCommand extends Command
{
    protected $signature = 'scope:demo';
}

abstract class ScopeAbstractThing {}

interface ScopeDemoInterface {}

/** @return string path inside the application base path, as the tests' fake project sees it */
function inApp(string $relative): string
{
    return str_replace('\\', '/', (string) realpath(base_path())).'/'.$relative;
}

it('traces everything inside code.paths: a folder, and nothing outside it', function () {
    config(['request-tracer.code.paths' => ['app', 'modules/Billing/src'], 'request-tracer.code.except' => []]);

    expect(CodeScope::includesFile(inApp('app/Services/OrderService.php')))->toBeTrue()
        ->and(CodeScope::includesFile(inApp('app/Http/Controllers/Deep/Nested/Thing.php')))->toBeTrue()
        ->and(CodeScope::includesFile(inApp('modules/Billing/src/Invoice.php')))->toBeTrue()
        ->and(CodeScope::includesFile(inApp('modules/Sales/src/Order.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('application/Other.php')))->toBeFalse()               // "app" is not a prefix of "application"
        ->and(CodeScope::includesFile(inApp('database/seeders/Seeder.php')))->toBeFalse();
});

it('accepts a single file, absolute or relative', function () {
    config(['request-tracer.code.paths' => ['app/Support/Money.php', inApp('lib/Clock.php')]]);

    expect(CodeScope::includesFile(inApp('app/Support/Money.php')))->toBeTrue()
        ->and(CodeScope::includesFile(inApp('app/Support/Other.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('lib/Clock.php')))->toBeTrue();
});

it('leaves out what code.except names, whether it is a folder, a file or a class pattern', function () {
    config(['request-tracer.code.paths' => ['app'], 'request-tracer.code.except' => ['app/Legacy', 'app/Support/Money.php', 'App\\Reports\\*']]);

    expect(CodeScope::includesFile(inApp('app/Legacy/Old/Thing.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('app/Support/Money.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('app/Support/Other.php')))->toBeTrue()
        ->and(CodeScope::classAllowed('App\\Reports\\Monthly'))->toBeFalse()
        ->and(CodeScope::classAllowed('App\\Billing\\Invoice'))->toBeTrue();
});

it('never looks into vendor code, however the paths are written', function () {
    config(['request-tracer.code.paths' => ['.', 'vendor', 'app']]);

    expect(CodeScope::includesFile(inApp('vendor/laravel/framework/src/Foo.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('node_modules/pkg/index.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('storage/framework/request-tracer/proxies/x.php')))->toBeFalse()
        ->and(CodeScope::includesFile(inApp('app/Foo.php')))->toBeTrue();
});

it('traces nothing by folder when code.paths is empty', function () {
    config(['request-tracer.code.paths' => []]);

    expect(CodeScope::includesFile(inApp('app/Foo.php')))->toBeFalse();
});

// (not OrderService: a class is compiled for Deep trace when it is first loaded in the process, and the Order tests need that to happen in their own app)
it('does not give a container proxy to what the framework builds or calls itself', function () {
    expect(CodeScope::proxyable(new ReflectionClass(PricingService::class)))->toBeTrue()
        ->and(CodeScope::proxyable(new ReflectionClass(FinalThing::class)))->toBeFalse()              // final
        ->and(CodeScope::proxyable(new ReflectionClass(User::class)))->toBeFalse()                    // a model
        ->and(CodeScope::proxyable(new ReflectionClass(ScopeDemoCommand::class)))->toBeFalse()        // a console command
        ->and(CodeScope::proxyable(new ReflectionClass(RecalculateStats::class)))->toBeFalse()        // a queued job
        ->and(CodeScope::proxyable(new ReflectionClass(ScopeAbstractThing::class)))->toBeFalse()
        ->and(CodeScope::proxyable(new ReflectionClass(ScopeDemoInterface::class)))->toBeFalse()
        ->and(CodeScope::proxyable(new ReflectionClass(RuntimeException::class)))->toBeFalse()        // an exception
        ->and(CodeScope::proxyable(new ReflectionClass(Kernel::class)))->toBeFalse()
        ->and(CodeScope::proxyable(new ReflectionClass(ServiceProvider::class)))->toBeFalse();
});
