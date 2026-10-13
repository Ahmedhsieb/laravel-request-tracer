<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Facades\Tracer as TracerFacade;
use Ahmedhsieb\RequestTracer\Tracer;

it('registers the tracer singleton, alias, facade and config', function () {
    expect(app(Tracer::class))->toBe(app('request-tracer'))
        ->and(TracerFacade::enabled())->toBeTrue()
        ->and(config('request-tracer.deep_trace.max_hits'))->toBe(20000);
});

it('has a trace() helper that returns its argument unchanged', function () {
    $obj = new stdClass;

    expect(trace($obj))->toBe($obj)
        ->and(trace(42, 'answer'))->toBe(42);
});
