<?php

/*
 * Runs in a PHP process of its own (see tests/Order/DeepTraceLoaderTest.php): the Deep trace autoloader is registered *before* any
 * other class of the package is loaded, and the first class it is asked about is an application class. That is the order of a real
 * application, and the one the test suite (where the package's classes are already loaded) can never produce.
 *
 * Prints one JSON object: where the class was loaded from, and every failure the tracer reported.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Support\Failure;
use RequestTracer\Tests\Fixtures\Services\PricingService;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$cache = sys_get_temp_dir().'/rt-fresh-process-'.getmypid();

$container = new Container;
Container::setInstance($container);
$container->instance('config', new Repository(['request-tracer' => [
    'enabled' => true,
    'deep_trace' => ['enabled' => true, 'namespaces' => [PricingService::class], 'files' => [], 'cache_path' => $cache],
    'proxies' => ['attribute_namespaces' => []],
]]));

$failures = [];
Failure::useSink(static function (string $message) use (&$failures): void {
    $failures[] = $message;
});

Loader::ensureRegistered();
class_exists(PricingService::class);                       // the first class the loader is asked about after it was registered

echo json_encode([
    'file' => str_replace('\\', '/', (string) (new ReflectionClass(PricingService::class))->getFileName()),
    'cache' => str_replace('\\', '/', $cache),
    'failures' => $failures,
]);
