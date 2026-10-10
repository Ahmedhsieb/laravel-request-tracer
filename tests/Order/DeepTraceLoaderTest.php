<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Hot;
use RequestTracer\Support\Paths;
use RequestTracer\Tests\Fixtures\Deep\Loaded\FallbackThing;
use RequestTracer\Tests\Fixtures\Deep\Loaded\Tracked;
use RequestTracer\Tests\Fixtures\Deep\Other\Plain;
use RequestTracer\Tests\Fixtures\Deep\Other\QueuedThing;
use RequestTracer\Tracer;

const LOADED_NS = 'RequestTracer\Tests\Fixtures\Deep\Loaded';

it('loads configured classes from a compiled copy and reports the original file', function () {
    config(['request-tracer.deep_trace.namespaces' => [LOADED_NS]]);

    $object = new Tracked;
    $file = (new ReflectionClass($object))->getFileName();

    expect(str_replace('\\', '/', $file))->toContain(str_replace('\\', '/', Loader::cachePath()))
        ->and(Loader::originalOf($file))->toEndWith('tests/Fixtures/Deep/Loaded/Tracked.php')
        ->and(Paths::relative($file))->toEndWith('tests/Fixtures/Deep/Loaded/Tracked.php')
        ->and($object->file())->toEndWith('tests/Fixtures/Deep/Loaded/Tracked.php')            // __FILE__ still means the original
        ->and($object->grade(95))->toBe('A')->and($object->grade(60))->toBe('B')->and($object->grade(1))->toBe('F');
});

it('leaves classes outside the configured namespaces alone', function () {
    config(['request-tracer.deep_trace.namespaces' => [LOADED_NS]]);

    $file = (new ReflectionClass(Plain::class))->getFileName();

    expect(str_replace('\\', '/', $file))->toEndWith('tests/Fixtures/Deep/Other/Plain.php')->and(Loader::originalOf($file))->toBeNull();
});

it('caches the compiled copy and reuses it', function () {
    config(['request-tracer.deep_trace.namespaces' => [LOADED_NS]]);
    class_exists(Tracked::class);

    $dir = Loader::cachePath();
    $files = glob($dir.'/*.php') ?: [];
    $names = array_map('basename', $files);

    expect(array_filter($names, fn ($n) => str_ends_with($n, '.meta.php')))->not->toBeEmpty()
        ->and(array_filter($names, fn ($n) => str_ends_with($n, '.map.php')))->not->toBeEmpty();
});

it('records a request through a compiled class and shows the original file in the trace', function () {
    config(['request-tracer.deep_trace.namespaces' => [LOADED_NS]]);
    Route::get('/graded', fn () => (new Tracked)->grade(72));

    $this->get('/graded')->assertOk();

    $lines = $this->traceStorage()->lines((string) $this->lastTrace()['id']);
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect($line['file'])->toEndWith('tests/Fixtures/Deep/Loaded/Tracked.php');
    }
    $conds = array_values(array_filter($lines, fn ($l) => $l['kind'] === 'cond'));
    expect(array_column($conds, 'line'))->toContain(11)->toContain(13);          // `if ($score >= 90)` and `elseif ($score >= 50)`
    expect(Hot::$on)->toBeFalse();
});

it('falls back to the original file and says so when a file cannot be compiled', function () {
    $blocker = sys_get_temp_dir().'/rt-not-a-directory-'.getmypid();
    file_put_contents($blocker, 'a file where the cache directory should be');
    config([
        'request-tracer.deep_trace.cache_path' => $blocker.'/compiled',            // cannot be created below a file
        'request-tracer.deep_trace.namespaces' => [LOADED_NS],
    ]);
    Route::get('/fallback', fn () => (new FallbackThing)->twice(4));

    $this->get('/fallback')->assertOk()->assertSee('8');

    $file = (new ReflectionClass(FallbackThing::class))->getFileName();
    expect(str_replace('\\', '/', $file))->toEndWith('tests/Fixtures/Deep/Loaded/FallbackThing.php');   // the original was loaded

    $notes = $this->lastTrace()['notes'];
    expect($notes)->not->toBeNull();
    $message = implode("\n", array_column($notes, 'message'));
    expect($message)->toContain('Deep trace could not compile this file, the original was used');
    @unlink($blocker);
});

it('traces a queued file on its next recorded request and then forgets it', function () {
    config(['request-tracer.deep_trace.namespaces' => []]);
    $path = Paths::normalize(realpath(__DIR__.'/../Fixtures/Deep/Other/QueuedThing.php'));
    Loader::queue()->add($path, 600);
    expect(Loader::queue()->contains($path))->toBeTrue();

    Route::get('/queued', fn () => (new QueuedThing)->decide(4));
    $this->get('/queued')->assertOk()->assertSee('even');

    $lines = $this->traceStorage()->lines((string) $this->lastTrace()['id']);
    expect(array_column($lines, 'file'))->toContain(Paths::relative($path));
    expect(Loader::queue()->read())->not->toHaveKey($path);                       // consumed by the recorded request
});

it('does not touch any class when Deep trace is switched off', function () {
    config(['request-tracer.deep_trace.enabled' => false, 'request-tracer.deep_trace.namespaces' => ['RequestTracer\Tests\Fixtures\Deep\Never']]);

    expect(Tracer::$instance)->not->toBeNull();
    expect(Loader::originalOf((string) (new ReflectionClass(Plain::class))->getFileName()))->toBeNull();
});
