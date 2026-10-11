<?php

/*
 * Runs in a PHP process of its own (see tests/Feature/DeepTraceFreshProcessTest.php): the Deep trace autoloader is registered *before*
 * any other class of the package is loaded, and the first class it is asked about is an application class. That is the order of a real
 * application, and the one the test suite (where the package's classes are already loaded) can never produce.
 *
 *   php deep-loader-fresh-process.php namespace   a class named in `deep_trace.namespaces`
 *   php deep-loader-fresh-process.php scope       the folder named in `code.paths`, minus `code.except`
 *
 * Prints one JSON object: where each class was loaded from, and every failure the tracer reported.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Support\Failure;
use RequestTracer\Tests\Fixtures\Models\User;
use RequestTracer\Tests\Fixtures\Services\CouponService;
use RequestTracer\Tests\Fixtures\Services\PricingService;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$mode = $argv[1] ?? 'namespace';
$cache = sys_get_temp_dir().'/rt-fresh-process-'.getmypid();
$services = str_replace('\\', '/', dirname(__DIR__)).'/Services';

$app = new Application(sys_get_temp_dir().'/rt-fresh-app-'.getmypid());
$settings = ['enabled' => true, 'deep_trace' => ['enabled' => true, 'namespaces' => [], 'files' => [], 'cache_path' => $cache], 'proxies' => ['attribute_namespaces' => []]];
if ($mode === 'scope') {
    $settings['mode'] = 'always';
    $settings['code'] = ['paths' => [$services], 'except' => [$services.'/CouponService.php'], 'line_by_line' => true];
} else {
    $settings['deep_trace']['namespaces'] = [PricingService::class];
}
$app->instance('config', new Repository(['request-tracer' => $settings]));

$failures = [];
Failure::useSink(static function (string $message) use (&$failures): void {
    $failures[] = $message;
});

Loader::ensureRegistered();

$where = static fn (string $class): string => str_replace('\\', '/', (string) (new ReflectionClass($class))->getFileName());

echo json_encode([
    'pricing' => $where(PricingService::class),                // inside the folder
    'coupon' => $where(CouponService::class),                  // inside the folder, but named in `except`
    'user' => $where(User::class),                             // outside the folder
    'cache' => str_replace('\\', '/', $cache),
    'failures' => $failures,
]);
